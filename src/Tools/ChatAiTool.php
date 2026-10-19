<?php

namespace Filament\ChatAi\Tools;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ChatAiTool
{
    /**
     * @param  array<string, mixed>  $inputSchema  JSON Schema of the arguments
     * @param  Closure(array<string, mixed>): ToolResult  $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly Closure $handler,
        public readonly bool $readOnly = false,
        public readonly ?string $title = null,
    ) {}

    /**
     * Run the tool. Never throws: a failure comes back as an error result the
     * model can explain, so one broken tool does not end the conversation.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function call(array $arguments): ToolResult
    {
        try {
            return ($this->handler)($arguments);
        } catch (AuthorizationException) {
            return ToolResult::error('The user does not have permission to do this.');
        } catch (Throwable $e) {
            Log::warning("[chatai] tool {$this->name} failed: {$e->getMessage()}", ['exception' => $e]);

            return ToolResult::error('The tool failed: '.$e->getMessage());
        }
    }

    public function label(): string
    {
        return $this->title ?? str($this->name)->replace(['-', '_'], ' ')->ucfirst()->toString();
    }

    /**
     * The OpenAI-compatible "function" definition.
     *
     * @return array<string, mixed>
     */
    public function toFunctionDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->inputSchema,
            ],
        ];
    }
}
