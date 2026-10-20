<?php

namespace Filament\ChatAi\Launchpad;

use Filament\ChatAi\Models\Usage;
use Filament\Launchpad\Launchpad\KpiResult;

/**
 * Launchpad tile: the questions the user asked the assistant in the last 30
 * days, with a bar per day for the last two weeks.
 */
class QuestionsKpi extends ChatAiKpi
{
    public static function key(): string
    {
        return 'chatai_questions';
    }

    public function label(): string
    {
        return __('chatai::chatai.launchpad.questions');
    }

    public function resolve(): KpiResult
    {
        $user = auth()->user();
        $mine = fn () => Usage::query()
            ->where('kind', Usage::KIND_CHAT)
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey())
            ->where('panel_id', $this->panelId());

        $perDay = $mine()->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')->groupByRaw('DATE(created_at)')
            ->pluck('total', 'day');
        $today = (int) ($perDay[now()->toDateString()] ?? 0);

        return KpiResult::make($mine()->where('created_at', '>=', now()->subDays(30))->count())
            ->unit(__('chatai::chatai.launchpad.questions_unit'))
            ->trend(__('chatai::chatai.launchpad.today', ['count' => $today]), $today > 0 ? 'success' : 'gray')
            ->sparkline(self::days($perDay->all(), 14), 'bar');
    }
}
