<?php

namespace Filament\ChatAi\Speech;

use Illuminate\Support\Facades\Http;

/**
 * Google Cloud Text-to-Speech and Speech-to-Text REST APIs, with an API key.
 */
class GoogleSpeech implements SpeechProvider
{
    use SendsSpeechRequests;

    public function __construct(
        protected string $apiKey,
        protected string $voice = 'pt-PT-Wavenet-A',
        protected string $language = 'pt-PT',
        protected int $timeout = 30,
    ) {}

    public function synthesize(string $text): string
    {
        $response = $this->send(fn () => Http::timeout($this->timeout)
            ->withHeaders(['X-Goog-Api-Key' => $this->apiKey])
            ->post('https://texttospeech.googleapis.com/v1/text:synthesize', [
                'input' => ['text' => $text],
                'voice' => ['languageCode' => $this->language, 'name' => $this->voice],
                'audioConfig' => ['audioEncoding' => 'MP3'],
            ]));

        return (string) base64_decode((string) $response->json('audioContent'), true);
    }

    public function transcribe(string $wav): string
    {
        $response = $this->send(fn () => Http::timeout($this->timeout)
            ->withHeaders(['X-Goog-Api-Key' => $this->apiKey])
            ->post('https://speech.googleapis.com/v1/speech:recognize', [
                'config' => [
                    'encoding' => 'LINEAR16',
                    'sampleRateHertz' => 16000,
                    'languageCode' => $this->language,
                    'enableAutomaticPunctuation' => true,
                ],
                'audio' => ['content' => base64_encode($wav)],
            ]));

        return trim(collect($response->json('results', []))
            ->map(fn (array $result): string => (string) ($result['alternatives'][0]['transcript'] ?? ''))
            ->implode(''));
    }

    public function voiceId(): string
    {
        return "google:{$this->voice}";
    }

    public function withVoice(string $voice): static
    {
        $clone = clone $this;
        $clone->voice = $voice;

        return $clone;
    }
}
