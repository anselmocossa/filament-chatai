<?php

use Filament\ChatAi\Ui\Catalog;
use Filament\ChatAi\Ui\ComponentValidator;

beforeEach(fn () => $this->validator = new ComponentValidator);

it('accepts a valid kpi block and fills defaults', function () {
    $component = $this->validator->validate([
        'type' => 'kpi',
        'items' => [['label' => 'Vendas', 'value' => 45300, 'unit' => 'MZN', 'change' => 12.5]],
    ]);

    expect($component['type'])->toBe('kpi')
        ->and($component['items'][0]['trend'])->toBe('up')
        ->and($component['items'][0]['value'])->toBe('45300');
});

it('truncates a table to 50 rows and says so', function () {
    $rows = array_map(fn (int $i) => ['name' => "P{$i}", 'qty' => $i], range(1, 80));

    $component = $this->validator->validate([
        'type' => 'table',
        'columns' => [['key' => 'name', 'label' => 'Produto'], ['key' => 'qty', 'label' => 'Qtd', 'format' => 'number']],
        'rows' => $rows,
    ]);

    expect($component['rows'])->toHaveCount(50)
        ->and($component['note'])->toContain('50')->toContain('80');
});

it('turns a chart without labels into a table and drops junk', function () {
    $fromChart = $this->validator->validate([
        'type' => 'chart',
        'series' => [['name' => 'Vendas', 'data' => [10, 20, 30]]],
    ]);

    expect($fromChart['type'])->toBe('table')
        ->and($fromChart['rows'])->toHaveCount(3)
        ->and($this->validator->validate(['type' => 'chart']))->toBeNull()
        ->and($this->validator->validate(['type' => 'banana', 'x' => 1]))->toBeNull()
        ->and($this->validator->validate('not an array'))->toBeNull();
});

it('limits suggestions to four and requires a prompt', function () {
    $component = $this->validator->validate([
        'type' => 'suggestions',
        'items' => [
            ['label' => 'A', 'prompt' => 'a?'], ['label' => 'B', 'prompt' => 'b?'], ['label' => 'Sem pergunta'],
            ['label' => 'C', 'prompt' => 'c?'], ['label' => 'D', 'prompt' => 'd?'], ['label' => 'E', 'prompt' => 'e?'],
        ],
    ]);

    expect($component['items'])->toHaveCount(4)
        ->and(array_column($component['items'], 'label'))->toBe(['A', 'B', 'C', 'D']);
});

it('describes the component types in the show_ui tool schema', function () {
    $schema = Catalog::showToolSchema();

    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBe(['components'])
        ->and($schema['properties']['components']['items']['properties']['type']['enum'])
        ->toBe(['kpi', 'table', 'chart', 'cards', 'records', 'map', 'suggestions', 'questions'])
        ->and(json_encode($schema))->toBeJson();
});

it('builds a record sheet with its badges, fields and sections', function () {
    $records = (new ComponentValidator)->validate([
        'type' => 'records',
        'title' => 'Candidates',
        'items' => [[
            'title' => 'Alex Manhica',
            'subtitle' => 'Industrial Mechanic',
            'badges' => ['Internship', 'Finished', 'Third', 'Dropped'],
            'fields' => [['label' => 'Place', 'value' => 'Maputo']],
            'sections' => [
                ['heading' => 'Summary', 'text' => 'Keeps machines running.'],
                ['heading' => 'Experience', 'items' => ['Cimentos', 'Portos']],
                ['heading' => 'Nothing to show'],
            ],
            'actions' => [['label' => 'Open', 'url' => '/admin/candidates/1']],
        ]],
    ]);

    expect($records['type'])->toBe('records')
        ->and($records['items'][0]['badges'])->toBe(['Internship', 'Finished', 'Third'])
        ->and($records['items'][0]['sections'])->toHaveCount(2)
        ->and($records['items'][0]['sections'][0]['text'])->toBe('Keeps machines running.')
        ->and($records['items'][0]['sections'][1]['items'])->toBe(['Cimentos', 'Portos'])
        ->and($records['items'][0]['actions'][0]['url'])->toBe('/admin/candidates/1');
});

it('drops a record sheet with no usable item', function () {
    expect((new ComponentValidator)->validate([
        'type' => 'records',
        'items' => [['subtitle' => 'no title here']],
    ]))->toBeNull();
});

it('keeps a row link only when it points inside the application', function () {
    $table = (new ComponentValidator)->validate([
        'type' => 'table',
        'columns' => [['key' => 'name', 'label' => 'Name']],
        'rows' => [
            ['name' => 'Ok', '_url' => '/admin/products/1/edit'],
            ['name' => 'Out', '_url' => 'https://evil.example/x'],
            ['name' => 'Protocol-relative', '_url' => '//evil.example/x'],
        ],
    ]);

    expect($table['rows'][0]['_url'])->toBe('/admin/products/1/edit')
        ->and($table['rows'][1])->not->toHaveKey('_url')
        ->and($table['rows'][2])->not->toHaveKey('_url');
});
