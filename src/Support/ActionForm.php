<?php

namespace Filament\ChatAi\Support;

use Filament\Facades\Filament;

/**
 * Turns a tool's input schema into the form shown on the confirmation card,
 * and checks what the user sends back before the tool runs.
 */
final class ActionForm
{
    public const MAX_FIELDS = 12;

    /**
     * One field per schema property, filled with what the model understood.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $values
     * @return array<int, array<string, mixed>>
     */
    public static function fields(array $schema, array $values): array
    {
        $required = (array) ($schema['required'] ?? []);
        $fields = [];

        foreach (array_slice((array) ($schema['properties'] ?? []), 0, self::MAX_FIELDS, true) as $key => $property) {
            $property = (array) $property;
            $type = self::type($property, (string) $key);
            $value = $values[$key] ?? null;

            $fields[] = array_filter([
                'key' => (string) $key,
                'label' => (string) ($property['title'] ?? str((string) $key)->replace(['_', '-'], ' ')->ucfirst()),
                'type' => $type,
                'required' => in_array($key, $required, true),
                'help' => isset($property['description']) ? str((string) $property['description'])->limit(140)->toString() : null,
                'options' => isset($property['enum']) ? array_map(fn ($v): array => ['value' => (string) $v, 'label' => (string) str((string) $v)->replace(['_', '-'], ' ')->ucfirst()], (array) $property['enum']) : null,
                'value' => $type === 'checkbox' ? (bool) $value : (is_scalar($value) ? (string) $value : ($value === null ? '' : (string) json_encode($value, JSON_UNESCAPED_UNICODE))),
                // An attached image is shown, not typed.
                'preview' => $type === 'image' && is_string($value) && preg_match('/^[0-9A-Za-z]{26}$/', $value) ? Filament::getCurrentPanel()?->route('chatai.files.download', ['file' => $value]) : null,
            ], fn ($v): bool => $v !== null);
        }

        return $fields;
    }

    /**
     * The values to run the tool with, or the errors to show on the form.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function validate(array $schema, array $input): array
    {
        $required = (array) ($schema['required'] ?? []);
        $values = [];
        $errors = [];

        foreach ((array) ($schema['properties'] ?? []) as $key => $property) {
            $property = (array) $property;
            $type = self::type($property, (string) $key);
            $raw = $input[$key] ?? null;
            $raw = is_string($raw) ? trim($raw) : $raw;

            if ($type === 'checkbox') {
                $values[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);

                continue;
            }

            if ($raw === null || $raw === '') {
                if (in_array($key, $required, true)) {
                    $errors[$key] = __('chatai::chatai.actions.required');
                }

                continue;
            }

            $ok = match (true) {
                isset($property['enum']) => in_array((string) $raw, array_map('strval', (array) $property['enum']), true),
                ($property['type'] ?? null) === 'integer' => preg_match('/^-?\d+$/', (string) $raw) === 1,
                ($property['type'] ?? null) === 'number' => is_numeric($raw),
                $type === 'email' => filter_var($raw, FILTER_VALIDATE_EMAIL) !== false,
                $type === 'image' => preg_match('/^[0-9A-Za-z]{26}$/', (string) $raw) === 1,
                $type === 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $raw) === 1 && strtotime((string) $raw) !== false,
                default => is_scalar($raw),
            };

            if (! $ok) {
                $errors[$key] = __('chatai::chatai.actions.invalid');

                continue;
            }

            $values[$key] = match ($property['type'] ?? null) {
                'integer' => (int) $raw,
                'number' => (float) $raw,
                default => (string) $raw,
            };
        }

        return [$values, $errors];
    }

    /**
     * @param  array<string, mixed>  $property
     */
    protected static function type(array $property, string $key): string
    {
        $format = $property['format'] ?? null;

        return match (true) {
            $format === 'chatai-image' => 'image',
            isset($property['enum']) => 'select',
            ($property['type'] ?? null) === 'boolean' => 'checkbox',
            in_array($property['type'] ?? null, ['integer', 'number'], true) => 'number',
            $format === 'email' || $key === 'email' => 'email',
            $format === 'date' || str_ends_with($key, '_date') || in_array($key, ['date', 'birthday'], true) => 'date',
            (int) ($property['maxLength'] ?? 0) > 200 || in_array($key, ['description', 'notes', 'note', 'message', 'body'], true) => 'textarea',
            default => 'text',
        };
    }
}
