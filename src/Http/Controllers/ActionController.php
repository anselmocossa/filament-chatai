<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\ToolAction;
use Filament\ChatAi\Support\ActionForm;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Ui\ComponentValidator;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Confirm / Cancel buttons of a proposed change.
 */
class ActionController
{
    public function confirm(Request $request, string $action): JsonResponse
    {
        $action = $this->pending($request, $action);

        /** @var ChatAiPlugin $plugin */
        $plugin = Filament::getCurrentPanel()->getPlugin('chatai');
        $context = ChatAiContext::fromArray(Filament::getCurrentPanel()->getId(), (array) $action->context, ConversationController::tenantId());

        // Resolved again for this user now: permissions are checked at the moment it runs.
        $tool = collect($plugin->resolveTools()->tools($request->user(), $context))->firstWhere('name', $action->tool);

        // The form on the card: what the user filled in or corrected.
        if ($tool && $request->has('values')) {
            [$values, $errors] = ActionForm::validate($tool->inputSchema, (array) $request->input('values'));

            if ($errors !== []) {
                $action->update(['status' => ToolAction::PENDING]);

                return response()->json(['message' => __('chatai::chatai.actions.check_form'), 'errors' => $errors], 422);
            }

            $action->update(['arguments' => $values]);
        }

        $result = $tool?->call((array) $action->arguments);
        $ok = $result !== null && ! $result->isError();

        $action->update([
            'status' => $ok ? ToolAction::DONE : ToolAction::FAILED,
            'result' => $result ? ['data' => $result->data, 'text' => $result->text, 'error' => $result->error] : ['error' => 'not available'],
        ]);

        $component = $result?->component ? app(ComponentValidator::class)->validate($result->component, fromServer: true) : null;
        $content = $ok
            ? __('chatai::chatai.actions.done', ['action' => $action->title])
            : __('chatai::chatai.actions.failed', ['action' => $action->title, 'reason' => $result?->error ?? __('chatai::chatai.actions.no_permission')]);

        $message = $this->answer($action, $content, $component);

        return response()->json(['status' => $action->status, 'result' => $ok ? $action->resultFields() : [], 'message' => $message]);
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
