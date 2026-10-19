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
        return $context->screenFields() !== [] && (bool) config('chatai.screen.fill', true);
    }

    public static function make(ChatAiContext $context): ChatAiTool
    {
        $paths = array_keys($context->screenFields());

        return new ChatAiTool(
            name: self::NAME,
            description: 'Fill in or change fields of the form the user has open on screen. Use the field paths listed under "On screen now". '
                .'The values appear in the form for the user to review; nothing is saved until they press Save.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'fields' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'enum' => $paths],
                                'value' => ['type' => ['string', 'number', 'boolean']],
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
     * @return array<int, array{path: string, label: string, value: string|int|float|bool}>
     */
    public static function fields(ChatAiContext $context, array $arguments): array
    {
        $onScreen = $context->screenFields();
        $fields = [];

        foreach ((array) ($arguments['fields'] ?? []) as $field) {
            $path = is_array($field) ? (string) ($field['path'] ?? '') : '';
            $value = is_array($field) ? ($field['value'] ?? null) : null;

            if (isset($onScreen[$path]) && is_scalar($value)) {
                $fields[$path] = ['path' => $path, 'label' => (string) ($onScreen[$path]['label'] ?? $path), 'value' => is_string($value) ? mb_substr($value, 0, 5000) : $value];
            }
        }

        return array_values($fields);
    }
}
