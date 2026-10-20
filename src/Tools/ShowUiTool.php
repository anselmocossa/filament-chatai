<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Ui\Catalog;

/**
 * The internal tool through which the model renders generative UI. The
 * orchestrator intercepts calls to it; the handler only acknowledges.
 */
final class ShowUiTool
{
    public const NAME = 'show_ui';

    public static function make(): ChatAiTool
    {
        return new ChatAiTool(
            name: self::NAME,
            description: 'Show numbers, lists and trends visually instead of writing them as text: '
                .'kpi for 1-4 headline figures, table for lists, cards (in "items") for records with pictures, map for anything by country or place, chart for trends over time or shares, '
                .'suggestions for 2-4 short follow-up questions. Use only data you got from other tools.',
            inputSchema: Catalog::showToolSchema(),
            handler: fn (array $arguments): ToolResult => ToolResult::text('Shown to the user.'),
            readOnly: true,
            title: 'Show',
        );
    }
}
