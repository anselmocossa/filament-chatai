<?php

use Filament\ChatAi\Support\ActionForm;

$schema = [
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string', 'title' => 'Name'],
        'email' => ['type' => 'string', 'format' => 'email'],
        'units' => ['type' => 'integer'],
        'status' => ['type' => 'string', 'enum' => ['new', 'shipped']],
        'birthday' => ['type' => 'string', 'format' => 'date'],
        'vip' => ['type' => 'boolean'],
    ],
    'required' => ['name', 'email'],
];

it('builds a form field for every schema property, filled with what the model gave', function () use ($schema) {
    $fields = collect(ActionForm::fields($schema, ['name' => 'Ana', 'units' => 3]))->keyBy('key');

    expect($fields['name'])->toMatchArray(['label' => 'Name', 'type' => 'text', 'required' => true, 'value' => 'Ana'])
        ->and($fields['email'])->toMatchArray(['type' => 'email', 'required' => true, 'value' => ''])
        ->and($fields['units'])->toMatchArray(['type' => 'number', 'value' => '3'])
        ->and($fields['status']['type'])->toBe('select')
        ->and($fields['status']['options'][1])->toBe(['value' => 'shipped', 'label' => 'Shipped'])
        ->and($fields['birthday']['type'])->toBe('date')
        ->and($fields['vip'])->toMatchArray(['type' => 'checkbox', 'value' => false]);
});

it('reports missing and wrong values, and casts the good ones', function () use ($schema) {
    [, $errors] = ActionForm::validate($schema, ['name' => ' ', 'email' => 'not-an-email', 'units' => '2.5', 'status' => 'lost', 'birthday' => '31/12/1990']);

    expect(array_keys($errors))->toBe(['name', 'email', 'units', 'status', 'birthday']);

    [$values, $errors] = ActionForm::validate($schema, ['name' => 'Ana', 'email' => 'ana@x.mz', 'units' => '4', 'status' => 'new', 'birthday' => '1990-12-31', 'vip' => '1', 'other' => 'ignored']);

    expect($errors)->toBe([])
        ->and($values)->toBe(['name' => 'Ana', 'email' => 'ana@x.mz', 'units' => 4, 'status' => 'new', 'birthday' => '1990-12-31', 'vip' => true]);
});
