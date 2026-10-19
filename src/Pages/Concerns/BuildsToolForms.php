<?php

namespace Filament\ChatAi\Pages\Concerns;

use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\ToolOverride;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiPermissions;
use Filament\ChatAi\Tools\HttpApiTool;
use Filament\ChatAi\Tools\MetricsTool;
use Filament\ChatAi\Tools\NoCode\ResourceInspector;
use Filament\ChatAi\Tools\ResourceQueryTool;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * The tool forms: adjusting a code tool, and making a resource/API tool.
 */
trait BuildsToolForms
{
    /**
     * @return array<string, mixed>
     */
    protected function overrideState(string $name): array
    {
        $override = ToolOverride::query()->where('tool_name', $name)->first();

        return [
            'enabled' => $override?->enabled ?? true,
            'title' => $override?->title,
            'description' => $override?->description,
            'roles' => $override?->roles ?? [],
            'permission' => $override?->permission,
        ];
    }

    /**
     * The Shield permission needed for the tool: its own UseChatAiTool:X by default,
     * or any existing permission (e.g. ViewAny:Order).
     */
    protected function permissionField(?string $tool): Select
    {
        $model = config('permission.models.permission');

        return Select::make('permission')
            ->label(__('chatai::chatai.tools.permission'))
            ->native(false)
            ->searchable()
            ->placeholder($tool ? __('chatai::chatai.tools.permission_default', ['permission' => ChatAiPermissions::forTool($tool)]) : __('chatai::chatai.tools.permission_auto'))
            ->options(fn (): array => $model && class_exists($model) ? $model::query()->orderBy('name')->pluck('name', 'name')->all() : [])
            ->helperText(ChatAiPermissions::enabled() ? __('chatai::chatai.tools.permission_help') : __('chatai::chatai.tools.permission_off'));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, mixed>
     */
    protected function overrideForm(array $record): array
    {
        $t = fn (string $key): string => __('chatai::chatai.tools.'.$key);

        return [
            Toggle::make('enabled')->label($t('enabled_help')),
            TextInput::make('title')->label($t('title_field'))->placeholder($record['title'])->maxLength(120),
            Textarea::make('description')->label($t('description_for_model'))->rows(5)
                ->placeholder($record['original_description'])->helperText($t('description_help')),
            TagsInput::make('roles')->label($t('roles'))->helperText($t('roles_help')),
            $this->permissionField((string) ($record['name'] ?? null) ?: null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function customState(string $name): array
    {
        $tool = CustomTool::query()->where('name', $name)->firstOrFail();
        $config = $tool->config;
        $config['secret'] = null; // never back to the browser

        return [...$tool->only(['name', 'title', 'description', 'type', 'enabled']), 'config' => $config];
    }

    /**
     * @return array<int, mixed>
     */
    protected function customForm(?string $editing): array
    {
        $t = fn (string $key): string => __('chatai::chatai.tools.'.$key);
        $isResource = fn (Get $get): bool => $get('type') === CustomTool::TYPE_RESOURCE;
        $isHttp = fn (Get $get): bool => $get('type') === CustomTool::TYPE_HTTP;
        $isMetrics = fn (Get $get): bool => $get('type') === CustomTool::TYPE_METRICS;
        $isRecord = fn (Get $get): bool => in_array($get('type'), [CustomTool::TYPE_CREATE, CustomTool::TYPE_UPDATE], true);
        $usesResource = fn (Get $get): bool => in_array($get('type'), [CustomTool::TYPE_RESOURCE, CustomTool::TYPE_METRICS, CustomTool::TYPE_CREATE, CustomTool::TYPE_UPDATE], true);
        $picked = fn (Get $get): bool => filled($get('config.resource'));
        $columns = fn (Get $get): array => ResourceQueryTool::columnsFor($get('config.resource'));
        // Inside a repeater row, the resource is two levels up.
        $rowModel = fn (Get $get) => ResourceInspector::model($get('../../resource') ?: $get('../../config.resource'));
        $metricsTarget = function (Get $get, bool $inRow = false) {
            $resource = $inRow ? ($get('../../resource') ?: $get('../../config.resource')) : $get('config.resource');
            $relation = $inRow ? ($get('../../relation') ?: $get('../../config.relation')) : $get('config.relation');
            $model = ResourceInspector::model($resource);
            $rel = ResourceInspector::relations($model, [HasMany::class])[$relation ?? ''] ?? null;

            return $rel?->getRelated() ?? $model;
        };
        $hosts = implode(', ', (array) config('chatai.http_tools.allowed_hosts', [])) ?: '—';
        $operators = ['=' => '=', '!=' => '≠', '<' => '<', '<=' => '≤', '>' => '>', '>=' => '≥', 'like' => $t('op_like'), 'null' => $t('op_null'), 'not_null' => $t('op_not_null')];

        ChatAiSettings::apply();

        $conditions = fn (string $path, \Closure $options, bool $withSearch = false): Repeater => Repeater::make($path)->label($t('conditions'))->helperText($t('conditions_help'))
            ->defaultItems(0)->columns($withSearch ? 5 : 4)->addActionLabel($t('add_condition'))->collapsible(false)
            ->schema([
                Select::make('column')->label($t('column'))->options($options)->required()->native(false),
                Select::make('operator')->label($t('operator'))->options($operators)->default('=')->required()->native(false)->live(),
                TextInput::make('value')->label($t('value'))->visible(fn (Get $get): bool => ! in_array($get('operator'), ['null', 'not_null'], true) && blank($get('compare_column'))),
                Select::make('compare_column')->label($t('compare_column'))->options($options)->native(false)->live()->placeholder($t('compare_column_none'))
                    ->visible(fn (Get $get): bool => ! in_array($get('operator'), ['null', 'not_null', 'like'], true)),
                ...($withSearch ? [Toggle::make('unless_search')->label($t('unless_search'))->inline(false)] : []),
            ]);

        return [
            Grid::make(2)->schema([
                TextInput::make('name')->label($t('name'))->required()->maxLength(64)->helperText($t('name_help'))
                    ->regex('/^[a-z0-9][a-z0-9_-]*$/')
                    ->rule(Rule::unique('chatai_custom_tools', 'name')->ignore($editing, 'name'))
                    ->disabled($editing !== null)->dehydrated($editing === null),
                TextInput::make('title')->label($t('title_field'))->required()->maxLength(120),
            ]),
            Textarea::make('description')->label($t('description_for_model'))->required()->rows(3)->helperText($t('description_help')),
            Select::make('type')->label($t('type'))->required()->live()->native(false)
                ->options([
                    CustomTool::TYPE_RESOURCE => $t('sources.resource'),
                    CustomTool::TYPE_METRICS => $t('sources.metrics'),
                    CustomTool::TYPE_CREATE => $t('sources.create'),
                    CustomTool::TYPE_UPDATE => $t('sources.update'),
                    CustomTool::TYPE_HTTP => $t('sources.http'),
                ])
                ->helperText(fn (Get $get): ?string => in_array($get('type'), [CustomTool::TYPE_CREATE, CustomTool::TYPE_UPDATE], true) ? $t('changes_help') : null),

            Select::make('config.resource')->label($t('resource'))->options(ResourceQueryTool::panelResources())->live()->native(false)
                ->visible($usesResource)->required($usesResource),

            // List records
            CheckboxList::make('config.fields')->label($t('fields'))->helperText($t('fields_help'))->columns(3)->searchable()->bulkToggleable()
                ->options(fn (Get $get): array => [...$columns($get), ...ResourceInspector::relatedColumnOptions(ResourceInspector::model($get('config.resource')))])
                ->visible(fn (Get $get): bool => $isResource($get) && $picked($get)),
            Grid::make(2)->visible(fn (Get $get): bool => $isResource($get) && $picked($get))->schema([
                CheckboxList::make('config.search')->label($t('search_columns'))->helperText($t('search_columns_help'))->options($columns)->columns(2)->searchable(),
                CheckboxList::make('config.filters')->label($t('filters'))->helperText($t('filters_help'))->options($columns)->columns(2)->searchable(),
            ]),
            Grid::make(4)->visible(fn (Get $get): bool => $isResource($get) && $picked($get))->schema([
                Select::make('config.date_field')->label($t('date_field'))->options($columns)->native(false),
                Select::make('config.sort')->label($t('sort'))->options($columns)->native(false),
                Select::make('config.sort_dir')->label($t('sort_dir'))->options(['desc' => $t('sort_desc'), 'asc' => $t('sort_asc')])->default('desc')->native(false),
                TextInput::make('config.limit')->label($t('limit'))->numeric()->minValue(1)->maxValue(ResourceQueryTool::MAX_ROWS),
            ]),
            $conditions('config.conditions', $columns, true)->visible(fn (Get $get): bool => $isResource($get) && $picked($get)),
            Select::make('config.count_by')->label($t('count_by'))->helperText($t('count_by_help'))->options($columns)->native(false)
                ->visible(fn (Get $get): bool => $isResource($get) && $picked($get)),
            Repeater::make('config.totals')->label($t('totals'))->helperText($t('totals_help'))->defaultItems(0)->columns(7)->addActionLabel($t('add_total'))
                ->visible(fn (Get $get): bool => $isResource($get) && $picked($get))
                ->schema([
                    Select::make('relation')->label($t('relation'))->required()->native(false)->live()
                        ->options(fn (Get $get): array => ResourceInspector::relationOptions($rowModel($get), HasMany::class)),
                    Select::make('function')->label($t('function'))->options($this->functionOptions(false))->default('count')->required()->native(false)->live(),
                    Select::make('column')->label($t('column'))->native(false)
                        ->options(fn (Get $get): array => ResourceInspector::columnOptions(ResourceInspector::relations($rowModel($get), [HasMany::class])[$get('relation') ?? '']?->getRelated()))
                        ->visible(fn (Get $get): bool => $get('function') !== 'count'),
                    TextInput::make('label')->label($t('label'))->required(),
                    Select::make('where_column')->label($t('only_where'))->native(false)->placeholder('—')
                        ->options(fn (Get $get): array => ResourceInspector::columnOptions(ResourceInspector::relations($rowModel($get), [HasMany::class])[$get('relation') ?? '']?->getRelated())),
                    Select::make('where_operator')->label($t('operator'))->options($operators)->default('=')->native(false),
                    TextInput::make('where_value')->label($t('value')),
                ]),
            Repeater::make('config.related')->label($t('related'))->helperText($t('related_help'))->defaultItems(0)->columns(3)->addActionLabel($t('add_related'))
                ->visible(fn (Get $get): bool => $isResource($get) && $picked($get))
                ->schema([
                    Select::make('relation')->label($t('relation'))->required()->native(false)->live()
                        ->options(fn (Get $get): array => ResourceInspector::relationOptions($rowModel($get), HasMany::class)),
                    Select::make('fields')->label($t('fields'))->multiple()->native(false)
                        ->options(fn (Get $get): array => ResourceInspector::columnOptions(ResourceInspector::relations($rowModel($get), [HasMany::class])[$get('relation') ?? '']?->getRelated())),
                    TextInput::make('limit')->label($t('limit'))->numeric()->default(5)->minValue(1)->maxValue(20),
                ]),

            // Totals for a period
            Grid::make(4)->visible(fn (Get $get): bool => $isMetrics($get) && $picked($get))->schema([
                Select::make('config.relation')->label($t('metrics_over'))->helperText($t('metrics_over_help'))->native(false)->live()->placeholder($t('metrics_over_self'))
                    ->options(fn (Get $get): array => ResourceInspector::relationOptions(ResourceInspector::model($get('config.resource')), HasMany::class)),
                Select::make('config.date_field')->label($t('date_field'))->options($columns)->native(false),
                Select::make('config.period')->label($t('default_period'))->native(false)->default('month')
                    ->options(collect(MetricsTool::PERIODS)->mapWithKeys(fn ($p) => [$p => $t('period_'.$p)])->all()),
                Toggle::make('config.compare')->label($t('compare'))->inline(false),
            ]),
            Repeater::make('config.metrics')->label($t('metrics'))->defaultItems(1)->columns(4)->addActionLabel($t('add_metric'))
                ->visible(fn (Get $get): bool => $isMetrics($get) && $picked($get))
                ->schema([
                    Select::make('function')->label($t('function'))->options($this->functionOptions(true))->default('count')->required()->native(false)->live(),
                    Select::make('column')->label($t('column'))->native(false)->options(fn (Get $get): array => ResourceInspector::columnOptions($metricsTarget($get, true)))
                        ->visible(fn (Get $get): bool => $get('function') !== 'count')->required(fn (Get $get): bool => $get('function') !== 'count'),
                    Select::make('column2')->label($t('times_column'))->native(false)->options(fn (Get $get): array => ResourceInspector::columnOptions($metricsTarget($get, true)))
                        ->visible(fn (Get $get): bool => $get('function') === 'sum_product'),
                    TextInput::make('label')->label($t('label'))->required(),
                ]),
            $conditions('config.conditions', $columns)->visible(fn (Get $get): bool => $isMetrics($get) && $picked($get)),
            Grid::make(2)->visible(fn (Get $get): bool => $isMetrics($get) && $picked($get))->schema([
                Select::make('config.group_by')->label($t('group_by'))->native(false)->placeholder($t('group_none'))
                    ->options(function (Get $get) use ($metricsTarget, $t): array {
                        $target = $metricsTarget($get);
                        $byRelation = collect(ResourceInspector::relations($target, [BelongsTo::class]))->keys()->mapWithKeys(fn ($r) => ['rel:'.$r => $r])->all();

                        return [
                            $t('group_dates') => blank($get('config.relation')) ? ['day' => $t('group_day'), 'month' => $t('group_month')] : [],
                            $t('group_related') => $byRelation,
                            $t('group_columns') => ResourceInspector::columnOptions($target),
                        ];
                    }),
                TextInput::make('config.group_limit')->label($t('group_limit'))->numeric()->default(20)->minValue(1)->maxValue(100),
            ]),

            // Create / change a record
            Repeater::make('config.columns')->label($t('record_columns'))->helperText($t('record_columns_help'))->defaultItems(1)->columns(4)->addActionLabel($t('add_column'))
                ->visible(fn (Get $get): bool => $isRecord($get) && $picked($get))
                ->schema([
                    Select::make('column')->label($t('column'))->required()->native(false)
                        ->options(fn (Get $get): array => ResourceInspector::columnOptions($rowModel($get))),
                    TextInput::make('label')->label($t('label')),
                    Select::make('mode')->label($t('mode'))->options(['set' => $t('mode_set'), 'add' => $t('mode_add')])->default('set')->native(false)
                        ->visible(fn (Get $get): bool => ($get('../../../type') ?: $get('../../type')) === CustomTool::TYPE_UPDATE),
                    Toggle::make('required')->label($t('required'))->inline(false),
                ]),
            CheckboxList::make('config.unique')->label($t('unique'))->helperText($t('unique_help'))->options($columns)->columns(3)
                ->visible(fn (Get $get): bool => $get('type') === CustomTool::TYPE_CREATE && $picked($get)),
            CheckboxList::make('config.lookup')->label($t('lookup'))->helperText($t('lookup_help'))->options($columns)->columns(3)
                ->visible(fn (Get $get): bool => $get('type') === CustomTool::TYPE_UPDATE && $picked($get))->required(fn (Get $get): bool => $get('type') === CustomTool::TYPE_UPDATE),
            Toggle::make('config.no_negative')->label($t('no_negative'))->default(true)
                ->visible(fn (Get $get): bool => $get('type') === CustomTool::TYPE_UPDATE && $picked($get)),

            // Call an API
            TextInput::make('config.url')->label($t('url'))->url()->visible($isHttp)->required($isHttp)
                ->helperText(__('chatai::chatai.tools.url_help', ['hosts' => $hosts]))
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    if ($get('type') === CustomTool::TYPE_HTTP && ! HttpApiTool::allowed((string) $value)) {
                        $fail(__('chatai::chatai.tools.host_not_allowed'));
                    }
                }),
            Repeater::make('config.params')->label($t('params'))->visible($isHttp)->defaultItems(0)->columns(4)->addActionLabel($t('add_param'))
                ->schema([
                    TextInput::make('name')->label($t('param_name'))->required()->regex('/^[A-Za-z0-9_]+$/'),
                    TextInput::make('description')->label($t('description')),
                    Select::make('in')->label($t('param_in'))->options(['query' => 'query', 'path' => 'path'])->default('query')->native(false),
                    Toggle::make('required')->label($t('required'))->inline(false),
                ]),
            Grid::make(2)->visible($isHttp)->schema([
                Select::make('config.auth')->label($t('auth'))->live()->native(false)
                    ->options(['none' => $t('auth_none'), 'bearer' => 'Bearer token', 'header' => $t('auth_header')]),
                TextInput::make('config.header_name')->label($t('header_name'))->placeholder('X-Api-Key')
                    ->visible(fn (Get $get): bool => $get('config.auth') === 'header'),
                TextInput::make('config.secret')->label($t('secret'))->password()->revealable()->autocomplete('new-password')
                    ->visible(fn (Get $get): bool => in_array($get('config.auth'), ['bearer', 'header'], true))
                    ->helperText($editing ? __('chatai::chatai.settings.key_stored') : null),
            ]),

            $this->permissionField($editing),
            Toggle::make('enabled')->label($t('enabled_help')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveCustom(array $data, CustomTool $tool): CustomTool
    {
        $config = (array) ($data['config'] ?? []);

        // A blank key field keeps the stored one.
        if (blank($config['secret'] ?? null)) {
            $config['secret'] = $tool->exists ? ($tool->config['secret'] ?? null) : null;
        }

        foreach (['params', 'conditions', 'totals', 'related', 'metrics', 'columns'] as $list) {
            $config[$list] = array_values($config[$list] ?? []);
        }

        $tool->fill([
            ...array_intersect_key($data, array_flip(['name', 'title', 'description', 'type', 'enabled'])),
            'config' => $config,
        ])->save();

        // The permission lives with the other per-tool adjustments.
        if (array_key_exists('permission', $data)) {
            ToolOverride::query()->updateOrCreate(['tool_name' => $tool->name], ['permission' => $data['permission'] ?: null]);
        }

        return $tool;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveOverride(string $name, array $data): void
    {
        ToolOverride::query()->updateOrCreate(['tool_name' => $name], [
            'enabled' => (bool) ($data['enabled'] ?? true),
            'title' => ($data['title'] ?? null) ?: null,
            'description' => ($data['description'] ?? null) ?: null,
            'roles' => array_values($data['roles'] ?? []) ?: null,
            'permission' => ($data['permission'] ?? null) ?: null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function functionOptions(bool $withProduct): array
    {
        $t = fn (string $key): string => __('chatai::chatai.tools.'.$key);

        return [
            'count' => $t('fn_count'), 'sum' => $t('fn_sum'), 'avg' => $t('fn_avg'), 'min' => $t('fn_min'), 'max' => $t('fn_max'),
            ...($withProduct ? ['sum_product' => $t('fn_sum_product')] : []),
        ];
    }
}
