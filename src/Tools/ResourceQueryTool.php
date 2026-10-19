<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Models\CustomTool;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

/**
 * A read-only search over a panel resource, configured on screen: which
 * fields come back and which ones the model may filter by.
 */
final class ResourceQueryTool
{
    public const MAX_ROWS = 50;

    /**
     * Columns of a resource's table the admin may expose (never the model's $hidden).
     *
     * @return array<string, string>
     */
    public static function columnsFor(?string $resource): array
    {
        if (! is_string($resource) || ! class_exists($resource) || ! is_subclass_of($resource, Resource::class)) {
            return [];
        }

        $model = $resource::getModel();
        $instance = new $model;
        $columns = array_values(array_diff(Schema::getColumnListing($instance->getTable()), $instance->getHidden()));

        return array_combine($columns, $columns);
    }

    /**
     * @return array<class-string, string>
     */
    public static function panelResources(): array
    {
        return collect(Filament::getCurrentPanel()?->getResources() ?? [])
            ->mapWithKeys(fn (string $resource): array => [$resource => ucfirst((string) $resource::getPluralModelLabel())])
            ->sort()
            ->all();
    }

    public static function make(CustomTool $tool): ?ChatAiTool
    {
        /** @var class-string<resource>|null $resource */
        $resource = $tool->config['resource'] ?? null;

        if (! is_string($resource) || ! in_array($resource, Filament::getCurrentPanel()?->getResources() ?? [], true)) {
            return null; // only resources of this panel
        }

        $model = $resource::getModel();
        $instance = new $model;
        $columns = Schema::getColumnListing($instance->getTable());
        $visible = array_values(array_diff($columns, $instance->getHidden()));

        // Admin choices are checked against real, non-hidden columns.
        $fields = array_values(array_intersect((array) ($tool->config['fields'] ?? []), $visible)) ?: array_slice($visible, 0, 8);
        $filters = array_values(array_intersect((array) ($tool->config['filters'] ?? []), $visible));
        $dateField = in_array($tool->config['date_field'] ?? null, $visible, true) ? $tool->config['date_field'] : null;
        $sort = in_array($tool->config['sort'] ?? null, $visible, true) ? $tool->config['sort'] : $instance->getKeyName();
        $limit = max(1, min((int) ($tool->config['limit'] ?? 20), self::MAX_ROWS));

        $properties = collect($filters)->mapWithKeys(fn (string $f): array => [$f => ['type' => 'string', 'description' => "Filter by {$f} (partial match)."]])->all();

        if ($dateField) {
            $properties['date_from'] = ['type' => 'string', 'description' => "Earliest {$dateField} (YYYY-MM-DD)."];
            $properties['date_to'] = ['type' => 'string', 'description' => "Latest {$dateField} (YYYY-MM-DD)."];
        }

        $properties['limit'] = ['type' => 'integer', 'description' => "Rows to return (max {$limit})."];

        return new ChatAiTool(
            name: $tool->name,
            description: $tool->description,
            inputSchema: ['type' => 'object', 'properties' => $properties],
            handler: function (array $args) use ($resource, $fields, $filters, $dateField, $sort, $limit): ToolResult {
                if (! $resource::canViewAny()) {
                    return ToolResult::error('The user does not have permission to see these records.');
                }

                // The resource's own query: its scopes and tenancy apply.
                $query = $resource::getEloquentQuery();

                foreach ($filters as $filter) {
                    $value = $args[$filter] ?? null;

                    if (is_scalar($value) && (string) $value !== '') {
                        is_numeric($value)
                            ? $query->where($filter, $value)
                            : $query->where($filter, 'like', '%'.addcslashes((string) $value, '%_').'%');
                    }
                }

                if ($dateField) {
                    $query->when($args['date_from'] ?? null, fn (Builder $q, $from) => $q->whereDate($dateField, '>=', $from))
                        ->when($args['date_to'] ?? null, fn (Builder $q, $to) => $q->whereDate($dateField, '<=', $to));
                }

                $total = (clone $query)->count();
                $rows = $query->orderByDesc($sort)
                    ->limit(max(1, min((int) ($args['limit'] ?? $limit), $limit)))
                    ->get()
                    ->map(fn ($record): array => Arr::only($record->attributesToArray(), $fields))
                    ->all();

                return ToolResult::data(['resource' => $resource::getPluralModelLabel(), 'total' => $total, 'rows' => $rows]);
            },
            readOnly: true,
            title: $tool->title,
        );
    }
}
