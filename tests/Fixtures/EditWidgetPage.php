<?php

namespace Filament\ChatAi\Tests\Fixtures;

/**
 * Stands in for a Filament resource page: ContextResolver only needs getResource().
 */
class EditWidgetPage
{
    public static function getResource(): string
    {
        return WidgetResource::class;
    }
}

class WidgetResource
{
    public static function getModelLabel(): string
    {
        return 'Widget';
    }
}
