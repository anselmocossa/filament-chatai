<?php

namespace Filament\ChatAi\Tests\Fixtures\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create-invoice')]
#[Description('Creates an invoice. Writes data.')]
class CreateInvoiceTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('created');
    }
}
