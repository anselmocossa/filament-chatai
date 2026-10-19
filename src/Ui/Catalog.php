<?php

namespace Filament\ChatAi\Ui;

/**
 * The closed set of components the model may render. This is the protocol:
 * the web renderer and (later) the mobile app draw exactly these, nothing else.
 */
final class Catalog
{
    public const TYPES = ['kpi', 'table', 'chart', 'suggestions'];

    /** Only built by server tools (never by show_ui): a document to download. */
    public const FILE = 'file';

    /** Only built by the orchestrator: a change waiting for the user's OK. */
    public const CONFIRM = 'confirm';

    /** Several changes of the same tool, approved at once from one paginated list. */
    public const CONFIRM_BATCH = 'confirm_batch';

    public const MAX_BATCH = 500;

    public const MAX_KPIS = 4;

    public const MAX_ROWS = 50;

    public const MAX_SUGGESTIONS = 4;

    public const MAX_POINTS = 100;

    public const COLUMN_FORMATS = ['text', 'number', 'money', 'date', 'badge'];

    public const CHART_TYPES = ['line', 'bar', 'pie', 'doughnut'];

    /**
     * Input schema of the internal show_ui tool. Kept flat (one object with a
     * `type` discriminator) because many models handle oneOf poorly.
     *
     * @return array<string, mixed>
     */
    public static function showToolSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'components' => [
                    'type' => 'array',
                    'description' => 'Visual blocks to show the user, in order.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => self::TYPES],
                            'title' => ['type' => 'string'],
                            'items' => [
                                'type' => 'array',
                                'description' => 'kpi: [{label, value, unit?, change? (percent), trend? (up|down|flat), description?}] (1-4). suggestions: [{label, prompt}] (max 4) — follow-up questions the user can click.',
                                'items' => ['type' => 'object'],
                            ],
                            'columns' => [
                                'type' => 'array',
                                'description' => 'table: [{key, label, format? (text|number|money|date|badge)}]',
                                'items' => ['type' => 'object'],
                            ],
                            'rows' => [
                                'type' => 'array',
                                'description' => 'table: objects keyed by column key (max 50).',
                                'items' => ['type' => 'object'],
                            ],
                            'chart_type' => ['type' => 'string', 'enum' => self::CHART_TYPES],
                            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'chart: x-axis labels.'],
                            'series' => [
                                'type' => 'array',
                                'description' => 'chart: [{name, data: number[]}] aligned with labels.',
                                'items' => ['type' => 'object'],
                            ],
                            'link' => ['type' => 'string', 'description' => 'Optional URL to the full list in the app.'],
                        ],
                        'required' => ['type'],
                    ],
                ],
            ],
            'required' => ['components'],
        ];
    }
}
