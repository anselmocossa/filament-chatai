<?php

use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Tests\Fixtures\Shop\Order;
use Filament\ChatAi\Tests\Fixtures\Shop\OrderItem;
use Filament\ChatAi\Tests\Fixtures\Shop\OrderItemResource;
use Filament\ChatAi\Tests\Fixtures\Shop\OrderResource;
use Filament\ChatAi\Tests\Fixtures\Shop\Product;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\QueryDataTool;
use Filament\Facades\Filament;

beforeEach(function () {
    ChatAiSettings::flush();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->user = User::create(['name' => 'Ana', 'email' => 'ana@query.test', 'password' => 'x']));

    $pillow = Product::create(['name' => 'Classic Pillow', 'sku' => 'P-1']);
    $mug = Product::create(['name' => 'Oak Mug', 'sku' => 'M-1']);
    Product::create(['name' => 'Oak Table', 'sku' => 'T-1']);
    $order = Order::create(['number' => 'OR-1', 'status' => 'new', 'total' => 100]);

    OrderItem::create(['shop_order_id' => $order->id, 'shop_product_id' => $mug->id, 'qty' => 5, 'unit_price' => 10]);
    OrderItem::create(['shop_order_id' => $order->id, 'shop_product_id' => $mug->id, 'qty' => 3, 'unit_price' => 10]);
    OrderItem::create(['shop_order_id' => $order->id, 'shop_product_id' => $pillow->id, 'qty' => 2, 'unit_price' => 25]);
});

function queryData(): ?ChatAiTool
{
    return collect(Filament::getPanel('admin')->getPlugin('chatai')->resolveTools()->tools(test()->user, new ChatAiContext(panelId: 'admin')))->firstWhere('name', QueryDataTool::NAME);
}

it('adds up the records of one related record, picked by name, without a tool of its own', function () {
    $result = queryData()->call([
        'resource' => OrderItemResource::class, 'kind' => 'totals',
        'related' => ['product' => 'Oak Mug'],
        'metrics' => [['function' => 'sum', 'column' => 'qty', 'label' => 'units'], ['function' => 'count', 'label' => 'lines']],
    ]);

    expect($result->data['totals'])->toBe(['units' => 8.0, 'lines' => 2.0]);
});

it('lists filtered rows and asks which one when a name matches several', function () {
    $rows = queryData()->call(['resource' => OrderItemResource::class, 'kind' => 'list', 'where' => [['column' => 'qty', 'operator' => '>=', 'value' => 3]], 'fields' => ['qty']])->data['rows'];

    expect(collect($rows)->pluck('qty')->sort()->values()->all())->toBe([3, 5]);

    $ambiguous = queryData()->call(['resource' => OrderItemResource::class, 'kind' => 'totals', 'related' => ['product' => 'Oak']])->data;
    expect($ambiguous['candidates'])->toBe(['Oak Mug', 'Oak Table']);
});

it('refuses resources outside the panel and can be switched off', function () {
    expect(queryData()->call(['resource' => 'App\\Nope', 'kind' => 'list'])->isError())->toBeTrue();

    ChatAiSettings::save(['query_data.enabled' => false]);
    expect(queryData())->toBeNull();
});

it('keeps the query for paging the table', function () {
    foreach (range(1, 60) as $i) {
        Order::create(['number' => "N-{$i}", 'status' => 'new', 'total' => $i]);
    }

    $data = queryData()->call(['resource' => OrderResource::class, 'kind' => 'list', 'where' => [['column' => 'status', 'operator' => '=', 'value' => 'new']]])->data;

    expect($data['pages'])->toBe(2)
        ->and($data['source']['tool'])->toBe(QueryDataTool::NAME)
        ->and($data['source']['args']['where'][0]['column'])->toBe('status');
});
