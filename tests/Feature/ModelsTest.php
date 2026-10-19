<?php

use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Pages\ChatAiModelsPage;
use Filament\ChatAi\Pages\ChatAiSettingsPage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\Pricing;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ChatAiSettings::flush();
    Filament::getPanel('admin')->getPlugin('chatai')->canManageSettings(fn (): bool => true);
    $this->actingAs(User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::getPanel('admin')->getPlugin('chatai')->canManageSettings(null));

it('uses the list order: first is the main model, the rest are fallbacks, also across providers', function () {
    AiModel::create(['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'sort' => 1, 'enabled' => true]);
    AiModel::create(['provider' => 'openai', 'model' => 'openai/gpt-4.1-mini', 'sort' => 2, 'enabled' => true]);
    AiModel::create(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'sort' => 3, 'enabled' => true]);
    AiModel::create(['provider' => 'anthropic', 'model' => 'claude-off', 'sort' => 4, 'enabled' => false]);

    $config = LlmConfig::fromConfig();

    expect($config->provider)->toBe('anthropic')
        ->and($config->models())->toBe(['claude-opus-5-5', 'claude-sonnet-5-5'])
        ->and($config->fallback?->provider)->toBe('openai')
        ->and($config->fallback?->models())->toBe(['openai/gpt-4.1-mini']);
});

it('keeps using the .env models while the list is empty', function () {
    config(['chatai.provider' => 'openai', 'chatai.model' => 'from-env']);

    expect(LlmConfig::fromConfig()->model)->toBe('from-env');
});

it('prices usage with the prices set on each model', function () {
    AiModel::create(['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'sort' => 1, 'enabled' => true, 'input_price' => 5, 'output_price' => 25]);

    expect(Pricing::chat('claude-opus-5-5', 1_000_000, 0))->toBe(5.0);
});

it('fills the list from the current configuration on first visit', function () {
    config(['chatai.provider' => 'anthropic', 'chatai.anthropic.model' => 'claude-opus-5-5', 'chatai.anthropic.fallback_models' => ['claude-sonnet-5-5'], 'chatai.fallback_provider' => 'openai', 'chatai.model' => 'openai/gpt-4.1-mini',
        'chatai.pricing.models' => [['model' => 'claude-opus-5-5', 'input' => 5, 'output' => 25]]]);

    $this->get('/admin/chatai/models')->assertOk()->assertSee('claude-sonnet-5-5');

    expect(AiModel::query()->orderBy('sort')->pluck('model')->all())->toBe(['claude-opus-5-5', 'claude-sonnet-5-5', 'openai/gpt-4.1-mini'])
        ->and((float) AiModel::query()->where('model', 'claude-opus-5-5')->value('input_price'))->toBe(5.0);
});

it('adds a model from the list', function () {
    AiModel::create(['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'sort' => 1, 'enabled' => true]);

    Livewire::test(ChatAiModelsPage::class)
        ->callTableAction('create', data: ['provider' => 'openai', 'model' => 'openai/gpt-4.1-mini', 'enabled' => true, 'input_price' => 0.4, 'output_price' => 1.6])
        ->assertHasNoTableActionErrors();

    expect(AiModel::query()->orderBy('sort')->pluck('model')->all())->toBe(['claude-opus-5-5', 'openai/gpt-4.1-mini']);
});

it('opens settings as tabs', function () {
    $this->get('/admin/chatai/settings')->assertOk()->assertSee('fi-sc-tabs', false);
});

it('names the settings page in the menu and title', function () {
    expect(ChatAiSettingsPage::getNavigationLabel())->toBe(__('chatai::chatai.settings.title'));
    expect((new ChatAiSettingsPage)->getTitle())->toBe(__('chatai::chatai.settings.title'));
});
