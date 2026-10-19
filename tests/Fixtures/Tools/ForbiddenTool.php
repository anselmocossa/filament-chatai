<?php

namespace Filament\ChatAi\Tests\Fixtures\Tools;

use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('stock')]
#[IsReadOnly]
#[Description('Stock levels.')]
class ForbiddenTool extends Tool
{
    public function handle(Request $request): Response
    {
        throw new AuthorizationException('This action is unauthorized.');
    }
}
