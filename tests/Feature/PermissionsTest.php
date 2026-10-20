<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\ToolOverride;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Support\ChatAiPermissions;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\ToolResult;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function permPlugin(): ChatAiPlugin
{
    return Filament::getPanel('admin')->getPlugin('chatai');
}

function permUser(string $name, array $permissions = []): User
{
    $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function permTools(User $user): array
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return collect(permPlugin()->resolveTools()->tools($user, new ChatAiContext('admin')))->pluck('name')->all();
}

beforeEach(function () {
    ChatAiSettings::flush();
    permPlugin()->tools(fn () => [
        new ChatAiTool('sales-stats', 'Sales', ['type' => 'object', 'properties' => []], fn () => ToolResult::data([]), readOnly: true),
        new ChatAiTool('hr-summary', 'Payroll and staff', ['type' => 'object', 'properties' => []], fn () => ToolResult::data([]), readOnly: true),
    ]);
});

afterEach(fn () => permPlugin()->tools(null));

it('names one Shield permission per tool, in the Shield format', function () {
    config(['filament-shield.permissions.separator' => ':', 'filament-shield.permissions.case' => 'pascal']);

    expect(ChatAiPermissions::forTool('hr-summary'))->toBe('UseChatAiTool:HrSummary')
        ->and(ChatAiPermissions::forChat())->toBe('UseChatAi');
});

it('changes nothing while permissions are off', function () {
    expect(permTools(permUser('Ana')))->toBe(['sales-stats', 'hr-summary', 'query_data', 'describe_data']);
});

it('only gives each user the tools their role allows once permissions are on', function () {
    ChatAiSettings::save(['permissions.enabled' => true]);

    $seller = permUser('Vendedor', ['UseChatAiTool:SalesStats']);
    $nobody = permUser('Visitante');

    expect(permTools($seller))->toBe(['sales-stats'])
        ->and(permTools($nobody))->toBe([]);
});

it('can require an existing permission for a tool instead of its own', function () {
    ChatAiSettings::save(['permissions.enabled' => true]);
    ToolOverride::create(['tool_name' => 'hr-summary', 'enabled' => true, 'permission' => 'ViewAny:Employee']);

    expect(permTools(permUser('Rh', ['ViewAny:Employee'])))->toBe(['hr-summary']);
});

it('lets the Shield super admin through', function () {
    ChatAiSettings::save(['permissions.enabled' => true]);
    config(['filament-shield.super_admin.enabled' => true, 'filament-shield.super_admin.name' => 'super_admin']);
    $boss = permUser('Boss');
    $boss->assignRole(Role::findOrCreate('super_admin', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(permTools($boss->fresh()))->toBe(['sales-stats', 'hr-summary', 'query_data', 'describe_data']);
});

it('closes the chat to users without the chat permission when required', function () {
    ChatAiSettings::save(['permissions.enabled' => true, 'permissions.require_chat' => true]);

    $this->actingAs(permUser('Visitante'))->get('/admin/chatai')->assertForbidden();
    $this->actingAs(permUser('Gestor', ['UseChatAi']))->get('/admin/chatai')->assertOk();
});

it('registers the tool and chat permissions with Shield and creates them', function () {
    ChatAiSettings::save(['permissions.enabled' => true]);
    config(['filament-shield.custom_permissions' => ['apply_pos_discount']]);
    $this->actingAs(permUser('Ana'));

    ChatAiPermissions::register(permPlugin());

    expect(config('filament-shield.custom_permissions'))->toContain('apply_pos_discount', 'UseChatAi', 'UseChatAiTool:SalesStats', 'UseChatAiTool:HrSummary')
        ->and(Permission::query()->where('name', 'UseChatAiTool:HrSummary')->exists())->toBeTrue();
});

it('lists code tool permissions for Shield even before anyone signs in', function () {
    ChatAiSettings::save(['permissions.enabled' => true]);
    config(['filament-shield.custom_permissions' => []]);
    auth()->logout();

    ChatAiPermissions::register(permPlugin());

    expect(config('filament-shield.custom_permissions'))->toContain('UseChatAiTool:SalesStats', 'UseChatAiTool:HrSummary');
});
