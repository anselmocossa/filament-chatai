<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\Models\Preference;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Speech\VoiceCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreferenceController
{
    /**
     * The user's own server voice. Browser voices differ per device, so the
     * browser keeps that choice locally instead.
     */
    public function update(Request $request): JsonResponse
    {
        ChatAiSettings::apply();
        abort_unless((bool) config('chatai.speech.user_choice', true), 403);

        $voice = $request->validate(['voice' => ['nullable', 'string', 'max:100']])['voice'] ?? null;
        abort_if($voice !== null && ! VoiceCatalog::isOffered($voice), 422);

        Preference::setVoice($request->user(), $voice);

        return response()->json(['voice' => $voice]);
    }
}
