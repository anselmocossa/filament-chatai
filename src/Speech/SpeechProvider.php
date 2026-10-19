<?php

namespace Filament\ChatAi\Speech;

/**
 * Server-side voice: neural text-to-speech and speech-to-text, sounding the
 * same on every device. Without one, the browser's own engine is used.
 */
interface SpeechProvider
{
    /**
     * @return string MP3 audio
     *
     * @throws SpeechException
     */
    public function synthesize(string $text): string;

    /**
     * @param  string  $wav  16 kHz mono PCM WAV, up to 60 seconds
     *
     * @throws SpeechException
     */
    public function transcribe(string $wav): string;

    /**
     * Identifies provider + voice, so cached audio changes when the voice does.
     */
    public function voiceId(): string;

    /**
     * The same provider speaking with another of its voices.
     */
    public function withVoice(string $voice): static;
}
