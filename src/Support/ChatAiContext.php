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
    public function __construct(
        public string $panelId,
        public ?string $url = null,
        public ?string $resource = null,
        public ?string $recordId = null,
        public ?string $tenantId = null,
        public ?string $pageTitle = null,
        /** What is on screen (heading, form fields with their values, visible table rows), when reading the screen is on. */
        public array $screen = [],
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

        return new self(
            panelId: $panelId,
            url: $clean('url', 500),
            resource: $clean('resource'),
            recordId: $clean('record_id', 64),
            tenantId: $tenantId,
            pageTitle: $clean('page_title'),
            screen: self::readsScreen() ? self::screen($data['screen'] ?? null) : [],
        );
    }

    protected static function readsScreen(): bool
    {
        ChatAiSettings::apply();

        return (bool) config('chatai.screen.read', true);
    }

    /**
     * Keeps the screen snapshot small and plain: strings only, capped.
     *
     * @return array<string, mixed>
     */
    protected static function screen(mixed $screen): array
    {
        if (! is_array($screen)) {
            return [];
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

        foreach (array_slice((array) ($screen['tables'] ?? []), 0, 2) as $table) {
            if (is_array($table)) {
                $tables[] = [
                    'headers' => array_map(fn ($h) => $str($h, 60), array_slice((array) ($table['headers'] ?? []), 0, 12)),
                    'rows' => array_map(fn ($r) => array_map(fn ($c) => $str($c, 120), array_slice((array) $r, 0, 12)), array_slice((array) ($table['rows'] ?? []), 0, 15)),
                ];
            }
        }

        return array_filter([
            'heading' => $str($screen['heading'] ?? '', 160),
            'breadcrumbs' => $str($screen['breadcrumbs'] ?? '', 200),
            'fields' => $fields,
            'tables' => $tables,
        ]);
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
