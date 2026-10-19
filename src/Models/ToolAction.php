<?php

namespace Filament\ChatAi\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change the assistant proposed (a call to a tool that is not read-only).
 * It waits for the user's confirmation and runs only then.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string $tool
 * @property ?string $title
 * @property array<string, mixed> $arguments
 * @property array<string, mixed> $context
 * @property string $status
 * @property ?array<string, mixed> $result
 */
class ToolAction extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    protected $table = 'chatai_actions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'context' => 'array', 'result' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * What the change returned, as label/value pairs for the card (scalars only).
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function resultFields(): array
    {
        $data = (array) ($this->result['data'] ?? []);
        $fields = [];
        $text = fn ($v): string => is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;

        // A change that reports before / after: one line per column, "1 → 4".
        if (is_array($data['before'] ?? null) && is_array($data['after'] ?? null)) {
            foreach ($data['after'] as $column => $after) {
                $before = $data['before'][$column] ?? null;
                $fields[] = ['label' => (string) str((string) $column)->replace(['_', '-'], ' ')->ucfirst(), 'value' => is_scalar($before) || $before === null ? $text($before).' → '.$text($after) : $text($after)];
            }

            unset($data['before'], $data['after']);
        }

        $scalars = [];

        foreach (array_slice($data, 0, 8, true) as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $scalars[] = ['label' => (string) str((string) $key)->replace(['_', '-'], ' ')->ucfirst(), 'value' => $text($value)];
            }
        }

        $fields = [...$scalars, ...$fields];

        if ($fields === [] && filled($this->result['text'] ?? null)) {
            $fields[] = ['label' => 'Result', 'value' => str((string) $this->result['text'])->limit(300)->toString()];
        }

        return $fields;
    }

    public function isOwnedBy(Authenticatable $user): bool
    {
        /** @var Model $user */
        return $this->user_type === $user->getMorphClass() && (string) $this->user_id === (string) $user->getKey();
    }
}
