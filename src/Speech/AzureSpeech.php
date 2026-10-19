<?php

namespace Filament\ChatAi\Speech;

use Illuminate\Support\Facades\Http;

/**
 * Azure AI Speech REST API (neural voices, e.g. pt-PT-RaquelNeural).
 */
class AzureSpeech implements SpeechProvider
{
    use SendsSpeechRequests;

    public function __construct(
        protected string $key,
        protected string $region,
        protected string $voice = 'pt-PT-RaquelNeural',
        protected string $language = 'pt-PT',
        protected int $timeout = 30,
    ) {}

    public function synthesize(string $text): string
    {
        $ssml = sprintf(
            "<speak version='1.0' xml:lang='%s'><voice name='%s'>%s</voice></speak>",
            $this->language,
            $this->voice,
            htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        );

        $response = $this->send(fn () => Http::timeout($this->timeout)
            ->withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->key,
                'X-Microsoft-OutputFormat' => 'audio-24khz-48kbitrate-mono-mp3',
                'User-Agent' => 'filament-chatai',
            ])
            ->withBody($ssml, 'application/ssml+xml')
            ->post("https://{$this->region}.tts.speech.microsoft.com/cognitiveservices/v1"));

        return $response->body();
    }

    public function transcribe(string $wav): string
    {
        $response = $this->send(fn () => Http::timeout($this->timeout)
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $this->key, 'Accept' => 'application/json'])
            ->withBody($wav, 'audio/wav; codecs=audio/pcm; samplerate=16000')
            ->post("https://{$this->region}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?language={$this->language}&format=simple"));

        return $response->json('RecognitionStatus') === 'Success' ? trim((string) $response->json('DisplayText')) : '';
    }

    public function voiceId(): string
    {
        return "azure:{$this->voice}";
    }

    public function withVoice(string $voice): static
    {
        $clone = clone $this;
        $clone->voice = $voice;

        return $clone;
    }
}
