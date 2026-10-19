<?php

use Filament\ChatAi\Events\TurnCompleted;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\AiConnection;
use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Usage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\Budget;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

beforeEach(function () {
    ChatAiSettings::flush();
    $this->claude = AiConnection::create(['name' => 'Anthropic', 'driver' => 'anthropic', 'enabled' => true, 'monthly_limit' => 10]);
    $this->openrouter = AiConnection::create(['name' => 'OpenRouter', 'driver' => 'openai', 'enabled' => true]);
    AiModel::create(['connection_id' => $this->claude->id, 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'sort' => 1, 'enabled' => true, 'input_price' => 5, 'output_price' => 25]);
    AiModel::create(['connection_id' => $this->openrouter->id, 'provider' => 'openai', 'model' => 'openai/gpt-4.1-mini', 'sort' => 2, 'enabled' => true]);
    $this->user = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']);
});

it('records which provider each answer was billed to', function () {
    event(new TurnCompleted($this->user, Conversation::start($this->user, 'admin'), 'claude-opus-5-5', 1_000_000, 0, 10, []));

    expect(Usage::query()->sole()->connection_id)->toBe($this->claude->id)
        ->and(Budget::spent($this->claude, 'month'))->toBe(5.0);
});

it('skips a provider that reached its limit and uses the next one', function () {
    event(new TurnCompleted($this->user, Conversation::start($this->user, 'admin'), 'claude-opus-5-5', 2_000_000, 0, 10, []));

    $config = LlmConfig::fromConfig();

    expect(Budget::exceeded($this->claude))->toBeTrue()
        ->and($config->model)->toBe('openai/gpt-4.1-mini')
        ->and($config->fallback)->toBeNull();
});

it('counts the daily limit only for today', function () {
    $this->claude->update(['monthly_limit' => null, 'daily_limit' => 1]);
    $old = Usage::record($this->user, Usage::KIND_CHAT, ['connection_id' => $this->claude->id, 'model' => 'claude-opus-5-5', 'cost' => 5]);
    $old->forceFill(['created_at' => now()->subDay()])->save();

    expect(Budget::exceeded($this->claude->fresh()))->toBeFalse();
});

it('pauses the assistant with a clear message when every provider reached its limit', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    $this->openrouter->update(['monthly_limit' => 1]);
    Usage::record($this->user, Usage::KIND_CHAT, ['connection_id' => $this->claude->id, 'cost' => 10]);
    Usage::record($this->user, Usage::KIND_CHAT, ['connection_id' => $this->openrouter->id, 'cost' => 1]);
    app()->instance(LlmClient::class, FakeLlmClient::script([['Olá']]));
    $conversation = Conversation::start($this->user, 'admin');

    $this->actingAs($this->user)
        ->post(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'Olá'])
        ->assertStatus(429)
        ->assertJson(['message' => __('chatai::chatai.budget_reached')]);
});
