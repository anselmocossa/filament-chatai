<?php

namespace Filament\ChatAi\Tests\Fixtures\Shop;

use Filament\Resources\Resource;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function getPages(): array
    {
        return [];
    }
}

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function getPages(): array
    {
        return [];
    }
}
