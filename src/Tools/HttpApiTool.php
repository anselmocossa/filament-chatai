<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Settings\ChatAiSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A GET call to an existing API, configured on screen. Only hosts on the
 * admin's allow list can be called.
 */
final class HttpApiTool
{
    public static function make(CustomTool $tool): ?ChatAiTool
    {
        $url = (string) ($tool->config['url'] ?? '');

        if (! self::allowed($url)) {
            return null;
        }

        $params = collect((array) ($tool->config['params'] ?? []))
            ->filter(fn ($p): bool => is_array($p) && preg_match('/^[A-Za-z0-9_]+$/', (string) ($p['name'] ?? '')) === 1)
            ->values();

        $schema = self::customSchema($tool->config['schema'] ?? null) ?? self::schemaFromParams($params->all());
        $pathParams = self::pathParams($url, $params->all());
        // Only arguments the schema declares are sent; anything else the model invents is dropped.
        $known = array_keys((array) ($schema['properties'] ?? []));

        return new ChatAiTool(
            name: $tool->name,
            description: $tool->description,
            inputSchema: $schema,
            handler: function (array $args) use ($tool, $url, $pathParams, $known): ToolResult {
                $query = [];

                foreach ($known as $name) {
                    $value = $args[$name] ?? null;

                    if (in_array($name, $pathParams, true)) {
                        if (! is_scalar($value) || (string) $value === '') {
                            return ToolResult::error("Missing {$name}.");
                        }

                        $url = str_replace('{'.$name.'}', rawurlencode((string) $value), $url);
                    } elseif (is_scalar($value) && (string) $value !== '') {
                        $query[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                    }
                }

                $request = Http::timeout((int) config('chatai.http_tools.timeout', 10))->acceptJson();
                $secret = (string) ($tool->config['secret'] ?? '');

                $request = match ($tool->config['auth'] ?? 'none') {
                    'bearer' => $request->withToken($secret),
                    'header' => $request->withHeaders([(string) ($tool->config['header_name'] ?? 'X-Api-Key') => $secret]),
                    default => $request,
                };

                try {
                    $response = $request->get($url, $query);
                } catch (ConnectionException) {
                    return ToolResult::error('The API did not answer.');
                }

                if ($response->failed()) {
                    return ToolResult::error("The API answered with status {$response->status()}.");
                }

                return is_array($json = $response->json())
                    ? ToolResult::data($json)
                    : ToolResult::text(Str::limit($response->body(), 4000));
            },
            readOnly: true,
            title: $tool->title,
        );
    }

    /**
     * {placeholders} in the URL, plus parameters marked as path.
     *
     * @param  array<int, array<string, mixed>>  $params
     * @return array<int, string>
     */
    public static function pathParams(string $url, array $params): array
    {
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $url, $matches);

        return array_values(array_unique([
            ...$matches[1],
            ...collect($params)->filter(fn ($p): bool => is_array($p) && ($p['in'] ?? null) === 'path')->pluck('name')->all(),
        ]));
    }

    /**
     * The JSON Schema the parameters list stands for.
     *
     * @param  array<int, array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    public static function schemaFromParams(array $params): array
    {
        $params = collect($params)->filter(fn ($p): bool => is_array($p) && preg_match('/^[A-Za-z0-9_]+$/', (string) ($p['name'] ?? '')) === 1);

        return array_filter([
            'type' => 'object',
            'properties' => $params->mapWithKeys(fn (array $p): array => [$p['name'] => ['type' => 'string', 'description' => (string) (($p['description'] ?? '') ?: $p['name'])]])->all(),
            'required' => $params->filter(fn (array $p): bool => (bool) ($p['required'] ?? false))->pluck('name')->values()->all(),
        ], fn ($v) => $v !== []) + ['type' => 'object'];
    }

    /**
     * A hand-written schema (JSON text or array), if it is a usable object schema.
     *
     * @return array<string, mixed>|null
     */
    public static function customSchema(mixed $schema): ?array
    {
        if (is_string($schema)) {
            $schema = trim($schema) === '' ? null : json_decode($schema, true);
        }

        return is_array($schema) && ($schema['type'] ?? null) === 'object' && is_array($schema['properties'] ?? null)
            ? $schema
            : null;
    }

    /**
     * http(s) only, and the host must be on the allow list (no internal addresses by accident).
     */
    public static function allowed(string $url): bool
    {
        ChatAiSettings::apply(); // the allow list is set on the settings page

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && $host !== ''
            && in_array($host, array_map('strtolower', (array) config('chatai.http_tools.allowed_hosts', [])), true);
    }
}
