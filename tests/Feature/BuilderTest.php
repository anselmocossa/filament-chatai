<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\Skill;
use Filament\ChatAi\Models\ToolAction;
use Filament\ChatAi\Models\ToolOverride;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\Shop\Product;
use Filament\ChatAi\Tests\Fixtures\Shop\ProductResource;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\BuilderTools;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\ToolResult;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

function builderPlugin(): ChatAiPlugin
{
    return Filament::getPanel('admin')->getPlugin('chatai');
}

/**
 * @return array<string, ChatAiTool>
 */
function builderTools(User $user): array
{
    return collect(builderPlugin()->resolveTools()->tools($user, new ChatAiContext(panelId: 'admin')))->keyBy('name')->all();
}

function lowStockArgs(array $overrides = []): array
{
    return [
        'name' => 'low-stock',
        'title' => 'Low stock',
        'description' => 'Products below their security stock.',
        'type' => CustomTool::TYPE_RESOURCE,
        'resource' => ProductResource::class,
        'config' => json_encode([
            'fields' => ['name', 'qty', 'security_stock'],
            'conditions' => [['column' => 'qty', 'operator' => '<', 'compare_column' => 'security_stock']],
        ]),
        ...$overrides,
    ];
}

beforeEach(function () {
    ChatAiSettings::flush();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->withoutMiddleware(VerifyCsrfToken::class);
    $this->boss = User::create(['name' => 'Boss', 'email' => 'boss@builder.test', 'password' => 'x']);
    $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@builder.test', 'password' => 'x']);
    ChatAiSettings::save(['access.managers' => ['boss@builder.test']]);

    Product::create(['name' => 'Classic Pillow', 'sku' => 'P-1', 'qty' => 2, 'security_stock' => 8]);
    Product::create(['name' => 'Oak Mug', 'sku' => 'M-1', 'qty' => 30, 'security_stock' => 5]);
});

it('gives the builder tools to managers only', function () {
    $this->actingAs($this->boss);
    expect(builderTools($this->boss))->toHaveKeys([BuilderTools::DESCRIBE, BuilderTools::CREATE_TOOL, BuilderTools::CREATE_SKILL]);

    $this->actingAs($this->ana);
    expect(builderTools($this->ana))->not->toHaveKey(BuilderTools::CREATE_TOOL);

    $this->actingAs($this->boss);
    ChatAiSettings::save(['builder.enabled' => false]);
    expect(builderTools($this->boss))->not->toHaveKey(BuilderTools::CREATE_TOOL);
});

it('describes the panel data so the model uses real columns', function () {
    $this->actingAs($this->boss);
    $describe = builderTools($this->boss)[BuilderTools::DESCRIBE];

    expect(collect($describe->call([])->data['resources'])->pluck('resource'))->toContain(ProductResource::class);

    expect($describe->call(['resource' => ProductResource::class])->data['columns'])->toContain('qty', 'security_stock');
});

it('saves a working tool that answers from the next question', function () {
    $this->actingAs($this->boss);

    $result = builderTools($this->boss)[BuilderTools::CREATE_TOOL]->call(lowStockArgs());

    expect($result->isError())->toBeFalse()
        ->and(CustomTool::query()->where('name', 'low-stock')->value('enabled'))->toBeTrue();

    $rows = builderTools($this->boss)['low-stock']->call([])->data['rows'];
    expect(collect($rows)->pluck('name')->all())->toBe(['Classic Pillow']);
});

it('refuses a duplicate name, an unknown resource or broken settings', function () {
    $this->actingAs($this->boss);
    $create = builderTools($this->boss)[BuilderTools::CREATE_TOOL];
    $create->call(lowStockArgs());

    expect($create->call(lowStockArgs())->isError())->toBeTrue()
        ->and($create->call(lowStockArgs(['name' => 'x', 'resource' => 'App\\Nope']))->isError())->toBeTrue()
        ->and($create->call(lowStockArgs(['name' => 'y', 'config' => 'not json']))->isError())->toBeTrue()
        ->and(CustomTool::query()->count())->toBe(1);
});

it('saves a skill', function () {
    $this->actingAs($this->boss);
    builderTools($this->boss)[BuilderTools::CREATE_TOOL]->call(lowStockArgs());

    builderTools($this->boss)[BuilderTools::CREATE_SKILL]->call([
        'name' => 'stock-review', 'title' => 'Stock review', 'when_to_use' => 'Weekly stock check',
        'instructions' => 'List low stock, then suggest quantities.', 'tools' => 'low-stock, describe_data',
    ]);

    expect(Skill::query()->where('name', 'stock-review')->first()?->tools)->toBe(['low-stock', 'describe_data']);
});

it('builds a tool only after the manager confirms the card, then asks the question again', function () {
    app()->instance(LlmClient::class, $llm = FakeLlmClient::script([
        [FakeLlmClient::call(BuilderTools::CREATE_TOOL, lowStockArgs())],
        ['Criei a proposta de ferramenta. Confirme no cartão.'],
    ]));

    $conversation = Conversation::start($this->boss, 'admin');
    $this->actingAs($this->boss)->post(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'Quero saber os produtos com pouco stock'])->assertOk()->streamedContent();

    expect(CustomTool::query()->count())->toBe(0);

    // Told to build what is missing instead of saying it cannot.
    expect($llm->requests[0]['messages'][0]['content'])->toContain('give yourself new abilities');

    $this->postJson(route('filament.admin.chatai.actions.confirm', ToolAction::query()->sole()))
        ->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('continue', __('chatai::chatai.builder_continue'));

    expect(CustomTool::query()->where('name', 'low-stock')->exists())->toBeTrue();
});

it('does not tell other users it can build tools', function () {
    app()->instance(LlmClient::class, $llm = FakeLlmClient::script([['Não tenho essa informação.']]));

    $conversation = Conversation::start($this->ana, 'admin');
    $this->actingAs($this->ana)->post(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'Total de horas da Camilla?'])->assertOk()->streamedContent();

    expect($llm->requests[0]['messages'][0]['content'])->not->toContain('new abilities');
});

it('describes available tool contracts without executing them or exposing a disabled tool', function () {
    $this->actingAs($this->boss);
    $ran = false;
    builderPlugin()->tools(fn () => [
        new ChatAiTool('profile-search', 'Search profiles', ['type' => 'object', 'properties' => ['by' => ['type' => 'string', 'enum' => ['name', 'role']], 'location' => ['type' => 'string']]], function () use (&$ran) {
            $ran = true;

            return ToolResult::data([]);
        }, readOnly: true),
        new ChatAiTool('disabled-tool', 'Disabled', ['type' => 'object'], fn () => ToolResult::data([]), readOnly: true),
    ]);
    ToolOverride::create(['tool_name' => 'disabled-tool', 'enabled' => false]);
    try {
        $tools = builderTools($this->boss);
        expect($tools)->toHaveKey('describe_tools');
        $catalog = $tools['describe_tools']->call([]);
        expect(array_column($catalog->data['tools'], 'name'))->toContain('profile-search', 'show_ui')->not->toContain('disabled-tool')
            ->and($tools['describe_tools']->call(['name' => 'profile-search'])->data['tools'][0]['parameters']['properties']['by']['enum'])->toBe(['name', 'role'])
            ->and($tools['describe_tools']->call(['name' => 'disabled-tool'])->isError())->toBeTrue()
            ->and($ran)->toBeFalse();
    } finally {
        builderPlugin()->tools(null);
    }
});

it('rejects unknown skill dependencies and empty instructions', function () {
    $this->actingAs($this->boss);
    $create = builderTools($this->boss)[BuilderTools::CREATE_SKILL];
    $args = ['name' => 'compare-profiles', 'title' => 'Compare', 'when_to_use' => 'Compare profiles', 'instructions' => 'Search and compare.', 'tools' => 'invented-tool'];
    expect($create->call($args)->isError())->toBeTrue()
        ->and($create->call([...$args, 'tools' => '', 'instructions' => ''])->isError())->toBeTrue()
        ->and(Skill::query()->count())->toBe(0);
});

it('validates skill examples without running them and stores their canonical contracts', function () {
    $this->actingAs($this->boss);
    $ran = false;
    builderPlugin()->tools(fn () => [new ChatAiTool('profile-search', 'Search', ['type' => 'object', 'properties' => [
        'term' => ['type' => 'string'], 'by' => ['type' => 'string', 'enum' => ['name', 'role']], 'location' => ['type' => 'string'],
    ], 'required' => ['term']], function () use (&$ran) {
        $ran = true;

        return ToolResult::data([]);
    }, readOnly: true)]);
    try {
        $create = builderTools($this->boss)[BuilderTools::CREATE_SKILL];
        $args = ['name' => 'compare-profiles', 'title' => 'Compare', 'when_to_use' => 'Compare profiles', 'instructions' => 'Ask for the reference when the skill is used, then search.', 'tools' => 'profile-search'];
        $bad = $create->call([...$args, 'examples' => json_encode([['tool' => 'profile-search', 'arguments' => ['term' => '<reference>', 'by' => 'location']]])]);
        expect($bad->isError())->toBeTrue()->and(Skill::query()->count())->toBe(0);
        $good = $create->call([...$args, 'examples' => json_encode([['tool' => 'profile-search', 'arguments' => ['term' => '<reference>', 'by' => 'role', 'location' => '<optional location>']]])]);
        expect($good->isError())->toBeFalse()->and($ran)->toBeFalse()
            ->and(Skill::query()->sole()->instructions)->toContain('Validated call examples')->toContain('"by":"role"');
    } finally {
        builderPlugin()->tools(null);
    }
});

it('guides reusable workflow creation separately from running it', function () {
    app()->instance(LlmClient::class, $llm = FakeLlmClient::script([['Vou preparar a capacidade reutilizável.']]));
    $conversation = Conversation::start($this->boss, 'admin');
    $this->actingAs($this->boss)->post(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'Cria uma ferramenta para comparar perfis'])->assertOk()->streamedContent();
    $prompt = $llm->requests[0]['messages'][0]['content'];
    expect($prompt)->toContain('create_skill')->toContain('describe_tools')->toContain('runtime inputs')
        ->and($prompt)->toContain('Do not turn a creation request into a search');
});

it('lets the model repair a bad skill example before showing a single confirmation', function () {
    builderPlugin()->tools(fn () => [new ChatAiTool('profile-search', 'Search profiles', ['type' => 'object', 'properties' => ['by' => ['type' => 'string', 'enum' => ['role']]]], fn () => ToolResult::data([]), readOnly: true)]);
    $args = ['name' => 'compare-profiles', 'title' => 'Compare', 'when_to_use' => 'Compare profiles', 'instructions' => 'Ask for the reference when used.', 'tools' => 'profile-search'];
    $bad = [...$args, 'examples' => json_encode([['tool' => 'profile-search', 'arguments' => ['by' => 'location']]])];
    $good = [...$args, 'examples' => json_encode([['tool' => 'profile-search', 'arguments' => ['by' => 'role']]])];
    app()->instance(LlmClient::class, FakeLlmClient::script([[FakeLlmClient::call(BuilderTools::CREATE_SKILL, $bad)], [FakeLlmClient::call(BuilderTools::CREATE_SKILL, $good)], ['Confirme a proposta.']]));
    try {
        $conversation = Conversation::start($this->boss, 'admin');
        $response = $this->actingAs($this->boss)->post(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'Cria uma capacidade para comparar perfis']);
        expect($response->assertOk()->streamedContent())->toContain('"type":"confirm"')
            ->and(Skill::query()->count())->toBe(0)
            ->and(ToolAction::query()->count())->toBe(1)
            ->and(ToolAction::query()->sole()->arguments['examples'])->toBe($good['examples']);
        $this->postJson(route('filament.admin.chatai.actions.confirm', ToolAction::query()->sole()))->assertOk()->assertJsonPath('status', 'done');
        expect(Skill::query()->sole()->name)->toBe('compare-profiles');
    } finally {
        builderPlugin()->tools(null);
    }
});
