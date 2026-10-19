<?php

namespace Filament\ChatAi\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tool_name
 * @property bool $enabled
 * @property ?string $title
 * @property ?string $description
 * @property ?array<int, string> $roles
 * @property ?string $permission
 */
class ToolOverride extends Model
{
    protected $table = 'chatai_tool_overrides';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'roles' => 'array'];
    }
}
