<?php

namespace Filament\ChatAi\Support;

use Filament\ChatAi\Models\AiConnection;
use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Models\Usage;

/**
 * Spending limits per provider: once a provider reaches its daily or monthly
 * limit, the next provider in the models list answers instead.
 */
final class Budget
{
    /**
     * @param  'day'|'month'  $period
     */
    public static function spent(AiConnection $connection, string $period): float
    {
        $from = $period === 'day' ? now()->startOfDay() : now()->startOfMonth();

        return round((float) Usage::query()->where('connection_id', $connection->id)->where('created_at', '>=', $from)->sum('cost'), 6);
    }

    public static function exceeded(?AiConnection $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        return ($connection->monthly_limit !== null && self::spent($connection, 'month') >= (float) $connection->monthly_limit)
            || ($connection->daily_limit !== null && self::spent($connection, 'day') >= (float) $connection->daily_limit);
    }

    /**
     * Every model in the list belongs to a provider over its limit: the assistant pauses.
     */
    public static function allExceeded(): bool
    {
        $chain = AiModel::chain();

        return $chain->isNotEmpty() && $chain->every(fn (AiModel $model): bool => self::exceeded($model->providerConnection));
    }
}
