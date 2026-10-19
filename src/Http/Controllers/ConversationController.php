<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Message;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ConversationController
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $conversations = Conversation::forUser($request->user(), static::tenantId())
            ->where('panel_id', Filament::getCurrentPanel()->getId())
            ->when($search !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($search, '%_').'%'))
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'title', 'last_message_at', 'created_at']);

        return response()->json(['data' => $conversations->map(fn (Conversation $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'last_message_at' => ($c->last_message_at ?? $c->created_at)?->toIso8601String(),
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $conversation = Conversation::start($request->user(), Filament::getCurrentPanel()->getId(), static::tenantId());

        return response()->json(['id' => $conversation->id, 'title' => null], 201);
    }

    public function show(string $conversation): JsonResponse
    {
        $conversation = static::authorized($conversation, 'view');

        // What the user saw: questions and answers. Tool plumbing stays server-side.
        $messages = $conversation->messages()
            ->whereIn('role', [Message::ROLE_USER, Message::ROLE_ASSISTANT])
            ->where(fn ($q) => $q->where('role', Message::ROLE_USER)->orWhereNotNull('content')->orWhereNotNull('components'))
            ->get()
            ->reject(fn (Message $m) => $m->role === Message::ROLE_ASSISTANT && blank($m->content) && blank($m->components))
            ->values()
            ->map(fn (Message $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'components' => $m->components ?? [],
                'status' => $m->status,
            ]);

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'messages' => $messages,
        ]);
    }

    public function destroy(string $conversation): Response
    {
        static::authorized($conversation, 'delete')->delete();

        return response()->noContent();
    }

    public static function authorized(string $id, string $ability): Conversation
    {
        $conversation = Conversation::query()->findOrFail($id);

        Gate::authorize($ability, $conversation);

        return $conversation;
    }

    public static function tenantId(): ?string
    {
        $key = Filament::getTenant()?->getKey();

        return $key === null ? null : (string) $key;
    }
}
