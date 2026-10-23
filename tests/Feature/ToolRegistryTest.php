<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\Skill;
use Filament\ChatAi\Models\ToolOverride;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tests\Fixtures\UserResource;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\SkillTool;
use Filament\ChatAi\Tools\ToolResult;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

function registryPlugin(): ChatAiPlugin
{
    return Filament::getPanel('admin')->getPlugin('chatai');
}

function registryUser(string $name = 'Ana'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'secret-hash']);
}

/**
 * @return array<string, ChatAiTool>
 */
function toolsFor(User $user): array
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return collect(registryPlugin()->resolveTools()->tools($user, new ChatAiContext('admin')))->keyBy('name')->all();
}

beforeEach(function () {
    ChatAiSettings::flush();
    registryPlugin()->tools(fn () => [
        new ChatAiTool('sales-stats', 'Sales totals', ['type' => 'object', 'properties' => []], fn () => ToolResult::data(['total' => 1]), readOnly: true, title: 'Vendas'),
        new ChatAiTool('payroll', 'Salaries', ['type' => 'object', 'properties' => []], fn () => ToolResult::data([]), readOnly: true),
    ])->userRoles(fn (User $user): array => $user->name === 'Ana' ? ['gestor'] : []);
});

afterEach(fn () => registryPlugin()->tools(null)->userRoles(null));

it('applies the admin adjustments to code tools', function () {
    ToolOverride::create(['tool_name' => 'sales-stats', 'enabled' => true, 'title' => 'Vendas da loja', 'description' => 'Totais de vendas por período, em MZN.']);
    ToolOverride::create(['tool_name' => 'payroll', 'enabled' => false]);

    $tools = toolsFor(registryUser());

    expect($tools)->not->toHaveKey('payroll')
        ->and($tools['sales-stats']->description)->toBe('Totais de vendas por período, em MZN.')
        ->and($tools['sales-stats']->title)->toBe('Vendas da loja')
        ->and($tools['sales-stats']->call([])->data)->toBe(['total' => 1]);
});

it('limits a tool to some profiles', function () {
    ToolOverride::create(['tool_name' => 'payroll', 'enabled' => true, 'roles' => ['gestor']]);

    expect(toolsFor(registryUser('Ana')))->toHaveKey('payroll')
        ->and(toolsFor(registryUser('Rui')))->not->toHaveKey('payroll');
});

it('builds a query tool from a panel resource, returning only the chosen fields', function () {
    registryUser('Ana');
    registryUser('Bruno');

    CustomTool::create([
        'name' => 'find-users', 'title' => 'Utilizadores', 'description' => 'Procura utilizadores', 'type' => CustomTool::TYPE_RESOURCE, 'enabled' => true,
        'config' => ['resource' => UserResource::class, 'fields' => ['name', 'email', 'password'], 'filters' => ['name'], 'limit' => 10],
    ]);

    $tool = toolsFor(User::query()->first())['find-users'];
    $result = $tool->call(['name' => 'Bru']);

    expect($tool->inputSchema['properties'])->toHaveKey('name')
        ->and($result->data['rows'])->toBe([['name' => 'Bruno', 'email' => 'bruno@example.com']])
        ->and($result->data['total'])->toBe(1);
});

it('calls an allowed GET API with its key and refuses other hosts', function () {
    Http::fake(['https://api.loja.test/*' => Http::response(['status' => 'paid'])]);
    config(['chatai.http_tools.allowed_hosts' => ['api.loja.test']]);

    $base = ['title' => 'Pagamento', 'description' => 'Estado do pagamento', 'type' => CustomTool::TYPE_HTTP, 'enabled' => true];
    CustomTool::create([...$base, 'name' => 'payment-status', 'config' => [
        'url' => 'https://api.loja.test/payments/{reference}', 'auth' => 'bearer', 'secret' => 'tok-123',
        'params' => [['name' => 'reference', 'description' => 'Referência', 'required' => true, 'in' => 'path'], ['name' => 'expand', 'in' => 'query']],
    ]]);
    CustomTool::create([...$base, 'name' => 'evil', 'config' => ['url' => 'http://169.254.169.254/latest/meta-data', 'params' => []]]);

    $tools = toolsFor(registryUser());

    expect($tools)->not->toHaveKey('evil')
        ->and($tools['payment-status']->call(['reference' => 'A/1', 'expand' => 'items'])->data)->toBe(['status' => 'paid']);

    Http::assertSent(fn ($r) => $r->url() === 'https://api.loja.test/payments/A%2F1?expand=items' && $r->hasHeader('Authorization', 'Bearer tok-123'));
});

it('offers skills to the model and hands over their instructions when used', function () {
    Skill::create(['name' => 'monthly-report', 'title' => 'Relatório mensal', 'when_to_use' => 'Quando pedirem o fecho do mês', 'instructions' => 'Mostra vendas, top 5 produtos e stock baixo, por esta ordem.', 'tools' => ['sales-stats'], 'enabled' => true]);
    Skill::create(['name' => 'off', 'title' => 'Off', 'when_to_use' => 'x', 'instructions' => 'y', 'enabled' => false]);

    $tools = toolsFor(registryUser());
    $skill = $tools[SkillTool::NAME];

    expect($skill->inputSchema['properties']['name']['enum'])->toBe(['monthly-report'])
        ->and($skill->call(['name' => 'monthly-report'])->text)->toContain('top 5 produtos')->toContain('sales-stats')
        ->and(registryPlugin()->composeSystemPrompt())->toContain('monthly-report')->toContain('Quando pedirem o fecho do mês');
});

it('adds the admin instructions to the system prompt', function () {
    registryPlugin()->systemPrompt('Demo shop.');
    ChatAiSettings::save(['instructions' => 'Trate o utilizador por Dr./Dra.']);

    expect(registryPlugin()->composeSystemPrompt())->toContain('Demo shop.')->toContain('Trate o utilizador por Dr./Dra.');

    registryPlugin()->systemPrompt(null);
});

it('uses a hand-written input schema for an API tool, sending non-path arguments as query', function () {
    Http::fake(['https://api.loja.test/*' => Http::response(['ok' => true])]);
    config(['chatai.http_tools.allowed_hosts' => ['api.loja.test']]);

    $schema = ['type' => 'object', 'properties' => [
        'city' => ['type' => 'string', 'description' => 'Cidade, ex.: Maputo'],
        'days' => ['type' => 'integer', 'description' => 'Dias de previsão'],
    ], 'required' => ['city']];

    CustomTool::create(['name' => 'weather', 'title' => 'Tempo', 'description' => 'Previsão', 'type' => CustomTool::TYPE_HTTP, 'enabled' => true, 'config' => [
        'url' => 'https://api.loja.test/weather/{city}', 'auth' => 'none', 'schema' => json_encode($schema),
        'params' => [['name' => 'city', 'in' => 'path']],
    ]]);

    $tool = toolsFor(registryUser())['weather'];

    expect($tool->inputSchema)->toBe($schema)
        ->and($tool->call(['city' => 'Maputo', 'days' => 3, 'evil' => 'x'])->data)->toBe(['ok' => true]);

    Http::assertSent(fn ($r) => $r->url() === 'https://api.loja.test/weather/Maputo?days=3');
});

it('loads current argument contracts with a skill and does not expose a restricted dependency schema', function () {
    Skill::create(['name' => 'compare', 'title' => 'Compare', 'when_to_use' => 'Compare', 'instructions' => 'Compare the returned data.', 'tools' => ['sales-stats', 'payroll'], 'enabled' => true]);
    ToolOverride::create(['tool_name' => 'payroll', 'enabled' => false]);
    $tool = toolsFor(registryUser())[SkillTool::NAME];
    $text = $tool->call(['name' => 'compare'])->text;
    expect($text)->toContain('Current tool contracts')->toContain('"name":"sales-stats"')
        ->and($text)->toContain('Some dependencies are unavailable')
        ->and($text)->not->toContain('"name":"payroll"');
});
