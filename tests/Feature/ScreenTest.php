<?php

use Filament\ChatAi\Agent\AgentEvent;
use Filament\ChatAi\Agent\Orchestrator;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\ArrayToolProvider;

beforeEach(fn () => ChatAiSettings::flush());

function screenContext(): ChatAiContext
{
    return ChatAiContext::fromArray('admin', [
        'url' => '/shop/products/1/edit',
        'screen' => [
            'heading' => 'Edit Bamboo Card Holder',
            'fields' => [
                ['path' => 'data.qty', 'label' => 'Quantity', 'type' => 'number', 'value' => '50'],
                ['path' => 'data.name', 'label' => 'Name', 'type' => 'text', 'value' => 'Bamboo Card Holder'],
                ['path' => 'bad path!', 'label' => 'x'],
            ],
        ],
    ]);
}

function screenTurn(FakeLlmClient $llm, ChatAiContext $context): array
{
    $user = User::create(['name' => 'Ana', 'email' => 'ana@screen.test', 'password' => 'x']);

    return iterator_to_array((new Orchestrator($llm))->run(
        conversation: Conversation::start($user, 'admin'),
        question: 'Põe a quantidade em 80',
        context: $context,
        tools: new ArrayToolProvider([]),
        user: $user,
        config: new LlmConfig('https://llm.test', 'k', 'test-model'),
    ), false);
}

it('tells the model what is on screen and keeps only safe field paths', function () {
    $context = screenContext();
    $llm = FakeLlmClient::script([['Ok.']]);
    screenTurn($llm, $context);

    expect(array_keys($context->screenFields()))->toBe(['data.qty', 'data.name'])
        ->and($llm->requests[0]['messages'][0]['content'])->toContain('Edit Bamboo Card Holder')->toContain('data.qty')
        ->and(collect($llm->requests[0]['tools'])->pluck('function.name'))->toContain('fill_form');
});

it('fills in only fields that are on screen and never claims to save', function () {
    $events = screenTurn(FakeLlmClient::script([
        [FakeLlmClient::call('fill_form', ['fields' => [['path' => 'data.qty', 'value' => 80], ['path' => 'data.price', 'value' => 1]]])],
        ['Pus 80 na quantidade. Reveja e carregue em Guardar.'],
    ]), screenContext());

    $fill = collect($events)->firstWhere('type', AgentEvent::FILL);
    $card = collect($events)->where('type', AgentEvent::COMPONENT)->first();

    expect($fill->data['fields'])->toBe([['path' => 'data.qty', 'label' => 'Quantity', 'value' => 80]])
        ->and($card->data['component'])->toMatchArray(['type' => 'filled']);
});

it('ignores the screen when reading it is switched off', function () {
    ChatAiSettings::save(['screen.read' => false]);
    $llm = FakeLlmClient::script([['Ok.']]);
    $context = screenContext();
    screenTurn($llm, $context);

    expect($context->screen)->toBe([])
        ->and(collect($llm->requests[0]['tools'])->pluck('function.name'))->not->toContain('fill_form');
});

it('reads the screen but does not offer to fill it when filling is off', function () {
    ChatAiSettings::save(['screen.fill' => false]);
    $llm = FakeLlmClient::script([['Ok.']]);
    screenTurn($llm, screenContext());

    expect(collect($llm->requests[0]['tools'])->pluck('function.name'))->not->toContain('fill_form')
        ->and($llm->requests[0]['messages'][0]['content'])->toContain('Edit Bamboo Card Holder');
});

it('fills a wizard: fields of several steps in one call, lists for checkbox lists', function () {
    $context = ChatAiContext::fromArray('admin', ['screen' => ['fields' => [
        ['path' => 'data.name', 'label' => 'Name', 'section' => 'Step 1: Personal'],
        ['path' => 'data.employment_type', 'label' => 'Employment type', 'type' => 'radio', 'section' => 'Step 2: Job', 'options' => ['full_time = Full Time', 'intern = Intern']],
        ['path' => 'data.skills', 'label' => 'Skills', 'type' => 'checkbox_list', 'section' => 'Step 4: Skills', 'options' => ['PHP', 'Laravel']],
    ]]]);

    expect($context->screenFields()['data.employment_type'])->toMatchArray(['section' => 'Step 2: Job', 'options' => ['full_time = Full Time', 'intern = Intern']]);

    $events = screenTurn(FakeLlmClient::script([
        [FakeLlmClient::call('fill_form', ['fields' => [
            ['path' => 'data.name', 'value' => 'Ana Muianga'],
            ['path' => 'data.employment_type', 'value' => 'full_time'],
            ['path' => 'data.skills', 'value' => ['PHP', 'Laravel', ['nested']]],
        ]])],
        ['Preenchi os três passos.'],
    ]), $context);

    $fill = collect($events)->firstWhere('type', AgentEvent::FILL);
    $card = collect($events)->where('type', AgentEvent::COMPONENT)->first()->data['component'];

    expect(collect($fill->data['fields'])->pluck('value', 'path')->all())->toBe(['data.name' => 'Ana Muianga', 'data.employment_type' => 'full_time', 'data.skills' => ['PHP', 'Laravel']])
        ->and(collect($card['fields'])->firstWhere('path', 'data.skills')['value'])->toBe('PHP, Laravel');
});
