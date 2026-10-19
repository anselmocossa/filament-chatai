<?php

use Filament\ChatAi\Agent\AgentEvent;
use Filament\ChatAi\Agent\Orchestrator;
use Filament\ChatAi\Events\TurnCompleted;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Llm\LlmException;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\ArrayToolProvider;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\ToolResult;
use Illuminate\Support\Facades\Event;

function runTurn(FakeLlmClient $llm, string $question = 'Como correram as vendas?'): array
{
    $user = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']);
    $conversation = Conversation::start($user, 'admin');

    $tools = new ArrayToolProvider([
        new ChatAiTool(
            name: 'sales-stats',
            description: 'Sales',
            inputSchema: ['type' => 'object', 'properties' => []],
            handler: fn (array $args) => ToolResult::data(['total' => 45300]),
            readOnly: true,
            title: 'Vendas',
        ),
    ]);

    $events = iterator_to_array((new Orchestrator($llm))->run(
        conversation: $conversation,
        question: $question,
        context: new ChatAiContext(panelId: 'admin'),
        tools: $tools,
        user: $user,
        config: new LlmConfig('https://llm.test', 'k', 'test-model'),
    ), false);

    return [$events, $conversation->fresh()];
}

it('runs tool then text and emits events in order', function () {
    [$events, $conversation] = runTurn(FakeLlmClient::script([
        [FakeLlmClient::call('sales-stats')],
        ['Vendeu ', '45 300 MZN.'],
    ]));

    expect(array_map(fn (AgentEvent $e) => $e->type, $events))
        ->toBe(['tool_start', 'tool_end', 'delta', 'delta', 'done'])
        ->and($events[0]->data['label'])->toBe('Vendas')
        ->and($conversation->messages->pluck('role')->all())
        ->toBe(['user', 'assistant', 'tool', 'assistant'])
        ->and($conversation->messages->last()->content)->toBe('Vendeu 45 300 MZN.');
});

it('renders show_ui components and stores them on the answer', function () {
    [$events, $conversation] = runTurn(FakeLlmClient::script([
        [FakeLlmClient::call('show_ui', ['components' => [
            ['type' => 'kpi', 'items' => [['label' => 'Vendas', 'value' => '45 300', 'unit' => 'MZN']]],
            ['type' => 'nonsense'],
        ]])],
        ['Aqui está.'],
    ]));

    $component = collect($events)->firstWhere('type', 'component');

    expect($component->data['component']['type'])->toBe('kpi')
        ->and(collect($events)->where('type', 'component'))->toHaveCount(1)
        ->and($conversation->messages->last()->components[0]['type'])->toBe('kpi');
});

it('shows the same component and the follow-up suggestions only once per answer', function () {
    $suggestions = ['type' => 'suggestions', 'items' => [['label' => 'Top', 'prompt' => 'Top products']]];
    $kpi = ['type' => 'kpi', 'items' => [['label' => 'Vendas', 'value' => 1]]];

    [$events, $conversation] = runTurn(FakeLlmClient::script([
        [FakeLlmClient::call('show_ui', ['components' => [$kpi, $suggestions]])],
        [FakeLlmClient::call('show_ui', ['components' => [$kpi, ['type' => 'suggestions', 'items' => [['label' => 'Other', 'prompt' => 'Other']]]]])],
        ['Feito.'],
    ]));

    expect(collect($events)->where('type', 'component'))->toHaveCount(2)
        ->and(collect($conversation->messages->last()->components)->pluck('type')->all())->toBe(['kpi', 'suggestions']);
});

it('stops after the maximum number of tool iterations and still answers', function () {
    config(['chatai.max_iterations' => 2]);

    $llm = FakeLlmClient::script([
        [FakeLlmClient::call('sales-stats', id: 'a')],
        [FakeLlmClient::call('sales-stats', id: 'b')],
        ['Resposta final.'],
    ]);

    [$events] = runTurn($llm);

    expect(collect($events)->where('type', 'tool_start'))->toHaveCount(2)
        ->and(end($events)->type)->toBe('done')
        ->and($llm->requests[2]['tools'])->toBe([]);
});

it('dispatches TurnCompleted with usage and tools used', function () {
    Event::fake([TurnCompleted::class]);

    runTurn(FakeLlmClient::script([
        [FakeLlmClient::call('sales-stats')],
        ['ok'],
    ]));

    Event::assertDispatched(TurnCompleted::class, fn (TurnCompleted $e) => $e->tokensIn === 200
        && $e->tokensOut === 40
        && $e->toolsUsed === ['sales-stats']
        && $e->model === 'test-model');
});

it('reports a provider failure as a friendly error and keeps the question', function () {
    [$events, $conversation] = runTurn(FakeLlmClient::script([
        new LlmException('The language model is unavailable (status 503).'),
    ]));

    expect(end($events)->type)->toBe('error')
        ->and(end($events)->data['message'])->toBe(__('chatai::chatai.unavailable'))
        ->and($conversation->messages)->toHaveCount(1)
        ->and($conversation->messages->first()->role)->toBe(Message::ROLE_USER)
        ->and($conversation->messages->first()->status)->toBe(Message::STATUS_ERROR);
});

it('tells the model which screen earlier questions were asked on', function () {
    $user = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']);
    $conversation = Conversation::start($user, 'admin');
    $conversation->addMessage(Message::ROLE_USER, 'Fala-me deste cliente.', ['context' => ['panel' => 'admin', 'resource' => 'customer', 'record_id' => '445']]);
    $conversation->addMessage(Message::ROLE_ASSISTANT, 'A cliente fez 5 pedidos.');

    $llm = FakeLlmClient::script([['O mais caro foi o OR215082.']]);

    iterator_to_array((new Orchestrator($llm))->run(
        conversation: $conversation,
        question: 'E o pedido mais caro dela?',
        context: new ChatAiContext(panelId: 'admin', url: '/chatai'),
        tools: new ArrayToolProvider([]),
        user: $user,
        config: new LlmConfig('https://llm.test', 'k', 'test-model'),
    ), false);

    $users = collect($llm->requests[0]['messages'])->where('role', 'user')->pluck('content')->values();

    expect($users[0])->toContain('customer #445')->toContain('Fala-me deste cliente.')
        ->and($users[1])->toBe('E o pedido mais caro dela?');
});
