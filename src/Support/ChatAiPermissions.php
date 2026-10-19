<?php

namespace Filament\ChatAi\Support;

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\Facades\Filament;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Filament Shield / spatie-permission integration: one permission per tool
 * (UseChatAiTool:SalesStats) and one to use the chat (UseChatAi), listed in
 * Shield's custom permissions so they are granted per role in the roles editor.
 */
final class ChatAiPermissions
{
    public const SEEN_TOOLS_KEY = 'chatai.permissions.seen-tools';

    /**
     * @param  array<int, string>  $names
     */
    public static function remember(array $names): void
    {
        $known = (array) Cache::get(self::SEEN_TOOLS_KEY, []);

        if (array_diff($names, $known) !== []) {
            Cache::forever(self::SEEN_TOOLS_KEY, array_values(array_unique([...$known, ...$names])));
        }
    }

    public static function enabled(): bool
    {
        ChatAiSettings::apply();

        return (bool) config('chatai.permissions.enabled', false);
    }

    public static function chatRequired(): bool
    {
        return self::enabled() && (bool) config('chatai.permissions.require_chat', false);
    }

    /**
     * Same shape as Shield's own permissions ("Action:Subject", its separator and case).
     */
    public static function forTool(string $tool): string
    {
        return config('chatai.permissions.tool_prefix', 'UseChatAiTool').self::separator().self::subject($tool);
    }

    public static function forChat(): string
    {
        return (string) config('chatai.permissions.chat', 'UseChatAi');
    }

    public static function allows(?Authenticatable $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        return self::isSuperAdmin($user) || (method_exists($user, 'can') && $user->can($permission));
    }

    /**
     * Shield's super admin passes everything, as in the rest of the panel.
     */
    public static function isSuperAdmin(Authenticatable $user): bool
    {
        return (bool) config('filament-shield.super_admin.enabled', false)
            && method_exists($user, 'hasRole')
            && $user->hasRole((string) config('filament-shield.super_admin.name', 'super_admin'));
    }

    /**
     * Adds the chat and tool permissions to Shield's custom permissions (so the
     * roles editor shows them) and creates the missing ones.
     */
    public static function register(ChatAiPlugin $plugin): void
    {
        if (! self::enabled()) {
            return;
        }

        $names = array_values(array_unique([self::forChat(), ...array_map(self::forTool(...), self::toolNames($plugin))]));

        if (is_array(config('filament-shield.custom_permissions'))) {
            config(['filament-shield.custom_permissions' => array_values(array_unique([...config('filament-shield.custom_permissions'), ...$names]))]);
        }

        self::createMissing($names);
    }

    /**
     * @return array<int, string>
     */
    protected static function toolNames(ChatAiPlugin $plugin): array
    {
        // Names seen whenever tools were listed for someone (providers may filter per user).
        $names = (array) Cache::get(self::SEEN_TOOLS_KEY, []);

        try {
            // At panel boot nobody may be signed in yet: list the code tools for a placeholder user.
            $user = auth()->user() ?? new GenericUser(['id' => 0]);
            $panel = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $names = [...$names, ...array_map(fn ($tool) => $tool->name, $plugin->resolveTools()->catalog($user, new ChatAiContext($panel)))];
        } catch (Throwable) {
            // A provider that needs a real user: its tools come from the cache above.
        }

        try {
            if (Schema::hasTable('chatai_custom_tools')) {
                $names = [...$names, ...CustomTool::query()->pluck('name')->all()];
            }
        } catch (Throwable) {
            // No tables yet.
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<int, string>  $names
     */
    protected static function createMissing(array $names): void
    {
        $model = config('permission.models.permission');

        try {
            if (! $model || ! class_exists($model) || ! Schema::hasTable((string) config('permission.table_names.permissions', 'permissions'))) {
                return;
            }

            $guard = (string) config('auth.defaults.guard', 'web');
            $existing = $model::query()->where('guard_name', $guard)->whereIn('name', $names)->pluck('name')->all();

            foreach (array_diff($names, $existing) as $name) {
                $model::query()->create(['name' => $name, 'guard_name' => $guard]);
            }

            if ($existing !== $names && class_exists(PermissionRegistrar::class)) {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        } catch (Throwable) {
            // No permission tables yet: nothing to create.
        }
    }

    protected static function separator(): string
    {
        return (string) config('filament-shield.permissions.separator', ':');
    }

    protected static function subject(string $tool): string
    {
        return match (config('filament-shield.permissions.case', 'pascal')) {
            'camel' => Str::camel($tool),
            'snake' => Str::snake(Str::camel($tool)),
            'kebab' => Str::kebab(Str::camel($tool)),
            'upper_snake' => Str::upper(Str::snake(Str::camel($tool))),
            'lower' => Str::lower(Str::studly($tool)),
            default => Str::studly($tool),
        };
    }
}
