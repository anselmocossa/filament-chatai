<?php

namespace Filament\ChatAi\Policies;

use Filament\ChatAi\Models\Conversation;
use Illuminate\Contracts\Auth\Authenticatable;

class ConversationPolicy
{
    public function view(Authenticatable $user, Conversation $conversation): bool
    {
        return $conversation->isOwnedBy($user);
    }

    public function update(Authenticatable $user, Conversation $conversation): bool
    {
        return $conversation->isOwnedBy($user);
    }

    public function delete(Authenticatable $user, Conversation $conversation): bool
    {
        return $conversation->isOwnedBy($user);
    }
}
