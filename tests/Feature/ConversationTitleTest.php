<?php

use Filament\ChatAi\Agent\ConversationTitle;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;

beforeEach(function () {
    ChatAiSettings::flush();
    $this->user = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']);
    $this->conversation = Conversation::start($this->user, 'admin');
    $this->conversation->addMessage(Message::ROLE_USER, 'Quantos candidatos com experiência em SAP temos no portal?');
});

$write = fn ($conversation, string $said) => ConversationTitle::write(
    $conversation,
    'Quantos candidatos com experiência em SAP temos no portal?',
    'Encontrei 124.',
    LlmConfig::fromConfig(),
    FakeLlmClient::script([[$said]]),
);

it('names the conversation from the first exchange', function () use ($write) {
    expect($write($this->conversation, 'Candidatos com experiência SAP'))->toBe('Candidatos com experiência SAP')
        ->and($this->conversation->fresh()->title)->toBe('Candidatos com experiência SAP');
});

it('strips the quotes and prefixes models like to add', function () use ($write) {
    expect($write($this->conversation, '"Título: Candidatos SAP."'))->toBe('Candidatos SAP');
});

it('keeps the old title when the model writes a sentence instead', function () use ($write) {
    $before = $this->conversation->title;

    expect($write($this->conversation, 'Claro! Aqui está um título para a sua conversa sobre os candidatos com experiência em SAP que encontrei no portal.'))->toBeNull()
        ->and($this->conversation->fresh()->title)->toBe($before);
});

it('names a conversation only once', function () use ($write) {
    $write($this->conversation, 'Candidatos SAP');
    $this->conversation->addMessage(Message::ROLE_USER, 'E quantos são de Maputo?');

    expect($write($this->conversation->fresh(), 'Candidatos de Maputo'))->toBeNull()
        ->and($this->conversation->fresh()->title)->toBe('Candidatos SAP');
});

it('does not ask for a title when the setting is off', function () use ($write) {
    ChatAiSettings::save(['conversation_title' => false]);

    expect($write($this->conversation, 'Candidatos SAP'))->toBeNull();
});
