<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\Preference;
use Filament\ChatAi\Models\Usage;
use Filament\ChatAi\Speech\SpeechException;
use Filament\ChatAi\Speech\SpeechProvider;
use Filament\ChatAi\Speech\SpeechText;
use Filament\ChatAi\Speech\VoiceCatalog;
use Filament\ChatAi\Support\Pricing;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

class SpeechController
{
    /**
     * Reads an answer aloud. Only stored answers can be read (never free text),
     * and each one is generated once: the audio is kept and served again.
     */
    public function synthesize(Request $request, string $message): Response|JsonResponse
    {
        $speech = $this->provider();

        $message = Message::query()->with('conversation')->where('role', Message::ROLE_ASSISTANT)->findOrFail($message);
        Gate::authorize('view', $message->conversation);

        $text = SpeechText::from($message->content);
        abort_if($text === '', 404);

        $voice = Preference::voiceFor($request->user());

        if (config('chatai.speech.user_choice', true) && VoiceCatalog::isOffered($voice)) {
            $speech = $speech->withVoice($voice);
        }

        return $this->audio($request, $speech, $text);
    }

    /**
     * A short sentence in one of the offered voices, to choose by ear.
     */
    public function sample(Request $request): Response|JsonResponse
    {
        $speech = $this->provider();
        $voice = (string) $request->validate(['voice' => ['required', 'string', 'max:100']])['voice'];
        abort_unless(VoiceCatalog::isOffered($voice), 422);

        // In the voice's language (pt-PT → pt_PT), not the panel's.
        $locale = str_replace('-', '_', (string) config('chatai.speech.language', 'pt-PT'));

        return $this->audio($request, $speech->withVoice($voice), (string) __('chatai::chatai.voice_sample', [], $locale));
    }

    protected function audio(Request $request, SpeechProvider $speech, string $text): Response|JsonResponse
    {
        $disk = Storage::disk(config('chatai.speech.disk', 'local'));
        $path = 'chatai/speech/'.sha1($speech->voiceId().'|'.$text).'.mp3';

        if (! $disk->exists($path)) {
            if ($limited = $this->rateLimit($request)) {
                return $limited;
            }

            try {
                $disk->put($path, $speech->synthesize($text));
            } catch (SpeechException $e) {
                return $this->unavailable($e);
            }

            // Billed once: replays come from the stored file.
            $characters = mb_strlen($text);
            $this->recordUsage($request, Usage::KIND_SPEECH, ['model' => $speech->voiceId(), 'characters' => $characters, 'cost' => Pricing::speech($characters)]);
        }

        return response((string) $disk->get($path), 200, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Turns a recorded question (16 kHz mono WAV from the browser) into text.
     */
    public function transcribe(Request $request): JsonResponse
    {
        $speech = $this->provider();

        $request->validate(['audio' => ['required', 'file', 'max:4096']]);
        $wav = (string) file_get_contents($request->file('audio')->getRealPath());
        abort_unless(str_starts_with($wav, 'RIFF'), 422);

        if ($limited = $this->rateLimit($request)) {
            return $limited;
        }

        try {
            $text = $speech->transcribe($wav);
        } catch (SpeechException $e) {
            return $this->unavailable($e);
        }

        $seconds = round(max(0, strlen($wav) - 44) / 32_000, 2); // 16 kHz × 16-bit mono
        $this->recordUsage($request, Usage::KIND_TRANSCRIPTION, ['model' => strtok($speech->voiceId(), ':'), 'seconds' => $seconds, 'cost' => Pricing::transcription($seconds)]);

        return response()->json(['text' => $text]);
    }

    protected function provider(): SpeechProvider
    {
        /** @var ChatAiPlugin $plugin */
        $plugin = Filament::getCurrentPanel()->getPlugin('chatai');

        // 404 tells the browser to use its own voice instead.
        return $plugin->resolveSpeech() ?? abort(404);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function recordUsage(Request $request, string $kind, array $attributes): void
    {
        Usage::record($request->user(), $kind, [
            'panel_id' => Filament::getCurrentPanel()?->getId(),
            'tenant_id' => ConversationController::tenantId(),
            ...$attributes,
        ]);
    }

    protected function unavailable(SpeechException $e): JsonResponse
    {
        Log::warning('[chatai] '.$e->getMessage());

        return response()->json(['message' => __('chatai::chatai.speech_unavailable')], 503);
    }

    protected function rateLimit(Request $request): ?JsonResponse
    {
        $bucket = 'chatai-speech:'.$request->user()->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($bucket, (int) config('chatai.speech.per_minute', 30))) {
            return response()->json(['message' => __('chatai::chatai.rate_limited')], 429);
        }

        RateLimiter::hit($bucket, 60);

        return null;
    }
}
