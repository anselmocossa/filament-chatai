<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Events\TurnCompleted;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Models\AiConnection;
use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\Skill;
use Filament\ChatAi\Models\ToolOverride;
use Filament\ChatAi\Pages\ChatAiLogsPage;
use Filament\ChatAi\Pages\ChatAiSkillsPage;
use Filament\ChatAi\Pages\ChatAiToolEditorPage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tests\Fixtures\UserResource;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\ToolCode;
use Filament\ChatAi\Tools\ToolResult;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function pagesPlugin(): ChatAiPlugin
{
    return Filament::getPanel('admin')->getPlugin('chatai');
}

beforeEach(function () {
    ChatAiSettings::flush();
    pagesPlugin()->canManageSettings(fn (): bool => true)->tools(fn () => [
        new ChatAiTool('sales-stats', 'Sales totals', ['type' => 'object', 'properties' => []], fn () => ToolResult::data([]), readOnly: true, title: 'Vendas'),
    ]);
    $this->actingAs(User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => pagesPlugin()->canManageSettings(null)->tools(null));

it('opens the tools, skills and logs pages only for managers', function () {
    $this->get('/admin/chatai/tools')->assertOk()->assertSee('sales-stats');
    $this->get('/admin/chatai/skills')->assertOk();
    $this->get('/admin/chatai/logs')->assertOk();

    pagesPlugin()->canManageSettings(null);

    $this->get('/admin/chatai/tools')->assertForbidden();
    $this->get('/admin/chatai/logs')->assertForbidden();
});

it('adjusts a code tool on its own page', function () {
    Livewire::withQueryParams(['tool' => 'sales-stats', 'mode' => 'code'])
        ->test(ChatAiToolEditorPage::class)
        ->fillForm(['enabled' => false, 'title' => 'Vendas da loja', 'description' => 'Totais em MZN', 'roles' => ['gestor']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ToolOverride::query()->sole())
        ->enabled->toBeFalse()
        ->title->toBe('Vendas da loja')
        ->roles->toBe(['gestor']);
});

it('creates a resource query tool on its own page and opens it for editing', function () {
    Livewire::test(ChatAiToolEditorPage::class)
        ->fillForm([
            'name' => 'find-users', 'title' => 'Utilizadores', 'description' => 'Procura utilizadores pelo nome', 'type' => 'resource', 'enabled' => true,
            'config' => ['resource' => UserResource::class, 'fields' => ['name', 'email'], 'filters' => ['name'], 'limit' => 10],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(ChatAiToolEditorPage::getUrl(['tool' => 'find-users']));

    expect(CustomTool::query()->sole()->config['fields'])->toBe(['name', 'email']);

    $this->get(ChatAiToolEditorPage::getUrl(['tool' => 'find-users']))->assertOk()->assertSee('fi-chatai-editor-test', false);
});

it('refuses an API tool on a host that is not allowed', function () {
    config(['chatai.http_tools.allowed_hosts' => ['api.loja.test']]);

    Livewire::test(ChatAiToolEditorPage::class)
        ->fillForm([
            'name' => 'meta', 'title' => 'Meta', 'description' => 'x', 'type' => 'http', 'enabled' => true,
            'config' => ['url' => 'http://169.254.169.254/latest', 'auth' => 'none', 'params' => []],
        ])
        ->call('save')
        ->assertHasFormErrors(['config.url']);

    expect(CustomTool::query()->count())->toBe(0);
});

it('tests the unsaved tool in the test chat, kept out of the chat history', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    User::create(['name' => 'Bruno', 'email' => 'bruno@example.com', 'password' => 'x']);
    $llm = FakeLlmClient::script([[FakeLlmClient::call('find-users', ['name' => 'Bru'])], ['Encontrei o Bruno.']]);
    app()->instance(LlmClient::class, $llm);

    $id = $this->postJson(route('filament.admin.chatai.playground.store'))->assertCreated()->json('id');

    $body = $this->post(route('filament.admin.chatai.playground.messages', $id), [
        'message' => 'Há algum Bruno?',
        'draft' => ['mode' => 'custom', 'tool' => null, 'data' => [
            'name' => 'find-users', 'title' => 'Utilizadores', 'description' => 'Procura', 'type' => 'resource',
            'config' => ['resource' => UserResource::class, 'fields' => ['name'], 'filters' => ['name']],
        ]],
    ])->assertOk()->streamedContent();

    expect($body)->toContain('event: tool_end')->toContain('Encontrei o Bruno.')
        ->and(collect($llm->requests[0]['tools'])->pluck('function.name')->all())->toBe(['find-users', 'show_ui'])
        ->and(CustomTool::query()->count())->toBe(0)
        ->and($this->getJson(route('filament.admin.chatai.conversations.index'))->json('data'))->toBe([]);
});

it('keeps the test chat for managers only', function () {
    pagesPlugin()->canManageSettings(null);

    $this->postJson(route('filament.admin.chatai.playground.store'))->assertForbidden();
});

it('creates a skill', function () {
    Livewire::test(ChatAiSkillsPage::class)
        ->callTableAction('create', data: ['name' => 'monthly-report', 'title' => 'Relatório mensal', 'when_to_use' => 'Fecho do mês', 'instructions' => 'Vendas, top 5 e stock.', 'tools' => ['sales-stats'], 'enabled' => true])
        ->assertHasNoTableActionErrors();

    expect(Skill::query()->sole()->tools)->toBe(['sales-stats']);
});

it('lists each answered question in the logs', function () {
    $user = auth()->user();
    $conversation = Conversation::start($user, 'admin');
    $conversation->addMessage(Message::ROLE_USER, 'Quanto vendemos hoje?');
    $answer = $conversation->addMessage(Message::ROLE_ASSISTANT, 'Vendeu 10.');
    event(new TurnCompleted($user, $conversation, 'claude-opus-5-5', 10, 5, 1500, ['sales-stats'], messageId: $answer->id));

    Livewire::test(ChatAiLogsPage::class)
        ->assertSee('Quanto vendemos hoje?')
        ->assertSee('sales-stats')
        ->assertSee('1.5 s');
});

it('pairs each answer with its own question even within the same second', function () {
    $this->travelTo(now()->startOfSecond());
    $user = auth()->user();
    $conversation = Conversation::start($user, 'admin');
    $conversation->addMessage(Message::ROLE_USER, 'Primeira pergunta');
    $first = $conversation->addMessage(Message::ROLE_ASSISTANT, 'Primeira resposta');
    $conversation->addMessage(Message::ROLE_USER, 'Segunda pergunta');
    event(new TurnCompleted($user, $conversation, 'm', 1, 1, 1, [], messageId: $first->id));

    Livewire::test(ChatAiLogsPage::class)->assertSee('Primeira pergunta')->assertDontSee('Segunda pergunta');
});

it('shows the tool as JSON, cURL and a laravel/mcp class, never with the key', function () {
    config(['chatai.http_tools.allowed_hosts' => ['api.loja.test']]);
    CustomTool::create(['name' => 'payment-status', 'title' => 'Pagamento', 'description' => 'Estado do pagamento', 'type' => 'http', 'enabled' => true, 'config' => [
        'url' => 'https://api.loja.test/payments/{reference}', 'auth' => 'bearer', 'secret' => 'tok-super-secret',
        'params' => [['name' => 'reference', 'description' => 'Referência', 'required' => true, 'in' => 'path'], ['name' => 'expand', 'in' => 'query']],
    ]]);

    $page = Livewire::withQueryParams(['tool' => 'payment-status'])->test(ChatAiToolEditorPage::class);
    $blocks = collect(ToolCode::blocks($page->instance()->draftTool(), $page->get('data'), 'custom'))->keyBy('id');

    expect($blocks->keys()->all())->toBe(['json', 'curl', 'php'])
        ->and($blocks['json']['code'])->toContain('"input_schema"')->toContain('"reference"')
        ->and($blocks['curl']['code'])->toContain('https://api.loja.test/payments/<reference>')->toContain('Bearer $API_KEY')->toContain('expand=<expand>')
        ->and($blocks['php']['code'])->toContain('class PaymentStatusTool extends Tool')->toContain("'reference' => \$schema->string()")
        ->and($blocks->implode('code', "\n"))->not->toContain('tok-super-secret');

    $page->assertSee('Bearer $API_KEY')->assertDontSee('tok-super-secret');
});

it('builds the API input schema from the parameters on request', function () {
    config(['chatai.http_tools.allowed_hosts' => ['api.loja.test']]);

    Livewire::test(ChatAiToolEditorPage::class)
        ->fillForm(['type' => 'http', 'config' => ['url' => 'https://api.loja.test/x', 'auth' => 'none', 'params' => [['name' => 'city', 'description' => 'Cidade', 'in' => 'query', 'required' => true]]]])
        ->callFormComponentAction('config.schema', 'schemaFromParams')
        ->assertFormSet(fn (array $state): array => [
            'config.schema' => json_encode(['type' => 'object', 'properties' => ['city' => ['type' => 'string', 'description' => 'Cidade']], 'required' => ['city']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
});

it('tests an API tool whose host was allowed on the settings page', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    ChatAiSettings::save(['http_tools.allowed_hosts' => ['api.meteo.test']]);
    ChatAiSettings::flush();
    config(['chatai.http_tools.allowed_hosts' => []]); // only the saved setting allows it
    Http::fake(['https://api.meteo.test/*' => Http::response(['temp' => 27])]);
    app()->instance(LlmClient::class, FakeLlmClient::script([[FakeLlmClient::call('tempo', ['city' => 'Maputo'])], ['Estão 27 °C.']]));

    $id = $this->postJson(route('filament.admin.chatai.playground.store'))->json('id');

    $body = $this->post(route('filament.admin.chatai.playground.messages', $id), [
        'message' => 'Temperatura em Maputo?',
        'draft' => ['mode' => 'custom', 'data' => ['name' => 'tempo', 'title' => 'Tempo', 'description' => 'x', 'type' => 'http', 'config' => ['url' => 'https://api.meteo.test/now', 'auth' => 'none', 'params' => [['name' => 'city', 'in' => 'query']]]]],
    ])->assertOk()->streamedContent();

    expect($body)->toContain('"ok":true')->toContain('Estão 27 °C.');
});

it('links skills to a tool from its editor, both ways', function () {
    $report = Skill::create(['name' => 'monthly-report', 'title' => 'Relatório mensal', 'when_to_use' => 'Fecho do mês', 'instructions' => 'x', 'tools' => ['other-tool'], 'enabled' => true]);
    $daily = Skill::create(['name' => 'daily', 'title' => 'Resumo do dia', 'when_to_use' => 'Hoje', 'instructions' => 'y', 'tools' => ['sales-stats'], 'enabled' => true]);

    $page = Livewire::withQueryParams(['tool' => 'sales-stats', 'mode' => 'code'])->test(ChatAiToolEditorPage::class)
        ->assertSet('data.skills', [$daily->id]);

    $page->set('data.skills', [$report->id])->call('save')->assertHasNoFormErrors();

    expect($report->fresh()->tools)->toBe(['other-tool', 'sales-stats'])
        ->and($daily->fresh()->tools)->toBe([]);
});

it('loads the linked skills in the test chat', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    $skill = Skill::create(['name' => 'daily', 'title' => 'Resumo do dia', 'when_to_use' => 'Quando pedirem o resumo do dia', 'instructions' => 'Vendas primeiro.', 'tools' => [], 'enabled' => true]);
    $llm = FakeLlmClient::script([[FakeLlmClient::call('use_skill', ['name' => 'daily'])], ['Ok.']]);
    app()->instance(LlmClient::class, $llm);

    $id = $this->postJson(route('filament.admin.chatai.playground.store'))->json('id');
    $body = $this->post(route('filament.admin.chatai.playground.messages', $id), [
        'message' => 'Resumo do dia',
        'draft' => ['mode' => 'code', 'tool' => 'sales-stats', 'data' => ['skills' => [$skill->id]]],
    ])->assertOk()->streamedContent();

    expect(collect($llm->requests[0]['tools'])->pluck('function.name')->all())->toBe(['sales-stats', 'use_skill', 'show_ui'])
        ->and($llm->requests[0]['messages'][0]['content'])->toContain('daily: Quando pedirem o resumo do dia')
        ->and($body)->toContain('"name":"use_skill"');
});

it('tests with the model and options chosen in the test chat', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    $connection = AiConnection::create(['name' => 'OpenAI', 'driver' => 'openai', 'base_url' => 'https://api.openai.test/v1', 'api_key' => 'k', 'enabled' => true]);
    AiModel::create(['connection_id' => $connection->id, 'provider' => 'openai', 'model' => 'main-model', 'sort' => 1, 'enabled' => true]);
    $other = AiModel::create(['connection_id' => $connection->id, 'provider' => 'openai', 'model' => 'o3', 'effort' => 'low', 'sort' => 2, 'enabled' => true]);
    $llm = FakeLlmClient::script([['Ok.']]);
    app()->instance(LlmClient::class, $llm);

    $id = $this->postJson(route('filament.admin.chatai.playground.store'))->json('id');
    $this->post(route('filament.admin.chatai.playground.messages', $id), [
        'message' => 'Olá',
        'draft' => ['mode' => 'code', 'tool' => 'sales-stats', 'data' => [], 'options' => ['model_id' => $other->id, 'effort' => 'high', 'max_tokens' => 2048, 'temperature' => 0.2]],
    ])->assertOk()->streamedContent();

    $config = $llm->configs[0];

    expect([$config->model, $config->effort, $config->outputTokens, $config->temperature, $config->fallback])->toBe(['o3', 'high', 2048, 0.2, null]);
});

it('ignores an effort the chosen model does not accept', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    $connection = AiConnection::create(['name' => 'OpenAI', 'driver' => 'openai', 'enabled' => true]);
    $reasoning = AiModel::create(['connection_id' => $connection->id, 'provider' => 'openai', 'model' => 'o3', 'effort' => 'low', 'sort' => 1, 'enabled' => true]);
    $plain = AiModel::create(['connection_id' => $connection->id, 'provider' => 'openai', 'model' => 'gpt-4.1', 'sort' => 2, 'enabled' => true]);
    $llm = FakeLlmClient::script([['a'], ['b']]);
    app()->instance(LlmClient::class, $llm);
    $id = $this->postJson(route('filament.admin.chatai.playground.store'))->json('id');
    $send = fn ($model, $effort) => $this->post(route('filament.admin.chatai.playground.messages', $id), ['message' => 'x', 'draft' => ['mode' => 'code', 'tool' => 'sales-stats', 'options' => ['model_id' => $model->id, 'effort' => $effort]]])->streamedContent();

    $send($reasoning, 'max');   // OpenAI-compatible: no "max"
    $send($plain, 'high');      // a model without effort

    expect($llm->configs[0]->effort)->toBe('low')->and($llm->configs[1]->effort)->toBeNull();
});
