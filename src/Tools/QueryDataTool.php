<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Tools\NoCode\Conditions;
use Filament\ChatAi\Tools\NoCode\ResourceInspector;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

/**
 * Reads any resource of the panel the user may see, when no tool of its own
 * answers: a filtered list, or totals for a period. Same query, scopes and
 * permissions as the resource's pages, the way the user would filter it.
 */
final class QueryDataTool
{
    public const NAME = 'query_data';

    public static function make(): ChatAiTool
    {
        return new ChatAiTool(
            name: self::NAME,
            description: 'Reads the records of a resource of this panel when no other tool answers: "list" returns matching rows, "totals" adds them up (count, sum, average…), optionally per group. '
                .'Call '.BuilderTools::DESCRIBE.' first for the resource id and its real columns and relations. Filter with "where" on columns, and with "related" to pick a related record by name, '
                .'e.g. {"employee": "Camilla Schimmel"} for one person\'s timesheets.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'resource' => ['type' => 'string', 'description' => 'Resource id from '.BuilderTools::DESCRIBE.'.'],
                    'kind' => ['type' => 'string', 'enum' => ['list', 'totals']],
                    'where' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => '[{column, operator: '.implode(' ', Conditions::OPERATORS).', value}]'],
                    'related' => ['type' => 'object', 'description' => 'Belongs-to relation → part of the related record\'s name, e.g. {"employee": "Camilla"}.'],
                    'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'list: columns to return, also "relation.column".'],
                    'sort' => ['type' => 'string'],
                    'sort_dir' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                    'metrics' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'totals: [{function: count|sum|avg|min|max, column, label}]. Default: count.'],
                    'group_by' => ['type' => 'string', 'description' => 'totals: day | month | a column | "rel:<belongs-to>".'],
                    'date_field' => ['type' => 'string', 'description' => 'Date column for date_from / date_to.'],
                    'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'page' => ['type' => 'integer', 'description' => 'list: page of rows, 1 = first.'],
                ],
                'required' => ['resource', 'kind'],
            ],
            handler: fn (array $args): ToolResult => self::run($args),
            readOnly: true,
            title: 'Read data',
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected static function run(array $args): ToolResult
    {
        $resource = ResourceInspector::resource($args['resource'] ?? null);
        $model = ResourceInspector::model($resource);

        if (! $resource || ! $model) {
            return ToolResult::error('Unknown resource. Call '.BuilderTools::DESCRIBE.' for the list.');
        }

        $conditions = array_values(array_filter((array) ($args['where'] ?? []), 'is_array'));

        // A related record by name becomes its key: "employee: Camilla" → employee_id = 7.
        foreach ((array) ($args['related'] ?? []) as $name => $value) {
            $relation = ResourceInspector::relations($model, [BelongsTo::class])[$name] ?? null;

            if (! $relation instanceof BelongsTo || ! is_scalar($value) || $value === '') {
                return ToolResult::error("\"{$name}\" is not a belongs-to relation of this resource. Use one from ".BuilderTools::DESCRIBE.'.');
            }

            $owner = $relation->getRelated();
            $title = ResourceInspector::titleColumn($owner);
            $matches = $owner->newQuery()->where($title, 'like', '%'.addcslashes((string) $value, '%_').'%')->limit(10)->pluck($title, $relation->getOwnerKeyName());

            // The exact name wins over others that merely contain it ("Ana" vs "Ana Maria").
            if ($matches->count() > 1 && ($exact = $matches->search(fn ($n): bool => strcasecmp((string) $n, (string) $value) === 0)) !== false) {
                $matches = $matches->only([$exact]);
            }

            if ($matches->count() !== 1) {
                return $matches->isEmpty()
                    ? ToolResult::error("No {$name} matches \"{$value}\".")
                    : ToolResult::data(['ambiguous' => $name, 'candidates' => $matches->values()->all(), 'note' => 'Several match: ask the user which one, or use the full name.']);
            }

            $conditions[] = ['column' => $relation->getForeignKeyName(), 'operator' => '=', 'value' => $matches->keys()->first()];
        }

        $totals = ($args['kind'] ?? 'list') === 'totals';
        $dateField = in_array($args['date_field'] ?? null, ResourceInspector::columns($model), true) ? $args['date_field'] : null;

        $tool = new CustomTool([
            'name' => self::NAME,
            'title' => 'Read data',
            'description' => 'Ad hoc query.',
            'type' => $totals ? CustomTool::TYPE_METRICS : CustomTool::TYPE_RESOURCE,
            'config' => $totals
                ? ['resource' => $resource, 'metrics' => array_values(array_filter((array) ($args['metrics'] ?? []), 'is_array')), 'conditions' => $conditions,
                    'group_by' => (string) ($args['group_by'] ?? ''), 'date_field' => $dateField, 'period' => 'all']
                : ['resource' => $resource, 'fields' => array_values(array_filter((array) ($args['fields'] ?? []), 'is_string')), 'conditions' => $conditions,
                    'sort' => $args['sort'] ?? null, 'sort_dir' => $args['sort_dir'] ?? 'desc', 'date_field' => $dateField, 'limit' => ResourceQueryTool::MAX_ROWS],
        ]);

        $built = $totals ? MetricsTool::make($tool) : ResourceQueryTool::make($tool);
        $result = $built?->call(array_filter(Arr::only($args, ['date_from', 'date_to', 'page']), 'filled'));

        if ($result === null || $result->isError() || $totals || ! isset($result->data['source'])) {
            return $result ?? ToolResult::error('This resource cannot be read.');
        }

        // The table pages with this same query.
        return ToolResult::data([...$result->data, 'source' => ['tool' => self::NAME, 'args' => Arr::except($args, ['page', 'limit'])]]);
    }
}
