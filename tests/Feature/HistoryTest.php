<?php

use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\ConversationGroup;
use Filament\ChatAi\Tests\Fixtures\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

function historyUser(string $name = 'Ana'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@history.test', 'password' => 'x']);
}

beforeEach(fn () => $this->withoutMiddleware(VerifyCsrfToken::class));

it('pins, renames and moves a conversation into a group', function () {
    $user = historyUser();
    $this->actingAs($user);
    $conversation = Conversation::start($user, 'admin');

    $group = $this->postJson(route('filament.admin.chatai.groups.store'), ['name' => 'Vendas'])->assertCreated()->json('id');

    $this->patchJson(route('filament.admin.chatai.conversations.update', $conversation), ['title' => 'Fecho do mês', 'pinned' => true, 'group_id' => $group])
        ->assertOk()
        ->assertJson(['title' => 'Fecho do mês', 'pinned' => true, 'group_id' => $group]);

    $this->getJson(route('filament.admin.chatai.conversations.index'))
        ->assertOk()
        ->assertJsonPath('data.0.pinned', true)
        ->assertJsonPath('data.0.group_id', $group)
        ->assertJsonPath('groups.0.name', 'Vendas');

    $this->patchJson(route('filament.admin.chatai.conversations.update', $conversation), ['pinned' => false, 'group_id' => null])
        ->assertJson(['pinned' => false, 'group_id' => null]);
});

it('lists pinned conversations first', function () {
    $user = historyUser();
    $this->actingAs($user);
    $old = Conversation::start($user, 'admin');
    $old->update(['title' => 'Antiga', 'last_message_at' => now()->subWeek(), 'pinned_at' => now()]);
    Conversation::start($user, 'admin')->update(['title' => 'Recente', 'last_message_at' => now()]);

    $this->getJson(route('filament.admin.chatai.conversations.index'))->assertJsonPath('data.0.title', 'Antiga');
});

it('does not let a user use or change someone else\'s group or conversation', function () {
    $ana = historyUser('Ana');
    $group = ConversationGroup::query()->create(['user_type' => $ana->getMorphClass(), 'user_id' => $ana->getKey(), 'panel_id' => 'admin', 'name' => 'Privado']);
    $anasConversation = Conversation::start($ana, 'admin');

    $rui = historyUser('Rui');
    $this->actingAs($rui);
    $mine = Conversation::start($rui, 'admin');

    $this->patchJson(route('filament.admin.chatai.conversations.update', $mine), ['group_id' => $group->id])->assertStatus(422);
    $this->patchJson(route('filament.admin.chatai.conversations.update', $anasConversation), ['pinned' => true])->assertForbidden();
    $this->patchJson(route('filament.admin.chatai.groups.update', $group->id), ['name' => 'x'])->assertNotFound();
    $this->deleteJson(route('filament.admin.chatai.groups.destroy', $group->id))->assertNotFound();
    $this->getJson(route('filament.admin.chatai.conversations.index'))->assertJsonCount(0, 'groups');
});

it('renames a group and keeps its conversations when it is deleted', function () {
    $user = historyUser();
    $this->actingAs($user);
    $group = $this->postJson(route('filament.admin.chatai.groups.store'), ['name' => 'Novo grupo'])->json('id');
    $conversation = Conversation::start($user, 'admin');
    $conversation->update(['group_id' => $group]);

    $this->patchJson(route('filament.admin.chatai.groups.update', $group), ['name' => 'Clientes'])->assertJson(['name' => 'Clientes']);
    $this->deleteJson(route('filament.admin.chatai.groups.destroy', $group))->assertNoContent();

    expect($conversation->fresh()->group_id)->toBeNull()
        ->and(ConversationGroup::query()->count())->toBe(0);
});
