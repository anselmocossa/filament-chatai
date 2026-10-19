<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\Models\Memory;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The user's own memory: see it, delete an item or all of it.
 */
class MemoryController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Memory::of($request->user(), ConversationController::tenantId(), Filament::getCurrentPanel()->getId())
            ->map(fn (Memory $m): array => ['id' => $m->id, 'content' => $m->content, 'created_at' => $m->created_at?->toIso8601String()])]);
    }

    public function destroy(Request $request, ?int $memory = null): Response
    {
        $query = Memory::forUser($request->user(), ConversationController::tenantId(), Filament::getCurrentPanel()->getId());

        $memory === null ? $query->delete() : $query->whereKey($memory)->delete();

        return response()->noContent();
    }
}
