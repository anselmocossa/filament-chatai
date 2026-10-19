<?php

namespace Filament\ChatAi\Events;

use Filament\ChatAi\Models\Conversation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after every answered question. The plugin does not price anything:
 * listen to this to log usage or enforce quotas (e.g. the Store's AiUsageLog).
 */
class TurnCompleted
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $toolsUsed
     */
    public function __construct(
        public Authenticatable $user,
        public Conversation $conversation,
        public string $model,
        public int $tokensIn,
        public int $tokensOut,
        public int $latencyMs,
        public array $toolsUsed,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
        public ?string $messageId = null,
    ) {}
}
