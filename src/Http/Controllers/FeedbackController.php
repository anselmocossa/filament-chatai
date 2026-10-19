<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thumbs up / down on an answer (and why, optionally).
 */
class FeedbackController
{
    public function __invoke(Request $request, string $message): JsonResponse
    {
        $message = Message::query()->where('role', Message::ROLE_ASSISTANT)->findOrFail($message);
        ConversationController::authorized($message->conversation_id, 'update');

        $data = $request->validate([
            'value' => ['nullable', 'in:up,down'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $message->update([
            'feedback' => match ($data['value'] ?? null) {
                'up' => 1,
                'down' => -1,
                default => null,
            },
            'feedback_comment' => ($data['value'] ?? null) === 'down' ? (trim((string) ($data['comment'] ?? '')) ?: null) : null,
        ]);

        return response()->json(['feedback' => $data['value'] ?? null]);
    }
}
