<?php

namespace Filament\ChatAi\Pages\Concerns;

use Filament\ChatAi\ChatAiPlugin;
use UnitEnum;

/**
 * Admin pages of the plugin: open only to canManageSettings(), grouped in the menu.
 */
trait ChecksChatAiManager
{
    public static function canAccess(): bool
    {
        return ChatAiPlugin::get()->canManage();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return ChatAiPlugin::get()->getAdminNavigationGroup();
    }
}
