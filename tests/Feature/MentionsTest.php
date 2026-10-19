<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Skill;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\ToolResult;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Spatie\Permission\Models\Permission;

function mentionPlugin(): ChatAiPlugin
{
    return Filament::getPanel('admin')->getPlugin('chatai');
}

beforeEach(function () {
    ChatAiSettings::flush();
    $this->withoutMiddleware(VerifyCsrfToken::class);
    mentionPlugin()->tools(fn () => [
        new ChatAiTool('sales-stats', 'Sales totals', ['type' => 'object', 'properties' => []], fn () => ToolResult::data([]), readOnly: true, title: 'Vendas'),
        new ChatAiTool('hr-summary', 'Staff', ['type' => 'object', 'properties' => []], fn () => ToolResult::data([]), readOnly: true, title: 'RH'),
    ]);
    $this->user = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']);
    $this->llm = FakeLlmClient::script([['Ok.']]);
    app()->instance(LlmClient::class, $this->llm);
});

afterEach(fn () => mentionPlugin()->tools(null));

function ask(array $payload): string
{
    $conversation = Conversation::start(test()->user, 'admin');

    return test()->actingAs(test()->user)->post(route('filament.admin.chatai.messages.store', $conversation), $payload)->assertOk()->streamedContent();
}

it('tells the model to use the tools the user mentioned with @', function () {
    ask(['message' => '@sales-stats como foi a semana?', 'mentions' => ['sales-stats', 'not-a-tool']]);

    $system = $this->llm->requests[0]['messages'][0]['content'];

    expect($system)->toContain('sales-stats')->not->toContain('not-a-tool');
});

it('applies the skill chosen with / to that question', function () {
    Skill::create(['name' => 'resumo-do-dia', 'title' => 'Resumo do dia', 'when_to_use' => 'Resumo', 'instructions' => 'Vendas primeiro, depois stock.', 'enabled' => true]);

    ask(['message' => '/resumo-do-dia', 'skill' => 'resumo-do-dia']);

    expect($this->llm->requests[0]['messages'][0]['content'])->toContain('Vendas primeiro, depois stock.');
});

it('offers the user only the tools and skills they can use', function () {
    ChatAiSettings::save(['permissions.enabled' => true]);
    Permission::findOrCreate('UseChatAiTool:SalesStats', 'web');
    $this->user->givePermissionTo('UseChatAiTool:SalesStats');
    Skill::create(['name' => 'resumo-do-dia', 'title' => 'Resumo do dia', 'when_to_use' => 'Resumo', 'instructions' => 'x', 'enabled' => true]);
    Skill::create(['name' => 'off', 'title' => 'Off', 'when_to_use' => 'x', 'instructions' => 'x', 'enabled' => false]);
    $this->actingAs($this->user->fresh());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $config = mentionPlugin()->clientConfig(remember: false);

    expect(collect($config['mentions'])->pluck('name')->all())->toBe(['sales-stats'])
        ->and(collect($config['mentions'])->first()['label'])->toBe('Vendas')
        ->and(collect($config['skills'])->pluck('name')->all())->toBe(['resumo-do-dia']);
});
