<?php

use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

function apiUser(string $name = 'Ana'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
}

beforeEach(function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    app()->instance(LlmClient::class, FakeLlmClient::script([['Olá ', 'Boss.']]));
});

it('streams the turn as server-sent events', function () {
    $user = apiUser();
    $conversation = Conversation::start($user, 'admin');

    $response = $this->actingAs($user)->post(
        route('filament.admin.chatai.messages.store', $conversation),
        ['message' => 'Olá', 'context' => ['url' => '/admin']],
    );

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
        ->assertHeader('X-Accel-Buffering', 'no');

    $body = $response->streamedContent();

    expect($body)->toContain("event: delta\ndata: {\"text\":\"Olá \"}")
        ->and($body)->toContain('event: done');
});

it('keeps conversations private to their owner', function () {
    $conversation = Conversation::start(apiUser('Ana'), 'admin');
    $this->actingAs(apiUser('Rui'));

    $this->getJson(route('filament.admin.chatai.conversations.show', $conversation))->assertForbidden();
    $this->postJson(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'x'])->assertForbidden();
    $this->deleteJson(route('filament.admin.chatai.conversations.destroy', $conversation))->assertForbidden();
});

it('lists, creates, shows and deletes the user\'s conversations', function () {
    $user = apiUser();
    $this->actingAs($user);

    $id = $this->postJson(route('filament.admin.chatai.conversations.store'))->assertCreated()->json('id');
    Conversation::find($id)->addMessage('user', 'Vendas do mês');

    $this->getJson(route('filament.admin.chatai.conversations.index'))
        ->assertOk()->assertJsonPath('data.0.title', 'Vendas do mês');
    $this->getJson(route('filament.admin.chatai.conversations.show', $id))
        ->assertOk()->assertJsonPath('messages.0.content', 'Vendas do mês');
    $this->deleteJson(route('filament.admin.chatai.conversations.destroy', $id))->assertNoContent();

    expect(Conversation::find($id))->toBeNull();
});

it('blocks everything when canAccess is false and redirects guests', function () {
    Filament::getPanel('admin')->getPlugin('chatai')->canAccess(fn () => false);
    $this->actingAs(apiUser());

    $this->getJson(route('filament.admin.chatai.conversations.index'))->assertForbidden();
    $this->postJson(route('filament.admin.chatai.conversations.store'))->assertForbidden();

    auth()->logout();
    $this->get(route('filament.admin.chatai.conversations.index'))->assertRedirect();
});

it('rate limits messages per minute with a friendly message', function () {
    config(['chatai.rate_limit.per_minute' => 2]);
    app()->instance(LlmClient::class, FakeLlmClient::script([['a'], ['b'], ['c']]));

    $user = apiUser();
    $conversation = Conversation::start($user, 'admin');
    $this->actingAs($user);
    $url = route('filament.admin.chatai.messages.store', $conversation);

    $this->post($url, ['message' => '1'])->assertOk();
    $this->post($url, ['message' => '2'])->assertOk();
    $this->postJson($url, ['message' => '3'])
        ->assertStatus(429)
        ->assertJsonPath('message', __('chatai::chatai.rate_limited'));
});
