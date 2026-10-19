<?php

namespace Filament\ChatAi\Http\Middleware;

use Closure;
use Filament\ChatAi\ChatAiPlugin;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureChatAiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $plugin = Filament::getCurrentPanel()?->getPlugin('chatai');

        abort_unless($plugin instanceof ChatAiPlugin && $plugin->isAccessible(), 403);

        return $next($request);
    }
}
