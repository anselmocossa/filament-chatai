<?php

namespace Filament\ChatAi\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A folder in the history sidebar. Each user has their own.
 *
 * @property int $id
 * @property string $name
 * @property int $sort
 */
class ConversationGroup extends Model
{
    protected $table = 'chatai_conversation_groups';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    public function scopeForUser(Builder $query, Authenticatable $user, ?string $tenantId, string $panelId): void
    {
        /** @var Model $user */
        $query->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey())
            ->where('panel_id', $panelId)
            ->where(fn (Builder $q) => $tenantId === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tenantId));
    }
}
