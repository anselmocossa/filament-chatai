<?php

namespace Filament\ChatAi\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * @property string $provider
 * @property string $model
 * @property ?string $effort
 * @property int $sort
 * @property bool $enabled
 * @property ?string $input_price
 * @property ?string $output_price
 * @property ?string $cache_read_price
 * @property ?string $cache_write_price
 */
class AiModel extends Model
{
    protected $table = 'chatai_models';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sort' => 'integer'];
    }

    /**
     * Not called "connection": Eloquent models already have a $connection property
     * (the database connection), which wins inside the class.
     */
    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(AiConnection::class, 'connection_id');
    }

    /**
     * Every model in list order; empty when the table is missing (before migrating).
     *
     * @return Collection<int, static>
     */
    public static function listed(): Collection
    {
        try {
            return Schema::hasTable('chatai_models')
                ? static::query()->with('providerConnection')->orderBy('sort')->orderBy('id')->get()
                : new Collection;
        } catch (Throwable) {
            return new Collection;
        }
    }

    /**
     * Enabled models in order: the first is the main one.
     *
     * @return Collection<int, static>
     */
    public static function chain(): Collection
    {
        // A model whose provider is switched off is skipped too.
        return static::listed()->filter(fn (self $m): bool => $m->enabled && ($m->providerConnection?->enabled ?? true))->values();
    }
}
