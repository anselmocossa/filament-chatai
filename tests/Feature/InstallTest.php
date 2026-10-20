<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Commands\InstallCommand;
use Filament\ChatAi\Pages\ChatAiSettingsPage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function chataiPlugin(): ChatAiPlugin
{
    return Filament::getPanel('admin')->getPlugin('chatai');
}

function panelProviderCode(string $id): string
{
    return <<<PHP
    <?php

    namespace App\Providers\Filament;

    use Filament\Panel;
    use Filament\PanelProvider;

    class {$id}PanelProvider extends PanelProvider
    {
        public function panel(Panel \$panel): Panel
        {
            return \$panel
                ->id('{$id}')
                ->path('{$id}')
                ->login();
        }
    }
    PHP;
}

beforeEach(function () {
    ChatAiSettings::flush();
    $this->appDir = sys_get_temp_dir().'/chatai-install-'.uniqid();
    File::ensureDirectoryExists($this->appDir.'/Providers/Filament');
    $this->originalPaths = [app_path(), public_path()];
    app()->useAppPath($this->appDir);
    app()->usePublicPath($this->appDir.'/public');
});

afterEach(function () {
    [$app, $public] = $this->originalPaths;
    app()->useAppPath($app);
    app()->usePublicPath($public);
    // vendor:publish copies the migrations into the testbench app (its paths are fixed at boot).
    File::delete(File::glob(database_path('migrations/*chatai*')));
    File::deleteDirectory($this->appDir);
    chataiPlugin()->canManageSettings(null);
});

it('adds the plugin right after the panel id', function () {
    $code = InstallCommand::addPlugin(panelProviderCode('admin'));

    expect($code)->toContain("->id('admin')\n            ->plugin(\\Filament\\ChatAi\\ChatAiPlugin::make())\n            ->path('admin')");
    expect(InstallCommand::addPlugin('<?php class Nothing {}'))->toBeNull();
});

it('installs on every panel, once, and saves who manages it', function () {
    File::put($this->appDir.'/Providers/Filament/AdminPanelProvider.php', panelProviderCode('admin'));
    File::put($this->appDir.'/Providers/Filament/ShopPanelProvider.php', panelProviderCode('shop'));

    // The test database is already migrated: answer no to running the published copies.
    $this->artisan('chatai:install', ['--panel' => ['admin', 'shop'], '--manager' => ['boss@example.com', 'super_admin']])
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();

    expect(File::glob(database_path('migrations/*_create_chatai_tables.php')))->toHaveCount(1);

    foreach (['Admin', 'Shop'] as $panel) {
        expect(substr_count(File::get($this->appDir."/Providers/Filament/{$panel}PanelProvider.php"), 'ChatAiPlugin::make()'))->toBe(1);
    }

    ChatAiSettings::flush();
    ChatAiSettings::apply();
    expect(config('chatai.access.managers'))->toBe(['boss@example.com', 'super_admin']);

    // Running it again changes nothing.
    $this->artisan('chatai:install', ['--panel' => ['admin'], '--manager' => ['boss@example.com']])
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();
    expect(substr_count(File::get($this->appDir.'/Providers/Filament/AdminPanelProvider.php'), 'ChatAiPlugin::make()'))->toBe(1);
});

it('lets managers in by e-mail or role, without code', function () {
    $boss = User::create(['name' => 'Boss', 'email' => 'Boss@Example.com', 'password' => 'x']);
    $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']);
    $rui = User::create(['name' => 'Rui', 'email' => 'rui@example.com', 'password' => 'x']);
    Role::create(['name' => 'finance', 'guard_name' => 'web']);
    $rui->assignRole('finance');

    $this->actingAs($boss);
    expect(chataiPlugin()->canManage())->toBeFalse(); // closed until someone is named

    ChatAiSettings::save(['access.managers' => ['boss@example.com', 'finance']]);

    expect(chataiPlugin()->canManage())->toBeTrue();
    $this->get('/admin/chatai/settings')->assertOk();

    $this->actingAs($rui);
    expect(chataiPlugin()->canManage())->toBeTrue();

    $this->actingAs($ana);
    expect(chataiPlugin()->canManage())->toBeFalse();
    $this->get('/admin/chatai/settings')->assertForbidden();
});

it('limits who may chat when users are set', function () {
    $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']);
    $rui = User::create(['name' => 'Rui', 'email' => 'rui@example.com', 'password' => 'x']);

    $this->actingAs($rui);
    expect(chataiPlugin()->isAccessible())->toBeTrue(); // nobody listed: everyone

    ChatAiSettings::save(['access.users' => ['ana@example.com']]);

    expect(chataiPlugin()->isAccessible())->toBeFalse();
    $this->get('/admin/chatai')->assertForbidden();

    $this->actingAs($ana);
    expect(chataiPlugin()->isAccessible())->toBeTrue();
});

it('does not let a manager lock everyone out', function () {
    $boss = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => 'x']);
    ChatAiSettings::save(['access.managers' => ['boss@example.com']]);
    $this->actingAs($boss);

    Livewire::test(ChatAiSettingsPage::class)
        ->set('data.access.managers', ['someone@example.com'])
        ->call('save')
        ->assertNotified(__('chatai::chatai.settings.access_lockout'));

    ChatAiSettings::flush();
    ChatAiSettings::apply();
    expect(config('chatai.access.managers'))->toBe(['boss@example.com']);

    Livewire::test(ChatAiSettingsPage::class)
        ->set('data.access.managers', ['boss@example.com', 'someone@example.com'])
        ->call('save');

    ChatAiSettings::flush();
    ChatAiSettings::apply();
    expect(config('chatai.access.managers'))->toBe(['boss@example.com', 'someone@example.com']);
});
