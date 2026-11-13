<?php

namespace Filament\ChatAi\Support;

use Filament\ChatAi\Settings\ChatAiSettings;

/**
 * Where the user was when they asked: lets "how much stock does this have?"
 * resolve "this" to the record open on screen. Indicative only — tools must
 * still check access to whatever record they load.
 */
final readonly class ChatAiContext
{
    /** Rows of a list copied into the prompt. The rest stays available to read_screen_list. */
    public const PREVIEW_ROWS = 15;

    /** Rows kept from the page, per list, for the model to read when it asks. */
    public const STORED_ROWS = 400;

    public function __construct(
        public string $panelId,
        public ?string $url = null,
        public ?string $resource = null,
        public ?string $recordId = null,
        public ?string $tenantId = null,
        public ?string $pageTitle = null,
        /** What is on screen (heading, form fields with their values, a preview of table rows), when reading the screen is on. */
        public array $screen = [],
        /**
         * Full lists captured from the page (tables, then record blocks). Not part of the prompt.
         *
         * @var list<array{headers: list<string>, rows: list<mixed>, total: int, capped: bool}>
         */
        public array $lists = [],
    ) {}

    /**
     * Form fields on screen the assistant may fill in, by state path.
     *
     * @return array<string, array<string, mixed>>
     */
    public function screenFields(): array
    {
        return collect($this->screen['fields'] ?? [])->keyBy('path')->all();
    }

    /**
     * Fields the assistant may write into (not the read-only ones, e.g. rich text).
     *
     * @return array<string, array<string, mixed>>
     */
    public function fillableFields(): array
    {
        return array_filter($this->screenFields(), fn (array $f): bool => ! str_contains((string) ($f['type'] ?? ''), 'read-only'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $panelId, array $data, ?string $tenantId = null): self
    {
        $clean = fn (string $key, int $max = 255): ?string => is_scalar($data[$key] ?? null) && (string) $data[$key] !== ''
            ? mb_substr((string) $data[$key], 0, $max)
            : null;

        [$screen, $lists] = self::readsScreen() ? self::screen($data['screen'] ?? null) : [[], []];

        return new self(
            panelId: $panelId,
            url: $clean('url', 500),
            resource: $clean('resource'),
            recordId: $clean('record_id', 64),
            tenantId: $tenantId,
            pageTitle: $clean('page_title'),
            screen: $screen,
            lists: $lists,
        );
    }

    protected static function readsScreen(): bool
    {
        ChatAiSettings::apply();

        return (bool) config('chatai.screen.read', true);
    }

    /**
     * Keeps the prompt snapshot small. The full lists (up to STORED_ROWS) come back beside it.
     *
     * @return array{0: array<string, mixed>, 1: list<array{headers: list<string>, rows: list<mixed>, total: int, capped: bool}>}
     */
    protected static function screen(mixed $screen): array
    {
        if (! is_array($screen)) {
            return [[], []];
        }

        $str = fn ($v, int $max = 300): string => mb_substr(is_scalar($v) ? (string) $v : '', 0, $max);

        $fields = [];

        foreach (array_slice((array) ($screen['fields'] ?? []), 0, 60) as $field) {
            if (is_array($field) && preg_match('/^[A-Za-z0-9_.\-]{1,120}$/', (string) ($field['path'] ?? ''))) {
                $fields[] = array_filter([
                    'path' => (string) $field['path'],
                    'label' => $str($field['label'] ?? '', 80),
                    'type' => $str($field['type'] ?? 'text', 40),
                    'value' => $str($field['value'] ?? ''),
                    'display' => $str($field['display'] ?? ''),
                    'section' => $str($field['section'] ?? '', 80),
                    'options' => array_values(array_map(fn ($o) => $str($o, 120), array_slice((array) ($field['options'] ?? []), 0, 40))) ?: null,
                ], fn ($v) => $v !== null && $v !== '');
            }
        }

        $tables = [];
        $lists = [];

        foreach (array_slice((array) ($screen['tables'] ?? []), 0, 2) as $table) {
            if (is_array($table)) {
                $built = self::tableList($table, $str);
                $tables[] = $built['preview'];
                $lists[] = $built['list'];
            }
        }

        // A view page has no form: what the user reads there are infolist entries.
        $entries = [];

        foreach (array_slice((array) ($screen['entries'] ?? []), 0, 40) as $entry) {
            if (is_array($entry) && filled($entry['label'] ?? null) && filled($entry['value'] ?? null)) {
                $entries[] = array_filter([
                    'label' => $str($entry['label'], 80),
                    'value' => $str($entry['value'], 600),
                    'section' => $str($entry['section'] ?? '', 80),
                ], fn ($v) => $v !== '');
            }
        }

        // A list with a content layout draws blocks instead of rows, so there is no table.
        $rawRecords = (array) ($screen['records'] ?? []);
        $recordsCapped = ($screen['records_capped'] ?? false) === true || count($rawRecords) > self::STORED_ROWS;
        $records = array_values(array_filter(array_map(
            fn ($record) => $str($record, 400),
            array_slice($rawRecords, 0, self::STORED_ROWS),
        )));

        if ($records !== []) {
            $lists[] = [
                'headers' => [],
                'rows' => array_map(fn (string $record): array => [$record], $records),
                'total' => count($records),
                'capped' => $recordsCapped,
            ];
        }

        $view = [
            'heading' => $str($screen['heading'] ?? '', 160),
            'breadcrumbs' => $str($screen['breadcrumbs'] ?? '', 200),
            'fields' => $fields,
            'tables' => $tables,
            'entries' => $entries,
            'records' => array_slice($records, 0, self::PREVIEW_ROWS),
        ];

        if (count($records) > self::PREVIEW_ROWS) {
            $view['records_total'] = count($records);
            $view['records_more'] = true;
        }

        if ($recordsCapped && $records !== []) {
            $view['records_capped'] = true;
        }

        return [array_filter($view), $lists];
    }

    /**
     * @param  callable(mixed, int=): string  $str
     * @return array{preview: array<string, mixed>, list: array{headers: list<string>, rows: list<list<string>>, total: int, capped: bool}}
     */
    protected static function tableList(array $table, callable $str): array
    {
        $headers = array_map(fn ($h) => $str($h, 60), array_slice((array) ($table['headers'] ?? []), 0, 12));
        $raw = (array) ($table['rows'] ?? []);
        $capped = ($table['capped'] ?? false) === true || count($raw) > self::STORED_ROWS;
        $rows = array_map(
            fn ($r) => array_map(fn ($c) => $str($c, 120), array_slice((array) $r, 0, 12)),
            array_slice($raw, 0, self::STORED_ROWS),
        );

        $preview = [
            'headers' => $headers,
            'rows' => array_slice($rows, 0, self::PREVIEW_ROWS),
            'total' => count($rows),
        ];

        if (count($rows) > self::PREVIEW_ROWS) {
            $preview['more'] = true;
        }

        if ($capped) {
            $preview['capped'] = true;
        }

        return [
            'preview' => $preview,
            'list' => [
                'headers' => $headers,
                'rows' => $rows,
                'total' => count($rows),
                'capped' => $capped,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'panel' => $this->panelId,
            'url' => $this->url,
            'resource' => $this->resource,
            'record_id' => $this->recordId,
            'tenant' => $this->tenantId,
            'page_title' => $this->pageTitle,
        ], fn (?string $value): bool => $value !== null);
    }
}
