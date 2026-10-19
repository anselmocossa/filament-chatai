<?php

namespace Filament\ChatAi\Agent;

use Filament\ChatAi\Contracts\ToolProvider;
use Filament\ChatAi\Events\TurnCompleted;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Llm\LlmException;
use Filament\ChatAi\Llm\StreamChunk;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\ShowUiTool;
use Filament\ChatAi\Tools\ToolResult;
use Filament\ChatAi\Ui\ComponentValidator;
use Generator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;

/**
 * The agent loop: model → tools → model, streamed as AgentEvents and
 * persisted as it goes so a dropped connection loses nothing already said.
 */
class Orchestrator
{
    public function __construct(
        protected LlmClient $llm,
        protected ComponentValidator $validator = new ComponentValidator,
    ) {}

    /**
     * @return Generator<int, AgentEvent>
     */
    public function run(
        Conversation $conversation,
        string $question,
        ChatAiContext $context,
        ToolProvider $tools,
        Authenticatable $user,
        LlmConfig $config,
        string $assistantName = 'ChatAI',
        ?string $systemPrompt = null,
    ): Generator {
        $startedAt = hrtime(true);
        $userMessage = $conversation->addMessage(Message::ROLE_USER, $question, ['context' => $context->toArray()]);

        /** @var array<string, ChatAiTool> $available */
        $available = collect([...$tools->tools($user, $context), ShowUiTool::make()])->keyBy('name')->all();
        $definitions = array_values(array_map(fn (ChatAiTool $t) => $t->toFunctionDefinition(), $available));

        $messages = [
            ['role' => 'system', 'content' => SystemPrompt::build($assistantName, $context, $systemPrompt)],
            ...$this->history($conversation, $userMessage),
        ];

        $maxIterations = (int) config('chatai.max_iterations', 6);
        $maxBytes = (int) config('chatai.max_tool_result_bytes', 20_000);
        $tokensIn = $tokensOut = $cacheRead = $cacheWrite = 0;
        $model = $config->model;
        $components = [];
        $toolsUsed = [];

        try {
            for ($iteration = 0; ; $iteration++) {
                // Out of tool rounds: ask once more without tools so it must answer.
                $offer = $iteration < $maxIterations ? $definitions : [];
                $text = '';
                $calls = [];

                foreach ($this->llm->stream($config, $messages, $offer) as $chunk) {
                    match ($chunk->type) {
                        StreamChunk::TEXT => $text .= $chunk->text,
                        StreamChunk::TOOL_CALL => $calls[] = $chunk,
                        StreamChunk::DONE => [$model, $tokensIn, $tokensOut, $cacheRead, $cacheWrite] = [
                            $chunk->model ?? $model,
                            $tokensIn + (int) $chunk->tokensIn,
                            $tokensOut + (int) $chunk->tokensOut,
                            $cacheRead + (int) $chunk->cacheRead,
                            $cacheWrite + (int) $chunk->cacheWrite,
                        ],
                    };

                    if ($chunk->type === StreamChunk::TEXT) {
                        yield new AgentEvent(AgentEvent::DELTA, ['text' => $chunk->text]);
                    }
                }

                if ($calls === [] || $offer === []) {
                    $answer = $conversation->addMessage(Message::ROLE_ASSISTANT, $text, [
                        'components' => $components ?: null,
                        'model' => $model,
                        'tokens_in' => $tokensIn,
                        'tokens_out' => $tokensOut,
                        'latency_ms' => $this->elapsedMs($startedAt),
                    ]);

                    break;
                }

                $toolCalls = array_map(fn (StreamChunk $c) => [
                    'id' => $c->toolCallId,
                    'type' => 'function',
                    'function' => ['name' => $c->toolName, 'arguments' => json_encode($c->arguments ?: new \stdClass)],
                ], $calls);

                $conversation->addMessage(Message::ROLE_ASSISTANT, $text ?: null, ['tool_calls' => $toolCalls, 'model' => $model]);
                $messages[] = ['role' => 'assistant', 'content' => $text ?: null, 'tool_calls' => $toolCalls];

                foreach ($calls as $call) {
                    if ($call->toolName === ShowUiTool::NAME) {
                        foreach ($this->validator->validateMany((array) ($call->arguments['components'] ?? [])) as $component) {
                            $components[] = $component;
                            yield new AgentEvent(AgentEvent::COMPONENT, ['component' => $component]);
                        }

                        $result = ToolResult::text('Shown to the user.');
                    } else {
                        $tool = $available[$call->toolName] ?? null;

                        yield new AgentEvent(AgentEvent::TOOL_START, ['name' => $call->toolName, 'label' => $tool?->label() ?? $call->toolName]);

                        $result = $tool?->call($call->arguments) ?? ToolResult::error("Unknown tool {$call->toolName}.");
                        $toolsUsed[] = $call->toolName;

                        yield new AgentEvent(AgentEvent::TOOL_END, ['name' => $call->toolName, 'ok' => ! $result->isError()]);

                        if ($result->component !== null && ($component = $this->validator->validate($result->component)) !== null) {
                            $components[] = $component;
                            yield new AgentEvent(AgentEvent::COMPONENT, ['component' => $component]);
                        }
                    }

                    $content = $result->toModelContent($maxBytes);

                    $conversation->addMessage(Message::ROLE_TOOL, $content, ['tool_call_id' => $call->toolCallId, 'tool_name' => $call->toolName]);
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $call->toolCallId, 'content' => $content];
                }
            }
        } catch (LlmException $e) {
            Log::warning('[chatai] '.$e->getMessage());
            $userMessage->update(['status' => Message::STATUS_ERROR]);

            yield new AgentEvent(AgentEvent::ERROR, ['message' => __('chatai::chatai.unavailable')]);

            return;
        }

        TurnCompleted::dispatch($user, $conversation, $model, $tokensIn, $tokensOut, $this->elapsedMs($startedAt), array_values(array_unique($toolsUsed)), $cacheRead, $cacheWrite, $answer->id);

        yield new AgentEvent(AgentEvent::DONE, [
            'conversation_id' => $conversation->id,
            'message_id' => $answer->id,
            'title' => $conversation->fresh()->title,
        ]);
    }

    /**
     * Earlier turns in OpenAI format. Failed questions are skipped so a
     * provider outage does not leave unanswered prompts in the context.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function history(Conversation $conversation, Message $current): array
    {
        return $conversation->messages()
            ->where('status', '!=', Message::STATUS_ERROR)
            ->get()
            ->map(fn (Message $m): array => match ($m->role) {
                Message::ROLE_TOOL => ['role' => 'tool', 'tool_call_id' => $m->tool_call_id, 'content' => (string) $m->content],
                Message::ROLE_ASSISTANT => array_filter([
                    'role' => 'assistant',
                    'content' => $m->content,
                    'tool_calls' => $m->tool_calls ?: null,
                ], fn ($v) => $v !== null) + ['content' => null],
                default => ['role' => 'user', 'content' => $m->is($current)
                    ? (string) $m->content
                    : $this->withScreen($m)],
            })
            ->all();
    }

    /**
     * Earlier questions keep the screen they were asked on, so "this customer"
     * still resolves after the user moves to another page.
     */
    protected function withScreen(Message $message): string
    {
        $context = $message->context ?? [];
        $screen = trim(($context['resource'] ?? '').(isset($context['record_id']) ? ' #'.$context['record_id'] : ''));

        return $screen === ''
            ? (string) $message->content
            : "[asked on screen: {$screen}]\n".$message->content;
    }

    protected function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
