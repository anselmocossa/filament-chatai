<?php

namespace Filament\ChatAi\Tests\Fixtures;

use Filament\ChatAi\ChatAiPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class TestPanelProvider extends PanelProvider
{
    /**
     * Tests swap the plugin (to change its settings) before the panel boots.
     */
    public static ?ChatAiPlugin $plugin = null;

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->plugins([static::$plugin ?? ChatAiPlugin::make()])
            ->resources([UserResource::class, Shop\ProductResource::class, Shop\OrderResource::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
