<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Support\ImageSource;
use Filament\ChatAi\Tools\NoCode\Conditions;
use Filament\ChatAi\Tools\NoCode\ResourceInspector;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        $resource = ResourceInspector::resource($tool->config['resource'] ?? null);
        $instance = ResourceInspector::model($resource);

        if (! $resource || ! $instance) {
            return null; // only resources of this panel
        }

        $config = (array) $tool->config;
        $visible = ResourceInspector::columns($instance);
        $belongsTo = ResourceInspector::relations($instance, [BelongsTo::class]);
        $hasMany = ResourceInspector::relations($instance, [HasMany::class]);

        // Admin choices are checked against real, non-hidden columns and real relations.
        $relatedFields = array_values(array_filter((array) ($config['fields'] ?? []), fn ($f): bool => is_string($f) && str_contains($f, '.') && isset($belongsTo[strtok($f, '.')]) && in_array(substr($f, strpos($f, '.') + 1), ResourceInspector::columns($belongsTo[strtok($f, '.')]->getRelated()), true)));
        $fields = array_values(array_intersect((array) ($config['fields'] ?? []), $visible)) ?: ($relatedFields ? [] : array_slice($visible, 0, 8));
        $filters = array_values(array_intersect((array) ($config['filters'] ?? []), $visible));
        $search = array_values(array_intersect((array) ($config['search'] ?? []), $visible));
        $conditions = array_values(array_filter((array) ($config['conditions'] ?? []), 'is_array'));
        $dateField = in_array($config['date_field'] ?? null, $visible, true) ? $config['date_field'] : null;
        $sort = in_array($config['sort'] ?? null, $visible, true) ? $config['sort'] : $instance->getKeyName();
        $direction = ($config['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $limit = max(1, min((int) ($config['limit'] ?? 20), self::MAX_ROWS));
        $countBy = in_array($config['count_by'] ?? null, $visible, true) ? $config['count_by'] : null;
        $totals = array_values(array_filter((array) ($config['totals'] ?? []), fn ($t): bool => is_array($t) && isset($hasMany[$t['relation'] ?? ''])));
        $related = array_values(array_filter((array) ($config['related'] ?? []), fn ($r): bool => is_array($r) && isset($hasMany[$r['relation'] ?? ''])));
        $image = config('chatai.images.enabled', true) ? ImageSource::valid($instance, $config['image'] ?? null) : null;

        $properties = [];

        if ($search) {
            $properties['search'] = ['type' => 'string', 'description' => 'Text to look for in '.implode(', ', $search).'.'];
        }

        foreach ($filters as $f) {
            $properties[$f] = ['type' => 'string', 'description' => "Filter by {$f}."] + (($enum = ResourceInspector::enumValues($instance, $f)) ? ['enum' => $enum] : []);
        }

        if ($dateField) {
            $properties['date_from'] = ['type' => 'string', 'description' => "Earliest {$dateField} (YYYY-MM-DD)."];
            $properties['date_to'] = ['type' => 'string', 'description' => "Latest {$dateField} (YYYY-MM-DD)."];
        }

        $properties['limit'] = ['type' => 'integer', 'description' => "Rows to return (max {$limit})."];
        $properties['page'] = ['type' => 'integer', 'description' => 'Page of rows, 1 = first. Only when the user asks for the next ones.'];

        $description = $tool->description
            .' Returns the total number of matching '.$resource::getPluralModelLabel().' (with no filters: all of them, so it also answers "how many") and up to '.$limit.' rows per page.'
            .(($fixed = Conditions::describe($conditions)) !== '' ? " Already filtered: {$fixed}." : '')
            .($image ? ' Rows carry "_image", the record\'s picture: show them as cards (or a table column with format image) when the user wants to see them.' : '');

        return new ChatAiTool(
            name: $tool->name,
            description: $description,
            inputSchema: ['type' => 'object', 'properties' => $properties],
            handler: function (array $args) use ($tool, $resource, $instance, $visible, $fields, $relatedFields, $filters, $search, $conditions, $dateField, $sort, $direction, $limit, $countBy, $totals, $related, $hasMany, $image): ToolResult {
                if (! $resource::canViewAny()) {
                    return ToolResult::error('The user does not have permission to see these records.');
                }

                // The resource's own query: its scopes and tenancy apply.
                $query = $resource::getEloquentQuery();
                $term = is_scalar($args['search'] ?? null) ? trim((string) $args['search']) : '';

                if ($term !== '' && $search) {
                    $query->where(function (Builder $q) use ($search, $term): void {
                        foreach ($search as $column) {
                            $q->orWhere($column, 'like', '%'.addcslashes($term, '%_').'%');
                        }
                    });
                }

                foreach ($filters as $filter) {
                    $value = $args[$filter] ?? null;

                    if (is_scalar($value) && (string) $value !== '') {
                        is_numeric($value) || ResourceInspector::enumValues($instance, $filter)
                            ? $query->where($filter, $value)
                            : $query->where($filter, 'like', '%'.addcslashes((string) $value, '%_').'%');
                    }
                }

                $searching = $term !== '' || collect($filters)->contains(fn ($f) => filled($args[$f] ?? null));
                Conditions::apply($query, $conditions, $visible, $searching);

                if ($dateField) {
                    $query->when($args['date_from'] ?? null, fn (Builder $q, $from) => $q->whereDate($dateField, '>=', $from))
                        ->when($args['date_to'] ?? null, fn (Builder $q, $to) => $q->whereDate($dateField, '<=', $to));
                }

                $counts = $countBy ? (clone $query)->reorder()->toBase()->selectRaw("{$countBy} as value, count(*) as total")->groupBy($countBy)->pluck('total', 'value')->all() : null;

                foreach ($relatedFields as $field) {
                    $query->with(strtok($field, '.'));
                }

                foreach ($totals as $i => $total) {
                    $relatedModel = $hasMany[$total['relation']]->getRelated();
                    $column = in_array($total['column'] ?? null, ResourceInspector::columns($relatedModel), true) ? $total['column'] : null;
                    $function = in_array($total['function'] ?? 'count', ['count', 'sum', 'avg', 'min', 'max'], true) ? $total['function'] : 'count';
                    $alias = 'total_'.$i;
                    $where = filled($total['where_column'] ?? null) ? [['column' => $total['where_column'], 'operator' => $total['where_operator'] ?? '=', 'value' => $total['where_value'] ?? null]] : [];
                    $constraint = fn (Builder $q) => Conditions::apply($q, $where, ResourceInspector::columns($relatedModel));

                    $function === 'count' || ! $column
                        ? $query->withCount([$total['relation'].' as '.$alias => $constraint])
                        : $query->withAggregate([$total['relation'].' as '.$alias => $constraint], $column, $function);
                }

                if ($image && str_starts_with($image, ImageSource::MEDIA) && method_exists($instance, 'media')) {
                    $query->with('media'); // one query for every row's picture
                }

                $total = (clone $query)->count();
                $perPage = max(1, min((int) ($args['limit'] ?? $limit), $limit));
                $pages = max(1, (int) ceil($total / $perPage));
                $page = max(1, min((int) ($args['page'] ?? 1), $pages));
                $records = $query->orderBy($sort, $direction)->offset(($page - 1) * $perPage)->limit($perPage)->get();

                $rows = $records->map(function ($record) use ($resource, $fields, $relatedFields, $totals, $related, $image): array {
                    $row = Arr::only($record->attributesToArray(), $fields);

                    if ($image && ($url = ImageSource::url($record, $image))) {
                        $row['_image'] = $url;
                    }

                    // Where the record opens, if the user may open it (for clickable rows).
                    foreach (['view', 'edit'] as $page) {
                        if ($resource::hasPage($page) && rescue(fn () => $page === 'view' ? $resource::canView($record) : $resource::canEdit($record), false, false)) {
                            $row['_url'] = rescue(fn () => $resource::getUrl($page, ['record' => $record]), null, false);
                            break;
                        }
                    }

                    foreach ($relatedFields as $field) {
                        [$relation, $column] = explode('.', $field, 2);
                        $row[$field] = $record->{$relation}?->{$column};
                    }

                    foreach ($totals as $i => $t) {
                        $row[$t['label'] ?? ('total_'.$i)] = round((float) $record->{'total_'.$i}, 2); // no related records = 0
                    }

                    foreach ($related as $r) {
                        $model = $record->{$r['relation']}()->getRelated();
                        $columns = array_values(array_intersect((array) ($r['fields'] ?? []), ResourceInspector::columns($model))) ?: [$model->getKeyName()];
                        $row['latest_'.$r['relation']] = $record->{$r['relation']}()->latest($model->getKeyName())->limit(max(1, min((int) ($r['limit'] ?? 5), 20)))->get($columns)->map->attributesToArray()->all();
                    }

                    return $row;
                })->all();

                // More than one page: the table can page through all of them by itself.
                $paging = $pages > 1 ? [
                    'page' => $page,
                    'pages' => $pages,
                    'source' => ['tool' => $tool->name, 'args' => Arr::except($args, ['page', 'limit'])],
                    'note' => "Page {$page} of {$pages}. To let the user see all {$total} in a table, put this \"source\" and \"total\" in the show_ui table: it pages by itself, so do not call this tool page by page.",
                ] : [];

                return ToolResult::data(array_filter([
                    'resource' => $resource::getPluralModelLabel(),
                    'total' => $total,
                    'counts_by_'.$countBy => $counts,
                    'rows' => $rows,
                    ...$paging,
                ], fn ($v): bool => $v !== null));
            },
            readOnly: true,
            title: $tool->title,
        );
    }
}
