<?php

namespace Filament\ChatAi\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $panel_id
 * @property ?string $tenant_id
 * @property ?string $title
 * @property ?Carbon $last_message_at
 * @property ?Carbon $pinned_at
 * @property ?int $group_id
 */
class Conversation extends Model
{
    use HasUlids;
    use SoftDeletes;

    public const TITLE_LENGTH = 60;

    protected $table = 'chatai_conversations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }

    public static function start(Authenticatable $user, string $panelId, ?string $tenantId = null): static
    {
        /** @var Model $user */
        return static::create([
            'user_type' => $user->getMorphClass(),
            'user_id' => $user->getKey(),
            'tenant_id' => $tenantId,
            'panel_id' => $panelId,
        ]);
    }

    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForUser(Builder $query, Authenticatable $user, ?string $tenantId = null): void
    {
        /** @var Model $user */
        $query->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey())
            ->where(fn (Builder $q) => $tenantId === null
                ? $q->whereNull('tenant_id')
                : $q->where('tenant_id', $tenantId));
    }

    public function isOwnedBy(Authenticatable $user): bool
    {
        /** @var Model $user */
        return $this->user_type === $user->getMorphClass()
            && (string) $this->user_id === (string) $user->getKey();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addMessage(string $role, ?string $content, array $attributes = []): Message
    {
        $message = $this->messages()->create([
            'role' => $role,
            'content' => $content,
            ...$attributes,
        ]);

        $updates = ['last_message_at' => now()];

        if ($this->title === null && $role === Message::ROLE_USER && filled($content)) {
            $updates['title'] = Str::of($content)->squish()->substr(0, self::TITLE_LENGTH)->toString();
        }

        $this->update($updates);

        return $message;
    }
}
