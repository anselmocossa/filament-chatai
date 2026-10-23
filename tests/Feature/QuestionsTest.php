<?php

use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmException;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Ui\ComponentValidator;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

function questionComponent(): array
{
    return ['type' => 'questions', 'title' => 'Definir pesquisa', 'questions' => [
        ['question' => 'O que pretende?', 'options' => [['label' => 'Comparar', 'description' => 'Ver diferenças'], ['label' => 'Resumir']]],
        ['question' => 'Em que área?'],
    ]];
}

beforeEach(function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    $this->actingAs($this->user = User::create(['name' => 'Ana', 'email' => 'ana@questions.test', 'password' => 'x']));
    $this->conversation = Conversation::start($this->user, 'admin');
    $this->message = $this->conversation->addMessage('assistant', '', ['components' => [(new ComponentValidator)->validate(questionComponent())]]);
    $this->url = route('filament.admin.chatai.messages.store', $this->conversation);
    app()->instance(LlmClient::class, FakeLlmClient::script([['Vou comparar na área SAP.']]));
    $this->payload = ['message' => 'untrusted label', 'question_answer' => ['message_id' => $this->message->id, 'component' => 0, 'answers' => [['option' => 0], ['text' => 'SAP']]]];
});

it('normalizes questions and never trusts model supplied answer status', function () {
    $c = (new ComponentValidator)->validate(questionComponent() + ['status' => 'answered', 'answers' => ['forged']]);
    expect($c)->not->toBeNull()
        ->and($c['status'])->toBe('pending')
        ->and($c['questions'][0]['options'][0])->toBe(['label' => 'Comparar', 'description' => 'Ver diferenças'])
        ->and($c['questions'][1]['options'])->toBe([])
        ->and($c)->not->toHaveKey('answers');
});

it('drops malformed questions and bounds model output', function () {
    $validator = new ComponentValidator;
    expect($validator->validate(['type' => 'questions', 'questions' => [['question' => []], ['question' => '   ']]]))->toBeNull();
    $c = $validator->validate(['type' => 'questions', 'questions' => array_fill(0, 10, ['question' => str_repeat('a', 600), 'options' => array_fill(0, 20, ['label' => 'Option'])])]);
    expect($c['questions'])->toHaveCount(3)
        ->and(mb_strlen($c['questions'][0]['question']))->toBe(300)
        ->and($c['questions'][0]['options'])->toHaveCount(6);
});

it('sends canonical answers to the model and persists the answered card on reload', function () {
    $response = $this->postJson($this->url, $this->payload)->assertOk();
    expect($response->streamedContent())->toContain('event: done');
    $card = $this->message->fresh()->components[0];
    expect($card['status'])->toBe('answered')
        ->and($card['answers'][0]['answer'])->toBe('Comparar')
        ->and($this->conversation->messages()->where('role', 'user')->first()->content)->toBe("O que pretende?\nComparar\n\nEm que área?\nSAP");
    $this->getJson(route('filament.admin.chatai.conversations.show', $this->conversation))->assertJsonPath('messages.0.components.0.status', 'answered');
    $this->postJson($this->url, $this->payload)->assertStatus(422);
});

it('rejects empty missing and nonexistent options without consuming the question', function () {
    foreach ([[['option' => 9], ['text' => 'SAP']], [['option' => 0], ['text' => '  ']], [['text' => 'only one']]] as $answers) {
        $payload = $this->payload;
        $payload['question_answer']['answers'] = $answers;
        $this->postJson($this->url, $payload)->assertStatus(422);
    }
    expect($this->message->fresh()->components[0]['status'])->toBe('pending');
});

it('rejects components of other conversations and another owners conversation', function () {
    $other = Conversation::start($this->user, 'admin');
    $this->postJson(route('filament.admin.chatai.messages.store', $other), $this->payload)->assertStatus(422);
    $this->actingAs(User::create(['name' => 'Rui', 'email' => 'rui@questions.test', 'password' => 'x']));
    $this->postJson($this->url, $this->payload)->assertForbidden();
});

it('does not consume questions when the turn is rate limited', function () {
    config(['chatai.rate_limit.per_minute' => 1]);
    $this->postJson($this->url, ['message' => 'Olá'])->assertOk();
    $this->postJson($this->url, $this->payload)->assertStatus(429);
    expect($this->message->fresh()->components[0]['status'])->toBe('pending');
});

it('keeps submitted answers after a provider failure so the normal retry can continue', function () {
    app()->instance(LlmClient::class, FakeLlmClient::script([new LlmException('Unavailable')]));
    $response = $this->postJson($this->url, $this->payload)->assertOk();
    expect($response->streamedContent())->toContain('event: error')
        ->and($this->message->fresh()->components[0]['status'])->toBe('answered')
        ->and($this->conversation->messages()->where('role', 'user')->first()->content)->toContain('SAP');
});

it('rejects malformed answer envelopes before opening the stream', function () {
    foreach ([[], ['component' => 0], ['message_id' => $this->message->id, 'component' => 0, 'answers' => [null]]] as $input) {
        $this->postJson($this->url, ['message' => 'x', 'question_answer' => $input])->assertStatus(422);
    }
});
