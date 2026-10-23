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
use Filament\ChatAi\Models\Memory;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\ToolAction;
use Filament\ChatAi\Support\ActionForm;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Support\FileParts;
use Filament\ChatAi\Tools\BuilderTools;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\DocumentTool;
use Filament\ChatAi\Tools\FillFormTool;
use Filament\ChatAi\Tools\MemoryTool;
use Filament\ChatAi\Tools\NavigateTool;
use Filament\ChatAi\Tools\QueryDataTool;
use Filament\ChatAi\Tools\ShowUiTool;
use Filament\ChatAi\Tools\ToolResult;
use Filament\ChatAi\Ui\Catalog;
use Filament\ChatAi\Ui\ComponentValidator;
use Filament\ChatAi\Ui\TablePager;
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
        ?\Closure $shouldStop = null,
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
            ...(MemoryTool::available() && ! str_ends_with($conversation->panel_id, ':playground') ? [MemoryTool::make($user, $context)] : []),
            // Not in the tool editor's test chat, which tries one tool on its own.
            ...(NavigateTool::available() && ! str_ends_with($conversation->panel_id, ':playground') ? [NavigateTool::make()] : []),
        ])->keyBy('name')->all();
        $definitions = array_values(array_map(fn (ChatAiTool $t) => $t->toFunctionDefinition(), $available));

        $messages = [
            ['role' => 'system', 'content' => SystemPrompt::build($assistantName, $context, $systemPrompt).$this->builderHint($available).$this->memories($user, $context, $conversation)],
            ...$this->history($conversation, $userMessage),
        ];

        $maxIterations = (int) config('chatai.max_iterations', 6);
        $maxBytes = (int) config('chatai.max_tool_result_bytes', 20_000);
        $tokensIn = $tokensOut = $cacheRead = $cacheWrite = 0;
        $model = $config->model;
        $components = [];
        $proposals = [];
        $toolsUsed = [];
        $stopped = false;
        $checkedAt = 0.0;

        // The user pressed Stop: checked at safe points (between rounds, while writing), at most every 0.3 s.
        $stopRequested = function () use ($shouldStop, &$checkedAt): bool {
            if (! $shouldStop || microtime(true) - $checkedAt < 0.3) {
                return false;
            }

            $checkedAt = microtime(true);

            return (bool) $shouldStop();
        };

        try {
            for ($iteration = 0; ; $iteration++) {
                // Out of tool rounds: ask once more without tools so it must answer.
                $offer = $iteration < $maxIterations ? $definitions : [];
                $text = '';
                $checkedAt = 0.0;

                if ($iteration > 0 && $stopRequested()) {
                    $stopped = true;
                    $offer = [];
                }

                if ($iteration > 0) {
                    yield new AgentEvent(AgentEvent::STEP, ['iteration' => $iteration]);
                }
                $calls = [];

                foreach ($stopped ? [] : $this->llm->stream($config, $messages, $offer) as $chunk) {
                    if ($stopRequested()) {
                        $stopped = true;
                        $calls = [];

                        break;
                    }

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

                if ($calls === [] || $offer === [] || $stopped) {
                    // Proposed changes go last: one form, or one list to approve when there are several.
                    foreach ($this->groupProposals($proposals) as $card) {
                        if (($card = $this->validator->validate($card, fromServer: true)) !== null) {
                            $components[] = $card;
                            yield new AgentEvent(AgentEvent::COMPONENT, ['component' => $card]);
                        }
                    }

                    $answer = $conversation->addMessage(Message::ROLE_ASSISTANT, $text, [
                        'components' => $components ?: null,
                        ...($stopped ? ['status' => 'stopped'] : []),
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
                        $asked = array_map(fn (array $c): array => TablePager::prepare($c, $available), array_values(array_filter((array) ($call->arguments['components'] ?? []), 'is_array')));
                        $valid = $this->validator->validateMany($asked);

                        foreach ($valid as $component) {
                            if ($this->alreadyShown($components, $component)) {
                                continue;
                            }

                            $components[] = $component;
                            yield new AgentEvent(AgentEvent::COMPONENT, ['component' => $component]);
                        }

                        // Said here because this is where the model decides how to refer to it.
                        $result = match (true) {
                            // No component at all: usually "component" (an object) instead of
                            // "components" (a list). Saying "shown" here leaves the user with
                            // nothing on screen and the model with nothing left to say.
                            $asked === [] => ToolResult::error('Nothing was shown: '.ShowUiTool::NAME.' takes "components", a LIST of components, e.g. {"components": [{"type": "table", ...}]}. Call it again with that shape.'),
                            $valid === [] => ToolResult::error('Nothing was shown: the components did not match the schema (check the field names, e.g. cards, records and kpi use "items"). Fix them and call '.ShowUiTool::NAME.' again.'),
                            count($valid) < count($asked) => ToolResult::text('Shown to the user, except '.(count($asked) - count($valid)).' component(s) that did not match the schema. Do not say where it is; just give the key finding.'),
                            default => ToolResult::text('Shown to the user, under your text. Do not say where it is ("above", "below", "in the table"); just give the key finding.'),
                        };
                    } elseif ($call->toolName === FillFormTool::NAME && isset($available[FillFormTool::NAME])) {
                        // The browser writes these into the open form; the user saves.
                        $filled = FillFormTool::fields($context, (array) $call->arguments);
                        $toolsUsed[] = $call->toolName;

                        // List filters are applied on screen, not saved.
                        $onlyFilters = $filled !== [] && collect($filled)->every(fn (array $f): bool => str_starts_with((string) $f['path'], 'tableDeferredFilters.') || $f['path'] === 'tableSearch');

                        if ($filled !== []) {
                            yield new AgentEvent(AgentEvent::FILL, ['fields' => $filled]);
                            $card = $this->validator->validate(['type' => Catalog::FILLED, 'fields' => $filled, 'filter' => $onlyFilters], fromServer: true);

                            if ($card !== null) {
                                $components[] = $card;
                                yield new AgentEvent(AgentEvent::COMPONENT, ['component' => $card]);
                            }
                        }

                        $result = $filled === []
                            ? ToolResult::error('None of those fields is on screen. Use the paths listed under "On screen now".')
                            : ToolResult::data(['filled' => array_column($filled, 'path'), 'note' => $onlyFilters
                                ? 'Applied as filters of the list on screen: the user now sees the filtered list. Nothing is saved. To give figures from it, use '.QueryDataTool::NAME.' or another tool; do not ask the user to press Save.'
                                : 'The values are in the form on screen. Nothing is saved: tell the user to review them and press Save.']);
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
     * For managers: build what is missing instead of saying it cannot be done.
     *
     * @param  array<string, ChatAiTool>  $available
     */
    protected function builderHint(array $available): string
    {
        $hint = '';

        if (isset($available[QueryDataTool::NAME])) {
            $hint .= "\n\nWhen no other tool answers a question about the application's data (a total, a count, a list for one person or status), answer it yourself with "
                .QueryDataTool::NAME.' (call '.BuilderTools::DESCRIBE.' first for the resource and its columns). Do not say you cannot, and do not send the user to filter a page to find it.';
        }

        if (isset($available[BuilderTools::CREATE_TOOL])) {
            $hint .= "\nThis user manages the assistant, so you can also give yourself new abilities: when they ask for a tool, or after answering a question that is likely to come back, "
                .'offer to save it as a tool with '.BuilderTools::CREATE_TOOL.' ("metrics" for sums, counts and averages, "resource" for lists; give it filters for what the user will name). '
                .'Without '.QueryDataTool::NAME.', build it in the same answer and say the answer comes as soon as they confirm.';
        }

        return $hint;
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

    /**
     * What this user asked to be remembered, for the system prompt.
     */
    protected function memories(Authenticatable $user, ChatAiContext $context, Conversation $conversation): string
    {
        if (! MemoryTool::available() || str_ends_with($conversation->panel_id, ':playground')) {
            return '';
        }

        $items = Memory::of($user, $context->tenantId, $context->panelId)->pluck('content');

        return $items->isEmpty() ? '' : "\n\nWhat this user asked you to remember (follow it):\n- ".$items->implode("\n- ");
    }
}
