<?php

namespace Filament\ChatAi\Support;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Throwable;

/** Validates the published contract without coercing arguments or running a tool. */
final class ToolArguments
{
    public static function error(array $schema, array $arguments): ?string
    {
        try {
            $validator = new CompliantValidator;
            $validator->setMaxErrors(5);
            // No URI resolver is registered: schemas cannot fetch remote resources.
            $result = $validator->validate(self::data($arguments, $schema, true), self::schema($schema));
            if ($result->isValid()) {
                return null;
            }
            $formatter = new ErrorFormatter;
            $errors = $formatter->format($result->error(), false, function (ValidationError $error) use ($formatter): string {
                $message = $formatter->formatErrorMessage($error);
                if ($error->keyword() === 'enum') {
                    $message .= ' Allowed: '.json_encode($error->schema()->info()->data()->enum ?? [], JSON_UNESCAPED_UNICODE);
                }

                return $message;
            });

            return 'Invalid arguments: '.mb_substr(json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1800).'. Correct the arguments before retrying.';
        } catch (Throwable) {
            return 'The tool input schema could not be validated. No tool was run. Ask the administrator to check its schema.';
        }
    }

    private static function schema(array|bool $schema): object|bool
    {
        if (is_bool($schema)) {
            return $schema;
        }
        foreach (['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'] as $map) {
            if (isset($schema[$map]) && is_array($schema[$map])) {
                $schema[$map] = (object) array_map(self::schema(...), $schema[$map]);
            }
        }
        foreach (['items', 'additionalProperties', 'contains', 'not', 'if', 'then', 'else', 'propertyNames', 'unevaluatedProperties', 'unevaluatedItems'] as $key) {
            if (isset($schema[$key]) && (is_array($schema[$key]) || is_bool($schema[$key]))) {
                $schema[$key] = $key === 'items' && is_array($schema[$key]) && array_is_list($schema[$key]) && $schema[$key] !== []
                    ? array_map(self::schema(...), $schema[$key]) : self::schema($schema[$key]);
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                $schema[$key] = array_map(self::schema(...), $schema[$key]);
            }
        }

        return json_decode(json_encode((object) $schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private static function data(mixed $data, array $schema, bool $root = false): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        $object = $root || ! array_is_list($data) || ($data === [] && in_array('object', (array) ($schema['type'] ?? []), true));
        foreach ($data as $key => $value) {
            $child = $object ? ($schema['properties'][$key] ?? []) : ($schema['items'] ?? []);
            $data[$key] = self::data($value, is_array($child) ? $child : []);
        }

        return $object ? (object) $data : $data;
    }
}
