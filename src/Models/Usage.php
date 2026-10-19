<?php

namespace Filament\ChatAi\Models;

use Filament\ChatAi\Support\Pricing;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $kind
 * @property ?string $model
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $characters
 * @property string $seconds
 * @property ?string $cost
 * @property ?string $conversation_id
 */
class Usage extends Model
{
    public const KIND_CHAT = 'chat';

    public const KIND_SPEECH = 'speech';

    public const KIND_TRANSCRIPTION = 'transcription';

    public const UPDATED_AT = null;

    protected $table = 'chatai_usage';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'latency_ms' => 'integer',
            'tools' => 'array',
            'characters' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function record(?Authenticatable $user, string $kind, array $attributes): self
    {
        /** @var ?Model $user */
        return static::query()->create([
            'user_type' => $user?->getMorphClass(),
            'user_id' => $user?->getKey(),
            'kind' => $kind,
            'currency' => Pricing::currency(),
            ...$attributes,
        ]);
    }
}
