<?php

use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Tests\Fixtures\Shop\Product;
use Filament\ChatAi\Tests\Fixtures\Shop\Student;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\NavigateTool;
use Filament\Facades\Filament;

beforeEach(function () {
    ChatAiSettings::flush();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::create(['name' => 'Ana', 'email' => 'ana@nav.test', 'password' => 'x']));
    Product::create(['name' => 'Classic Pillow', 'sku' => 'P-1', 'qty' => 2]);
    Product::create(['name' => 'Classic Mug', 'sku' => 'M-1', 'qty' => 9]);
});

it('opens a list, a create page and one record by its name', function () {
    $tool = NavigateTool::make();
    $key = collect($tool->inputSchema['properties']['resource']['enum'])->first(fn ($k) => str_contains($k, 'product'));

    expect($tool->description)->toContain('Never say you cannot navigate')
        ->and($tool->call(['resource' => $key])->component['url'])->toEndWith('/products')
        ->and($tool->call(['resource' => $key, 'action' => 'create'])->component['url'])->toEndWith('/products/create');

    $open = $tool->call(['resource' => $key, 'action' => 'edit', 'record' => 'Classic Pillow']);

    expect($open->component)->toMatchArray(['type' => 'navigate', 'label' => 'Classic Pillow'])
        ->and($open->component['url'])->toEndWith('/products/'.Product::query()->where('sku', 'P-1')->value('id').'/edit');
});

it('opens one record when the name is split across columns', function () {
    Student::create(['nome' => 'Cremildo', 'apelido' => 'Macamo', 'numero' => '014']);
    Student::create(['nome' => 'Cremildo', 'apelido' => 'Sitoe', 'numero' => '015']);

    $tool = NavigateTool::make();
    $key = collect($tool->inputSchema['properties']['resource']['enum'])->first(fn ($k) => str_contains($k, 'student'));
    $open = $tool->call(['resource' => $key, 'action' => 'view', 'record' => 'Cremildo Macamo']);

    expect($open->isError())->toBeFalse()
        ->and($open->component['label'])->toBe('Cremildo Macamo')
        ->and($open->component['url'])->toEndWith('/students/'.Student::query()->where('numero', '014')->value('id'));

    expect($tool->call(['resource' => $key, 'action' => 'view', 'record' => 'Cremildo'])->error)
        ->toContain('Cremildo Macamo')
        ->toContain('Cremildo Sitoe');
});

it('asks which one when several records match, and refuses unknown places', function () {
    $tool = NavigateTool::make();
    $key = collect($tool->inputSchema['properties']['resource']['enum'])->first(fn ($k) => str_contains($k, 'product'));

    expect($tool->call(['resource' => $key, 'action' => 'edit', 'record' => 'Classic'])->error)->toContain('Classic Pillow')->toContain('Classic Mug')
        ->and($tool->call(['resource' => 'nowhere'])->isError())->toBeTrue();
});

it('is not offered when navigation is switched off', function () {
    ChatAiSettings::save(['screen.navigate' => false]);
    ChatAiSettings::apply();

    expect(NavigateTool::available())->toBeFalse();
});
