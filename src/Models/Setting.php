<?php

namespace Filament\ChatAi\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property array{v: mixed} $value
 */
class Setting extends Model
{
    protected $table = 'chatai_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Wrapped as ['v' => ...] so scalars, lists and booleans all round-trip.
            'value' => 'encrypted:array',
        ];
    }
}
