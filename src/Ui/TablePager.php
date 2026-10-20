<?php

namespace Filament\ChatAi\Ui;

use Filament\ChatAi\Tools\ChatAiTool;

/**
 * Tables that page through every row of a list tool by themselves: the
 * table keeps the tool and its arguments, and each page is fetched from the
 * tool again (with the user's permissions), never copied by the model.
 */
final class TablePager
{
    /**
     * Only read-only tools that take a page.
     */
    public static function pageable(?ChatAiTool $tool): bool
    {
        return $tool !== null && $tool->readOnly && isset($tool->inputSchema['properties']['page']);
    }

    /**
     * The arguments as the model gave them to the tool: scalars, and lists or
     * objects of scalars (conditions, fields), nothing else.
     *
     * @return array<string, mixed>
     */
    public static function args(mixed $args): array
    {
        $clean = function (mixed $value, int $depth) use (&$clean): mixed {
            if (is_scalar($value)) {
                return $value;
            }

            if (! is_array($value) || $depth > 3) {
                return null;
            }

            return array_filter(array_map(fn ($v) => $clean($v, $depth + 1), $value), fn ($v): bool => $v !== null);
        };

        return array_filter(
            array_map(fn ($v) => $clean($v, 1), array_diff_key(is_array($args) ? $args : [], array_flip(['page', 'limit']))),
            fn ($value, $key): bool => is_string($key) && $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * One page of the tool's rows.
     *
     * @param  array<string, mixed>  $args
     * @return array{rows: array<int, array<string, mixed>>, page: int, pages: int, total: int}|null
     */
    public static function fetch(ChatAiTool $tool, array $args, int $page): ?array
    {
        $result = $tool->call([...$args, 'page' => max(1, $page)]);

        if ($result->isError() || ! is_array($result->data['rows'] ?? null)) {
            return null;
        }

        return [
            'rows' => array_values(array_filter($result->data['rows'], 'is_array')),
            'page' => (int) ($result->data['page'] ?? 1),
            'pages' => (int) ($result->data['pages'] ?? 1),
            'total' => (int) ($result->data['total'] ?? count($result->data['rows'])),
        ];
    }

    /**
     * A table the model asked to page: its rows become the tool's first page.
     *
     * @param  array<string, mixed>  $component
     * @param  array<string, ChatAiTool>  $available
     * @return array<string, mixed>
     */
    public static function prepare(array $component, array $available): array
    {
        $source = $component['source'] ?? null;
        unset($component['source'], $component['page'], $component['pages']);

        if (($component['type'] ?? null) !== 'table' || ! is_array($source)) {
            return $component;
        }

        $tool = $available[(string) ($source['tool'] ?? '')] ?? null;
        $args = self::args($source['args'] ?? []);

        if (! self::pageable($tool) || ($first = self::fetch($tool, $args, 1)) === null || $first['pages'] < 2) {
            return $component;
        }

        return [
            ...$component,
            'rows' => $first['rows'],
            'source' => ['tool' => $tool->name, 'args' => $args],
            'total' => $first['total'],
            'page' => 1,
            'pages' => $first['pages'],
        ];
    }
}
