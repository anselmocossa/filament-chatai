<?php

namespace Filament\ChatAi\Launchpad;

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Pages\ChatAiPage;
use Filament\ChatAi\Pages\ChatAiUsagePage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\Facades\Filament;
use Filament\Launchpad\LaunchpadPlugin;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hands the launchpad (anselmocossa/filament-launchpad) the assistant's tiles:
 * live KPI sources for the card form, and ready cards for the builder's
 * library — the assistant, pending approvals, cost, and one "ask" tile per
 * suggested question.
 */
final class LaunchpadCards
{
    public static function register(): void
    {
        if (! class_exists(LaunchpadPlugin::class) || ! method_exists(LaunchpadPlugin::class, 'provide')) {
            return;
        }

        LaunchpadPlugin::provide(
            kpis: [QuestionsKpi::class, PendingKpi::class, CostKpi::class],
            cards: [fn (): array => self::presets()],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presets(): array
    {
        ChatAiSettings::apply(); // the suggested questions set on the settings page

        try {
            if (! Filament::getCurrentPanel()?->hasPlugin('chatai') || ! ($plugin = ChatAiPlugin::get())->isAccessible() || ! $plugin->hasPage()) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $t = fn (string $key, array $replace = []): string => __('chatai::chatai.launchpad.'.$key, $replace);
        $card = fn (string $key, array $fields): array => [
            'key' => 'chatai_'.$key, 'subtitle' => null, 'icon' => null, 'type' => 'kpi', 'kpi_value' => null, 'unit' => null,
            'trend' => null, 'trend_color' => null, 'badge' => null, 'target_type' => 'page', 'target_value' => ChatAiPage::class,
            'kpi_source' => null, 'widget_key' => null, ...$fields,
        ];

        $presets = [
            $card('assistant', ['title' => $plugin->getLabel(), 'subtitle' => $t('assistant_subtitle'), 'icon' => $plugin->getIcon(), 'kpi_source' => QuestionsKpi::key()]),
            $card('pending', ['title' => $t('pending'), 'subtitle' => $plugin->getLabel(), 'icon' => 'heroicon-o-clipboard-document-check', 'kpi_source' => PendingKpi::key()]),
        ];

        if ($plugin->canManage()) {
            $presets[] = $card('cost', ['title' => $t('cost'), 'subtitle' => $t('cost_subtitle'), 'icon' => 'heroicon-o-banknotes', 'kpi_source' => CostKpi::key(), 'target_value' => ChatAiUsagePage::class]);
        }

        foreach (array_slice($plugin->resolveStarters(), 0, 12) as $question) {
            $presets[] = $card('ask_'.substr(md5($question), 0, 10), [
                'title' => Str::limit($question, 60),
                'subtitle' => $t('ask_subtitle', ['assistant' => $plugin->getLabel()]),
                'icon' => 'heroicon-o-chat-bubble-left-ellipsis',
                'type' => 'shortcut',
                'target_type' => 'url',
                'target_value' => ChatAiPage::getUrl(['ask' => $question]),
            ]);
        }

        return $presets;
    }
}
