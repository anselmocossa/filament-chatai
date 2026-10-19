<?php

namespace Filament\ChatAi\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property string $title
 * @property string $description
 * @property string $type
 * @property array<string, mixed> $config
 * @property bool $enabled
 */
class CustomTool extends Model
{
    public const TYPE_RESOURCE = 'resource';

    public const TYPE_HTTP = 'http';

    public const TYPE_METRICS = 'metrics';

    public const TYPE_CREATE = 'create';

    public const TYPE_UPDATE = 'update';

    protected $table = 'chatai_custom_tools';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'enabled' => 'boolean'];
    }
}
