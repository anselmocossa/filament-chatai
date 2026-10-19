<?php

namespace Filament\ChatAi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $conversation_id
 * @property ?string $message_id
 * @property string $kind
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property string $disk
 * @property string $path
 */
class ConversationFile extends Model
{
    use HasUlids;

    public const KIND_UPLOAD = 'upload';

    public const KIND_GENERATED = 'generated';

    public const UPDATED_AT = null;

    protected $table = 'chatai_files';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contents(): string
    {
        return (string) Storage::disk($this->disk)->get($this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    /**
     * @return array{id: string, name: string, size: int, mime: string}
     */
    public function summary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'size' => $this->size, 'mime' => $this->mime];
    }
}
