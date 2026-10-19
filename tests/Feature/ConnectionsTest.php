<?php

use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Llm\OpenAiCompatibleClient;
use Filament\ChatAi\Models\AiConnection;
use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Pages\ChatAiProvidersPage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    ChatAiSettings::flush();
    Filament::getPanel('admin')->getPlugin('chatai')->canManageSettings(fn (): bool => true);
    $this->actingAs(User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::getPanel('admin')->getPlugin('chatai')->canManageSettings(null));

it('chains any number of providers, each with its own address and key', function () {
    config(['chatai.anthropic.api_key' => 'env-ant']);
    $openrouter = AiConnection::create(['name' => 'OpenRouter', 'driver' => 'openai', 'base_url' => 'https://openrouter.ai/api/v1', 'api_key' => 'or-key', 'enabled' => true]);
    $openai = AiConnection::create(['name' => 'OpenAI', 'driver' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'api_key' => 'oa-key', 'enabled' => true]);
    $claude = AiConnection::create(['name' => 'Anthropic', 'driver' => 'anthropic', 'enabled' => true]);

    AiModel::create(['connection_id' => $claude->id, 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'sort' => 1, 'enabled' => true]);
    AiModel::create(['connection_id' => $openrouter->id, 'provider' => 'openai', 'model' => 'openai/gpt-4.1-mini', 'sort' => 2, 'enabled' => true]);
    AiModel::create(['connection_id' => $openai->id, 'provider' => 'openai', 'model' => 'gpt-4.1', 'sort' => 3, 'enabled' => true]);

    $config = LlmConfig::fromConfig();

    expect([$config->provider, $config->model, $config->apiKey])->toBe(['anthropic', 'claude-opus-5-5', 'env-ant'])
        ->and([$config->fallback->baseUrl, $config->fallback->model, $config->fallback->apiKey])->toBe(['https://openrouter.ai/api/v1', 'openai/gpt-4.1-mini', 'or-key'])
        ->and([$config->fallback->fallback->baseUrl, $config->fallback->fallback->model, $config->fallback->fallback->apiKey])->toBe(['https://api.openai.com/v1', 'gpt-4.1', 'oa-key'])
        ->and($config->fallback->fallback->fallback)->toBeNull();
});

it('stores provider keys encrypted', function () {
    AiConnection::create(['name' => 'OpenAI', 'driver' => 'openai', 'api_key' => 'sk-very-secret', 'enabled' => true]);

    expect(DB::table('chatai_connections')->value('api_key'))->not->toContain('sk-very-secret');
});

it('fills the providers from the current configuration and links the models', function () {
    config(['chatai.base_url' => 'https://openrouter.ai/api/v1']);
    AiModel::create(['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'sort' => 1, 'enabled' => true]);
    AiModel::create(['provider' => 'openai', 'model' => 'openai/gpt-4.1-mini', 'sort' => 2, 'enabled' => true]);

    $this->get('/admin/chatai/providers')->assertOk();

    expect(AiConnection::query()->pluck('driver')->sort()->values()->all())->toBe(['anthropic', 'openai'])
        ->and(AiModel::query()->whereNull('connection_id')->count())->toBe(0)
        ->and(AiConnection::query()->whereNotNull('api_key')->count())->toBe(0); // .env keys are not copied
});

it('adds a second OpenAI-compatible provider with its own key, keeping the key on edit', function () {
    Livewire::test(ChatAiProvidersPage::class)
        ->callTableAction('create', data: ['name' => 'Ollama local', 'driver' => 'openai', 'base_url' => 'http://localhost:11434/v1', 'api_key' => 'ollama', 'enabled' => true])
        ->assertHasNoTableActionErrors();

    $connection = AiConnection::query()->where('name', 'Ollama local')->sole();

    Livewire::test(ChatAiProvidersPage::class)
        ->callTableAction('edit', $connection, data: ['name' => 'Ollama', 'api_key' => ''])
        ->assertHasNoTableActionErrors();

    expect($connection->fresh()->name)->toBe('Ollama')->and($connection->fresh()->api_key)->toBe('ollama');
});

it('leaves the models of a switched-off provider out of the chain', function () {
    $on = AiConnection::create(['name' => 'Anthropic', 'driver' => 'anthropic', 'enabled' => true]);
    $off = AiConnection::create(['name' => 'OpenRouter', 'driver' => 'openai', 'enabled' => false]);
    AiModel::create(['connection_id' => $on->id, 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'sort' => 1, 'enabled' => true]);
    AiModel::create(['connection_id' => $off->id, 'provider' => 'openai', 'model' => 'openai/gpt-4.1-mini', 'sort' => 2, 'enabled' => true]);

    expect(AiModel::chain()->pluck('model')->all())->toBe(['claude-opus-5-5'])
        ->and(LlmConfig::fromConfig()->fallback)->toBeNull();
});

it('takes the effort from each model, for any provider', function () {
    $openai = AiConnection::create(['name' => 'OpenAI', 'driver' => 'openai', 'enabled' => true]);
    $claude = AiConnection::create(['name' => 'Anthropic', 'driver' => 'anthropic', 'enabled' => true]);
    AiModel::create(['connection_id' => $openai->id, 'provider' => 'openai', 'model' => 'o3', 'effort' => 'high', 'sort' => 1, 'enabled' => true]);
    AiModel::create(['connection_id' => $openai->id, 'provider' => 'openai', 'model' => 'gpt-4.1', 'effort' => null, 'sort' => 2, 'enabled' => true]);
    AiModel::create(['connection_id' => $claude->id, 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'low', 'sort' => 3, 'enabled' => true]);

    $config = LlmConfig::fromConfig();

    expect([$config->model, $config->effort])->toBe(['o3', 'high'])
        ->and([$config->fallback->model, $config->fallback->effort])->toBe(['gpt-4.1', null])
        ->and([$config->fallback->fallback->model, $config->fallback->fallback->effort])->toBe(['claude-opus-5-5', 'low']);
});

it('sends reasoning_effort to OpenAI-compatible models only when set', function () {
    Http::fake(['*' => Http::response("data: [DONE]\n\n", 200)]);
    $client = new OpenAiCompatibleClient;

    iterator_to_array($client->stream(new LlmConfig('https://api.test', 'k', 'o3', effort: 'high'), [['role' => 'user', 'content' => 'x']], []), false);
    iterator_to_array($client->stream(new LlmConfig('https://api.test', 'k', 'gpt-4.1'), [['role' => 'user', 'content' => 'x']], []), false);

    $bodies = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data());

    expect($bodies[0]['reasoning_effort'])->toBe('high')
        ->and($bodies[1])->not->toHaveKey('reasoning_effort');
});
