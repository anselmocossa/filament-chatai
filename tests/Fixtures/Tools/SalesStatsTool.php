<?php

namespace Filament\ChatAi\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('sales-stats')]
#[Title('Sales stats')]
#[IsReadOnly]
#[Description('Total sales for a period.')]
class SalesStatsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->description('month or year')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return Response::structured([
            'period' => $request->get('period'),
            'total' => 45300,
            'orders' => 12,
        ]);
    }
}
