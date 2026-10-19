<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\Agent\Orchestrator;
use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Skill;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Tools\ArrayToolProvider;
use Filament\ChatAi\Tools\SkillTool;
use Filament\ChatAi\Tools\ToolDraft;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The test chat of the tool editor: the real model, with only the tool being
 * edited (as it stands in the form). Test conversations stay out of the
 * user's chat history.
 */
class PlaygroundController
{
    public const PANEL_SUFFIX = ':playground';

    public function store(Request $request): JsonResponse
    {
        $this->plugin(); // managers only

        $conversation = Conversation::start($request->user(), Filament::getCurrentPanel()->getId().self::PANEL_SUFFIX, ConversationController::tenantId());

        return response()->json(['id' => $conversation->id, 'title' => null], 201);
    }

    public function message(Request $request, string $conversation): StreamedResponse|JsonResponse
    {
        $plugin = $this->plugin();
        $conversation = ConversationController::authorized($conversation, 'update');
        abort_unless(str_ends_with($conversation->panel_id, self::PANEL_SUFFIX), 404);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'draft' => ['required', 'array'],
            'draft.mode' => ['required', 'in:'.ToolDraft::MODE_CODE.','.ToolDraft::MODE_CUSTOM],
            'draft.tool' => ['nullable', 'string', 'max:64'],
            'draft.data' => ['nullable', 'array'],
            'draft.options.model_id' => ['nullable', 'integer', 'exists:chatai_models,id'],
            'draft.options.effort' => ['nullable', 'in:low,medium,high,xhigh,max'],
            'draft.options.max_tokens' => ['nullable', 'integer', 'min:256', 'max:128000'],
            'draft.options.temperature' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        if ($limited = MessageStreamController::rateLimit($request) ?? MessageStreamController::budgetPaused()) {
            return $limited;
        }

        $context = new ChatAiContext(Filament::getCurrentPanel()->getId(), tenantId: ConversationController::tenantId());
        $tool = ToolDraft::build($validated['draft']['mode'], $validated['draft']['tool'] ?? null, $validated['draft']['data'] ?? [], $plugin->resolveTools(), $request->user(), $context);

        if ($tool === null) {
            return response()->json(['message' => __('chatai::chatai.editor.incomplete')], 422);
        }

        // A change proposed here is confirmed against this same unsaved tool.
        Cache::put(self::draftKey($conversation->id), $validated['draft'], now()->addHour());

        // The skills chosen in the editor, saved or not, so a whole task can be tried.
        $skills = Skill::query()->whereIn('id', (array) ($validated['draft']['data']['skills'] ?? []))->orderBy('title')->get();
        $tools = $skills->isEmpty() ? [$tool] : [$tool, SkillTool::make($skills)];

        $events = (new Orchestrator(app(LlmClient::class)))->run(
            conversation: $conversation,
            question: $validated['message'],
            context: $context,
            tools: new ArrayToolProvider($tools),
            user: $request->user(),
            config: $this->config($plugin, (array) ($validated['draft']['options'] ?? [])),
            assistantName: $plugin->getLabel(),
            systemPrompt: trim($plugin->composeSystemPrompt($skills)."\n\nAn administrator is testing the tool \"{$tool->name}\": it is the only data tool you have here. Use it whenever it can answer."),
        );

        return MessageStreamController::sse($events);
    }

    /**
     * The model chosen in the test chat (alone, no fallback), or the normal chain,
     * with the test options on top.
     *
     * @param  array<string, mixed>  $options
     */
    protected function config(ChatAiPlugin $plugin, array $options): LlmConfig
    {
        $model = filled($options['model_id'] ?? null) ? AiModel::query()->with('providerConnection')->find($options['model_id']) : null;
        $config = $model ? LlmConfig::fromModels([$model]) : $plugin->resolveLlmConfig();

        // Only an effort the chosen model's API accepts, and only for a model that uses effort at all.
        $effort = $model && filled($model->effort) && in_array($options['effort'] ?? null, LlmConfig::EFFORTS[$config->provider] ?? [], true)
            ? (string) $options['effort']
            : null;

        return $config->withOptions(
            $effort,
            filled($options['max_tokens'] ?? null) ? (int) $options['max_tokens'] : null,
            isset($options['temperature']) && $options['temperature'] !== '' ? (float) $options['temperature'] : null,
        );
    }

    protected function plugin(): ChatAiPlugin
    {
        /** @var ChatAiPlugin $plugin */
        $plugin = Filament::getCurrentPanel()->getPlugin('chatai');
        abort_unless($plugin->canManage(), 403);

        return $plugin;
    }

    public static function draftKey(string $conversation): string
    {
        return 'chatai:playground-draft:'.$conversation;
    }
}
