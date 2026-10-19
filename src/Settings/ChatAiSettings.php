<?php

namespace Filament\ChatAi\Settings;

use Filament\ChatAi\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Settings saved from the settings page, laid over config/chatai.php.
 * Keys are paths under "chatai." so the rest of the plugin keeps reading config().
 */
final class ChatAiSettings
{
    public const KEYS = [
        'provider', 'fallback_provider',
        'base_url', 'api_key', 'model', 'fallback_models',
        'anthropic.api_key', 'anthropic.model', 'anthropic.fallback_models', 'anthropic.effort',
        'speech.enabled', 'speech.provider', 'speech.language', 'speech.user_choice', 'speech.voices',
        'speech.azure.key', 'speech.azure.region', 'speech.azure.voice',
        'speech.google.api_key', 'speech.google.voice',
        'pricing.currency', 'pricing.models', 'pricing.speech_per_million_characters', 'pricing.transcription_per_hour',
        'instructions', 'http_tools.allowed_hosts',
        'assistant.name', 'assistant.icon', 'assistant.avatar',
        'screen.read', 'screen.fill', 'screen.navigate', 'actions.enabled', 'permissions.enabled', 'permissions.require_chat',
        'files.disk', 'files.max_kb', 'files.max_per_message',
        'rate_limit.per_minute', 'rate_limit.per_day', 'max_iterations', 'timeout', 'max_tool_result_bytes',
    ];

    /** Never sent back to the browser; a blank field keeps the stored value. */
    public const SECRETS = ['api_key', 'anthropic.api_key', 'speech.azure.key', 'speech.google.api_key'];

    private static bool $applied = false;

    /**
     * Lays saved values over config once per request. Safe before the
     * migration has run: the env config is then used as is.
     */
    public static function apply(): void
    {
        if (self::$applied) {
            return;
        }

        self::$applied = true;

        try {
            if (! Schema::hasTable('chatai_settings')) {
                return;
            }

            foreach (Setting::query()->whereIn('key', self::KEYS)->get() as $setting) {
                config(['chatai.'.$setting->key => $setting->value['v'] ?? null]);
            }
        } catch (Throwable) {
            // No database or unreadable value (APP_KEY changed): keep the env config.
        }
    }

    /**
     * @param  array<string, mixed>  $values  dotted keys, e.g. "anthropic.model"
     */
    public static function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                continue;
            }

            $secret = in_array($key, self::SECRETS, true);
            $blank = $value === null || $value === '';

            if ($blank && $secret) {
                continue;
            }

            // Same as the .env/config file value: no override, so later .env changes still apply.
            if (! $secret && ! $blank && self::sameAsDefault($key, $value)) {
                $blank = true;
            }

            $blank
                ? Setting::query()->where('key', $key)->delete() // back to the .env value
                : Setting::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        }

        self::flush();
    }

    /**
     * The value config/chatai.php gives without saved settings (read fresh,
     * so env() is evaluated again).
     */
    public static function default(string $key): mixed
    {
        static $file = null;

        $file ??= (function (): array {
            $path = file_exists(config_path('chatai.php')) ? config_path('chatai.php') : __DIR__.'/../../config/chatai.php';

            return (array) require $path;
        })();

        return data_get($file, $key);
    }

    protected static function sameAsDefault(string $key, mixed $value): bool
    {
        $default = self::default($key);

        return match (true) {
            is_array($value) || is_array($default) => array_values((array) $value) === array_values((array) $default),
            is_bool($value) || is_bool($default) => $value === $default,
            default => (string) $value === (string) $default,
        };
    }

    public static function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();
        self::flush();
    }

    /**
     * Current values for the form, nested like the config; secrets left empty.
     *
     * @return array<string, mixed>
     */
    public static function formState(): array
    {
        self::apply();

        $state = [];

        foreach (self::KEYS as $key) {
            data_set($state, $key, in_array($key, self::SECRETS, true) ? null : config('chatai.'.$key));
        }

        return $state;
    }

    public static function hasSecret(string $key): bool
    {
        self::apply();

        return filled(config('chatai.'.$key));
    }

    public static function flush(): void
    {
        self::$applied = false;
    }
}
