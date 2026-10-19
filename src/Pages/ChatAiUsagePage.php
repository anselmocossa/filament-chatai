<?php

namespace Filament\ChatAi\Pages;

use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\ChatAi\Http\Controllers\ConversationController;
use Filament\ChatAi\Pages\Concerns\ChecksChatAiManager;
use Filament\ChatAi\Support\UsageReport;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * What the assistant costs and how much it is used, per day, user and model.
 */
class ChatAiUsagePage extends Page
{
    use ChecksChatAiManager;

    public const PERIODS = ['today', 'month', 'last_30_days', 'year'];

    protected static ?string $slug = 'chatai/usage';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 1002;

    protected string $view = 'filament-chatai::pages.usage';

    public string $period = 'month';

    protected $queryString = ['period'];

    public static function getNavigationLabel(): string
    {
        return __('chatai::chatai.usage.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('chatai::chatai.usage.title');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $period = in_array($this->period, self::PERIODS, true) ? $this->period : 'month';
        $now = CarbonImmutable::now();

        $from = match ($period) {
            'today' => $now->startOfDay(),
            'last_30_days' => $now->subDays(29)->startOfDay(),
            'year' => $now->startOfYear(),
            default => $now->startOfMonth(),
        };

        return [
            'periods' => collect(self::PERIODS)->mapWithKeys(fn (string $p): array => [$p => __('chatai::chatai.usage.periods.'.$p)])->all(),
            'report' => UsageReport::for($from, $now->endOfDay(), Filament::getCurrentPanel()?->getId(), ConversationController::tenantId()),
        ];
    }
}
