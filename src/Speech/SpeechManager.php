<?php

namespace Filament\ChatAi\Speech;

use Filament\ChatAi\Settings\ChatAiSettings;

final class SpeechManager
{
    /**
     * The provider from config/chatai.php, or null when no key is set
     * (the chat then falls back to the browser's voice).
     */
    public static function fromConfig(): ?SpeechProvider
    {
        ChatAiSettings::apply();

        $config = config('chatai.speech');
        $language = (string) ($config['language'] ?? 'pt-PT');
        $timeout = (int) ($config['timeout'] ?? 30);

        return match ($config['provider'] ?? null) {
            'azure' => filled($config['azure']['key'] ?? null) && filled($config['azure']['region'] ?? null)
                ? new AzureSpeech($config['azure']['key'], $config['azure']['region'], (string) $config['azure']['voice'], $language, $timeout)
                : null,
            'google' => filled($config['google']['api_key'] ?? null)
                ? new GoogleSpeech($config['google']['api_key'], (string) $config['google']['voice'], $language, $timeout)
                : null,
            default => null,
        };
    }
}
