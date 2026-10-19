<?php

use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Llm\LlmException;
use Filament\ChatAi\Llm\ProviderLlmClient;
use Filament\ChatAi\Llm\StreamChunk;
use Filament\ChatAi\Models\Setting;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Testing\FakeLlmClient;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => ChatAiSettings::flush());

it('overrides the env config with saved settings', function () {
    config(['chatai.provider' => 'openai', 'chatai.model' => 'from-env']);

    ChatAiSettings::save(['provider' => 'anthropic', 'anthropic.model' => 'claude-opus-5-5', 'model' => null]);
    ChatAiSettings::apply();

    expect(config('chatai.provider'))->toBe('anthropic')
        ->and(config('chatai.anthropic.model'))->toBe('claude-opus-5-5')
        ->and(config('chatai.model'))->toBe('from-env');
});

it('stores keys encrypted and keeps them when the field is left blank', function () {
    ChatAiSettings::save(['api_key' => 'sk-secret-1']);
    ChatAiSettings::save(['api_key' => '', 'model' => 'gpt']);
    ChatAiSettings::apply();

    $raw = DB::table('chatai_settings')->where('key', 'api_key')->value('value');

    expect(config('chatai.api_key'))->toBe('sk-secret-1')
        ->and($raw)->not->toContain('sk-secret-1')
        ->and(ChatAiSettings::formState()['api_key'])->toBeNull()
        ->and(ChatAiSettings::hasSecret('api_key'))->toBeTrue();
});

it('ignores keys that are not settings', function () {
    ChatAiSettings::save(['app.key' => 'x', 'provider' => 'anthropic']);

    expect(Setting::query()->pluck('key')->all())->toBe(['provider']);
});

it('builds a fallback provider and switches to it when the main one fails', function () {
    config(['chatai.provider' => 'anthropic', 'chatai.fallback_provider' => 'openai', 'chatai.model' => 'gpt-backup', 'chatai.anthropic.model' => 'claude-opus-5-5']);

    $config = LlmConfig::fromConfig();

    expect($config->provider)->toBe('anthropic')
        ->and($config->fallback?->provider)->toBe('openai')
        ->and($config->fallback?->model)->toBe('gpt-backup');

    $main = FakeLlmClient::script([new LlmException('down', 503)]);
    $backup = FakeLlmClient::script([['Olá da reserva.']]);
    $client = new ProviderLlmClient(openAi: null, anthropic: null, resolve: fn (LlmConfig $c): LlmClient => $c->provider === 'anthropic' ? $main : $backup);

    $text = collect(iterator_to_array($client->stream($config, [['role' => 'user', 'content' => 'Olá']], []), false))
        ->where('type', StreamChunk::TEXT)->pluck('text')->implode('');

    expect($text)->toBe('Olá da reserva.');
});

it('has no fallback when it is the same provider or unset', function () {
    config(['chatai.provider' => 'openai', 'chatai.fallback_provider' => 'openai']);
    expect(LlmConfig::fromConfig()->fallback)->toBeNull();

    config(['chatai.fallback_provider' => null]);
    expect(LlmConfig::fromConfig()->fallback)->toBeNull();
});

it('does not freeze .env values: only what differs from them is saved', function () {
    ChatAiSettings::save(['provider' => 'openai', 'timeout' => 1, 'anthropic.effort' => 'low', 'anthropic.model' => 'claude-sonnet-5-5']);

    expect(Setting::query()->pluck('key')->all())->toBe(['anthropic.model']);
});
