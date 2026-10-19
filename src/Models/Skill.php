<?php

namespace Filament\ChatAi\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property string $title
 * @property string $when_to_use
 * @property string $instructions
 * @property ?array<int, string> $tools
 * @property bool $enabled
 */
class Skill extends Model
{
    protected $table = 'chatai_skills';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tools' => 'array', 'enabled' => 'boolean'];
    }
}
