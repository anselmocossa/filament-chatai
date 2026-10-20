<?php

namespace Filament\ChatAi\Launchpad;

use Filament\ChatAi\ChatAiPlugin;
use Filament\Facades\Filament;
use Filament\Launchpad\Launchpad\BaseKpiSource;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Base for the assistant's launchpad tiles: shown only on a panel that uses
 * the assistant, to a user who may use it. Values belong to one user, so they
 * are never cached across requests.
 */
abstract class ChatAiKpi extends BaseKpiSource
{
    public function authorize(?Authenticatable $user): bool
    {
        try {
            return $user !== null && (bool) Filament::getCurrentPanel()?->hasPlugin('chatai') && ChatAiPlugin::get()->isAccessible();
        } catch (Throwable) {
            return false;
        }
    }

    protected function panelId(): string
    {
        return (string) Filament::getCurrentPanel()?->getId();
    }

    /**
     * One value per day, oldest first, for the last $count days (0 for a day with none).
     *
     * @param  array<string, int|float|string>  $byDay  'Y-m-d' => value
     * @return array<int, float>
     */
    protected static function days(array $byDay, int $count): array
    {
        return collect(range($count - 1, 0))
            ->map(fn (int $ago): float => (float) ($byDay[now()->subDays($ago)->toDateString()] ?? 0))
            ->all();
    }
}
