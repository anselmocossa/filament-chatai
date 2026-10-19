<?php

use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    $this->user = User::create(['name' => 'Ana', 'email' => 'ana@controls.test', 'password' => 'x']);
    $this->actingAs($this->user);
});

function answered(): array
{
    $conversation = Conversation::start(test()->user, 'admin');
    $conversation->addMessage('user', 'Vendas de hoje?');
    $answer = $conversation->addMessage('assistant', 'Foram 10.');

    return [$conversation, $answer];
}

it('stores thumbs up, thumbs down with a reason, and clearing it', function () {
    [$conversation, $answer] = answered();
    $url = route('filament.admin.chatai.messages.feedback', $answer);

    $this->postJson($url, ['value' => 'up'])->assertOk();
    expect($answer->fresh()->feedback)->toBe(1);

    $this->postJson($url, ['value' => 'down', 'comment' => 'Faltou o total'])->assertOk();
    expect($answer->fresh())->feedback->toBe(-1)->feedback_comment->toBe('Faltou o total');

    $this->postJson($url, ['value' => null])->assertOk();
    expect($answer->fresh())->feedback->toBeNull()->feedback_comment->toBeNull();

    $this->getJson(route('filament.admin.chatai.conversations.show', $conversation))->assertJsonPath('messages.1.feedback', null);
});

it('does not let someone rate another user answer', function () {
    [, $answer] = answered();
    $this->actingAs(User::create(['name' => 'Rui', 'email' => 'rui@controls.test', 'password' => 'x']));

    $this->postJson(route('filament.admin.chatai.messages.feedback', $answer), ['value' => 'down'])->assertForbidden();
});

it('replaces the last exchange when answering again or editing the question', function () {
    [$conversation] = answered();
    app()->instance(LlmClient::class, FakeLlmClient::script([['Foram 12, corrigido.']]));

    $this->post(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'Vendas de ontem?', 'replace_last' => true])->assertOk()->streamedContent();

    expect($conversation->messages()->pluck('content')->all())->toBe(['Vendas de ontem?', 'Foram 12, corrigido.']);
});
