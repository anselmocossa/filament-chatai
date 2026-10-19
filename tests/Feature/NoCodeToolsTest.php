<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\ToolAction;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Tests\Fixtures\Shop\Order;
use Filament\ChatAi\Tests\Fixtures\Shop\OrderItem;
use Filament\ChatAi\Tests\Fixtures\Shop\OrderResource;
use Filament\ChatAi\Tests\Fixtures\Shop\Product;
use Filament\ChatAi\Tests\Fixtures\Shop\ProductResource;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\Facades\Filament;

beforeEach(function () {
    ChatAiSettings::flush();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->user = User::create(['name' => 'Ana', 'email' => 'ana@nocode.test', 'password' => 'x']));

    $pillow = Product::create(['name' => 'Classic Pillow', 'sku' => 'P-1', 'qty' => 2, 'security_stock' => 8]);
    $mug = Product::create(['name' => 'Oak Mug', 'sku' => 'M-1', 'qty' => 30, 'security_stock' => 5]);

    $o1 = Order::create(['number' => 'OR-1', 'status' => 'new', 'total' => 100, 'created_at' => now()->startOfMonth()->addHour()]);
    $o2 = Order::create(['number' => 'OR-2', 'status' => 'shipped', 'total' => 50, 'created_at' => now()->startOfMonth()->addDay()]);
    Order::create(['number' => 'OR-3', 'status' => 'cancelled', 'total' => 999, 'created_at' => now()->startOfMonth()->addHours(2)]);
    Order::create(['number' => 'OR-0', 'status' => 'shipped', 'total' => 75, 'created_at' => now()->startOfMonth()->subDays(3)]);

    OrderItem::create(['shop_order_id' => $o1->id, 'shop_product_id' => $mug->id, 'qty' => 5, 'unit_price' => 10]);
    OrderItem::create(['shop_order_id' => $o2->id, 'shop_product_id' => $pillow->id, 'qty' => 2, 'unit_price' => 25]);
});

function noCode(string $type, array $config, string $name = 'nocode'): ChatAiTool
{
    CustomTool::query()->create(['name' => $name, 'title' => ucfirst($name), 'description' => 'Test tool', 'type' => $type, 'enabled' => true, 'config' => $config]);

    /** @var ChatAiPlugin $plugin */
    $plugin = Filament::getPanel('admin')->getPlugin('chatai');

    return collect($plugin->resolveTools()->tools(test()->user, new ChatAiContext(panelId: 'admin')))->firstWhere('name', $name);
}

it('lists records with a fixed column-vs-column condition that steps aside when searching', function () {
    $tool = noCode(CustomTool::TYPE_RESOURCE, [
        'resource' => ProductResource::class,
        'fields' => ['name', 'qty', 'security_stock'],
        'search' => ['name', 'sku'],
        'conditions' => [['column' => 'qty', 'operator' => '<', 'compare_column' => 'security_stock', 'unless_search' => true]],
    ]);

    expect($tool->description)->toContain('Already filtered: qty < security_stock')
        ->and(collect($tool->call([])->data['rows'])->pluck('name')->all())->toBe(['Classic Pillow'])
        ->and(collect($tool->call(['search' => 'M-1'])->data['rows'])->pluck('name')->all())->toBe(['Oak Mug']);
});

it('adds counts per value, totals of related records and their latest rows', function () {
    $data = noCode(CustomTool::TYPE_RESOURCE, [
        'resource' => OrderResource::class,
        'fields' => ['number'],
        'filters' => ['status'],
        'count_by' => 'status',
        'sort' => 'id', 'sort_dir' => 'asc',
        'totals' => [
            ['relation' => 'items', 'function' => 'sum', 'column' => 'qty', 'label' => 'units'],
            ['relation' => 'items', 'function' => 'count', 'label' => 'big_lines', 'where_column' => 'qty', 'where_operator' => '>', 'where_value' => 10],
        ],
        'related' => [['relation' => 'items', 'fields' => ['qty'], 'limit' => 3]],
    ])->call(['status' => 'new'])->data;

    expect($data['counts_by_status'])->toBe(['new' => 1])
        ->and($data['rows'][0])->toMatchArray(['number' => 'OR-1', 'units' => 5.0, 'big_lines' => 0.0])
        ->and($data['rows'][0]['latest_items'][0]['qty'])->toBe(5);
});

it('adds up a period, compares it with the previous one and breaks it down', function () {
    $data = noCode(CustomTool::TYPE_METRICS, [
        'resource' => OrderResource::class,
        'date_field' => 'created_at',
        'period' => 'month',
        'compare' => true,
        'metrics' => [['function' => 'sum', 'column' => 'total', 'label' => 'revenue'], ['function' => 'count', 'label' => 'orders']],
        'conditions' => [['column' => 'status', 'operator' => '!=', 'value' => 'cancelled']],
        'group_by' => 'day',
    ])->call([])->data;

    expect($data['totals'])->toBe(['revenue' => 150.0, 'orders' => 2.0])
        ->and($data['breakdown'])->toHaveCount(2);
});

it('adds up a relation of the records, grouped by a related record (best sellers)', function () {
    $data = noCode(CustomTool::TYPE_METRICS, [
        'resource' => OrderResource::class,
        'relation' => 'items',
        'date_field' => 'created_at',
        'metrics' => [['function' => 'sum', 'column' => 'qty', 'label' => 'units'], ['function' => 'sum_product', 'column' => 'qty', 'column2' => 'unit_price', 'label' => 'revenue']],
        'conditions' => [['column' => 'status', 'operator' => '!=', 'value' => 'cancelled']],
        'group_by' => 'rel:product',
    ])->call(['period' => 'month'])->data;

    expect($data['breakdown'][0])->toBe(['group' => 'Oak Mug', 'units' => 5.0, 'revenue' => 50.0])
        ->and($data['breakdown'][1]['group'])->toBe('Classic Pillow');
});

it('creates a record from the chosen fields and refuses duplicates', function () {
    $tool = noCode(CustomTool::TYPE_CREATE, [
        'resource' => ProductResource::class,
        'columns' => [['column' => 'name', 'required' => true], ['column' => 'sku', 'required' => true], ['column' => 'qty']],
        'unique' => ['sku'],
    ]);

    expect($tool->readOnly)->toBeFalse()
        ->and($tool->inputSchema['required'])->toBe(['name', 'sku'])
        ->and($tool->inputSchema['properties']['qty']['type'])->toBe('integer');

    expect($tool->call(['name' => 'Linen Sheet', 'sku' => 'L-1', 'qty' => 4])->isError())->toBeFalse()
        ->and(Product::query()->where('sku', 'L-1')->value('qty'))->toBe(4)
        ->and($tool->call(['name' => 'Again', 'sku' => 'L-1'])->error)->toContain('already');
});

it('finds a record, adds to a number without going below zero, and offers enum values', function () {
    $stock = noCode(CustomTool::TYPE_UPDATE, [
        'resource' => ProductResource::class,
        'lookup' => ['sku', 'name'],
        'columns' => [['column' => 'qty', 'mode' => 'add', 'required' => true]],
        'no_negative' => true,
    ], 'stock');

    $result = $stock->call(['record' => 'Classic Pillow', 'qty' => 3]);

    expect($result->data)->toBe(['record' => 'Classic Pillow', 'before' => ['qty' => 2], 'after' => ['qty' => 5]])
        ->and($stock->call(['record' => 'P-1', 'qty' => -10])->error)->toContain('below zero');

    $status = noCode(CustomTool::TYPE_UPDATE, [
        'resource' => OrderResource::class,
        'lookup' => ['number'],
        'columns' => [['column' => 'status', 'required' => true]],
    ], 'status');

    expect($status->inputSchema['properties']['status']['enum'])->toBe(['new', 'shipped', 'cancelled'])
        ->and($status->call(['record' => 'OR-1', 'status' => 'shipped'])->data['after'])->toBe(['status' => 'shipped']);
});

it('shows a change as before → after on the card', function () {
    $action = new ToolAction(['result' => ['data' => ['record' => 'Classic Pillow', 'before' => ['qty' => 2], 'after' => ['qty' => 5]]]]);

    expect($action->resultFields())->toBe([['label' => 'Record', 'value' => 'Classic Pillow'], ['label' => 'Qty', 'value' => '2 → 5']]);
});
