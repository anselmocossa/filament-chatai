<?php

namespace Filament\ChatAi\Ui;

use Filament\ChatAi\Support\Countries;

/**
 * Normalizes what the model asked to render. A bad component never reaches
 * the browser: it is repaired, downgraded to a table, or dropped (null).
 */
class ComponentValidator
{
    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param  bool  $fromServer  tool results may carry a file card; show_ui (the model) may not
     */
    public function validate(mixed $component, bool $fromServer = false): ?array
    {
        if (is_array($component) && $fromServer && ($component['type'] ?? null) === Catalog::FILE) {
            return $this->file($component);
        }

        if (is_array($component) && $fromServer && ($component['type'] ?? null) === Catalog::CONFIRM) {
            return $this->confirm($component);
        }

        if (is_array($component) && $fromServer && ($component['type'] ?? null) === Catalog::MEMORY) {
            return $this->scalar($component['text'] ?? null) ? ['type' => Catalog::MEMORY, 'text' => $this->str((string) $component['text'], 500)] : null;
        }

        if (is_array($component) && $fromServer && ($component['type'] ?? null) === Catalog::NAVIGATE) {
            $url = (string) ($component['url'] ?? '');

            // Only places of this application.
            return str_starts_with($url, url('/')) || (str_starts_with($url, '/') && ! str_starts_with($url, '//'))
                ? ['type' => Catalog::NAVIGATE, 'url' => $url, 'label' => $this->str((string) ($component['label'] ?? ''), 120)]
                : null;
        }

        if (is_array($component) && $fromServer && ($component['type'] ?? null) === Catalog::FILLED) {
            $fields = array_values(array_filter(array_map(fn ($f) => is_array($f) && $this->scalar($f['path'] ?? null) ? [
                'path' => $this->str((string) $f['path'], 120),
                'label' => $this->str((string) ($f['label'] ?? $f['path']), 80),
                'value' => match (true) {
                    filled($f['shown'] ?? null) => $this->str((string) $f['shown'], 300),
                    is_bool($f['value'] ?? null) => $f['value'] ? '✓' : '—',
                    is_array($f['value'] ?? null) => $this->str(implode(', ', array_filter($f['value'], 'is_scalar')), 300),
                    default => $this->str((string) ($f['value'] ?? ''), 300),
                },
            ] : null, array_slice((array) ($component['fields'] ?? []), 0, 60))));

            return $fields === [] ? null : ['type' => Catalog::FILLED, 'fields' => $fields];
        }

        if (is_array($component) && $fromServer && ($component['type'] ?? null) === Catalog::CONFIRM_BATCH) {
            return $this->confirmBatch($component);
        }

        if (! is_array($component) || ! in_array($component['type'] ?? null, Catalog::TYPES, true)) {
            return null;
        }

        return match ($component['type']) {
            'kpi' => $this->kpi($component),
            'table' => $this->table($component),
            'chart' => $this->chart($component),
            'cards' => $this->cards($component),
            'map' => $this->map($component),
            'suggestions' => $this->suggestions($component),
        };
    }

    /**
     * @param  array<int, mixed>  $components
     * @return array<int, array<string, mixed>>
     */
    public function validateMany(array $components): array
    {
        return array_values(array_filter(array_map($this->validate(...), $components)));
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>|null
     */
    protected function kpi(array $c): ?array
    {
        $items = [];

        foreach (array_slice($this->list($c['items'] ?? []), 0, Catalog::MAX_KPIS) as $item) {
            if (! is_array($item) || ! $this->scalar($item['label'] ?? null) || ! $this->scalar($item['value'] ?? null)) {
                continue;
            }

            $change = is_numeric($item['change'] ?? null) ? round((float) $item['change'], 2) : null;
            $trend = in_array($item['trend'] ?? null, ['up', 'down', 'flat'], true)
                ? $item['trend']
                : match (true) {
                    $change > 0 => 'up',
                    $change < 0 => 'down',
                    default => 'flat',
                };

            $items[] = array_filter([
                'label' => $this->str($item['label'], 60),
                'value' => $this->str($item['value'], 40),
                'unit' => $this->scalar($item['unit'] ?? null) ? $this->str($item['unit'], 12) : null,
                'change' => $change,
                'trend' => $trend,
                'description' => $this->scalar($item['description'] ?? null) ? $this->str($item['description'], 120) : null,
            ], fn ($v) => $v !== null);
        }

        return $items === [] ? null : array_filter([
            'type' => 'kpi',
            'title' => $this->title($c),
            'items' => $items,
        ]);
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>|null
     */
    protected function table(array $c): ?array
    {
        $rows = array_values(array_filter($this->list($c['rows'] ?? []), 'is_array'));

        if ($rows === []) {
            return null;
        }

        $columns = [];

        foreach ($this->list($c['columns'] ?? []) as $column) {
            if (is_array($column) && $this->scalar($column['key'] ?? null)) {
                $columns[] = [
                    'key' => (string) $column['key'],
                    'label' => $this->str($column['label'] ?? $column['key'], 40),
                    'format' => in_array($column['format'] ?? null, Catalog::COLUMN_FORMATS, true) ? $column['format'] : 'text',
                ];
            }
        }

        if ($columns === []) {
            $columns = array_map(
                fn (string $key): array => ['key' => $key, 'label' => str($key)->headline()->toString(), 'format' => 'text'],
                array_keys($rows[0]),
            );
        }

        $keys = array_column($columns, 'key');
        $images = array_column(array_filter($columns, fn (array $c): bool => $c['format'] === 'image'), 'key');
        $total = count($rows);

        $clean = array_map(function (array $row) use ($keys, $images): array {
            $out = [];
            foreach ($keys as $key) {
                $value = $row[$key] ?? null;
                $out[$key] = in_array($key, $images, true)
                    ? $this->imageUrl($value)
                    : ($this->scalar($value) ? (is_string($value) ? $this->str($value, 200) : $value) : null);
            }

            // A row can open its record — only places of this application.
            if ($this->internalUrl($row['_url'] ?? null)) {
                $out['_url'] = (string) $row['_url'];
            }

            return $out;
        }, array_slice($rows, 0, Catalog::MAX_ROWS));

        return array_filter([
            'type' => 'table',
            'title' => $this->title($c),
            'columns' => $columns,
            'rows' => $clean,
            'note' => $total > Catalog::MAX_ROWS ? __('chatai::chatai.table_truncated', ['shown' => Catalog::MAX_ROWS, 'total' => $total]) : null,
            'link' => $this->link($c['link'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>|null
     */
    protected function chart(array $c): ?array
    {
        $series = [];

        foreach ($this->list($c['series'] ?? []) as $s) {
            $data = is_array($s) ? array_values(array_filter($this->list($s['data'] ?? []), 'is_numeric')) : [];

            if ($data !== []) {
                $series[] = [
                    'name' => $this->str($s['name'] ?? 'Série', 40),
                    'data' => array_map('floatval', array_slice($data, 0, Catalog::MAX_POINTS)),
                ];
            }
        }

        if ($series === []) {
            return null;
        }

        $labels = array_map(fn ($l) => $this->str($l, 40), array_filter($this->list($c['labels'] ?? []), $this->scalar(...)));

        if ($labels === []) {
            // Without labels the chart is unreadable: show the numbers instead.
            $rows = [];
            foreach ($series[0]['data'] as $i => $_) {
                $row = ['#' => $i + 1];
                foreach ($series as $s) {
                    $row[$s['name']] = $s['data'][$i] ?? null;
                }
                $rows[] = $row;
            }

            return $this->table(['title' => $c['title'] ?? null, 'rows' => $rows]);
        }

        return array_filter([
            'type' => 'chart',
            'title' => $this->title($c),
            'chart_type' => in_array($c['chart_type'] ?? null, Catalog::CHART_TYPES, true) ? $c['chart_type'] : 'line',
            'labels' => array_values(array_slice($labels, 0, Catalog::MAX_POINTS)),
            'series' => $series,
            // Clicking a bar asks this, with the clicked label in place of {label}.
            'drill' => is_string($c['drill'] ?? null) && str_contains($c['drill'], '{label}') ? $this->str($c['drill'], 200) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>|null
     */
    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>|null
     */
    protected function file(array $c): ?array
    {
        $url = (string) ($c['url'] ?? '');

        if (! $this->scalar($c['name'] ?? null) || ! (str_starts_with($url, '/') || str_starts_with($url, url('/')))) {
            return null;
        }

        return [
            'type' => Catalog::FILE,
            'title' => $this->title($c),
            'name' => $this->str($c['name'], 180),
            'format' => $this->str((string) ($c['format'] ?? ''), 10),
            'size' => (int) ($c['size'] ?? 0),
            'url' => $url,
        ];
    }

    protected function confirm(array $c): ?array
    {
        if (! $this->scalar($c['action_id'] ?? null)) {
            return null;
        }

        $fields = [];

        foreach (array_slice((array) ($c['fields'] ?? []), 0, 12) as $field) {
            if (! is_array($field) || ! $this->scalar($field['label'] ?? null)) {
                continue;
            }

            $type = in_array($field['type'] ?? null, ['text', 'email', 'number', 'date', 'select', 'checkbox', 'textarea', 'image'], true) ? $field['type'] : 'text';

            $fields[] = array_filter([
                'key' => isset($field['key']) ? $this->str((string) $field['key'], 60) : null,
                'label' => $this->str((string) $field['label'], 60),
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'help' => isset($field['help']) ? $this->str((string) $field['help'], 140) : null,
                'options' => $type === 'select' ? array_values(array_map(fn ($o): array => ['value' => $this->str((string) ($o['value'] ?? ''), 80), 'label' => $this->str((string) ($o['label'] ?? $o['value'] ?? ''), 80)], array_slice(array_filter((array) ($field['options'] ?? []), 'is_array'), 0, 30))) : null,
                'value' => $type === 'checkbox' ? (bool) ($field['value'] ?? false) : $this->str((string) ($field['value'] ?? ''), 2000),
                'preview' => $type === 'image' && $this->internalUrl($field['preview'] ?? null) ? (string) $field['preview'] : null,
            ], fn ($v): bool => $v !== null);
        }

        return [
            'type' => Catalog::CONFIRM,
            'action_id' => (string) $c['action_id'],
            'title' => $this->title($c),
            'fields' => $fields,
            'status' => in_array($c['status'] ?? null, ['pending', 'done', 'cancelled', 'failed'], true) ? $c['status'] : 'pending',
        ];
    }

    protected function confirmBatch(array $c): ?array
    {
        $statuses = ['pending', 'done', 'cancelled', 'failed', 'partial', 'skipped'];
        $columns = array_values(array_map(fn (array $col): array => ['key' => $this->str((string) $col['key'], 60), 'label' => $this->str((string) ($col['label'] ?? $col['key']), 60)],
            array_slice(array_filter((array) ($c['columns'] ?? []), fn ($col) => is_array($col) && $this->scalar($col['key'] ?? null)), 0, 12)));
        $keys = array_column($columns, 'key');

        $rows = [];

        foreach (array_slice((array) ($c['rows'] ?? []), 0, Catalog::MAX_BATCH) as $row) {
            if (! is_array($row) || ! $this->scalar($row['action_id'] ?? null)) {
                continue;
            }

            $rows[] = array_filter([
                'action_id' => (string) $row['action_id'],
                'values' => collect($keys)->mapWithKeys(fn ($k) => [$k => $this->str((string) (($row['values'] ?? [])[$k] ?? ''), 300)])->all(),
                'status' => in_array($row['status'] ?? null, $statuses, true) ? $row['status'] : 'pending',
                'error' => isset($row['error']) ? $this->str((string) $row['error'], 300) : null,
            ], fn ($v) => $v !== null);
        }

        if ($rows === []) {
            return null;
        }

        return [
            'type' => Catalog::CONFIRM_BATCH,
            'title' => $this->title($c),
            'columns' => $columns,
            'rows' => $rows,
            'status' => in_array($c['status'] ?? null, $statuses, true) ? $c['status'] : 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>|null
     */
    protected function cards(array $c): ?array
    {
        $items = [];

        // Models sometimes name the list after the type.
        foreach (array_slice($this->list($c['items'] ?? $c['cards'] ?? []), 0, Catalog::MAX_CARDS) as $item) {
            if (! is_array($item) || ! $this->scalar($item['title'] ?? null)) {
                continue;
            }

            $fields = [];

            foreach (array_slice($this->list($item['fields'] ?? []), 0, 4) as $field) {
                if (is_array($field) && $this->scalar($field['label'] ?? null) && $this->scalar($field['value'] ?? null)) {
                    $fields[] = ['label' => $this->str($field['label'], 40), 'value' => $this->str($field['value'], 80)];
                }
            }

            $items[] = array_filter([
                'title' => $this->str($item['title'], 80),
                'subtitle' => $this->scalar($item['subtitle'] ?? null) ? $this->str($item['subtitle'], 120) : null,
                'image' => $this->imageUrl($item['image'] ?? null),
                'badge' => $this->scalar($item['badge'] ?? null) ? $this->str($item['badge'], 30) : null,
                'fields' => $fields ?: null,
                'url' => $this->internalUrl($item['url'] ?? null) ? (string) $item['url'] : null,
                'actions' => $this->actions($item['actions'] ?? null) ?: null,
            ], fn ($v) => $v !== null);
        }

        return $items === [] ? null : array_filter([
            'type' => 'cards',
            'title' => $this->title($c),
            'items' => $items,
            'link' => $this->link($c['link'] ?? null),
        ]);
    }

    /**
     * Buttons on a card: ask the assistant something, or open a place of the app.
     *
     * @return array<int, array<string, string>>
     */
    protected function actions(mixed $actions): array
    {
        $out = [];

        foreach (array_slice($this->list($actions), 0, 3) as $action) {
            if (! is_array($action) || ! $this->scalar($action['label'] ?? null)) {
                continue;
            }

            $button = array_filter([
                'label' => $this->str($action['label'], 30),
                'prompt' => $this->scalar($action['prompt'] ?? null) ? $this->str($action['prompt'], 300) : null,
                'url' => $this->internalUrl($action['url'] ?? null) ? (string) $action['url'] : null,
            ]);

            if (isset($button['prompt']) || isset($button['url'])) {
                $out[] = $button;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>|null
     */
    protected function map(array $c): ?array
    {
        if (! config('chatai.maps.enabled', true)) {
            return null;
        }

        $markers = [];

        foreach ($this->list($c['markers'] ?? $c['items'] ?? []) as $marker) {
            if (! is_array($marker)) {
                continue;
            }

            // A country code wins over coordinates: the server knows where countries are.
            $country = Countries::find($marker['country'] ?? null);
            $lat = $country['lat'] ?? (is_numeric($marker['lat'] ?? null) ? (float) $marker['lat'] : null);
            $lng = $country['lng'] ?? (is_numeric($marker['lng'] ?? $marker['lon'] ?? null) ? (float) ($marker['lng'] ?? $marker['lon']) : null);

            if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
                continue;
            }

            $label = $this->scalar($marker['label'] ?? null) ? $this->str($marker['label'], 80) : ($country['name'] ?? null);

            if ($label === null) {
                continue;
            }

            $markers[] = array_filter([
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'label' => $label,
                'value' => is_numeric($marker['value'] ?? null) ? round((float) $marker['value'], 2) : null,
                'url' => $this->internalUrl($marker['url'] ?? null) ? (string) $marker['url'] : null,
                'prompt' => $this->scalar($marker['prompt'] ?? null) ? $this->str($marker['prompt'], 300) : null,
            ], fn ($v) => $v !== null);

            if (count($markers) === Catalog::MAX_MARKERS) {
                break;
            }
        }

        return $markers === [] ? null : array_filter([
            'type' => 'map',
            'title' => $this->title($c),
            'unit' => $this->scalar($c['unit'] ?? null) ? $this->str($c['unit'], 30) : null,
            'markers' => $markers,
        ]);
    }

    protected function suggestions(array $c): ?array
    {
        $items = [];

        foreach ($this->list($c['items'] ?? []) as $item) {
            if (is_array($item) && $this->scalar($item['label'] ?? null) && $this->scalar($item['prompt'] ?? null)) {
                $items[] = ['label' => $this->str($item['label'], 40), 'prompt' => $this->str($item['prompt'], 300)];
            }

            if (count($items) === Catalog::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $items === [] ? null : ['type' => 'suggestions', 'items' => $items];
    }

    /**
     * @return array<int, mixed>
     */
    protected function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    protected function scalar(mixed $value): bool
    {
        return is_scalar($value) && $value !== '';
    }

    protected function str(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function title(array $c): ?string
    {
        return $this->scalar($c['title'] ?? null) ? $this->str($c['title'], 80) : null;
    }

    protected function link(mixed $link): ?string
    {
        // Only same-app paths or http(s) URLs — never javascript: or data:.
        return is_string($link) && preg_match('#^(https?://|/)#i', $link) ? mb_substr($link, 0, 500) : null;
    }

    /**
     * Pictures only from this application or from hosts the config allows
     * (an outside image would tell that host who is looking).
     */
    protected function imageUrl(mixed $url): ?string
    {
        if (! config('chatai.images.enabled', true) || ! is_string($url) || $url === '' || mb_strlen($url) > 1000) {
            return null;
        }

        if ($this->internalUrl($url) || (filled(config('app.url')) && str_starts_with($url, rtrim((string) config('app.url'), '/').'/'))) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return str_starts_with($url, 'https://') && is_string($host) && in_array(strtolower($host), array_map('strtolower', (array) config('chatai.images.hosts', [])), true)
            ? $url
            : null;
    }

    protected function internalUrl(mixed $url): bool
    {
        return is_string($url) && $url !== '' && mb_strlen($url) <= 500
            && (str_starts_with($url, url('/').'/') || (str_starts_with($url, '/') && ! str_starts_with($url, '//')));
    }
}
