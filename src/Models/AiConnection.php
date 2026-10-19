<?php

namespace Filament\ChatAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property string $driver
 * @property ?string $base_url
 * @property ?string $api_key
 * @property bool $enabled
 * @property ?string $monthly_limit
 * @property ?string $daily_limit
 */
class AiConnection extends Model
{
    protected $table = 'chatai_connections';

    protected $guarded = [];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'enabled' => 'boolean', 'monthly_limit' => 'decimal:2', 'daily_limit' => 'decimal:2'];
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'connection_id');
    }
}
