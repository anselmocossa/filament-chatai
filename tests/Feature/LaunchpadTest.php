<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Launchpad\CostKpi;
use Filament\ChatAi\Launchpad\LaunchpadCards;
use Filament\ChatAi\Launchpad\PendingKpi;
use Filament\ChatAi\Launchpad\QuestionsKpi;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\ToolAction;
use Filament\ChatAi\Models\Usage;
use Filament\ChatAi\Pages\ChatAiPage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Filament\Launchpad\LaunchpadPlugin;
use Livewire\Livewire;

beforeEach(function () {
    ChatAiSettings::flush();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->user = User::create(['name' => 'Ana', 'email' => 'ana@launchpad.test', 'password' => 'x']));
    $this->other = User::create(['name' => 'Rui', 'email' => 'rui@launchpad.test', 'password' => 'x']);
});

function chataiUsage(User $user, string $when, float $cost = 0.01): void
{
    Usage::query()->create([
        'user_type' => $user->getMorphClass(), 'user_id' => $user->getKey(), 'panel_id' => 'admin',
        'kind' => Usage::KIND_CHAT, 'model' => 'm', 'cost' => $cost, 'created_at' => $when,
    ]);
}

it('hands the launchpad its KPI sources without touching the app\'s own discovery', function () {
    $options = LaunchpadPlugin::make()->getKpiSourceOptions();

    expect($options)->toHaveKeys(['chatai_questions', 'chatai_pending', 'chatai_cost'])
        ->and($options['chatai_pending'])->toBe(__('chatai::chatai.launchpad.pending'));
});

it('counts only the user\'s own questions, with a bar per day', function () {
    chataiUsage($this->user, now()->toDateTimeString());
    chataiUsage($this->user, now()->toDateTimeString());
    chataiUsage($this->user, now()->subDays(3)->toDateTimeString());
    chataiUsage($this->user, now()->subDays(40)->toDateTimeString());
    chataiUsage($this->other, now()->toDateTimeString());

    $result = (new QuestionsKpi)->resolve();

    expect($result->getValue())->toBe(3)
        ->and($result->getTrend())->toBe(__('chatai::chatai.launchpad.today', ['count' => 2]))
        ->and($result->getSparkline())->toHaveCount(14)
        ->and(array_sum($result->getSparkline()))->toEqual(3.0)
        ->and($result->getSparkline()[13])->toEqual(2.0);
});

it('counts the changes waiting in the user\'s own conversations', function () {
    $mine = Conversation::start($this->user, 'admin');
    $theirs = Conversation::start($this->other, 'admin');
    $action = fn (Conversation $c, string $status) => ToolAction::query()->create(['conversation_id' => $c->id, 'user_type' => $c->user_type, 'user_id' => $c->user_id, 'tool' => 't', 'arguments' => [], 'context' => [], 'status' => $status]);
    $action($mine, ToolAction::PENDING);
    $action($mine, ToolAction::PENDING);
    $action($mine, ToolAction::DONE);
    $action($theirs, ToolAction::PENDING);

    $result = (new PendingKpi)->resolve();

    expect($result->getValue())->toBe(2)->and($result->getBadge())->not->toBeNull();

    ToolAction::query()->update(['status' => ToolAction::DONE]);

    expect((new PendingKpi)->resolve()->getBadge())->toBeNull();
});

it('shows the cost only to the people who manage the assistant', function () {
    chataiUsage($this->user, now()->toDateTimeString(), 1.25);
    chataiUsage($this->other, now()->toDateTimeString(), 0.5);

    expect((new CostKpi)->authorize($this->user))->toBeFalse()
        ->and(collect(LaunchpadCards::presets())->pluck('key'))->not->toContain('chatai_cost');

    ChatAiPlugin::get()->canManageSettings(fn (): bool => true);

    $result = (new CostKpi)->resolve();

    expect((new CostKpi)->authorize($this->user))->toBeTrue()
        ->and($result->getValue())->toBe('1.75')
        ->and($result->getSparkline())->toHaveCount(now()->day)
        ->and(collect(LaunchpadCards::presets())->pluck('key'))->toContain('chatai_cost');

    ChatAiPlugin::get()->canManageSettings(null);
});

it('offers ready cards: the assistant, pending changes and one "ask" tile per suggested question', function () {
    config(['chatai.starters' => ['Sales today?']]);

    $presets = collect(LaunchpadCards::presets())->keyBy('key');
    $ask = $presets->first(fn (array $p): bool => str_starts_with($p['key'], 'chatai_ask_'));

    expect($presets['chatai_assistant'])->toMatchArray(['type' => 'kpi', 'kpi_source' => 'chatai_questions', 'target_type' => 'page', 'target_value' => ChatAiPage::class])
        ->and($presets['chatai_pending']['kpi_source'])->toBe('chatai_pending')
        ->and($ask)->toMatchArray(['title' => 'Sales today?', 'type' => 'shortcut', 'target_type' => 'url'])
        ->and($ask['target_value'])->toBe(ChatAiPage::getUrl(['ask' => 'Sales today?']))
        ->and(collect(LaunchpadPlugin::make()->getCardLibrary())->pluck('key'))->toContain('chatai_assistant', $ask['key']);
});

it('offers nothing to a user who may not use the assistant', function () {
    ChatAiPlugin::get()->canAccess(fn (): bool => false);

    expect(LaunchpadCards::presets())->toBe([])
        ->and((new QuestionsKpi)->authorize($this->user))->toBeFalse();

    ChatAiPlugin::get()->canAccess(fn (): bool => true);
});

it('sends the question of an "ask" link once, only in a new conversation', function () {
    $config = fn ($component) => $component->viewData('config');

    expect($config(Livewire::withQueryParams(['ask' => 'Sales today?'])->test(ChatAiPage::class))['ask'])->toBe('Sales today?');

    $conversation = Conversation::start($this->user, 'admin');

    expect($config(Livewire::withQueryParams(['ask' => 'Sales today?', 'conversation' => $conversation->id])->test(ChatAiPage::class))['ask'])->toBeNull();
});
