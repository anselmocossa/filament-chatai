<?php

use Filament\ChatAi\Agent\Orchestrator;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Memory;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\ArrayToolProvider;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

beforeEach(function () {
    ChatAiSettings::flush();
    $this->withoutMiddleware(VerifyCsrfToken::class);
    $this->user = User::create(['name' => 'Ana', 'email' => 'ana@memory.test', 'password' => 'x']);
});

function memoryTurn(FakeLlmClient $llm, string $question = 'Lembra-te que os valores são em MZN'): array
{
    $events = iterator_to_array((new Orchestrator($llm))->run(
        conversation: Conversation::start(test()->user, 'admin'),
        question: $question,
        context: new ChatAiContext(panelId: 'admin'),
        tools: new ArrayToolProvider([]),
        user: test()->user,
        config: new LlmConfig('https://llm.test', 'k', 'test-model'),
    ), false);

    return $events;
}

it('remembers what the user asks and uses it in the next conversations', function () {
    $events = memoryTurn(FakeLlmClient::script([
        [FakeLlmClient::call('remember', ['fact' => 'Prefere valores em MZN.'])],
        ['Combinado.'],
    ]));

    expect(Memory::query()->pluck('content')->all())->toBe(['Prefere valores em MZN.'])
        ->and(collect($events)->firstWhere('type', 'component')->data['component'])->toBe(['type' => 'memory', 'text' => 'Prefere valores em MZN.']);

    $next = FakeLlmClient::script([['Ok.']]);
    memoryTurn($next, 'Vendas de hoje?');

    expect($next->requests[0]['messages'][0]['content'])->toContain('Prefere valores em MZN.');
});

it('lets each user see and forget only their own memory', function () {
    Memory::query()->create(['user_type' => $this->user->getMorphClass(), 'user_id' => $this->user->getKey(), 'panel_id' => 'admin', 'content' => 'Gere a loja de Maputo.']);
    $rui = User::create(['name' => 'Rui', 'email' => 'rui@memory.test', 'password' => 'x']);
    Memory::query()->create(['user_type' => $rui->getMorphClass(), 'user_id' => $rui->getKey(), 'panel_id' => 'admin', 'content' => 'Do Rui.']);

    $this->actingAs($this->user)->getJson(route('filament.admin.chatai.memories.index'))
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.content', 'Gere a loja de Maputo.');

    $this->deleteJson(route('filament.admin.chatai.memories.clear'))->assertNoContent();

    expect(Memory::query()->pluck('content')->all())->toBe(['Do Rui.']);
});

it('neither offers the tool nor uses memories when memory is switched off', function () {
    Memory::query()->create(['user_type' => $this->user->getMorphClass(), 'user_id' => $this->user->getKey(), 'panel_id' => 'admin', 'content' => 'Segredo.']);
    ChatAiSettings::save(['memory.enabled' => false]);
    ChatAiSettings::apply();

    $llm = FakeLlmClient::script([['Ok.']]);
    memoryTurn($llm, 'Olá');

    expect(collect($llm->requests[0]['tools'])->pluck('function.name'))->not->toContain('remember')
        ->and($llm->requests[0]['messages'][0]['content'])->not->toContain('Segredo.');
});
