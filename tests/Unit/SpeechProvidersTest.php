<?php

use Filament\ChatAi\Speech\AzureSpeech;
use Filament\ChatAi\Speech\GoogleSpeech;
use Filament\ChatAi\Speech\SpeechException;
use Filament\ChatAi\Speech\SpeechText;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('synthesizes with Azure through SSML and the chosen pt-PT voice', function () {
    Http::fake(['https://westeurope.tts.speech.microsoft.com/*' => Http::response('MP3BYTES', 200, ['Content-Type' => 'audio/mpeg'])]);

    $audio = (new AzureSpeech('secret', 'westeurope', 'pt-PT-RaquelNeural', 'pt-PT'))->synthesize('Vendas & <stock>');

    expect($audio)->toBe('MP3BYTES');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Ocp-Apim-Subscription-Key', 'secret')
        && str_contains($r->body(), "<voice name='pt-PT-RaquelNeural'>Vendas &amp; &lt;stock&gt;</voice>")
        && str_contains($r->body(), "xml:lang='pt-PT'"));
});

it('transcribes WAV audio with Azure', function () {
    Http::fake(['https://westeurope.stt.speech.microsoft.com/*' => Http::response(['RecognitionStatus' => 'Success', 'DisplayText' => 'Quantos pedidos há?'])]);

    expect((new AzureSpeech('secret', 'westeurope'))->transcribe('RIFF....'))->toBe('Quantos pedidos há?');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'language=pt-PT') && $r->hasHeader('Content-Type', 'audio/wav; codecs=audio/pcm; samplerate=16000'));
});

it('synthesizes and transcribes with Google', function () {
    Http::fake([
        'https://texttospeech.googleapis.com/*' => Http::response(['audioContent' => base64_encode('MP3')]),
        'https://speech.googleapis.com/*' => Http::response(['results' => [['alternatives' => [['transcript' => 'Olá']]], ['alternatives' => [['transcript' => ' tudo bem']]]]]),
    ]);

    $google = new GoogleSpeech('gkey', 'pt-PT-Wavenet-A', 'pt-PT');

    expect($google->synthesize('Olá'))->toBe('MP3')
        ->and($google->transcribe('RIFF'))->toBe('Olá tudo bem');
});

it('never leaks the provider body or key in errors', function () {
    Http::fake(['*' => Http::response('invalid key secret', 401)]);

    expect(fn () => (new AzureSpeech('secret', 'westeurope'))->synthesize('x'))
        ->toThrow(fn (SpeechException $e) => expect($e->getMessage())->not->toContain('secret')->and($e->getCode())->toBe(401));
});

it('turns markdown into speakable text', function () {
    expect(SpeechText::from("**Há 5 produtos** com [stock baixo](https://x.test).\n- `Oak` mug\n\n```php\ncode\n```"))
        ->toBe('Há 5 produtos com stock baixo. Oak mug');
});
