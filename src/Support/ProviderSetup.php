<?php

namespace Filament\ChatAi\Support;

use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\AiConnection;
use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Settings\ChatAiSettings;

/**
 * First visit of the Models / Providers pages: what is set in .env and on the
 * settings page becomes rows, so nothing has to be typed again.
 */
final class ProviderSetup
{
    public static function ensure(): void
    {
        ChatAiSettings::apply();

        if (AiConnection::query()->doesntExist()) {
            self::importConnections();
        }

        // Models made before providers existed: link them to the first provider of their kind.
        foreach (AiModel::query()->whereNull('connection_id')->get() as $model) {
            $connection = AiConnection::query()->where('driver', $model->provider)->orderBy('id')->first();
            $model->update(['connection_id' => $connection?->id]);
        }
    }

    protected static function importConnections(): void
    {
        $drivers = collect([config('chatai.provider'), config('chatai.fallback_provider'), ...AiModel::query()->pluck('provider')])
            ->filter(fn ($driver): bool => in_array($driver, [LlmConfig::PROVIDER_ANTHROPIC, LlmConfig::PROVIDER_OPENAI], true))
            ->unique();

        foreach ($drivers as $driver) {
            AiConnection::query()->create([
                'name' => $driver === LlmConfig::PROVIDER_ANTHROPIC ? 'Anthropic' : self::nameFor((string) config('chatai.base_url')),
                'driver' => $driver,
                // The address is kept; keys are not copied: an empty key means "the one in .env / settings".
                'base_url' => $driver === LlmConfig::PROVIDER_OPENAI ? config('chatai.base_url') : null,
                'enabled' => true,
            ]);
        }
    }

    protected static function nameFor(string $baseUrl): string
    {
        return match (true) {
            str_contains($baseUrl, 'openrouter') => 'OpenRouter',
            str_contains($baseUrl, 'openai.com') => 'OpenAI',
            str_contains($baseUrl, '11434') || str_contains($baseUrl, 'ollama') => 'Ollama',
            default => 'OpenAI-compatible',
        };
    }
}
