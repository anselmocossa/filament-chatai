<?php

namespace Filament\ChatAi\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Something a user asked the assistant to remember, used in their next conversations.
 *
 * @property int $id
 * @property string $content
 */
class Memory extends Model
{
    public const MAX_PER_USER = 50;

    protected $table = 'chatai_memories';

    protected $guarded = [];

    public function scopeForUser(Builder $query, Authenticatable $user, ?string $tenantId, string $panelId): void
    {
        /** @var Model $user */
        $query->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey())
            ->where('panel_id', $panelId)
            ->where(fn (Builder $q) => $tenantId === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tenantId));
    }

    /**
     * The user's memories, newest last; empty before migrating.
     *
     * @return Collection<int, static>
     */
    public static function of(Authenticatable $user, ?string $tenantId, string $panelId): Collection
    {
        try {
            return Schema::hasTable('chatai_memories')
                ? static::forUser($user, $tenantId, $panelId)->orderBy('id')->limit(self::MAX_PER_USER)->get()
                : new Collection;
        } catch (Throwable) {
            return new Collection;
        }
    }
}
