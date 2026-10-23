<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\Skill;
use Filament\ChatAi\Support\ImageSource;
use Filament\ChatAi\Tools\NoCode\ResourceInspector;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Lets the assistant improve itself for the people who manage it: it reads
 * which data the panel has, then proposes a new tool made on screen (a list
 * or totals over a resource) or a skill. Nothing is saved until the manager
 * confirms the card, and the tool must answer a test call first. Never code.
 */
final class BuilderTools
{
    public const DESCRIBE = 'describe_data';

    public const CREATE_TOOL = 'create_tool';

    public const CREATE_SKILL = 'create_skill';

    /**
     * @return array<int, ChatAiTool>
     */
    public static function make(): array
    {
        return [self::describe(), self::createTool(), self::createSkill()];
    }

    public static function describe(): ChatAiTool
    {
        return new ChatAiTool(
            name: self::DESCRIBE,
            description: 'For building a new tool: without "resource", lists the data (resources) of this panel; with "resource", its columns, relations and picture sources. '
                .'Use it before create_tool when the user (an administrator of the assistant) asks for something no current tool answers.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'resource' => ['type' => 'string', 'description' => 'A resource id from the list.'],
                ],
            ],
            handler: function (array $args): ToolResult {
                $resource = ResourceInspector::resource($args['resource'] ?? null);

                // Only what the user may open: the column names of a resource they
                // cannot see are not theirs to read either.
                $mayView = fn (string $class): bool => rescue(fn (): bool => $class::canViewAny(), false, false);

                if ($resource === null) {
                    return ToolResult::data(['resources' => collect(ResourceQueryTool::panelResources())
                        ->filter(fn (string $label, string $id): bool => $mayView($id))
                        ->map(fn (string $label, string $id): array => ['resource' => $id, 'label' => $label])->values()->all()]);
                }

                if (! $mayView($resource)) {
                    return ToolResult::error('The user does not have permission to see these records.');
                }

                $model = ResourceInspector::model($resource);

                return ToolResult::data([
                    'resource' => $resource,
                    'columns' => ResourceInspector::columns($model),
                    'belongs_to' => array_keys(ResourceInspector::relations($model, [BelongsTo::class])),
                    'has_many' => collect(ResourceInspector::relations($model, [HasMany::class]))
                        ->map(fn ($relation): array => ResourceInspector::columns($relation->getRelated()))->all(),
                    'image_sources' => array_keys(ImageSource::options($model)),
                    'existing_tools' => CustomTool::query()->pluck('name')->all(),
                ]);
            },
            readOnly: true,
            title: 'Data of the panel',
        );
    }

    public static function createTool(): ChatAiTool
    {
        return new ChatAiTool(
            name: self::CREATE_TOOL,
            description: 'Gives the assistant a new ability, made on screen (no code): "resource" lists records, "metrics" gives totals for a period. '
                .'Call describe_data first and use only real columns and relations. The manager confirms before it is saved; it can be used from the next question and edited in Tools. '
                .'config for "resource": {fields: [columns or "relation.column"], search: [columns], filters: [columns], date_field, sort, sort_dir: asc|desc, limit (≤50), '
                .'conditions: [{column, operator: = != < <= > >= like null not_null, value | compare_column}], count_by, image, '
                .'totals: [{relation, function: count|sum|avg|min|max, column, label}], related: [{relation, fields: [columns], limit}]}. '
                .'config for "metrics": {relation (optional has-many to measure instead), date_field, period: today|week|month|year|all, compare: true|false, '
                .'metrics: [{function: count|sum|avg|min|max|sum_product, column, column2, label}], conditions: [...], '
                .'filters: [columns, or "rel:<belongs-to>" to filter by a related record\'s name, e.g. "rel:employee" for one person], '
                .'group_by: day | month | a column name | "rel:<belongs-to>", group_limit}. Give a tool filters for whatever the user will name (a person, a status).',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'title' => 'Name', 'description' => 'lowercase-with-dashes, unique, e.g. birthdays-this-month'],
                    'title' => ['type' => 'string', 'title' => 'Title'],
                    'description' => ['type' => 'string', 'title' => 'When to use it', 'maxLength' => 1000, 'description' => 'What it answers, written for the assistant.'],
                    'type' => ['type' => 'string', 'title' => 'Kind', 'enum' => [CustomTool::TYPE_RESOURCE, CustomTool::TYPE_METRICS]],
                    'resource' => ['type' => 'string', 'title' => 'Resource', 'description' => 'Resource id from describe_data.'],
                    'config' => ['type' => 'string', 'title' => 'Settings (JSON)', 'maxLength' => 20000, 'description' => 'The settings object, as JSON.'],
                ],
                'required' => ['name', 'title', 'description', 'type', 'resource', 'config'],
            ],
            handler: fn (array $args): ToolResult => self::saveTool($args),
            readOnly: false,
            title: 'New tool for the assistant',
        );
    }

    public static function createSkill(): ChatAiTool
    {
        return new ChatAiTool(
            name: self::CREATE_SKILL,
            description: 'Saves a skill: instructions the assistant follows for a kind of task (steps, tone, which tools to use). The manager confirms before it is saved.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'title' => 'Name', 'description' => 'lowercase-with-dashes, unique'],
                    'title' => ['type' => 'string', 'title' => 'Title'],
                    'when_to_use' => ['type' => 'string', 'title' => 'When to use it', 'maxLength' => 500],
                    'instructions' => ['type' => 'string', 'title' => 'Instructions', 'maxLength' => 10000],
                    'tools' => ['type' => 'string', 'title' => 'Tools', 'description' => 'Tool names to use, comma-separated.'],
                ],
                'required' => ['name', 'title', 'when_to_use', 'instructions'],
            ],
            handler: fn (array $args): ToolResult => self::saveSkill($args),
            readOnly: false,
            title: 'New skill for the assistant',
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected static function saveTool(array $args): ToolResult
    {
        $name = Str::slug((string) ($args['name'] ?? ''));
        $type = (string) ($args['type'] ?? '');
        $config = json_decode((string) ($args['config'] ?? ''), true);

        if ($name === '' || CustomTool::query()->where('name', $name)->exists()) {
            return ToolResult::error("A tool named \"{$name}\" already exists or the name is empty; choose another name.");
        }

        if (! in_array($type, [CustomTool::TYPE_RESOURCE, CustomTool::TYPE_METRICS], true) || ! is_array($config)) {
            return ToolResult::error('The kind must be resource or metrics, and the settings a JSON object.');
        }

        $config['resource'] = $args['resource'] ?? null;

        foreach (['conditions', 'totals', 'related', 'metrics'] as $list) {
            $config[$list] = array_values(array_filter((array) ($config[$list] ?? []), 'is_array'));
        }

        $tool = new CustomTool([
            'name' => $name,
            'title' => Str::limit(trim((string) ($args['title'] ?? '')) ?: $name, 120, ''),
            'description' => trim((string) ($args['description'] ?? '')),
            'type' => $type,
            'config' => $config,
            'enabled' => true,
        ]);

        $built = $type === CustomTool::TYPE_METRICS ? MetricsTool::make($tool) : ResourceQueryTool::make($tool);

        if ($built === null) {
            return ToolResult::error('That resource is not part of this panel. Use a resource id from describe_data.');
        }

        // It must answer before it is kept.
        $trial = $built->call([]);

        if ($trial->isError()) {
            return ToolResult::error('The tool did not work, so it was not saved: '.$trial->toModelContent(500));
        }

        $tool->save();

        return ToolResult::data([
            'created' => $name,
            'note' => 'Saved and enabled. It can be used from the next question; the administrator can adjust it in Tools.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected static function saveSkill(array $args): ToolResult
    {
        $name = Str::slug((string) ($args['name'] ?? ''));

        if ($name === '' || Skill::query()->where('name', $name)->exists()) {
            return ToolResult::error("A skill named \"{$name}\" already exists or the name is empty; choose another name.");
        }

        $tools = array_values(array_filter(array_map('trim', explode(',', (string) ($args['tools'] ?? '')))));

        Skill::query()->create([
            'name' => $name,
            'title' => Str::limit(trim((string) ($args['title'] ?? '')) ?: $name, 120, ''),
            'when_to_use' => trim((string) ($args['when_to_use'] ?? '')),
            'instructions' => trim((string) ($args['instructions'] ?? '')),
            'tools' => $tools ?: null,
            'enabled' => true,
        ]);

        return ToolResult::data(['created' => $name, 'note' => 'Saved and enabled. It applies from the next question.']);
    }
}
