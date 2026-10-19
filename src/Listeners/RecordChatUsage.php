<?php

namespace Filament\ChatAi\Listeners;

use Filament\ChatAi\Events\TurnCompleted;
use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Models\Usage;
use Filament\ChatAi\Support\Pricing;

class RecordChatUsage
{
    public function handle(TurnCompleted $event): void
    {
        Usage::record($event->user, Usage::KIND_CHAT, [
            'panel_id' => $event->conversation->panel_id,
            'tenant_id' => $event->conversation->tenant_id,
            'conversation_id' => $event->conversation->id,
            'message_id' => $event->messageId,
            'latency_ms' => $event->latencyMs,
            'tools' => $event->toolsUsed,
            'model' => $event->model,
            // The provider of the model that answered, for its spending limit.
            'connection_id' => AiModel::chain()->firstWhere('model', $event->model)?->connection_id
                ?? AiModel::query()->where('model', $event->model)->value('connection_id'),
            'tokens_in' => $event->tokensIn,
            'tokens_out' => $event->tokensOut,
            'cache_read_tokens' => $event->cacheRead,
            'cache_write_tokens' => $event->cacheWrite,
            'cost' => Pricing::chat($event->model, $event->tokensIn, $event->tokensOut, $event->cacheRead, $event->cacheWrite),
        ]);
    }
}
