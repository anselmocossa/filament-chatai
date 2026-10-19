<?php

namespace Filament\ChatAi\Tools\NoCode;

use Illuminate\Database\Eloquent\Builder;

/**
 * Fixed conditions set on screen, e.g. "status is not cancelled" or
 * "qty is below security_stock". Only real columns are accepted.
 */
final class Conditions
{
    public const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'like', 'null', 'not_null'];

    /**
     * @param  array<int, array<string, mixed>>  $conditions
     * @param  array<int, string>  $columns
     */
    public static function apply(Builder $query, array $conditions, array $columns, bool $searching = false): Builder
    {
        $table = $query->getModel()->getTable();

        foreach ($conditions as $condition) {
            $column = $condition['column'] ?? null;
            $operator = $condition['operator'] ?? '=';

            if (! in_array($column, $columns, true) || ! in_array($operator, self::OPERATORS, true)) {
                continue;
            }

            // e.g. "products below security stock" is the list when nothing is searched.
            if ($searching && ! empty($condition['unless_search'])) {
                continue;
            }

            $qualified = "{$table}.{$column}";
            $other = $condition['compare_column'] ?? null;

            match (true) {
                $operator === 'null' => $query->whereNull($qualified),
                $operator === 'not_null' => $query->whereNotNull($qualified),
                filled($other) && in_array($other, $columns, true) && $operator !== 'like' => $query->whereColumn($qualified, $operator, "{$table}.{$other}"),
                $operator === 'like' => $query->where($qualified, 'like', '%'.addcslashes((string) ($condition['value'] ?? ''), '%_').'%'),
                default => $query->where($qualified, $operator, $condition['value'] ?? null),
            };
        }

        return $query;
    }

    /**
     * Readable list for the tool description, so the model knows what is already filtered.
     *
     * @param  array<int, array<string, mixed>>  $conditions
     */
    public static function describe(array $conditions): string
    {
        return collect($conditions)
            ->filter(fn (array $c): bool => filled($c['column'] ?? null))
            ->map(fn (array $c): string => trim(($c['column'] ?? '').' '.str_replace(['not_null', 'null'], ['is set', 'is empty'], (string) ($c['operator'] ?? '=')).' '.($c['compare_column'] ?? $c['value'] ?? '')))
            ->implode('; ');
    }
}
