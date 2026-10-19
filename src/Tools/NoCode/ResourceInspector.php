<?php

namespace Filament\ChatAi\Tools\NoCode;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * What the tool editor can offer for a resource: its columns, its relations
 * (found from the model's typed relation methods) and the options of enum casts.
 */
final class ResourceInspector
{
    /**
     * @return class-string<resource>|null only resources of the current panel
     */
    public static function resource(mixed $resource): ?string
    {
        return is_string($resource) && in_array($resource, Filament::getCurrentPanel()?->getResources() ?? [], true) ? $resource : null;
    }

    public static function model(?string $resource): ?Model
    {
        if (! is_string($resource) || ! class_exists($resource) || ! is_subclass_of($resource, Resource::class)) {
            return null;
        }

        $model = $resource::getModel();

        return new $model;
    }

    /**
     * Real columns of the model's table, never the model's $hidden ones.
     *
     * @return array<int, string>
     */
    public static function columns(?Model $model): array
    {
        if (! $model) {
            return [];
        }

        return array_values(array_diff(Schema::getColumnListing($model->getTable()), $model->getHidden()));
    }

    /**
     * @return array<string, string>
     */
    public static function columnOptions(?Model $model): array
    {
        $columns = self::columns($model);

        return array_combine($columns, $columns) ?: [];
    }

    /**
     * Relations of the given kinds, by name.
     *
     * @param  array<int, class-string<Relation>>  $kinds
     * @return array<string, Relation>
     */
    public static function relations(?Model $model, array $kinds = [BelongsTo::class, HasMany::class]): array
    {
        if (! $model) {
            return [];
        }

        $found = [];

        foreach ((new \ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();

            if ($method->getNumberOfParameters() > 0 || $method->isStatic() || ! $type instanceof ReflectionNamedType || $method->class === Model::class) {
                continue;
            }

            foreach ($kinds as $kind) {
                if (is_a($type->getName(), $kind, true)) {
                    try {
                        $found[$method->getName()] = $model->{$method->getName()}();
                    } catch (Throwable) {
                        // A relation that cannot be built without context is skipped.
                    }

                    break;
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @return array<string, string>
     */
    public static function relationOptions(?Model $model, string $kind): array
    {
        return collect(self::relations($model, [$kind]))->mapWithKeys(fn (Relation $r, string $name): array => [$name => $name])->all();
    }

    /**
     * Columns of the records a belongsTo points at, as "relation.column".
     *
     * @return array<string, string>
     */
    public static function relatedColumnOptions(?Model $model): array
    {
        $options = [];

        foreach (self::relations($model, [BelongsTo::class]) as $name => $relation) {
            foreach (self::columns($relation->getRelated()) as $column) {
                $options["{$name}.{$column}"] = "{$name} › {$column}";
            }
        }

        return $options;
    }

    /**
     * The column that names a record (name, title, number…).
     */
    public static function titleColumn(Model $model): string
    {
        $columns = self::columns($model);

        foreach (['name', 'title', 'number', 'label', 'code', 'email'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return $model->getKeyName();
    }

    /**
     * Options of a column cast to a backed enum.
     *
     * @return array<int, string>|null
     */
    public static function enumValues(Model $model, string $column): ?array
    {
        $cast = $model->getCasts()[$column] ?? null;

        return is_string($cast) && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)
            ? array_map(fn (BackedEnum $case) => (string) $case->value, $cast::cases())
            : null;
    }

    /**
     * JSON Schema of a column, for the model to fill in.
     *
     * @return array<string, mixed>
     */
    public static function columnSchema(Model $model, string $column, ?string $title = null): array
    {
        $type = strtolower((string) rescue(fn () => Schema::getColumnType($model->getTable(), $column), 'string', false));
        $schema = ['title' => $title ?: (string) str($column)->replace('_', ' ')->ucfirst()];

        if ($enum = self::enumValues($model, $column)) {
            return [...$schema, 'type' => 'string', 'enum' => $enum];
        }

        return match (true) {
            str_contains($type, 'int') => [...$schema, 'type' => 'integer'],
            str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'numeric') => [...$schema, 'type' => 'number'],
            str_contains($type, 'bool') || str_contains($type, 'tinyint(1)') => [...$schema, 'type' => 'boolean'],
            $type === 'date' => [...$schema, 'type' => 'string', 'format' => 'date'],
            str_contains($column, 'email') => [...$schema, 'type' => 'string', 'format' => 'email'],
            str_contains($type, 'text') => [...$schema, 'type' => 'string', 'maxLength' => 5000],
            default => [...$schema, 'type' => 'string'],
        };
    }
}
