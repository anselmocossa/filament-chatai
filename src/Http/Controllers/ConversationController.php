<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\ConversationFile;
use Filament\ChatAi\Models\ConversationGroup;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\ToolAction;
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
            ->orderByRaw('pinned_at is null')
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'title', 'last_message_at', 'created_at', 'pinned_at', 'group_id']);

        $groups = ConversationGroup::forUser($request->user(), static::tenantId(), Filament::getCurrentPanel()->getId())
            ->orderBy('sort')->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'data' => $conversations->map(fn (Conversation $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'last_message_at' => ($c->last_message_at ?? $c->created_at)?->toIso8601String(),
                'pinned' => $c->pinned_at !== null,
                'group_id' => $c->group_id,
            ]),
            'groups' => $groups->map(fn (ConversationGroup $g): array => ['id' => $g->id, 'name' => $g->name]),
        ]);
    }

    /**
     * Rename, pin/unpin or move a conversation to one of the user's groups.
     */
    public function update(Request $request, string $conversation): JsonResponse
    {
        $conversation = static::authorized($conversation, 'update');

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'pinned' => ['sometimes', 'boolean'],
            'group_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if (array_key_exists('group_id', $data) && $data['group_id'] !== null) {
            abort_unless(
                ConversationGroup::forUser($request->user(), static::tenantId(), Filament::getCurrentPanel()->getId())->whereKey($data['group_id'])->exists(),
                422,
            );
        }

        if (array_key_exists('title', $data)) {
            $conversation->title = filled($data['title']) ? trim($data['title']) : null;
        }

        if (array_key_exists('pinned', $data)) {
            $conversation->pinned_at = $data['pinned'] ? now() : null;
        }

        if (array_key_exists('group_id', $data)) {
            $conversation->group_id = $data['group_id'];
        }

        $conversation->save();

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'pinned' => $conversation->pinned_at !== null,
            'group_id' => $conversation->group_id,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $conversation = Conversation::start($request->user(), Filament::getCurrentPanel()->getId(), static::tenantId());

        return response()->json(['id' => $conversation->id, 'title' => null], 201);
    }

    public function show(string $conversation): JsonResponse
    {
        $conversation = static::authorized($conversation, 'view');

        $files = ConversationFile::query()->where('conversation_id', $conversation->id)->where('kind', ConversationFile::KIND_UPLOAD)
            ->whereNotNull('message_id')->orderBy('id')->get()->groupBy('message_id');
        $panel = Filament::getCurrentPanel();
        $actions = ToolAction::query()->where('conversation_id', $conversation->id)->get()->keyBy('id');
        $withStatus = fn (array $components): array => array_map(function (array $c) use ($actions): array {
            $action = ($c['type'] ?? null) === 'confirm' ? $actions->get($c['action_id'] ?? '') : null;

            return $action ? [...$c, 'status' => $action->status, 'result' => $action->status === ToolAction::DONE ? $action->resultFields() : []] : $c;
        }, $components);

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
                'components' => $withStatus($m->components ?? []),
                'status' => $m->status,
                'attachments' => ($files->get($m->id) ?? collect())->map(fn (ConversationFile $f): array => [
                    ...$f->summary(),
                    'url' => $panel->route('chatai.files.download', ['file' => $f->id]),
                ])->values()->all(),
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
