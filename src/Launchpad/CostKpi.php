<?php

namespace Filament\ChatAi\Launchpad;

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Http\Controllers\ConversationController;
use Filament\ChatAi\Support\Pricing;
use Filament\ChatAi\Support\UsageReport;
use Filament\Launchpad\Launchpad\KpiResult;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Launchpad tile for the people who manage the assistant: what it cost this
 * month, with the cost per day.
 */
class CostKpi extends ChatAiKpi
{
    public static function key(): string
    {
        return 'chatai_cost';
    }

    public function label(): string
    {
        return __('chatai::chatai.launchpad.cost');
    }

    public function authorize(?Authenticatable $user): bool
    {
        return parent::authorize($user) && ChatAiPlugin::get()->canManage();
    }

    public function resolve(): KpiResult
    {
        $report = UsageReport::for(now()->startOfMonth(), now(), $this->panelId(), ConversationController::tenantId());
        $perDay = collect($report['by_day'])->pluck('cost', 'day')->all();

        return KpiResult::make(number_format($report['totals']['cost'], 2))
            ->unit(Pricing::currency())
            ->trend(__('chatai::chatai.launchpad.cost_trend', ['count' => $report['totals']['questions']]), 'gray')
            ->sparkline(self::days($perDay, now()->day), 'area');
    }
}
