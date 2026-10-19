<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\ToolAction;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\ToolResult;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

function actionPlugin(): ChatAiPlugin
{
    return Filament::getPanel('admin')->getPlugin('chatai');
}

beforeEach(function () {
    ChatAiSettings::flush();
    $this->withoutMiddleware(VerifyCsrfToken::class);
    $this->runs = 0;
    actionPlugin()->tools(fn () => [
        new ChatAiTool('adjust-stock', 'Add or remove stock of a product', [
            'type' => 'object',
            'properties' => ['product' => ['type' => 'string'], 'quantity' => ['type' => 'integer', 'title' => 'Units to add']],
        ], function (array $args) {
            $this->runs++;

            return ToolResult::data(['product' => $args['product'], 'qty' => 12]);
        }, readOnly: false, title: 'Adjust stock'),
    ]);
    $this->user = User::create(['name' => 'Ana', 'email' => 'ana@actions.test', 'password' => 'x']);
    app()->instance(LlmClient::class, FakeLlmClient::script([
        [FakeLlmClient::call('adjust-stock', ['product' => 'Classic Pillow', 'quantity' => 2])],
        ['Vou adicionar 2 unidades. Confirme no cartão.'],
    ]));
});

afterEach(fn () => actionPlugin()->tools(null));

function proposeChange(): array
{
    $conversation = Conversation::start(test()->user, 'admin');
    test()->actingAs(test()->user)->post(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'Adiciona 2 unidades ao Classic Pillow'])->assertOk()->streamedContent();

    return [$conversation->fresh(), ToolAction::query()->sole()];
}

it('does not run a tool that changes data; it shows a confirmation card instead', function () {
    [$conversation, $action] = proposeChange();

    $card = collect($conversation->messages()->where('role', Message::ROLE_ASSISTANT)->get()->last()->components)->firstWhere('type', 'confirm');

    expect($this->runs)->toBe(0)
        ->and($action->status)->toBe(ToolAction::PENDING)
        ->and($card['action_id'])->toBe($action->id)
        ->and($card['title'])->toBe('Adjust stock')
        ->and(collect($card['fields'])->firstWhere('key', 'quantity'))->toMatchArray(['label' => 'Units to add', 'type' => 'number', 'value' => '2']);
});

it('runs the change once when the user confirms and records it in the conversation', function () {
    [$conversation, $action] = proposeChange();

    $this->postJson(route('filament.admin.chatai.actions.confirm', $action))
        ->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('result.1', ['label' => 'Qty', 'value' => '12'])
        ->assertJsonPath('message.role', 'assistant');

    $this->postJson(route('filament.admin.chatai.actions.confirm', $action))->assertStatus(409);

    expect($this->runs)->toBe(1)
        ->and($action->fresh()->status)->toBe(ToolAction::DONE)
        ->and($conversation->messages()->get()->last()->content)->toContain('Adjust stock');

    $this->getJson(route('filament.admin.chatai.conversations.show', $conversation))
        ->assertJsonFragment(['type' => 'confirm', 'status' => 'done']);
});

it('changes nothing when the user cancels', function () {
    [, $action] = proposeChange();

    $this->postJson(route('filament.admin.chatai.actions.cancel', $action))->assertOk()->assertJsonPath('status', 'cancelled');

    expect($this->runs)->toBe(0);
});

it('lets only the owner answer a proposed change', function () {
    [, $action] = proposeChange();

    $this->actingAs(User::create(['name' => 'Rui', 'email' => 'rui@actions.test', 'password' => 'x']))
        ->postJson(route('filament.admin.chatai.actions.confirm', $action))->assertForbidden();

    expect($this->runs)->toBe(0)->and($action->fresh()->status)->toBe(ToolAction::PENDING);
});

it('offers only read-only tools when changes are switched off', function () {
    ChatAiSettings::save(['actions.enabled' => false]);

    $names = collect(actionPlugin()->resolveTools()->tools($this->user, new ChatAiContext(panelId: 'admin')))->pluck('name');

    expect($names)->not->toContain('adjust-stock');
});

it('runs with the values the user filled in on the form, after checking them', function () {
    [, $action] = proposeChange();

    $this->postJson(route('filament.admin.chatai.actions.confirm', $action), ['values' => ['product' => '', 'quantity' => 'x']])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['quantity']]);

    expect($this->runs)->toBe(0)->and($action->fresh()->status)->toBe(ToolAction::PENDING);

    $this->postJson(route('filament.admin.chatai.actions.confirm', $action), ['values' => ['product' => 'Classic Pillow', 'quantity' => '5']])
        ->assertOk()->assertJsonPath('status', 'done');

    expect($this->runs)->toBe(1)->and($action->fresh()->arguments)->toBe(['product' => 'Classic Pillow', 'quantity' => 5]);
});
