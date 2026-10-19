<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Contracts\ToolProvider;
use Filament\ChatAi\Support\ChatAiContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Exposes existing laravel/mcp tools to the assistant without rewriting them.
 * Uses Tool::toArray() — the same shape the MCP server publishes — so it
 * follows whatever laravel/mcp version the app has.
 */
class LaravelMcpToolProvider implements ToolProvider
{
    /**
     * @param  array<int, class-string<Tool>|Tool>  $tools
     */
    public function __construct(
        protected array $tools,
        protected bool $readOnlyOnly = false,
    ) {}

    public function tools(Authenticatable $user, ChatAiContext $context): array
    {
        $exposed = [];

        foreach ($this->tools as $tool) {
            $instance = is_string($tool) ? app($tool) : $tool;
            $definition = $instance->toArray();
            $readOnly = (bool) (((array) ($definition['annotations'] ?? []))['readOnlyHint'] ?? false);

            if ($this->readOnlyOnly && ! $readOnly) {
                Log::info("[chatai] tool {$definition['name']} left out: this provider only takes read-only tools");

                continue;
            }

            $exposed[] = new ChatAiTool(
                name: $definition['name'],
                description: (string) ($definition['description'] ?? ''),
                inputSchema: $this->normalizeSchema($definition['inputSchema'] ?? []),
                handler: fn (array $arguments): ToolResult => $this->run($instance, $arguments),
                readOnly: $readOnly,
                title: $definition['title'] ?? null,
            );
        }

        return $exposed;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function run(Tool $tool, array $arguments): ToolResult
    {
        /** @var Response|ResponseFactory $response */
        $response = app()->call([$tool, 'handle'], ['request' => new Request($arguments)]);

        $responses = $response instanceof ResponseFactory ? $response->responses()->all() : [$response];
        $text = collect($responses)->map(fn (Response $r): string => (string) $r->content())->implode("\n");

        if (collect($responses)->contains(fn (Response $r): bool => $r->isError())) {
            return ToolResult::error($text);
        }

        if ($response instanceof ResponseFactory && ($structured = $response->getStructuredContent()) !== null) {
            return ToolResult::data($structured);
        }

        return ToolResult::text($text);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function normalizeSchema(array $schema): array
    {
        // laravel/mcp casts empty properties to an object for JSON; keep arrays.
        $schema = json_decode(json_encode($schema), true) ?: [];
        $schema['type'] ??= 'object';
        $schema['properties'] ??= [];

        return $schema;
    }
}
