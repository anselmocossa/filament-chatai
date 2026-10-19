<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\ToolAction;
use Filament\ChatAi\Support\ActionForm;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\ToolDraft;
use Filament\ChatAi\Tools\ToolResult;
use Filament\ChatAi\Ui\Catalog;
use Filament\ChatAi\Ui\ComponentValidator;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Confirm / Cancel buttons of a proposed change.
 */
class ActionController
{
    public function confirm(Request $request, string $action): JsonResponse
    {
        $action = $this->pending($request, $action);
        $tool = $this->tool($request, $action);

        // The form on the card: what the user filled in or corrected.
        if ($tool && $request->has('values')) {
            [$values, $errors] = ActionForm::validate($tool->inputSchema, (array) $request->input('values'));

            if ($errors !== []) {
                $action->update(['status' => ToolAction::PENDING]);

                return response()->json(['message' => __('chatai::chatai.actions.check_form'), 'errors' => $errors], 422);
            }

            $action->update(['arguments' => $values]);
        }

        $result = $this->run($action, $tool);
        $ok = $action->status === ToolAction::DONE;

        $component = $result?->component ? app(ComponentValidator::class)->validate($result->component, fromServer: true) : null;
        $content = $ok
            ? __('chatai::chatai.actions.done', ['action' => $action->title])
            : __('chatai::chatai.actions.failed', ['action' => $action->title, 'reason' => $result?->error ?? __('chatai::chatai.actions.no_permission')]);

        $message = $this->answer($action, $content, $component);

        return response()->json(['status' => $action->status, 'result' => $ok ? $action->resultFields() : [], 'message' => $message]);
    }

    /**
     * The list card: one approval for every row still waiting; rows the user
     * unticked are left out. Each row is checked and runs on its own, so one
     * bad row does not stop the others.
     */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:'.Catalog::MAX_BATCH],
            'ids.*' => ['string'],
            'skip' => ['array'],
            'skip.*' => ['string'],
            'confirmed' => ['required', 'boolean'],
        ]);

        $skip = array_flip((array) ($data['skip'] ?? []));
        $rows = [];
        $last = null;

        foreach (array_unique($data['ids']) as $id) {
            $action = ToolAction::query()->find($id);

            if (! $action || ! $action->isOwnedBy($request->user()) || $action->status !== ToolAction::PENDING) {
                continue;
            }

            $action = $this->pending($request, $id);
            $last = $action;

            if (! $data['confirmed'] || isset($skip[$id])) {
                $action->update(['status' => $data['confirmed'] ? 'skipped' : ToolAction::CANCELLED]);
                $rows[] = ['action_id' => $id, 'status' => $action->status];

                continue;
            }

            $tool = $this->tool($request, $action);
            $errors = $tool ? ActionForm::validate($tool->inputSchema, (array) $action->arguments)[1] : [];

            if ($errors !== []) {
                $action->update(['status' => ToolAction::FAILED, 'result' => ['error' => collect($errors)->map(fn ($e, $k) => "{$k}: {$e}")->implode(' ')]]);
                $rows[] = ['action_id' => $id, 'status' => ToolAction::FAILED, 'error' => $action->result['error']];

                continue;
            }

            $result = $this->run($action, $tool);
            $rows[] = array_filter(['action_id' => $id, 'status' => $action->status, 'error' => $action->status === ToolAction::FAILED ? ($result?->error ?? __('chatai::chatai.actions.no_permission')) : null]);
        }

        abort_if($last === null, 409, __('chatai::chatai.actions.already'));

        $done = collect($rows)->where('status', ToolAction::DONE)->count();
        $failed = collect($rows)->where('status', ToolAction::FAILED)->count();
        $status = ! $data['confirmed'] ? ToolAction::CANCELLED : ($failed === 0 ? ToolAction::DONE : ($done === 0 ? ToolAction::FAILED : 'partial'));
        $content = $data['confirmed']
            ? __('chatai::chatai.actions.batch_done', ['action' => $last->title, 'done' => $done, 'total' => count($rows)]).($failed ? ' '.__('chatai::chatai.actions.batch_failed', ['count' => $failed]) : '')
            : __('chatai::chatai.actions.cancelled', ['action' => $last->title]);

        return response()->json(['status' => $status, 'rows' => $rows, 'message' => $this->answer($last, $content)]);
    }

    /**
     * Resolved again for this user now: permissions are checked at the moment it runs.
     */
    protected function tool(Request $request, ToolAction $action): ?ChatAiTool
    {
        /** @var ChatAiPlugin $plugin */
        $plugin = Filament::getCurrentPanel()->getPlugin('chatai');
        $context = ChatAiContext::fromArray(Filament::getCurrentPanel()->getId(), (array) $action->context, ConversationController::tenantId());
        $tool = collect($plugin->resolveTools()->tools($request->user(), $context))->firstWhere('name', $action->tool);

        // From the tool editor's test chat: the unsaved tool it was tried with.
        if (! $tool && ($draft = Cache::get(PlaygroundController::draftKey($action->conversation_id)))) {
            $tool = ToolDraft::build($draft['mode'], $draft['tool'] ?? null, $draft['data'] ?? [], $plugin->resolveTools(), $request->user(), $context);
        }

        return $tool;
    }

    protected function run(ToolAction $action, ?ChatAiTool $tool): ?ToolResult
    {
        $result = $tool?->call((array) $action->arguments);
        $ok = $result !== null && ! $result->isError();

        $action->update([
            'status' => $ok ? ToolAction::DONE : ToolAction::FAILED,
            'result' => $result ? ['data' => $result->data, 'text' => $result->text, 'error' => $result->error] : ['error' => 'not available'],
        ]);

        return $result;
    }

    public function cancel(Request $request, string $action): JsonResponse
    {
        $action = $this->pending($request, $action);
        $action->update(['status' => ToolAction::CANCELLED]);

        return response()->json(['status' => $action->status, 'message' => $this->answer($action, __('chatai::chatai.actions.cancelled', ['action' => $action->title]))]);
    }

    /**
     * The user's own action, still waiting. Taken under a lock so a double
     * click cannot run it twice.
     */
    protected function pending(Request $request, string $id): ToolAction
    {
        return DB::transaction(function () use ($request, $id): ToolAction {
            $action = ToolAction::query()->lockForUpdate()->findOrFail($id);

            abort_unless($action->isOwnedBy($request->user()), 403);
            abort_unless($action->status === ToolAction::PENDING, 409, __('chatai::chatai.actions.already'));

            $action->update(['status' => 'running']);

            return $action;
        });
    }

    /**
     * What happened goes into the conversation, so it stays in the history and
     * the model knows about it in the next question.
     *
     * @param  array<string, mixed>|null  $component
     * @return array<string, mixed>
     */
    protected function answer(ToolAction $action, string $content, ?array $component = null): array
    {
        $message = $action->conversation->addMessage(Message::ROLE_ASSISTANT, $content, ['components' => $component ? [$component] : null]);

        return ['id' => $message->id, 'role' => Message::ROLE_ASSISTANT, 'content' => $content, 'components' => $component ? [$component] : []];
    }
}
