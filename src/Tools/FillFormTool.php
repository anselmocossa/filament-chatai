<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Support\ChatAiContext;

/**
 * Fills in the form the user has open (an edit or create page). The
 * orchestrator intercepts the call and the browser writes the values into the
 * page's fields; nothing is saved — the user reviews and presses Save.
 */
final class FillFormTool
{
    public const NAME = 'fill_form';

    public static function available(ChatAiContext $context): bool
    {
        return $context->fillableFields() !== [] && (bool) config('chatai.screen.fill', true);
    }

    public static function make(ChatAiContext $context): ChatAiTool
    {
        $paths = array_keys($context->fillableFields());

        return new ChatAiTool(
            name: self::NAME,
            description: 'Fill in or change fields of the form the user has open on screen. Use the field paths listed under "On screen now". '
                .'Fields of every step of a wizard (or every tab) can be filled in one call; the user goes through the steps and presses the final button. '
                .'The values appear in the form for the user to review; nothing is saved until they press Save / Create.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'fields' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'enum' => $paths],
                                'value' => ['type' => ['string', 'number', 'boolean', 'array'], 'items' => ['type' => 'string'], 'description' => 'For choices use the value before "=" in the options; for checkbox lists and multi-selects an array of values.'],
                            ],
                            'required' => ['path', 'value'],
                        ],
                    ],
                ],
                'required' => ['fields'],
            ],
            handler: fn (array $arguments): ToolResult => ToolResult::text('Filled in.'),
            readOnly: true,
            title: 'Fill in the form',
        );
    }

    /**
     * Only fields that are on screen, with plain values.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<int, array{path: string, label: string, value: string|int|float|bool|array<int, string>}>
     */
    public static function fields(ChatAiContext $context, array $arguments): array
    {
        $onScreen = $context->fillableFields();
        $fields = [];

        foreach ((array) ($arguments['fields'] ?? []) as $field) {
            $path = is_array($field) ? (string) ($field['path'] ?? '') : '';
            $value = is_array($field) ? ($field['value'] ?? null) : null;

            if (is_array($value)) {
                $value = array_values(array_map('strval', array_filter($value, 'is_scalar')));

                // A list only where the field takes several values; a single choice (e.g. a
                // list filter) gets the first one, a text field the values joined.
                $type = $onScreen[$path]['type'] ?? null;

                if (isset($onScreen[$path]) && ! in_array($type, ['checkbox_list', 'multi_select'], true) && ! is_array($onScreen[$path]['value'] ?? null)) {
                    $value = filled($onScreen[$path]['options'] ?? null) || in_array($type, ['select', 'radio'], true) ? ($value[0] ?? '') : implode(', ', $value);
                }
            }

            // On/off fields get a real true/false (models sometimes send 1 or "yes").
            if (isset($onScreen[$path]) && in_array($onScreen[$path]['type'] ?? null, ['toggle', 'checkbox'], true) && is_scalar($value)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }

            if (isset($onScreen[$path]) && (is_scalar($value) || is_array($value))) {
                $fields[$path] = array_filter([
                    'path' => $path,
                    'label' => (string) ($onScreen[$path]['label'] ?? $path),
                    'value' => is_string($value) ? mb_substr($value, 0, 5000) : $value,
                    'shown' => self::shown($onScreen[$path], $value),
                ], fn ($v) => $v !== null);
            }
        }

        return array_values($fields);
    }

    /**
     * For a choice, the name the user reads ("Finance") instead of its value ("2").
     *
     * @param  array<string, mixed>  $field
     */
    protected static function shown(array $field, mixed $value): ?string
    {
        $names = collect((array) ($field['options'] ?? []))
            ->filter(fn ($o) => str_contains((string) $o, ' = '))
            ->mapWithKeys(function ($o): array {
                [$key, $name] = explode(' = ', (string) $o, 2);

                return [$key => $name];
            });

        if ($names->isEmpty()) {
            return null;
        }

        return collect((array) $value)->map(fn ($v) => $names[(string) $v] ?? (string) $v)->implode(', ');
    }
}
