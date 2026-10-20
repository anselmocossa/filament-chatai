<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Support\Countries;
use Filament\ChatAi\Tests\Fixtures\Shop\Address;
use Filament\ChatAi\Tests\Fixtures\Shop\Order;
use Filament\ChatAi\Tests\Fixtures\Shop\OrderResource;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Ui\ComponentValidator;
use Filament\Facades\Filament;

beforeEach(function () {
    ChatAiSettings::flush();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->user = User::create(['name' => 'Ana', 'email' => 'ana@maps.test', 'password' => 'x']));
    $this->validator = new ComponentValidator;
});

it('places countries from their code and keeps only real coordinates', function () {
    $map = $this->validator->validate(['type' => 'map', 'title' => 'Orders', 'unit' => 'orders', 'markers' => [
        ['country' => 'mz', 'value' => 12, 'prompt' => 'Orders from Mozambique', 'lat' => 0, 'lng' => 0],
        ['label' => 'Maputo office', 'lat' => -25.9692, 'lng' => 32.5732, 'url' => '/admin/offices/1'],
        ['label' => 'Nowhere', 'lat' => 123, 'lng' => 10],
        ['country' => 'xx', 'label' => 'Unknown code'],
        ['label' => 'Bad link', 'lat' => 1, 'lng' => 1, 'url' => 'javascript:alert(1)'],
    ]]);

    expect($map['markers'])->toHaveCount(3)
        ->and($map['markers'][0])->toMatchArray(['label' => 'Mozambique', 'lat' => Countries::find('MZ')['lat'], 'value' => 12.0, 'prompt' => 'Orders from Mozambique'])
        ->and($map['markers'][1])->toMatchArray(['label' => 'Maputo office', 'url' => '/admin/offices/1'])
        ->and($map['markers'][2])->not->toHaveKey('url')
        ->and($map['unit'])->toBe('orders');
});

it('drops maps when they are switched off', function () {
    config(['chatai.maps.enabled' => false]);

    expect($this->validator->validate(['type' => 'map', 'markers' => [['country' => 'pt']]]))->toBeNull();
});

it('keeps card buttons that ask something or open a place of the app', function () {
    $cards = $this->validator->validate(['type' => 'cards', 'cards' => [['title' => 'Oak Mug', 'actions' => [
        ['label' => 'Orders', 'prompt' => 'Show the orders of Oak Mug'],
        ['label' => 'Open', 'url' => '/admin/products/1/edit'],
        ['label' => 'Evil', 'url' => 'https://evil.test'],
        ['label' => 'Empty'],
    ]]]]);

    expect($cards['items'][0]['actions'])->toBe([
        ['label' => 'Orders', 'prompt' => 'Show the orders of Oak Mug'],
        ['label' => 'Open', 'url' => '/admin/products/1/edit'],
    ]);
});

it('lets a chart ask about the bar the user clicks', function () {
    $chart = fn (string $drill) => $this->validator->validate(['type' => 'chart', 'chart_type' => 'bar', 'labels' => ['Jan', 'Feb'], 'series' => [['name' => 'Sales', 'data' => [1, 2]]], 'drill' => $drill]);

    expect($chart('Show the orders of {label}')['drill'])->toBe('Show the orders of {label}')
        ->and($chart('No placeholder'))->not->toHaveKey('drill');
});

it('breaks totals down by a field of a one-to-one record, such as the address country', function () {
    foreach ([['OR-1', 'pt', 100], ['OR-2', 'pt', 50], ['OR-3', 'mz', 70], ['OR-4', null, 10]] as [$number, $country, $total]) {
        $order = Order::create(['number' => $number, 'status' => 'new', 'total' => $total]);
        $country && $order->address()->save(new Address(['country' => $country]));
    }

    // Another model with the same id must not be counted (morph type).
    Address::create(['addressable_type' => 'other', 'addressable_id' => 1, 'country' => 'us']);

    CustomTool::query()->create(['name' => 'by-country', 'title' => 'By country', 'description' => 'Orders by country', 'type' => CustomTool::TYPE_METRICS, 'enabled' => true, 'config' => [
        'resource' => OrderResource::class, 'date_field' => 'created_at', 'period' => 'all',
        'metrics' => [['function' => 'count', 'label' => 'orders'], ['function' => 'sum', 'column' => 'total', 'label' => 'revenue']],
        'group_by' => 'one:address.country',
    ]]);

    /** @var ChatAiPlugin $plugin */
    $plugin = Filament::getPanel('admin')->getPlugin('chatai');
    $tool = collect($plugin->resolveTools()->tools($this->user, new ChatAiContext(panelId: 'admin')))->firstWhere('name', 'by-country');

    expect($tool->call([])->data['breakdown'])->toBe([
        ['group' => 'pt', 'orders' => 2.0, 'revenue' => 150.0],
        ['group' => 'mz', 'orders' => 1.0, 'revenue' => 70.0],
        ['group' => null, 'orders' => 1.0, 'revenue' => 10.0],
    ]);
});
