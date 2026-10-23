<?php

use Filament\ChatAi\Agent\Orchestrator;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\ToolAction;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\ArrayToolProvider;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\ToolResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

beforeEach(fn () => config(['chatai.conversation_title' => false]));

/** Runs the real harness with a scripted provider and isolated tool handlers. */
function harnessTurn(FakeLlmClient $llm, array $tools, ?Closure $stop = null): array
{
    $user = User::create(['name' => 'Harness', 'email' => 'harness@example.test', 'password' => 'x']);
    $conversation = Conversation::start($user, 'admin');
    $events = iterator_to_array((new Orchestrator($llm))->run(
        conversation: $conversation,
        question: 'Testar a recuperação do assistente.',
        context: new ChatAiContext('admin'),
        tools: new ArrayToolProvider($tools),
        user: $user,
        config: new LlmConfig('https://llm.test', 'k', 'harness-model'),
        shouldStop: $stop,
    ), false);

    return [$events, $conversation->fresh()];
}

it('recovers from invalid enum arguments with feedback and runs only the corrected search', function () {
    $received = [];
    $search = new ChatAiTool('search', 'Search', ['type' => 'object', 'properties' => [
        'by' => ['type' => 'string', 'enum' => ['name', 'role']],
    ], 'required' => ['by']], function ($args) use (&$received) {
        $received[] = $args;

        return ToolResult::data(['count' => 2]);
    }, readOnly: true);
    $llm = FakeLlmClient::script([
        [FakeLlmClient::call('search', ['by' => 'location'], 'bad')],
        [FakeLlmClient::call('search', ['by' => 'role'], 'fixed')],
        ['Encontrei 2 resultados.'],
    ]);
    [$events, $conversation] = harnessTurn($llm, [$search]);
    $feedback = collect($llm->requests[1]['messages'])->where('role', 'tool')->last()['content'];
    expect($received)->toBe([['by' => 'role']])
        ->and($feedback)->toContain('by')->toContain('role')
        ->and(collect($events)->where('type', 'tool_end')->pluck('data.ok')->all())->toBe([false, true])
        ->and($conversation->messages->last()->content)->toBe('Encontrei 2 resultados.');
});

it('recovers after a hallucinated tool without executing any unavailable handler', function () {
    $ran = 0;
    $tool = new ChatAiTool('available', 'Available', ['type' => 'object'], function () use (&$ran) {
        $ran++;

        return ToolResult::data(['ok' => true]);
    }, readOnly: true);
    $llm = FakeLlmClient::script([
        [FakeLlmClient::call('invented', [], 'unknown')],
        [FakeLlmClient::call('available', [], 'real')],
        ['Concluído.'],
    ]);
    [, $conversation] = harnessTurn($llm, [$tool]);
    expect($ran)->toBe(1)
        ->and($conversation->messages->where('role', 'tool')->first()->content)->toContain('Unknown tool invented')
        ->and($conversation->messages->last()->content)->toBe('Concluído.');
});

it('does not expose internal exception details to the model or persisted conversation', function () {
    Log::spy();
    $secret = 'SQLSTATE fake-secret-in-exception /private/internal/db';
    $tool = new ChatAiTool('broken', 'Broken', ['type' => 'object'], function () use ($secret) {
        throw new RuntimeException($secret);
    }, readOnly: true);
    $llm = FakeLlmClient::script([[FakeLlmClient::call('broken')], ['Não foi possível consultar.']]);
    [, $conversation] = harnessTurn($llm, [$tool]);
    $feedback = $conversation->messages->where('role', 'tool')->first()->content;
    expect($feedback)->toContain('error')->not->toContain('fake-secret-in-exception')
        ->and(json_encode($llm->requests))->not->toContain('fake-secret-in-exception');
    Log::shouldHaveReceived('warning')->once();
});

it('keeps authorization failures distinct and never returns protected records', function () {
    $tool = new ChatAiTool('private', 'Private', ['type' => 'object'], function () {
        throw new AuthorizationException('Sensitive reason');
    }, readOnly: true);
    $llm = FakeLlmClient::script([[FakeLlmClient::call('private')], ['Sem acesso.']]);
    [, $conversation] = harnessTurn($llm, [$tool]);
    expect($conversation->messages->where('role', 'tool')->first()->content)->toContain('permission')->not->toContain('Sensitive reason');
});

it('honours Stop between calls in one batch and keeps a reply for each call id', function () {
    $stop = false;
    $lateRuns = 0;
    $first = new ChatAiTool('first', 'First', ['type' => 'object'], function () use (&$stop) {
        $stop = true;

        return ToolResult::data(['ok' => true]);
    }, readOnly: true);
    $later = new ChatAiTool('later', 'Later', ['type' => 'object'], function () use (&$lateRuns) {
        $lateRuns++;

        return ToolResult::data([]);
    }, readOnly: true);
    $write = new ChatAiTool('write', 'Write', ['type' => 'object'], fn () => ToolResult::data([]));
    $llm = FakeLlmClient::script([[
        FakeLlmClient::call('first', [], 'one'), FakeLlmClient::call('later', [], 'two'), FakeLlmClient::call('write', [], 'three'),
    ]]);
    [$events, $conversation] = harnessTurn($llm, [$first, $later, $write], function () use (&$stop) {
        return $stop;
    });
    expect($lateRuns)->toBe(0)
        ->and(ToolAction::query()->count())->toBe(0)
        ->and($llm->requests)->toHaveCount(1)
        ->and($conversation->messages->where('role', 'tool')->pluck('tool_call_id')->values()->all())->toBe(['one', 'two', 'three'])
        ->and($conversation->messages->where('tool_call_id', 'two')->first()->content)->toContain('stopped')
        ->and($conversation->messages->last()->status)->toBe('stopped')
        ->and(end($events)->type)->toBe('done');
});

it('never executes extra calls after the iteration cap and explains an empty final answer', function () {
    config(['chatai.max_iterations' => 1]);
    $ran = 0;
    $tool = new ChatAiTool('repeat', 'Repeat', ['type' => 'object'], function () use (&$ran) {
        $ran++;

        return ToolResult::data([]);
    }, readOnly: true);
    $llm = FakeLlmClient::script([
        [FakeLlmClient::call('repeat', [], 'one')],
        [FakeLlmClient::call('repeat', [], 'ignored')],
    ]);
    [$events, $conversation] = harnessTurn($llm, [$tool]);
    expect($ran)->toBe(1)
        ->and($llm->requests)->toHaveCount(2)
        ->and($llm->requests[1]['tools'])->toBe([])
        ->and($conversation->messages->last()->content)->toBe(__('chatai::chatai.steps_exhausted'))
        ->and(collect($events)->where('type', 'delta')->pluck('data.text')->implode(''))->toBe(__('chatai::chatai.steps_exhausted'));
});

it('allows a transient read failure to recover on the next call', function () {
    $runs = 0;
    $tool = new ChatAiTool('retry-read', 'Retry read', ['type' => 'object'], function () use (&$runs) {
        return ++$runs === 1 ? ToolResult::error('Temporarily unavailable') : ToolResult::data(['count' => 3]);
    }, readOnly: true);
    [, $conversation] = harnessTurn(FakeLlmClient::script([
        [FakeLlmClient::call('retry-read', [], 'first')],
        [FakeLlmClient::call('retry-read', [], 'second')],
        ['Encontrei 3.'],
    ]), [$tool]);
    expect($runs)->toBe(2)->and($conversation->messages->last()->content)->toBe('Encontrei 3.');
});

it('lets the model repair malformed UI before pausing at a valid question', function () {
    $llm = FakeLlmClient::script([
        [FakeLlmClient::call('show_ui', ['component' => ['type' => 'questions']], 'bad')],
        [FakeLlmClient::call('show_ui', ['components' => [['type' => 'questions', 'questions' => [['question' => 'Qual formato?']]]]], 'fixed')],
        ['Must not run before the user replies'],
    ]);
    [, $conversation] = harnessTurn($llm, []);
    expect($llm->requests)->toHaveCount(2)
        ->and($conversation->messages->where('role', 'tool')->first()->content)->toContain('Nothing was shown')
        ->and($conversation->messages->last()->components[0]['status'])->toBe('pending');
});

it('sends a list written as text back to the model and proposes the change only after it is an array', function () {
    $ran = 0;
    $tool = new ChatAiTool('criar-grupo', 'Create a group of candidates', [
        'type' => 'object',
        'properties' => [
            'nome' => ['type' => 'string'],
            'candidatos' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ],
        'required' => ['nome', 'candidatos'],
    ], function () use (&$ran) {
        $ran++;

        return ToolResult::data([]);
    }, readOnly: false, title: 'Criar grupo');
    $llm = FakeLlmClient::script([
        [FakeLlmClient::call('criar-grupo', ['nome' => 'Teste', 'candidatos' => '8835, 8820'], 'bad')],
        [FakeLlmClient::call('criar-grupo', ['nome' => 'Teste', 'candidatos' => [8835, 8820]], 'good')],
        ['Confirme o grupo.'],
    ]);

    [, $conversation] = harnessTurn($llm, [$tool]);
    $feedback = collect($llm->requests[1]['messages'])->where('role', 'tool')->last()['content'];

    expect($ran)->toBe(0)
        ->and($feedback)->toContain('candidatos')->toContain('array')
        ->and(ToolAction::query()->count())->toBe(1)
        ->and(ToolAction::query()->sole()->status)->toBe(ToolAction::PENDING)
        ->and(ToolAction::query()->sole()->arguments['candidatos'])->toBe([8835, 8820])
        ->and($conversation->messages->last()->content)->toBe('Confirme o grupo.');
});

it('shows an explicit failure when the provider returns no text or usable UI', function () {
    [$events, $conversation] = harnessTurn(FakeLlmClient::script([[]]), []);
    expect($conversation->messages->last()->content)->toBe(__('chatai::chatai.unavailable'))
        ->and(collect($events)->where('type', 'delta')->pluck('data.text')->implode(''))->toBe(__('chatai::chatai.unavailable'));
});

it('compacts an over-budget conversation before the model sees it', function () {
    config([
        'chatai.history_budget_chars' => 3_000,
        'chatai.history_keep_chars' => 2_500,
    ]);
    $user = User::create(['name' => 'Harness', 'email' => 'compact@example.test', 'password' => 'x']);
    $conversation = Conversation::start($user, 'admin');
    $old = str_repeat('ANTIGO-UNICO-', 700);
    $conversation->addMessage(Message::ROLE_USER, $old);
    $conversation->addMessage(Message::ROLE_ASSISTANT, null, ['tool_calls' => [[
        'id' => 'call-grupo',
        'type' => 'function',
        'function' => ['name' => 'criar-grupo', 'arguments' => '{"nome":"Grupo Norte"}'],
    ]]]);
    $conversation->addMessage(Message::ROLE_TOOL, '{"status":"awaiting_confirmation","nome":"Grupo Norte"}', [
        'tool_call_id' => 'call-grupo',
        'tool_name' => 'criar-grupo',
    ]);
    $recent = str_repeat('RECENTE-', 300);
    $conversation->addMessage(Message::ROLE_USER, $recent);

    $llm = FakeLlmClient::script([['Continuo daqui.']]);
    iterator_to_array((new Orchestrator($llm))->run(
        conversation: $conversation,
        question: 'AGORA-RESPONDE',
        context: new ChatAiContext('admin'),
        tools: new ArrayToolProvider([]),
        user: $user,
        config: new LlmConfig('https://llm.test', 'k', 'harness-model'),
    ), false);

    $messages = $llm->requests[0]['messages'];
    $encoded = json_encode($messages, JSON_UNESCAPED_UNICODE);

    expect($encoded)->toContain('Earlier turns were compacted')
        ->and($encoded)->toContain('Grupo Norte')
        ->and($encoded)->toContain($recent)
        ->and($encoded)->toContain('AGORA-RESPONDE')
        ->and($encoded)->not->toContain($old)
        ->and(collect($messages)->where('role', 'user')->last()['content'])->toBe('AGORA-RESPONDE')
        ->and($conversation->messages->last()->content)->toBe('Continuo daqui.');
});

it('does not add a false failure to a valid visual-only answer', function () {
    [, $conversation] = harnessTurn(FakeLlmClient::script([
        [FakeLlmClient::call('show_ui', ['components' => [['type' => 'kpi', 'items' => [['label' => 'Total', 'value' => 3]]]]])],
        [],
    ]), []);
    expect($conversation->messages->last()->content)->toBe('')
        ->and($conversation->messages->last()->components[0]['type'])->toBe('kpi');
});
