<?php

namespace Filament\ChatAi\Tests\Fixtures;

use Filament\Resources\Resource;

/**
 * A panel resource for the "query a Resource" custom tools.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function getPages(): array
    {
        return [];
    }
}
