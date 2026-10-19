<?php

namespace Filament\ChatAi\Support;

use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Settings\ChatAiSettings;
use Illuminate\Support\Str;

/**
 * Estimated cost from the prices set on the settings page (per million
 * tokens / characters, per hour of audio). Null when no price is set.
 */
final class Pricing
{
    public static function currency(): string
    {
        ChatAiSettings::apply();

        return (string) (config('chatai.pricing.currency') ?: 'USD');
    }

    /**
     * Cache reads/writes default to 10% / 125% of the input price (Anthropic's
     * rates) unless the model row sets cache_read / cache_write.
     */
    public static function chat(?string $model, int $tokensIn, int $tokensOut, int $cacheRead = 0, int $cacheWrite = 0): ?float
    {
        $price = self::modelPrice($model);

        if ($price === null) {
            return null;
        }

        return round((
            $tokensIn * $price['input']
            + $tokensOut * $price['output']
            + $cacheRead * ($price['cache_read'] ?? $price['input'] * 0.1)
            + $cacheWrite * ($price['cache_write'] ?? $price['input'] * 1.25)
        ) / 1_000_000, 6);
    }

    public static function speech(int $characters): ?float
    {
        $price = self::number('pricing.speech_per_million_characters');

        return $price === null ? null : round($characters * $price / 1_000_000, 6);
    }

    public static function transcription(float $seconds): ?float
    {
        $price = self::number('pricing.transcription_per_hour');

        return $price === null ? null : round($seconds * $price / 3600, 6);
    }

    /**
     * Exact model name first, then the longest matching prefix ("openai/" covers "openai/gpt-4.1-mini").
     *
     * @return array{input: float, output: float, cache_read: ?float, cache_write: ?float}|null
     */
    protected static function modelPrice(?string $model): ?array
    {
        if ($model === null || $model === '') {
            return null;
        }

        ChatAiSettings::apply();

        // Prices set on each model in the models list, then the older price rows.
        $rows = AiModel::listed()
            ->map(fn (AiModel $m): array => ['model' => $m->model, 'input' => $m->input_price, 'output' => $m->output_price, 'cache_read' => $m->cache_read_price, 'cache_write' => $m->cache_write_price])
            ->concat((array) config('chatai.pricing.models', []));

        $match = $rows
            ->filter(fn ($row): bool => is_array($row) && filled($row['model'] ?? null) && is_numeric($row['input'] ?? null) && is_numeric($row['output'] ?? null))
            ->filter(fn (array $row): bool => $row['model'] === $model || Str::startsWith($model, $row['model']))
            ->sortByDesc(fn (array $row): int => ($row['model'] === $model ? 1000 : 0) + strlen($row['model']))
            ->first();

        return $match ? [
            'input' => (float) $match['input'],
            'output' => (float) $match['output'],
            'cache_read' => is_numeric($match['cache_read'] ?? null) ? (float) $match['cache_read'] : null,
            'cache_write' => is_numeric($match['cache_write'] ?? null) ? (float) $match['cache_write'] : null,
        ] : null;
    }

    protected static function number(string $key): ?float
    {
        ChatAiSettings::apply();

        $value = config('chatai.'.$key);

        return is_numeric($value) ? (float) $value : null;
    }
}
