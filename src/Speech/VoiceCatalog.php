<?php

namespace Filament\ChatAi\Speech;

use Filament\ChatAi\Settings\ChatAiSettings;

/**
 * European Portuguese neural voices of each provider. Admins may also allow
 * any other voice id of their provider in config/settings ("speech.voices").
 */
final class VoiceCatalog
{
    public const VOICES = [
        'azure' => [
            'pt-PT-RaquelNeural' => 'Raquel',
            'pt-PT-DuarteNeural' => 'Duarte',
            'pt-PT-FernandaNeural' => 'Fernanda',
        ],
        'google' => [
            'pt-PT-Wavenet-A' => 'Wavenet A',
            'pt-PT-Wavenet-B' => 'Wavenet B',
            'pt-PT-Wavenet-C' => 'Wavenet C',
            'pt-PT-Wavenet-D' => 'Wavenet D',
        ],
    ];

    /**
     * @return array<string, string> voice id => label
     */
    public static function all(?string $provider): array
    {
        return self::VOICES[$provider] ?? [];
    }

    /**
     * Voices users may pick: the admin's list, or every voice of the provider.
     *
     * @return array<string, string>
     */
    public static function offered(): array
    {
        ChatAiSettings::apply();

        $provider = config('chatai.speech.provider');
        $catalog = self::all($provider);
        $allowed = array_values(array_filter((array) config('chatai.speech.voices', [])));

        if ($allowed === []) {
            return $catalog;
        }

        return collect($allowed)->mapWithKeys(fn (string $id): array => [$id => $catalog[$id] ?? $id])->all();
    }

    public static function isOffered(?string $voice): bool
    {
        return $voice !== null && array_key_exists($voice, self::offered());
    }
}
