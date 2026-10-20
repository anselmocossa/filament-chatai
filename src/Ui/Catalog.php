<?php

namespace Filament\ChatAi\Ui;

/**
 * The closed set of components the model may render. This is the protocol:
 * the web renderer and (later) the mobile app draw exactly these, nothing else.
 */
final class Catalog
{
    public const TYPES = ['kpi', 'table', 'chart', 'cards', 'map', 'suggestions'];

    /** Only built by server tools (never by show_ui): a document to download. */
    public const FILE = 'file';

    /** Only built by the orchestrator: a change waiting for the user's OK. */
    public const CONFIRM = 'confirm';

    /** Several changes of the same tool, approved at once from one paginated list. */
    public const CONFIRM_BATCH = 'confirm_batch';

    /** What the assistant wrote into the form on screen (still to be saved by the user). */
    public const FILLED = 'filled';

    /** A place of the panel the browser opens when the answer ends. */
    public const NAVIGATE = 'navigate';

    /** A note that something was saved to the user's memory. */
    public const MEMORY = 'memory';

    public const MAX_BATCH = 500;

    public const MAX_KPIS = 4;

    public const MAX_ROWS = 50;

    public const MAX_SUGGESTIONS = 4;

    public const MAX_CARDS = 12;

    public const MAX_MARKERS = 200;

    public const MAX_POINTS = 100;

    public const COLUMN_FORMATS = ['text', 'number', 'money', 'date', 'badge', 'image'];

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
                                'description' => 'kpi: [{label, value, unit?, change? (percent), trend? (up|down|flat), description?}] (1-4). suggestions: [{label, prompt}] (max 4) — follow-up questions the user can click. cards: [{title, subtitle?, image?, badge?, fields?: [{label, value}], url?}] (max 12) — a gallery, best for records with a picture (products, people); copy "image" from a row\'s "_image" and "url" from its "_url". Each card may have actions: [{label, prompt}] (max 3), buttons that ask the assistant about that record (e.g. "Ver encomendas").',
                                'items' => ['type' => 'object'],
                            ],
                            'columns' => [
                                'type' => 'array',
                                'description' => 'table: [{key, label, format? (text|number|money|date|badge|image)}]. format image shows a thumbnail: use key "_image" when the rows have one.',
                                'items' => ['type' => 'object'],
                            ],
                            'rows' => [
                                'type' => 'array',
                                'description' => 'table: objects keyed by column key (max 50). Copy a row\'s "_url" from the tool result when it has one, so the row opens that record.',
                                'items' => ['type' => 'object'],
                            ],
                            'chart_type' => ['type' => 'string', 'enum' => self::CHART_TYPES],
                            'drill' => ['type' => 'string', 'description' => 'chart: optional question asked when the user clicks a bar/slice, with {label} for the clicked label, e.g. "Mostra as encomendas de {label}".'],
                            'markers' => [
                                'type' => 'array',
                                'description' => 'map: [{country? (ISO 2-letter code, placed for you), lat?, lng?, label, value?, url?, prompt?}] (max 200). Prefer country codes; give lat/lng only for real places you are sure of. value sizes the bubble; prompt is a question in the user\'s language, naming the place (not its code), asked when the user clicks "Ask" on the marker.',
                                'items' => ['type' => 'object'],
                            ],
                            'unit' => ['type' => 'string', 'description' => 'map: what value counts, e.g. "encomendas".'],
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
