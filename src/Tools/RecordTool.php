<?php

namespace Filament\ChatAi\Tools;

use BackedEnum;
use Filament\ChatAi\Models\ConversationFile;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Support\ImageSource;
use Filament\ChatAi\Tools\NoCode\ResourceInspector;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Creates or changes a record of a resource, set up on screen. It is never
 * read-only, so the user always gets the form on the card and confirms first.
 *
 * Create: the chosen columns become the form; "unique" columns are checked.
 * Update: the record is found by one of the lookup columns; each chosen column
 * is either set to a new value or added to (e.g. stock + 5).
 *
 * Image: when set up, an image the user attached in the chat is put onto the
 * record (a column, or a Spatie media collection).
 */
final class RecordTool
{
    /** Input key of the attached image (not a column name, so it never clashes with one). */
    public const IMAGE = 'attach_image';

    public static function make(CustomTool $tool): ?ChatAiTool
    {
        $resource = ResourceInspector::resource($tool->config['resource'] ?? null);
        $model = ResourceInspector::model($resource);

        if (! $resource || ! $model) {
            return null;
        }

        $config = (array) $tool->config;
        $creating = $tool->type === CustomTool::TYPE_CREATE;
        $columns = array_diff(ResourceInspector::columns($model), [$model->getKeyName(), $model->getCreatedAtColumn(), $model->getUpdatedAtColumn(), 'deleted_at']);

        $fields = collect((array) ($config['columns'] ?? []))
            ->filter(fn ($f): bool => is_array($f) && in_array($f['column'] ?? null, $columns, true))
            ->map(fn (array $f): array => ['column' => $f['column'], 'label' => ($f['label'] ?? null) ?: null, 'required' => (bool) ($f['required'] ?? false), 'mode' => ($f['mode'] ?? 'set') === 'add' ? 'add' : 'set'])
            ->keyBy('column')
            ->all();

        $image = config('chatai.images.enabled', true) ? ImageSource::valid($model, $config['image'] ?? null) : null;

        if ($fields === [] && ! $image) {
            return null;
        }

        $lookup = array_values(array_intersect((array) ($config['lookup'] ?? []), ResourceInspector::columns($model)));
        $unique = array_values(array_intersect((array) ($config['unique'] ?? []), array_keys($fields)));
        $noNegative = (bool) ($config['no_negative'] ?? true);

        if (! $creating && $lookup === []) {
            return null;
        }

        $properties = [];
        $required = [];

        if (! $creating) {
            $properties['record'] = ['type' => 'string', 'title' => ucfirst((string) $resource::getModelLabel()), 'description' => 'Which one: its '.implode(' or ', $lookup).'.'];
            $required[] = 'record';
        }

        foreach ($fields as $column => $field) {
            $schema = ResourceInspector::columnSchema($model, $column, $field['label']);

            if ($field['mode'] === 'add') {
                $schema = ['type' => 'integer', 'title' => $field['label'] ?: ucfirst(str_replace('_', ' ', $column)).' to add', 'description' => 'Amount to add; negative to subtract.'];
            }

            $properties[$column] = $schema;

            if ($field['required']) {
                $required[] = $column;
            }
        }

        if ($image) {
            $properties[self::IMAGE] = ['type' => 'string', 'format' => 'chatai-image', 'title' => __('chatai::chatai.actions.image'), 'description' => 'File id of an image the user attached in this conversation (shown as "file id" next to it).'];
        }

        return new ChatAiTool(
            name: $tool->name,
            description: $tool->description.($image ? ' It can also put an image the user attached onto the record.' : ''),
            inputSchema: ['type' => 'object', 'properties' => $properties, 'required' => $required],
            handler: function (array $args) use ($resource, $model, $creating, $fields, $lookup, $unique, $noNegative, $image): ToolResult {
                $values = array_intersect_key($args, $fields);
                $file = null;

                if ($image && filled($args[self::IMAGE] ?? null)) {
                    $file = self::attachment((string) $args[self::IMAGE]);

                    if (! $file) {
                        return ToolResult::error('That image was not found among the files the user attached.');
                    }
                }

                if ($creating) {
                    if (! $resource::canCreate()) {
                        return ToolResult::error('The user does not have permission to create these records.');
                    }

                    foreach ($unique as $column) {
                        if (filled($values[$column] ?? null) && $model->newQuery()->where($column, $values[$column])->exists()) {
                            return ToolResult::error("There is already a record with {$column} = {$values[$column]}.");
                        }
                    }

                    // Only the columns chosen on screen are written.
                    $record = $model->newInstance()->forceFill(array_filter($values, fn ($v): bool => $v !== null && $v !== ''));
                    $record->save();

                    if ($file && ($error = ImageSource::attach($record, $image, $file))) {
                        return ToolResult::data(['created' => $resource::getModelLabel(), 'id' => $record->getKey(), 'image_error' => $error]);
                    }

                    return ToolResult::data(['created' => $resource::getModelLabel(), 'id' => $record->getKey(), ...self::show($record, array_keys($fields)), ...($file ? [self::IMAGE => $file->name] : [])]);
                }

                $search = trim((string) ($args['record'] ?? ''));
                $matches = $resource::getEloquentQuery()->where(fn ($q) => collect($lookup)->each(fn ($c) => $q->orWhere($c, $search)))->limit(6)->get();

                if ($matches->isEmpty()) {
                    $matches = $resource::getEloquentQuery()->where(fn ($q) => collect($lookup)->each(fn ($c) => $q->orWhere($c, 'like', '%'.addcslashes($search, '%_').'%')))->limit(6)->get();
                }

                if ($matches->count() !== 1) {
                    $title = ResourceInspector::titleColumn($model);

                    return ToolResult::error($matches->isEmpty()
                        ? "No {$resource::getModelLabel()} matches \"{$search}\"."
                        : 'More than one matches: '.$matches->pluck($title)->implode(', ').'. Ask which one.');
                }

                /** @var Model $record */
                $record = $matches->first();

                if (! $resource::canEdit($record)) {
                    return ToolResult::error('The user does not have permission to change this record.');
                }

                $before = self::show($record, array_keys($values));

                // A gallery (media collection) gains the image; a column has its old one replaced.
                if ($file && ! str_starts_with($image, ImageSource::MEDIA)) {
                    $current = ImageSource::url($record, $image);
                    $before[self::IMAGE] = $current ? basename((string) parse_url($current, PHP_URL_PATH)) : null;
                }

                foreach ($values as $column => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    if ($fields[$column]['mode'] === 'add') {
                        $new = (float) $record->getAttribute($column) + (float) $value;

                        if ($noNegative && $new < 0) {
                            return ToolResult::error("{$column} would go below zero (it is {$record->getAttribute($column)}).");
                        }

                        $record->setAttribute($column, $new == (int) $new ? (int) $new : $new);
                    } else {
                        $record->setAttribute($column, $value);
                    }
                }

                $record->save();

                if ($file && ($error = ImageSource::attach($record, $image, $file))) {
                    return ToolResult::error($error);
                }

                $gallery = $file && str_starts_with($image, ImageSource::MEDIA);

                return ToolResult::data([
                    'record' => $record->getAttribute(ResourceInspector::titleColumn($model)),
                    ...($gallery ? [self::IMAGE => $file->name] : []),
                    'before' => $before,
                    'after' => self::show($record->refresh(), array_keys($values)) + ($file && ! $gallery ? [self::IMAGE => $file->name] : []),
                ]);
            },
            readOnly: false,
            title: $tool->title,
        );
    }

    /**
     * An image the current user attached (never someone else's file).
     */
    protected static function attachment(string $id): ?ConversationFile
    {
        $user = Filament::auth()->user() ?? auth()->user();

        if (! $user) {
            return null;
        }

        $file = ConversationFile::query()->whereKey($id)->where('kind', ConversationFile::KIND_UPLOAD)
            ->where('user_type', $user->getMorphClass())->where('user_id', $user->getKey())->first();

        return $file?->isImage() ? $file : null;
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<string, mixed>
     */
    protected static function show(Model $record, array $columns): array
    {
        return collect($columns)->mapWithKeys(function (string $column) use ($record): array {
            $value = $record->getAttribute($column);

            return [$column => $value instanceof BackedEnum ? $value->value : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)];
        })->all();
    }
}
