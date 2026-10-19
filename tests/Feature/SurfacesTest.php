<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Support\ContextResolver;
use Filament\ChatAi\Tests\Fixtures\EditWidgetPage;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\Facades\Filament;

function surfaceUser(string $name = 'Ana'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
}

function plugin(): ChatAiPlugin
{
    return Filament::getPanel('admin')->getPlugin('chatai');
}

it('renders the floating launcher and the assets on panel pages', function () {
    $this->actingAs(surfaceUser())
        ->get('/admin/chatai')
        ->assertOk()
        ->assertSee('fi-chatai-launcher', false)
        ->assertSee('chataiChat(', false)
        ->assertSee('chatai.js', false)
        ->assertSee('id="chatai-context"', false);
});

it('hides the launcher when the floating widget is off or access is denied', function () {
    $this->actingAs(surfaceUser());

    plugin()->floatingWidget(false);
    $this->get('/admin/chatai')->assertOk()->assertDontSee('fi-chatai-launcher', false);

    plugin()->floatingWidget(true)->canAccess(fn () => false);
    $this->get('/admin')->assertDontSee('fi-chatai-launcher', false)->assertDontSee('chatai.js', false);
});

it('serves the full page only to users with access', function () {
    $this->actingAs(surfaceUser());

    $this->get('/admin/chatai')->assertOk()->assertSee('fi-chatai-page', false);

    plugin()->canAccess(fn () => false);
    $this->get('/admin/chatai')->assertForbidden();
});

it('does not open another user\'s conversation from the URL', function () {
    $foreign = Conversation::start(surfaceUser('Rui'), 'admin');

    $this->actingAs(surfaceUser('Ana'))
        ->get('/admin/chatai?conversation='.$foreign->id)
        ->assertOk()
        ->assertDontSee($foreign->id);
});

it('resolves the record on screen from a resource route', function () {
    $context = ContextResolver::fromRoute(
        controller: EditWidgetPage::class,
        parameters: ['record' => 42],
    );

    expect($context)->toBe(['resource' => 'Widget', 'record_id' => '42']);
});
