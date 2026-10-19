<?php

namespace Filament\ChatAi\Contracts;

use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Tools\ChatAiTool;
use Illuminate\Contracts\Auth\Authenticatable;

interface ToolProvider
{
    /**
     * The tools this user may call in this context.
     *
     * @return array<int, ChatAiTool>
     */
    public function tools(Authenticatable $user, ChatAiContext $context): array;
}
