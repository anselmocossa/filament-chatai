<?php

use Filament\ChatAi\Events\TurnCompleted;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\Usage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\Pricing;
use Filament\ChatAi\Support\UsageReport;
use Filament\ChatAi\Tests\Fixtures\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    ChatAiSettings::flush();
    config([
        'chatai.pricing.currency' => 'USD',
        'chatai.pricing.models' => [
            ['model' => 'claude-opus-5-5', 'input' => 5, 'output' => 25],
            ['model' => 'openai/', 'input' => 0.4, 'output' => 1.6],
        ],
        'chatai.pricing.speech_per_million_characters' => 16,
        'chatai.pricing.transcription_per_hour' => 1,
    ]);
});

function usageUser(string $name = 'Ana'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
}

it('prices tokens per million, matching the model exactly or by prefix', function () {
    expect(Pricing::chat('claude-opus-5-5', 1_000_000, 100_000))->toBe(7.5)
        ->and(Pricing::chat('openai/gpt-4.1-mini', 500_000, 0))->toBe(0.2)
        ->and(Pricing::chat('unknown-model', 1000, 1000))->toBeNull()
        ->and(Pricing::speech(500_000))->toBe(8.0)
        ->and(Pricing::transcription(1800))->toBe(0.5);
});

it('records every answered question with its cost', function () {
    $user = usageUser();
    $conversation = Conversation::start($user, 'admin');

    event(new TurnCompleted($user, $conversation, 'claude-opus-5-5', 2000, 400, 1500, ['sales-stats']));

    $usage = Usage::query()->sole();

    expect($usage->kind)->toBe(Usage::KIND_CHAT)
        ->and($usage->model)->toBe('claude-opus-5-5')
        ->and($usage->tokens_in)->toBe(2000)
        ->and((float) $usage->cost)->toBe(0.02)
        ->and($usage->conversation_id)->toBe($conversation->id);
});

it('records read-aloud characters once, not on cached replays', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Storage::fake('local');
    Http::fake(['*' => Http::response('MP3', 200)]);
    config(['chatai.speech.provider' => 'azure', 'chatai.speech.azure.key' => 'k', 'chatai.speech.azure.region' => 'westeurope']);

    $user = usageUser();
    $answer = Conversation::start($user, 'admin')->addMessage(Message::ROLE_ASSISTANT, 'Olá Boss.');

    $this->actingAs($user)->post(route('filament.admin.chatai.speech.synthesize', $answer));
    $this->actingAs($user)->post(route('filament.admin.chatai.speech.synthesize', $answer));

    $usage = Usage::query()->sole();

    expect($usage->kind)->toBe(Usage::KIND_SPEECH)->and($usage->characters)->toBe(9);
});

it('sums cost and volume by user, model and day', function () {
    $ana = usageUser('Ana');
    $rui = usageUser('Rui');
    $conversation = Conversation::start($ana, 'admin');

    event(new TurnCompleted($ana, $conversation, 'claude-opus-5-5', 1_000_000, 0, 10, []));
    event(new TurnCompleted($rui, Conversation::start($rui, 'admin'), 'openai/gpt-4.1-mini', 1_000_000, 0, 10, []));

    $report = UsageReport::for(now()->startOfMonth(), now()->endOfDay());

    expect($report['totals']['cost'])->toBe(5.4)
        ->and($report['totals']['questions'])->toBe(2)
        ->and($report['by_model'][0]['model'])->toBe('claude-opus-5-5')
        ->and($report['by_user'][0]['name'])->toBe('Ana')
        ->and(collect($report['by_day'])->sum('cost'))->toBe(5.4);
});

it('counts cache reads and writes at their own price', function () {
    // 1M cache reads at 10% of 5 = 0.5; 1M cache writes at 125% of 5 = 6.25
    expect(Pricing::chat('claude-opus-5-5', 0, 0, cacheRead: 1_000_000, cacheWrite: 1_000_000))->toBe(6.75);

    config(['chatai.pricing.models' => [['model' => 'claude-opus-5-5', 'input' => 5, 'output' => 25, 'cache_read' => 1, 'cache_write' => 2]]]);
    expect(Pricing::chat('claude-opus-5-5', 0, 0, cacheRead: 1_000_000, cacheWrite: 1_000_000))->toBe(3.0);

    $user = usageUser();
    event(new TurnCompleted($user, Conversation::start($user, 'admin'), 'claude-opus-5-5', 10, 5, 100, [], cacheRead: 9000, cacheWrite: 500));

    expect(Usage::query()->sole())->cache_read_tokens->toBe(9000)->cache_write_tokens->toBe(500);
});

it('links each answered question to its message, latency and tools for the logs', function () {
    $user = usageUser();
    $conversation = Conversation::start($user, 'admin');
    $answer = $conversation->addMessage(Message::ROLE_ASSISTANT, 'Vendeu 10.');

    event(new TurnCompleted($user, $conversation, 'claude-opus-5-5', 10, 5, 1234, ['sales-stats'], messageId: $answer->id));

    $usage = Usage::query()->sole();

    expect($usage->message->content)->toBe('Vendeu 10.')
        ->and($usage->latency_ms)->toBe(1234)
        ->and($usage->tools)->toBe(['sales-stats']);
});
