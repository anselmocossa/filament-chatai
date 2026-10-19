<?php

namespace Filament\ChatAi\Support;

use Illuminate\Http\Request;
use Throwable;

/**
 * Links to this system pasted in a question ("look at http://…/shop/customers/445/edit")
 * become "customer #445" for the assistant, the same way the open screen does.
 * Only this application's host is considered; tools still check permissions.
 */
final class UrlContext
{
    public const MAX_LINKS = 5;

    /**
     * @return array<int, array{url: string, resource?: string, record_id?: string}>
     */
    public static function fromMessage(string $message): array
    {
        preg_match_all('#https?://[^\s<>"\')\]]+#i', $message, $matches);

        $hosts = array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), request()->getHost()]);
        $found = [];

        foreach (array_slice(array_unique($matches[0]), 0, self::MAX_LINKS) as $url) {
            $url = rtrim($url, '.,;:!?');

            if (! in_array(parse_url($url, PHP_URL_HOST), $hosts, true)) {
                continue;
            }

            try {
                $route = app('router')->getRoutes()->match(Request::create($url));
                $context = ContextResolver::fromRoute($route->getControllerClass(), $route->parameters());
            } catch (Throwable) {
                continue; // not a page of this app
            }

            if ($context !== []) {
                $found[] = ['url' => $url, ...$context];
            }
        }

        return $found;
    }

    public static function hint(string $message): string
    {
        $links = array_map(
            fn (array $link): string => trim(($link['resource'] ?? 'page').(isset($link['record_id']) ? ' #'.$link['record_id'] : '')).' ('.$link['url'].')',
            self::fromMessage($message),
        );

        return $links === [] ? '' : 'The user linked these records of this system; "this"/"it" may mean them: '.implode('; ', $links).'.';
    }
}
