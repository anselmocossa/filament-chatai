<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Models\CustomTool;
use Illuminate\Support\Str;

/**
 * A tool shown as code: the definition the model receives, the API call as
 * cURL, and a laravel/mcp class to move it into the codebase. Keys never
 * appear: a $API_KEY placeholder stands in.
 */
final class ToolCode
{
    /**
     * @return array<int, array{id: string, label: string, code: string}>
     */
    public static function blocks(?ChatAiTool $tool, array $data, string $mode): array
    {
        if ($tool === null) {
            return [];
        }

        $blocks = [['id' => 'json', 'label' => 'JSON', 'code' => self::definition($tool)]];

        if ($mode !== ToolDraft::MODE_CODE && ($data['type'] ?? null) === CustomTool::TYPE_HTTP) {
            $blocks[] = ['id' => 'curl', 'label' => 'cURL', 'code' => self::curl($tool, (array) ($data['config'] ?? []))];
        }

        // The PHP export covers the list and API types; the others are JSON only.
        if ($mode !== ToolDraft::MODE_CODE && in_array($data['type'] ?? null, [CustomTool::TYPE_RESOURCE, CustomTool::TYPE_HTTP], true)) {
            $blocks[] = ['id' => 'php', 'label' => 'PHP (laravel/mcp)', 'code' => self::php($tool, $data)];
        }

        return $blocks;
    }

    public static function definition(ChatAiTool $tool): string
    {
        return (string) json_encode([
            'name' => $tool->name,
            'description' => $tool->description,
            'input_schema' => $tool->inputSchema,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function curl(ChatAiTool $tool, array $config): string
    {
        $url = (string) ($config['url'] ?? '');
        $path = HttpApiTool::pathParams($url, (array) ($config['params'] ?? []));
        $lines = [];

        foreach (array_keys((array) ($tool->inputSchema['properties'] ?? [])) as $name) {
            if (in_array($name, $path, true)) {
                $url = str_replace('{'.$name.'}', '<'.$name.'>', $url);
            } else {
                $lines[] = "  --data-urlencode \"{$name}=<{$name}>\"";
            }
        }

        $auth = match ($config['auth'] ?? 'none') {
            'bearer' => ['  -H "Authorization: Bearer $API_KEY"'],
            'header' => ['  -H "'.($config['header_name'] ?? 'X-Api-Key').': $API_KEY"'],
            default => [],
        };

        return implode(" \\\n", ['curl -G "'.$url.'"', '  -H "Accept: application/json"', ...$auth, ...$lines]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function php(ChatAiTool $tool, array $data): string
    {
        $class = Str::studly($tool->name).'Tool';
        $description = addslashes($tool->description);
        $required = (array) ($tool->inputSchema['required'] ?? []);
        $fields = collect((array) ($tool->inputSchema['properties'] ?? []))
            ->map(function (array $property, string $name) use ($required): string {
                $type = in_array($property['type'] ?? 'string', ['integer', 'number', 'boolean', 'array', 'object'], true) ? $property['type'] : 'string';
                $line = "            '{$name}' => \$schema->{$type}()";
                $line .= filled($property['description'] ?? null) ? "->description('".addslashes((string) $property['description'])."')" : '';

                return $line.(in_array($name, $required, true) ? '->required()' : '').',';
            })
            ->implode("\n");

        $config = (array) ($data['config'] ?? []);
        $body = ($data['type'] ?? null) === CustomTool::TYPE_HTTP
            ? self::phpHttpBody($config)
            : self::phpResourceBody($config);

        return <<<PHP
        <?php

        namespace App\\Mcp\\Tools;

        use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
        use Laravel\\Mcp\\Request;
        use Laravel\\Mcp\\Response;
        use Laravel\\Mcp\\Server\\Tool;
        use Laravel\\Mcp\\Server\\Tools\\Annotations\\IsReadOnly;

        #[IsReadOnly]
        class {$class} extends Tool
        {
            protected string \$name = '{$tool->name}';

            protected string \$description = '{$description}';

            public function schema(JsonSchema \$schema): array
            {
                return [
        {$fields}
                ];
            }

            public function handle(Request \$request): Response
            {
        {$body}
            }
        }
        PHP;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected static function phpHttpBody(array $config): string
    {
        $url = addslashes((string) ($config['url'] ?? ''));
        $auth = match ($config['auth'] ?? 'none') {
            'bearer' => "\n            ->withToken(config('services.my_api.key'))",
            'header' => "\n            ->withHeaders(['".addslashes((string) ($config['header_name'] ?? 'X-Api-Key'))."' => config('services.my_api.key')])",
            default => '',
        };

        return <<<PHP
                \$url = preg_replace_callback('/\\{(\\w+)\\}/', fn (\$m) => rawurlencode((string) \$request->get(\$m[1])), '{$url}');

                \$response = \\Illuminate\\Support\\Facades\\Http::acceptJson(){$auth}
                    ->get(\$url, \$request->except(/* path parameters */ []));

                return \$response->failed()
                    ? Response::error("The API answered with status {\$response->status()}.")
                    : Response::structured(\$response->json());
        PHP;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected static function phpResourceBody(array $config): string
    {
        $resource = '\\'.ltrim((string) ($config['resource'] ?? 'App\\Filament\\Resources\\MyResource'), '\\');
        $fields = "'".implode("', '", (array) ($config['fields'] ?? []))."'";
        $filters = collect((array) ($config['filters'] ?? []))
            ->map(fn (string $f): string => "            ->when(\$request->get('{$f}'), fn (\$q, \$v) => \$q->where('{$f}', 'like', \"%{\$v}%\"))")
            ->implode("\n");
        $limit = (int) ($config['limit'] ?? 20);

        return <<<PHP
                abort_unless({$resource}::canViewAny(), 403);

                \$rows = {$resource}::getEloquentQuery()
        {$filters}
                    ->limit({$limit})
                    ->get([{$fields}]);

                return Response::structured(['rows' => \$rows->toArray()]);
        PHP;
    }
}
