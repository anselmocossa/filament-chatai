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

it('describes the four component types in the show_ui tool schema', function () {
    $schema = Catalog::showToolSchema();

    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBe(['components'])
        ->and($schema['properties']['components']['items']['properties']['type']['enum'])
        ->toBe(['kpi', 'table', 'chart', 'suggestions'])
        ->and(json_encode($schema))->toBeJson();
});
