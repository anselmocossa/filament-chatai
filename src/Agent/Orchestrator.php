<?php

namespace Filament\ChatAi\Agent;

use Filament\ChatAi\Contracts\ToolProvider;
use Filament\ChatAi\Events\TurnCompleted;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Llm\LlmException;
use Filament\ChatAi\Llm\StreamChunk;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\ConversationFile;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\ToolAction;
use Filament\ChatAi\Support\ActionForm;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Support\FileParts;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\DocumentTool;
use Filament\ChatAi\Tools\FillFormTool;
use Filament\ChatAi\Tools\NavigateTool;
use Filament\ChatAi\Tools\ShowUiTool;
use Filament\ChatAi\Tools\ToolResult;
use Filament\ChatAi\Ui\Catalog;
use Filament\ChatAi\Ui\ComponentValidator;
use Generator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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
        iterable $attachments = [],
    ): Generator {
        $startedAt = hrtime(true);
        $userMessage = $conversation->addMessage(Message::ROLE_USER, $question, ['context' => $context->toArray()]);

        foreach ($attachments as $file) {
            $file->update(['message_id' => $userMessage->id]);
        }

        /** @var array<string, ChatAiTool> $available */
        $available = collect([
            ...$tools->tools($user, $context),
            ShowUiTool::make(),
            DocumentTool::make($conversation, $user),
            ...(FillFormTool::available($context) ? [FillFormTool::make($context)] : []),
            // Not in the tool editor's test chat, which tries one tool on its own.
            ...(NavigateTool::available() && ! str_ends_with($conversation->panel_id, ':playground') ? [NavigateTool::make()] : []),
        ])->keyBy('name')->all();
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
        $proposals = [];
        $toolsUsed = [];

        try {
            for ($iteration = 0; ; $iteration++) {
                // Out of tool rounds: ask once more without tools so it must answer.
                $offer = $iteration < $maxIterations ? $definitions : [];
                $text = '';

                if ($iteration > 0) {
                    yield new AgentEvent(AgentEvent::STEP, ['iteration' => $iteration]);
                }
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
                    // Proposed changes go last: one form, or one list to approve when there are several.
                    foreach ($this->groupProposals($proposals) as $card) {
                        if (($card = $this->validator->validate($card, fromServer: true)) !== null) {
                            $components[] = $card;
                            yield new AgentEvent(AgentEvent::COMPONENT, ['component' => $card]);
                        }
                    }

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
                            if ($this->alreadyShown($components, $component)) {
                                continue;
                            }

                            $components[] = $component;
                            yield new AgentEvent(AgentEvent::COMPONENT, ['component' => $component]);
                        }

                        $result = ToolResult::text('Shown to the user.');
                    } elseif ($call->toolName === FillFormTool::NAME && isset($available[FillFormTool::NAME])) {
                        // The browser writes these into the open form; the user saves.
                        $filled = FillFormTool::fields($context, (array) $call->arguments);
                        $toolsUsed[] = $call->toolName;

                        if ($filled !== []) {
                            yield new AgentEvent(AgentEvent::FILL, ['fields' => $filled]);
                            $card = $this->validator->validate(['type' => Catalog::FILLED, 'fields' => $filled], fromServer: true);

                            if ($card !== null) {
                                $components[] = $card;
                                yield new AgentEvent(AgentEvent::COMPONENT, ['component' => $card]);
                            }
                        }

                        $result = $filled === []
                            ? ToolResult::error('None of those fields is on screen. Use the paths listed under "On screen now".')
                            : ToolResult::data(['filled' => array_column($filled, 'path'), 'note' => 'The values are in the form on screen. Nothing is saved: tell the user to review them and press Save.']);
                    } elseif (($tool = $available[$call->toolName] ?? null) !== null && ! $tool->readOnly) {
                        // A change: nothing runs until the user confirms; shown when the answer ends.
                        $proposals[] = ['tool' => $tool->name, 'card' => $this->propose($conversation, $user, $context, $tool, (array) $call->arguments)];
                        $toolsUsed[] = $call->toolName;

                        $result = ToolResult::data([
                            'status' => 'awaiting_confirmation',
                            'note' => 'Nothing has changed yet. The user gets a form with these values (several calls of this tool become one list they approve at once), can correct them, then Confirm or Cancel. For a list (e.g. rows of a file), call the tool once per row. In one sentence, say what will happen and ask them to check and confirm. Do not ask for missing values in text.',
                        ]);
                    } else {
                        $tool = $available[$call->toolName] ?? null;

                        yield new AgentEvent(AgentEvent::TOOL_START, ['name' => $call->toolName, 'label' => $tool?->label() ?? $call->toolName]);

                        $result = $tool?->call($call->arguments) ?? ToolResult::error("Unknown tool {$call->toolName}.");
                        $toolsUsed[] = $call->toolName;

                        yield new AgentEvent(AgentEvent::TOOL_END, ['name' => $call->toolName, 'ok' => ! $result->isError()]);

                        if ($result->component !== null && ($component = $this->validator->validate($result->component, fromServer: true)) !== null) {
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
        // Files ride with the question they were attached to (prompt caching keeps re-sends cheap).
        $files = ConversationFile::query()->where('conversation_id', $conversation->id)->where('kind', ConversationFile::KIND_UPLOAD)
            ->whereNotNull('message_id')->orderBy('id')->get()->groupBy('message_id');

        return $conversation->messages()
            ->where('status', '!=', Message::STATUS_ERROR)
            ->get()
            ->map(fn (Message $m): array => $this->withFiles(match ($m->role) {
                Message::ROLE_TOOL => ['role' => 'tool', 'tool_call_id' => $m->tool_call_id, 'content' => (string) $m->content],
                Message::ROLE_ASSISTANT => array_filter([
                    'role' => 'assistant',
                    'content' => $m->content,
                    'tool_calls' => $m->tool_calls ?: null,
                ], fn ($v) => $v !== null) + ['content' => null],
                default => ['role' => 'user', 'content' => $m->is($current)
                    ? (string) $m->content
                    : $this->withScreen($m)],
            }, $files->get($m->id)))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  Collection<int, ConversationFile>|null  $files
     * @return array<string, mixed>
     */
    protected function withFiles(array $message, ?Collection $files): array
    {
        if ($message['role'] !== 'user' || $files === null || $files->isEmpty()) {
            return $message;
        }

        return [...$message, 'content' => [
            ['type' => 'text', 'text' => (string) $message['content']],
            ...$files->flatMap(fn (ConversationFile $file): array => FileParts::for($file))->all(),
        ]];
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

    /**
     * Models sometimes call show_ui again with what they already showed; one
     * copy of a component, and one set of follow-up suggestions, per answer.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @param  array<string, mixed>  $component
     */
    protected function alreadyShown(array $components, array $component): bool
    {
        foreach ($components as $shown) {
            if ($shown == $component || ($component['type'] === 'suggestions' && $shown['type'] === 'suggestions')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keeps the proposed change and returns the confirmation card for it.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function propose(Conversation $conversation, Authenticatable $user, ChatAiContext $context, ChatAiTool $tool, array $arguments): array
    {
        /** @var Model $user */
        $action = ToolAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_type' => $user->getMorphClass(),
            'user_id' => $user->getKey(),
            'tool' => $tool->name,
            'title' => $tool->label(),
            'arguments' => $arguments,
            'context' => $context->toArray(),
        ]);

        $fields = ActionForm::fields($tool->inputSchema, $arguments);

        return ['type' => Catalog::CONFIRM, 'action_id' => $action->id, 'title' => $tool->label(), 'fields' => $fields, 'status' => ToolAction::PENDING];
    }

    /**
     * One proposal stays a form; several of the same tool become one list
     * approved at once.
     *
     * @param  array<int, array{tool: string, card: array<string, mixed>}>  $proposals
     * @return array<int, array<string, mixed>>
     */
    protected function groupProposals(array $proposals): array
    {
        $cards = [];

        foreach (collect($proposals)->groupBy('tool') as $group) {
            if ($group->count() === 1) {
                $cards[] = $group->first()['card'];

                continue;
            }

            $first = $group->first()['card'];
            $columns = collect($group)->flatMap(fn (array $p) => $p['card']['fields'])->unique('key')
                ->map(fn (array $f): array => ['key' => $f['key'], 'label' => $f['label']])->values()->all();

            $cards[] = [
                'type' => Catalog::CONFIRM_BATCH,
                'title' => $first['title'],
                'columns' => $columns,
                'rows' => $group->map(fn (array $p): array => [
                    'action_id' => $p['card']['action_id'],
                    'values' => collect($p['card']['fields'])->mapWithKeys(fn (array $f): array => [$f['key'] => is_bool($f['value']) ? ($f['value'] ? '✓' : '') : (string) $f['value']])->all(),
                    'status' => ToolAction::PENDING,
                ])->values()->all(),
                'status' => ToolAction::PENDING,
            ];
        }

        return $cards;
    }
}
