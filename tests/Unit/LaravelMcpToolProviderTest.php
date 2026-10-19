<?php

use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Tests\Fixtures\Tools\CreateInvoiceTool;
use Filament\ChatAi\Tests\Fixtures\Tools\ForbiddenTool;
use Filament\ChatAi\Tests\Fixtures\Tools\SalesStatsTool;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\LaravelMcpToolProvider;
use Illuminate\Support\Facades\Log;

function mcpTools(array $classes): array
{
    return (new LaravelMcpToolProvider($classes))->tools(new User, new ChatAiContext(panelId: 'admin'));
}

it('exposes a read-only MCP tool with its name, description and input schema', function () {
    [$tool] = mcpTools([SalesStatsTool::class]);

    expect($tool->name)->toBe('sales-stats')
        ->and($tool->title)->toBe('Sales stats')
        ->and($tool->description)->toBe('Total sales for a period.')
        ->and($tool->readOnly)->toBeTrue()
        ->and($tool->inputSchema['type'])->toBe('object')
        ->and($tool->inputSchema['properties'])->toHaveKey('period')
        ->and($tool->inputSchema['required'])->toBe(['period']);
});

it('refuses tools that are not read-only and logs a warning', function () {
    Log::spy();

    $tools = mcpTools([SalesStatsTool::class, CreateInvoiceTool::class]);

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->name)->toBe('sales-stats');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'create-invoice'))->once();
});

it('returns the structured content of the tool', function () {
    [$tool] = mcpTools([SalesStatsTool::class]);

    $result = $tool->call(['period' => 'month']);

    expect($result->isError())->toBeFalse()
        ->and($result->data)->toBe(['period' => 'month', 'total' => 45300, 'orders' => 12]);
});

it('turns an authorization failure into a permission error result', function () {
    [$tool] = mcpTools([ForbiddenTool::class]);

    $result = $tool->call([]);

    expect($result->isError())->toBeTrue()
        ->and($result->error)->toContain('permission');
});
