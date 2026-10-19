<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Events\TurnCompleted;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Pages\ChatAiPage;
use Filament\ChatAi\Pages\ChatAiSettingsPage;
use Filament\ChatAi\Pages\ChatAiUsagePage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function adminPlugin(): ChatAiPlugin
{
    return Filament::getPanel('admin')->getPlugin('chatai');
}

function adminUser(string $name = 'Ana'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
}

beforeEach(fn () => ChatAiSettings::flush());

afterEach(fn () => adminPlugin()->canManageSettings(null));

it('keeps settings and usage closed unless the panel allows it', function () {
    $this->actingAs(adminUser());

    $this->get('/admin/chatai/settings')->assertForbidden();
    $this->get('/admin/chatai/usage')->assertForbidden();

    adminPlugin()->canManageSettings(fn (User $user): bool => $user->name === 'Ana');

    $this->get('/admin/chatai/settings')->assertOk()->assertSee(__('chatai::chatai.settings.instructions'));
    $this->get('/admin/chatai/usage')->assertOk();
});

it('saves the assistant settings and keeps keys off this page', function () {
    adminPlugin()->canManageSettings(fn (): bool => true);
    $this->actingAs(adminUser());

    Livewire::test(ChatAiSettingsPage::class)
        ->assertDontSee('sk-')
        ->set('data.instructions', 'Valores sempre em MZN.')
        ->call('save')
        ->assertHasNoErrors();

    ChatAiSettings::flush();
    ChatAiSettings::apply();

    expect(config('chatai.instructions'))->toBe('Valores sempre em MZN.');
});

it('shows cost and volume on the usage page', function () {
    adminPlugin()->canManageSettings(fn (): bool => true);
    config(['chatai.pricing.models' => [['model' => 'claude-opus-5-5', 'input' => 5, 'output' => 25]]]);
    $user = adminUser();
    event(new TurnCompleted($user, Conversation::start($user, 'admin'), 'claude-opus-5-5', 1_000_000, 0, 10, []));

    $this->actingAs($user)->get('/admin/chatai/usage')
        ->assertOk()
        ->assertSee('claude-opus-5-5')
        ->assertSee('5.00');

    Livewire::test(ChatAiUsagePage::class)->set('period', 'year')->assertOk();
});

it('applies the limits saved on the settings page to the chat', function () {
    adminPlugin()->canManageSettings(fn (): bool => true);
    $user = adminUser();
    $this->actingAs($user)->withoutMiddleware(VerifyCsrfToken::class);

    Livewire::test(ChatAiSettingsPage::class)
        ->set('data.rate_limit.per_minute', 1)
        ->set('data.max_iterations', 3)
        ->set('data.timeout', 45)
        ->call('save')
        ->assertHasNoErrors();

    ChatAiSettings::flush();
    app()->instance(LlmClient::class, FakeLlmClient::script([['a'], ['b']]));
    $conversation = Conversation::start($user, 'admin');
    $send = fn () => $this->post(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'Olá']);

    $send()->assertOk();
    $send()->assertStatus(429);

    expect(config('chatai.max_iterations'))->toBe(3)
        ->and(LlmConfig::fromConfig()->timeout)->toBe(45);
});

it('renames the assistant and changes its icon from the settings page', function () {
    adminPlugin()->canManageSettings(fn (): bool => true);
    $this->actingAs(adminUser());

    Livewire::test(ChatAiSettingsPage::class)
        ->set('data.assistant.name', 'Joule')
        ->set('data.assistant.icon', 'heroicon-o-light-bulb')
        ->call('save')
        ->assertHasNoErrors();

    ChatAiSettings::flush();

    expect(adminPlugin()->getLabel())->toBe('Joule')
        ->and(adminPlugin()->getIcon())->toBe('heroicon-o-light-bulb')
        ->and(ChatAiPage::getNavigationLabel())->toBe('Joule');

    $this->get('/admin/chatai')->assertSee('Joule')->assertSee('fi-chatai-header-avatar', false);
});

it('shows an uploaded image as the assistant avatar', function () {
    Storage::fake('public');
    ChatAiSettings::save(['assistant.avatar' => 'chatai/logo.png']);
    ChatAiSettings::flush();

    expect(adminPlugin()->getAvatarUrl())->toContain('chatai/logo.png');
    $this->actingAs(adminUser())->get('/admin/chatai')->assertSee('chatai/logo.png');
});
