<?php

namespace Filament\ChatAi\Pages\Concerns;

use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\ToolOverride;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiPermissions;
use Filament\ChatAi\Tools\HttpApiTool;
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
        $columns = fn (Get $get): array => ResourceQueryTool::columnsFor($get('config.resource'));
        $hosts = implode(', ', (array) config('chatai.http_tools.allowed_hosts', [])) ?: '—';

        ChatAiSettings::apply();

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
                ->options([CustomTool::TYPE_RESOURCE => $t('sources.resource'), CustomTool::TYPE_HTTP => $t('sources.http')]),

            // Query a resource
            Select::make('config.resource')->label($t('resource'))->options(ResourceQueryTool::panelResources())->live()->native(false)
                ->visible($isResource)->required($isResource),
            CheckboxList::make('config.fields')->label($t('fields'))->helperText($t('fields_help'))->options($columns)->columns(3)->searchable()->bulkToggleable()
                ->visible(fn (Get $get): bool => $isResource($get) && filled($get('config.resource'))),
            CheckboxList::make('config.filters')->label($t('filters'))->helperText($t('filters_help'))->options($columns)->columns(3)->searchable()->bulkToggleable()
                ->visible(fn (Get $get): bool => $isResource($get) && filled($get('config.resource'))),
            Grid::make(2)->visible(fn (Get $get): bool => $isResource($get) && filled($get('config.resource')))->schema([
                Select::make('config.date_field')->label($t('date_field'))->options($columns)->native(false),
                Select::make('config.sort')->label($t('sort'))->options($columns)->native(false),
                TextInput::make('config.limit')->label($t('limit'))->numeric()->minValue(1)->maxValue(ResourceQueryTool::MAX_ROWS),
            ]),

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

        $config['params'] = array_values($config['params'] ?? []);

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
}
