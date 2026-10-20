<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Tools\NoCode\Conditions;
use Filament\ChatAi\Tools\NoCode\ResourceInspector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/**
 * Totals of a resource for a period, set up on screen: counts and sums,
 * the change against the previous period, and a breakdown by day, month,
 * a column, a related record or a column of a one-to-one relation (e.g. the
 * country of the order's address). Optionally over a relation of the resource
 * (e.g. the items of the orders).
 */
final class MetricsTool
{
    public const FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max', 'sum_product'];

    public const PERIODS = ['today', 'week', 'month', 'year', 'all'];

    public static function make(CustomTool $tool): ?ChatAiTool
    {
        $resource = ResourceInspector::resource($tool->config['resource'] ?? null);
        $parent = ResourceInspector::model($resource);

        if (! $resource || ! $parent) {
            return null;
        }

        $config = (array) $tool->config;
        $parentColumns = ResourceInspector::columns($parent);
        $relation = ResourceInspector::relations($parent, [HasMany::class])[$config['relation'] ?? ''] ?? null;
        $target = $relation?->getRelated() ?? $parent;
        $targetColumns = ResourceInspector::columns($target);
        $dateField = in_array($config['date_field'] ?? null, $parentColumns, true) ? $config['date_field'] : null;
        $conditions = array_values(array_filter((array) ($config['conditions'] ?? []), 'is_array'));
        $compare = (bool) ($config['compare'] ?? false);
        $defaultPeriod = in_array($config['period'] ?? null, self::PERIODS, true) ? $config['period'] : 'month';
        $groupBy = (string) ($config['group_by'] ?? '');
        $groupLimit = max(1, min((int) ($config['group_limit'] ?? 20), 100));
        $belongsTo = ResourceInspector::relations($target, [BelongsTo::class]);
        $hasOne = ResourceInspector::relations($target, [HasOne::class, MorphOne::class]);

        $metrics = array_values(array_filter((array) ($config['metrics'] ?? []), fn ($m): bool => is_array($m)
            && in_array($m['function'] ?? null, self::FUNCTIONS, true)
            && (($m['function'] ?? null) === 'count' || in_array($m['column'] ?? null, $targetColumns, true))
            && (($m['function'] ?? null) !== 'sum_product' || in_array($m['column2'] ?? null, $targetColumns, true))));

        if ($metrics === []) {
            $metrics = [['function' => 'count', 'label' => 'count']];
        }

        $properties = [];

        if ($dateField) {
            $properties['period'] = ['type' => 'string', 'enum' => self::PERIODS, 'description' => "Period by {$dateField}. Default: {$defaultPeriod}."];
            $properties['date_from'] = ['type' => 'string', 'description' => 'Custom start (YYYY-MM-DD); overrides period.'];
            $properties['date_to'] = ['type' => 'string', 'description' => 'Custom end (YYYY-MM-DD).'];
        }

        $description = $tool->description.(($fixed = Conditions::describe($conditions)) !== '' ? " Already filtered: {$fixed}." : '');

        return new ChatAiTool(
            name: $tool->name,
            description: $description,
            inputSchema: ['type' => 'object', 'properties' => $properties],
            handler: function (array $args) use ($resource, $relation, $target, $targetColumns, $parentColumns, $dateField, $conditions, $compare, $defaultPeriod, $groupBy, $groupLimit, $belongsTo, $hasOne, $metrics): ToolResult {
                if (! $resource::canViewAny()) {
                    return ToolResult::error('The user does not have permission to see these records.');
                }

                [$from, $to, $period] = $dateField ? self::range($args, $defaultPeriod) : [null, null, 'all'];

                // The records to add up: the resource's own query (its scopes and tenancy), or a relation of it.
                $base = function (?Carbon $from, ?Carbon $to) use ($resource, $relation, $target, $parentColumns, $dateField, $conditions): Builder {
                    $parents = Conditions::apply($resource::getEloquentQuery(), $conditions, $parentColumns);

                    if ($dateField && $from && $to) {
                        $parents->whereBetween($parents->getModel()->getTable().'.'.$dateField, [$from, $to]);
                    }

                    return $relation
                        ? $target->newQuery()->whereIn($target->getTable().'.'.$relation->getForeignKeyName(), $parents->select($parents->getModel()->getTable().'.'.$relation->getLocalKeyName()))
                        : $parents;
                };

                $select = fn (Builder $q): Builder => $q->reorder()->selectRaw(collect($metrics)->map(fn (array $m, int $i): string => self::sql($m, $target).' as m'.$i)->implode(', '));
                $label = fn (array $m, int $i): string => (string) (($m['label'] ?? '') ?: ($m['function'] === 'count' ? 'count' : $m['function'].'_'.$m['column']));
                $read = fn ($row): array => collect($metrics)->mapWithKeys(fn (array $m, int $i) => [$label($m, $i) => round((float) ($row?->{'m'.$i} ?? 0), 2)])->all();

                $current = $read($select($base($from, $to))->toBase()->first());
                $result = array_filter(['period' => $dateField ? $period : null, 'from' => $from?->toDateString(), 'to' => $to?->toDateString()]) + ['totals' => $current];

                if ($compare && $from && $to) {
                    $length = $from->diffInSeconds($to);
                    $previous = $read($select($base($from->copy()->subSeconds($length + 1), $from->copy()->subSecond()))->toBase()->first());
                    $result['previous'] = $previous;
                    $result['change_percent'] = collect($current)->mapWithKeys(fn ($v, $k) => [$k => ($previous[$k] ?? 0) > 0 ? round(($v - $previous[$k]) / $previous[$k] * 100, 1) : null])->all();
                }

                if ($groupBy !== '') {
                    $result['breakdown'] = self::breakdown($select($base($from, $to)), $groupBy, $target, $targetColumns, $belongsTo + $hasOne, $dateField && ! $relation ? $dateField : null, $groupLimit, $read);
                }

                return ToolResult::data($result);
            },
            readOnly: true,
            title: $tool->title,
        );
    }

    /**
     * @param  array<string, mixed>  $metric
     */
    protected static function sql(array $metric, Model $target): string
    {
        $wrap = fn (string $key): string => $target->getConnection()->getQueryGrammar()->wrap($target->getTable().'.'.$metric[$key]);

        return match ($metric['function']) {
            'count' => 'count(*)',
            'sum_product' => "sum({$wrap('column')} * {$wrap('column2')})",
            default => strtoupper($metric['function'])."({$wrap('column')})",
        };
    }

    /**
     * @param  array<string, Relation>  $belongsTo  belongs-to, plus the one-to-one relations
     * @return array<int, array<string, mixed>>
     */
    protected static function breakdown(Builder $query, string $groupBy, $target, array $targetColumns, array $belongsTo, ?string $dateField, int $limit, \Closure $read): array
    {
        $table = $target->getTable();

        if (in_array($groupBy, ['day', 'month'], true) && $dateField) {
            $expression = $groupBy === 'day' ? "date({$table}.{$dateField})" : "substr({$table}.{$dateField}, 1, 7)";

            return $query->selectRaw("{$expression} as grp")->groupBy('grp')->orderBy('grp')->toBase()->get()
                ->map(fn ($row): array => ['group' => $row->grp, ...$read($row)])->all();
        }

        if (str_starts_with($groupBy, 'one:') && str_contains($groupBy, '.')) {
            [$name, $column] = explode('.', substr($groupBy, 4), 2);
            $relation = $belongsTo[$name] ?? null;

            if (! ($relation instanceof HasOne || $relation instanceof MorphOne) || ! in_array($column, ResourceInspector::columns($relation->getRelated()), true)) {
                return [];
            }

            // One row per record (one-to-one), so the totals are not multiplied.
            $alias = 'chatai_one';
            $query->leftJoin($relation->getRelated()->getTable().' as '.$alias, function ($join) use ($relation, $alias, $table): void {
                $join->on("{$alias}.".$relation->getForeignKeyName(), '=', "{$table}.".$relation->getLocalKeyName());

                if ($relation instanceof MorphOne) {
                    $join->where("{$alias}.".$relation->getMorphType(), '=', $relation->getMorphClass());
                }
            });

            return $query->selectRaw("{$alias}.{$column} as grp")->groupBy("{$alias}.{$column}")->orderByRaw('m0 desc')->limit($limit)->toBase()->get()
                ->map(fn ($row): array => ['group' => $row->grp, ...$read($row)])->all();
        }

        if (str_starts_with($groupBy, 'rel:') && ($relation = $belongsTo[substr($groupBy, 4)] ?? null) instanceof BelongsTo) {
            $key = $relation->getForeignKeyName();
            $rows = $query->selectRaw("{$table}.{$key} as grp")->groupBy("{$table}.{$key}")->orderByRaw('m0 desc')->limit($limit)->toBase()->get();
            $owner = $relation->getRelated();
            $title = ResourceInspector::titleColumn($owner);
            $names = $owner->newQuery()->whereIn($relation->getOwnerKeyName(), $rows->pluck('grp'))->pluck($title, $relation->getOwnerKeyName());

            return $rows->map(fn ($row): array => ['group' => $names[$row->grp] ?? $row->grp, ...$read($row)])->all();
        }

        if (in_array($groupBy, $targetColumns, true)) {
            return $query->selectRaw("{$table}.{$groupBy} as grp")->groupBy("{$table}.{$groupBy}")->orderByRaw('m0 desc')->limit($limit)->toBase()->get()
                ->map(fn ($row): array => ['group' => $row->grp, ...$read($row)])->all();
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: ?Carbon, 1: ?Carbon, 2: string}
     */
    protected static function range(array $args, string $default): array
    {
        if (filled($args['date_from'] ?? null)) {
            $from = rescue(fn () => Carbon::parse((string) $args['date_from'])->startOfDay(), null, false);
            $to = filled($args['date_to'] ?? null) ? rescue(fn () => Carbon::parse((string) $args['date_to'])->endOfDay(), null, false) : now();

            if ($from && $to) {
                return [$from, $to, 'custom'];
            }
        }

        $period = in_array($args['period'] ?? null, self::PERIODS, true) ? $args['period'] : $default;

        return match ($period) {
            'today' => [now()->startOfDay(), now(), $period],
            'week' => [now()->startOfWeek(), now(), $period],
            'year' => [now()->startOfYear(), now(), $period],
            'all' => [null, null, $period],
            default => [now()->startOfMonth(), now(), 'month'],
        };
    }
}
