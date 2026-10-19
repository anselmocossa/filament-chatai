<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\ConversationGroup;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The user's own folders in the history sidebar.
 */
class ConversationGroupController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        /** @var Model $user */
        $user = $request->user();

        $group = ConversationGroup::query()->create([
            'user_type' => $user->getMorphClass(),
            'user_id' => $user->getKey(),
            'tenant_id' => ConversationController::tenantId(),
            'panel_id' => Filament::getCurrentPanel()->getId(),
            'name' => trim($data['name']),
            'sort' => (int) static::groups($request)->max('sort') + 1,
        ]);

        return response()->json(['id' => $group->id, 'name' => $group->name], 201);
    }

    public function update(Request $request, int $group): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $group = static::groups($request)->findOrFail($group);
        $group->update(['name' => trim($data['name'])]);

        return response()->json(['id' => $group->id, 'name' => $group->name]);
    }

    /**
     * Deleting a group keeps its conversations; they go back to the list.
     */
    public function destroy(Request $request, int $group): Response
    {
        $group = static::groups($request)->findOrFail($group);

        Conversation::forUser($request->user(), ConversationController::tenantId())->where('group_id', $group->id)->update(['group_id' => null]);
        $group->delete();

        return response()->noContent();
    }

    /**
     * @return Builder<ConversationGroup>
     */
    protected static function groups(Request $request)
    {
        return ConversationGroup::forUser($request->user(), ConversationController::tenantId(), Filament::getCurrentPanel()->getId());
    }
}
