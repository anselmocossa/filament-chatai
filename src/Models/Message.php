<?php

namespace Filament\ChatAi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $role
 * @property ?string $content
 * @property ?array<int, array<string, mixed>> $components
 * @property ?array<int, array<string, mixed>> $tool_calls
 * @property ?string $tool_call_id
 * @property ?string $tool_name
 * @property string $status
 */
class Message extends Model
{
    use HasUlids;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_INTERRUPTED = 'interrupted';

    public const STATUS_ERROR = 'error';

    public const UPDATED_AT = null;

    protected $table = 'chatai_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'tool_calls' => 'array',
            'context' => 'array',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
