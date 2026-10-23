<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\Agent\AgentEvent;
use Filament\ChatAi\Agent\Orchestrator;
use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\ConversationFile;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\Budget;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Support\UrlContext;
use Filament\ChatAi\Ui\QuestionAnswer;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageStreamController
{
    public function __invoke(Request $request, string $conversation): StreamedResponse|JsonResponse
    {
        $conversation = ConversationController::authorized($conversation, 'update');

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'context' => ['nullable', 'array'],
            'mentions' => ['nullable', 'array', 'max:10'],
            'mentions.*' => ['string', 'max:64'],
            'skill' => ['nullable', 'string', 'max:64'],
            'attachments' => ['nullable', 'array', 'max:'.(int) config('chatai.files.max_per_message', 5)],
            'attachments.*' => ['string', 'max:26'],
            'replace_last' => ['nullable', 'boolean'],
            ...QuestionAnswer::rules(),
        ]);

        if ($limited = static::rateLimit($request)) {
            return $limited;
        }

        if ($paused = static::budgetPaused()) {
            return $paused;
        }

        if (isset($validated['question_answer'])) {
            if ($validated['replace_last'] ?? false) {
                throw ValidationException::withMessages(['question_answer' => __('chatai::chatai.questions.unavailable')]);
            }
            $validated['message'] = QuestionAnswer::submit($conversation, $validated['question_answer']);
        }

        // Stop button: the browser sets this flag and the turn ends at the next safe point.
        $stopKey = static::stopKey($conversation->id);
        Cache::forget($stopKey);

        // "Regenerate" or "edit the question": the last exchange is replaced, not added to.
        if ($validated['replace_last'] ?? false) {
            static::dropLastExchange($conversation);
        }

        /** @var ChatAiPlugin $plugin */
        $plugin = Filament::getCurrentPanel()->getPlugin('chatai');
        $user = $request->user();
        $context = ChatAiContext::fromArray(Filament::getCurrentPanel()->getId(), $validated['context'] ?? [], ConversationController::tenantId());

        $events = (new Orchestrator(app(LlmClient::class)))->run(
            conversation: $conversation,
            question: $validated['message'],
            context: $context,
            tools: $plugin->resolveTools(),
            user: $user,
            config: $plugin->resolveLlmConfig(),
            assistantName: $plugin->getLabel(),
            shouldStop: fn (): bool => Cache::has($stopKey),
            attachments: ConversationFile::query()
                ->where('conversation_id', $conversation->id)->where('kind', ConversationFile::KIND_UPLOAD)
                ->whereNull('message_id')->whereIn('id', $validated['attachments'] ?? [])->get(),
            systemPrompt: trim(implode("\n\n", array_filter([
                $plugin->composeSystemPrompt(),
                $plugin->turnHints($user, $context, $validated['mentions'] ?? [], $validated['skill'] ?? null),
                UrlContext::hint($validated['message']),
            ]))),
        );

        return static::sse($events);
    }

    /**
     * Agent events as server-sent events, flushed one by one.
     *
     * @param  iterable<AgentEvent>  $events
     */
    public static function sse(iterable $events): StreamedResponse
    {
        return response()->stream(function () use ($events): void {
            // Finish and save the answer even if the browser goes away mid-stream.
            ignore_user_abort(true);

            // A turn with several tools and a long answer outlasts PHP's usual 30 s.
            if (function_exists('set_time_limit')) {
                @set_time_limit(max(30, (int) config('chatai.max_turn_seconds', 300)));
            }

            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            echo ": stream open\n\n";
            flush();

            foreach ($events as $event) {
                /** @var AgentEvent $event */
                echo $event->toSse();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public static function budgetPaused(): ?JsonResponse
    {
        return Budget::allExceeded()
            ? response()->json(['message' => __('chatai::chatai.budget_reached')], 429)
            : null;
    }

    public static function rateLimit(Request $request): ?JsonResponse
    {
        ChatAiSettings::apply(); // limits can be set on the settings page

        $key = 'chatai:'.$request->user()->getAuthIdentifier();
        $limits = [
            [$key.':minute', (int) config('chatai.rate_limit.per_minute', 20), 60],
            [$key.':day', (int) config('chatai.rate_limit.per_day', 200), 86_400],
        ];

        foreach ($limits as [$bucket, $max]) {
            if (RateLimiter::tooManyAttempts($bucket, $max)) {
                return response()->json(['message' => __('chatai::chatai.rate_limited')], 429);
            }
        }

        foreach ($limits as [$bucket, , $decay]) {
            RateLimiter::hit($bucket, $decay);
        }

        return null;
    }

    /**
     * Removes the last question and everything after it (answer, tool calls),
     * keeping attachments of that question for the new turn.
     */
    public static function dropLastExchange(Conversation $conversation): void
    {
        $last = $conversation->messages()->where('role', Message::ROLE_USER)->reorder()->latest('id')->first();

        if (! $last) {
            return;
        }

        ConversationFile::query()->where('message_id', $last->id)->update(['message_id' => null]);
        $conversation->messages()->reorder()->where('id', '>=', $last->id)->delete();
    }

    public static function stopKey(string $conversation): string
    {
        return 'chatai:stop:'.$conversation;
    }

    /**
     * The Stop button: the running turn ends at its next safe point and keeps what it wrote.
     */
    public function stop(string $conversation): Response
    {
        $conversation = ConversationController::authorized($conversation, 'update');
        Cache::put(static::stopKey($conversation->id), true, now()->addMinutes(2));

        return response()->noContent();
    }
}
