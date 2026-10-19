<?php

use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Tests\Fixtures\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Storage::fake('local');
    config(['chatai.speech.provider' => 'azure', 'chatai.speech.azure.key' => 'secret', 'chatai.speech.azure.region' => 'westeurope']);
});

function answer(User $user): Message
{
    $conversation = Conversation::start($user, 'admin');
    $conversation->addMessage(Message::ROLE_USER, 'Stock?');

    return $conversation->addMessage(Message::ROLE_ASSISTANT, 'Há **5** produtos.');
}

function speechUser(string $name = 'Ana'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
}

it('reads an answer aloud once and serves the cached audio after', function () {
    Http::fake(['*' => Http::response('MP3BYTES', 200)]);
    $user = speechUser();
    $message = answer($user);

    $first = $this->actingAs($user)->post(route('filament.admin.chatai.speech.synthesize', $message));
    $second = $this->actingAs($user)->post(route('filament.admin.chatai.speech.synthesize', $message));

    $first->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    expect($first->getContent())->toBe('MP3BYTES')
        ->and($second->getContent())->toBe('MP3BYTES');
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_contains($r->body(), '>Há 5 produtos.</voice>'));
});

it('only reads answers from the user own conversations', function () {
    Http::fake();
    $message = answer(speechUser('Ana'));

    $this->actingAs(speechUser('Rui'))->post(route('filament.admin.chatai.speech.synthesize', $message))->assertForbidden();
    Http::assertNothingSent();
});

it('says there is no server voice when none is configured', function () {
    config(['chatai.speech.provider' => null]);
    $user = speechUser();

    $this->actingAs($user)->post(route('filament.admin.chatai.speech.synthesize', answer($user)))->assertNotFound();
});

it('transcribes a recorded question', function () {
    Http::fake(['*' => Http::response(['RecognitionStatus' => 'Success', 'DisplayText' => 'Quantos pedidos há?'])]);

    $this->actingAs(speechUser())
        ->post(route('filament.admin.chatai.speech.transcribe'), ['audio' => UploadedFile::fake()->createWithContent('q.wav', 'RIFF....WAVE')])
        ->assertOk()
        ->assertJson(['text' => 'Quantos pedidos há?']);
});

it('reports a friendly error when the voice service fails', function () {
    Http::fake(['*' => Http::response('boom secret', 500)]);
    $user = speechUser();

    $this->actingAs($user)->post(route('filament.admin.chatai.speech.synthesize', answer($user)))
        ->assertStatus(503)
        ->assertJsonMissing(['boom secret']);
});
