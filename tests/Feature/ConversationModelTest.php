<?php

use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Policies\ConversationPolicy;
use Filament\ChatAi\Tests\Fixtures\User;
use Illuminate\Support\Facades\Schema;

function makeUser(string $name = 'Ana'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
}

it('creates the conversation and message tables', function () {
    expect(Schema::hasColumns('chatai_conversations', [
        'id', 'user_type', 'user_id', 'tenant_id', 'panel_id', 'title', 'last_message_at', 'deleted_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('chatai_messages', [
            'id', 'conversation_id', 'role', 'content', 'components', 'tool_calls', 'tool_call_id',
            'tool_name', 'context', 'model', 'tokens_in', 'tokens_out', 'latency_ms', 'status',
        ]))->toBeTrue();
});

it('scopes conversations to their owner and tenant', function () {
    $ana = makeUser('Ana');
    $rui = makeUser('Rui');

    Conversation::start($ana, 'admin');
    Conversation::start($ana, 'admin', tenantId: 'shop-2');
    Conversation::start($rui, 'admin');

    expect(Conversation::forUser($ana)->count())->toBe(1)
        ->and(Conversation::forUser($ana, 'shop-2')->count())->toBe(1)
        ->and(Conversation::forUser($rui)->count())->toBe(1);
});

it('lets only the owner view, update and delete a conversation', function () {
    $ana = makeUser('Ana');
    $rui = makeUser('Rui');
    $conversation = Conversation::start($ana, 'admin');
    $policy = new ConversationPolicy;

    expect($policy->view($ana, $conversation))->toBeTrue()
        ->and($policy->view($rui, $conversation))->toBeFalse()
        ->and($policy->update($rui, $conversation))->toBeFalse()
        ->and($policy->delete($rui, $conversation))->toBeFalse();
});

it('titles the conversation from the first user message, cut to 60 characters', function () {
    $conversation = Conversation::start(makeUser(), 'admin');

    $conversation->addMessage(Message::ROLE_USER, str_repeat('Quanto vendemos ', 10));
    $conversation->addMessage(Message::ROLE_USER, 'Outra pergunta');

    expect($conversation->fresh()->title)->toHaveLength(60)
        ->and($conversation->fresh()->title)->toStartWith('Quanto vendemos')
        ->and($conversation->fresh()->last_message_at)->not->toBeNull()
        ->and($conversation->messages()->count())->toBe(2);
});
