<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Contracts\ToolProvider;
use Filament\ChatAi\Support\ChatAiContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Tools defined inline, for apps without laravel/mcp and for tests.
 */
class ArrayToolProvider implements ToolProvider
{
    /**
     * @param  array<int, ChatAiTool>  $tools
     */
    public function __construct(protected array $tools = []) {}

    public function tools(Authenticatable $user, ChatAiContext $context): array
    {
        return array_values(array_filter($this->tools, fn (ChatAiTool $tool): bool => $tool->readOnly));
    }
}
