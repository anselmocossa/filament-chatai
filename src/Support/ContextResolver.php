<?php

namespace Filament\ChatAi\Support;

use Illuminate\Routing\Route;

/**
 * Works out what is on screen (resource + record) from the current route, so
 * the assistant can resolve "this". Rendered into the page on every request;
 * the browser adds the URL and title when the question is sent.
 */
class ContextResolver
{
    /**
     * @return array<string, string>
     */
    public static function current(): array
    {
        $route = request()->route();

        if (! $route instanceof Route) {
            return [];
        }

        return static::fromRoute($route->getControllerClass(), $route->parameters());
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, string>
     */
    public static function fromRoute(?string $controller, array $parameters): array
    {
        $context = [];

        if ($controller && method_exists($controller, 'getResource')) {
            $resource = $controller::getResource();
            $context['resource'] = method_exists($resource, 'getModelLabel')
                ? (string) $resource::getModelLabel()
                : class_basename($resource);
        }

        $record = $parameters['record'] ?? null;

        if (is_object($record) && method_exists($record, 'getKey')) {
            $record = $record->getKey();
        }

        if (is_scalar($record) && (string) $record !== '') {
            $context['record_id'] = (string) $record;
        }

        return $context;
    }
}
