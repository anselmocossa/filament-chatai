<?php

use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\Preference;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Speech\VoiceCatalog;
use Filament\ChatAi\Tests\Fixtures\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Storage::fake('local');
    ChatAiSettings::flush();
    config(['chatai.speech.provider' => 'azure', 'chatai.speech.azure.key' => 'k', 'chatai.speech.azure.region' => 'westeurope', 'chatai.speech.voices' => []]);
});

function voiceUser(string $name = 'Ana'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
}

it('offers the provider voices, or only the ones the admin allowed', function () {
    expect(array_keys(VoiceCatalog::offered()))->toContain('pt-PT-RaquelNeural', 'pt-PT-DuarteNeural');

    config(['chatai.speech.voices' => ['pt-PT-DuarteNeural']]);

    expect(array_keys(VoiceCatalog::offered()))->toBe(['pt-PT-DuarteNeural']);
});

it('saves the user voice and reads answers with it', function () {
    Http::fake(['*' => Http::response('MP3', 200)]);
    $user = voiceUser();

    $this->actingAs($user)->post(route('filament.admin.chatai.preferences.update'), ['voice' => 'pt-PT-DuarteNeural'])->assertOk();

    $conversation = Conversation::start($user, 'admin');
    $answer = $conversation->addMessage(Message::ROLE_ASSISTANT, 'Olá.');
    $this->actingAs($user)->post(route('filament.admin.chatai.speech.synthesize', $answer))->assertOk();

    expect(Preference::voiceFor($user))->toBe('pt-PT-DuarteNeural');
    Http::assertSent(fn ($r) => str_contains($r->body(), "<voice name='pt-PT-DuarteNeural'>"));
});

it('refuses voices that are not offered and personal choice when the admin turned it off', function () {
    $user = voiceUser();
    config(['chatai.speech.voices' => ['pt-PT-RaquelNeural']]);

    $this->actingAs($user)->post(route('filament.admin.chatai.preferences.update'), ['voice' => 'pt-PT-DuarteNeural'])->assertUnprocessable();

    config(['chatai.speech.user_choice' => false]);

    $this->actingAs($user)->post(route('filament.admin.chatai.preferences.update'), ['voice' => 'pt-PT-RaquelNeural'])->assertForbidden();
});

it('plays a sample of an offered voice', function () {
    Http::fake(['*' => Http::response('SAMPLE', 200)]);

    $response = $this->actingAs(voiceUser())->post(route('filament.admin.chatai.speech.sample'), ['voice' => 'pt-PT-FernandaNeural']);

    $response->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    expect($response->getContent())->toBe('SAMPLE');
    Http::assertSent(fn ($r) => str_contains($r->body(), "<voice name='pt-PT-FernandaNeural'>"));
});
