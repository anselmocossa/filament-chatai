<?php

namespace Filament\ChatAi\Launchpad;

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Pages\ChatAiLogsPage;
use Filament\ChatAi\Pages\ChatAiModelsPage;
use Filament\ChatAi\Pages\ChatAiPage;
use Filament\ChatAi\Pages\ChatAiProvidersPage;
use Filament\ChatAi\Pages\ChatAiSettingsPage;
use Filament\ChatAi\Pages\ChatAiSkillsPage;
use Filament\ChatAi\Pages\ChatAiToolsPage;
use Filament\ChatAi\Pages\ChatAiUsagePage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\Facades\Filament;
use Filament\Launchpad\LaunchpadPlugin;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hands the launchpad (anselmocossa/filament-launchpad) the assistant's tiles:
 * live KPI sources for the card form, and ready cards for the builder's
 * library — the assistant, pending approvals, cost, every page of the assistant
 * the user may open, and one "ask" tile per suggested question.
 *
 * A panel with the sidebar turned off reaches a page only through a card, so the
 * admin pages have to be in the library or they have no way in at all.
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
     * The assistant's own pages, by card key. The tool editor is left out: it is
     * opened from the tools page, never on its own.
     *
     * @return array<string, class-string>
     */
    protected static function pages(): array
    {
        return [
            'tools' => ChatAiToolsPage::class,
            'skills' => ChatAiSkillsPage::class,
            'settings' => ChatAiSettingsPage::class,
            'providers' => ChatAiProvidersPage::class,
            'models' => ChatAiModelsPage::class,
            'logs' => ChatAiLogsPage::class,
        ];
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

        // One tile per page of the assistant, so a launchpad-only panel can reach
        // them. Each is offered only to whoever may open it: the admin pages hold
        // API keys and answer canAccess() with the plugin's own rules.
        foreach (self::pages() as $key => $page) {
            if (! rescue(fn (): bool => $page::canAccess(), false, false)) {
                continue;
            }

            $presets[] = $card($key, [
                'title' => rescue(fn (): string => (string) $page::getNavigationLabel(), $key, false),
                'subtitle' => $plugin->getLabel(),
                'icon' => rescue(fn (): ?string => is_string($icon = $page::getNavigationIcon()) ? $icon : null, null, false) ?? 'heroicon-o-sparkles',
                'type' => 'shortcut',
                'target_value' => $page,
            ]);
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
