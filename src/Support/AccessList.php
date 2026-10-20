<?php

namespace Filament\ChatAi\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Who may use or manage the assistant, set without code: each entry is a
 * user's e-mail, a user id or a role name (spatie/laravel-permission).
 */
final class AccessList
{
    /**
     * @param  array<int, mixed>|string|null  $entries
     * @return array<int, string>
     */
    public static function normalize(array|string|null $entries): array
    {
        $entries = is_string($entries) ? explode(',', $entries) : (array) $entries;

        return array_values(array_unique(array_filter(array_map(fn ($entry): string => trim((string) $entry), $entries), 'strlen')));
    }

    /**
     * @param  array<int, mixed>|string|null  $entries
     */
    public static function matches(?Authenticatable $user, array|string|null $entries): bool
    {
        if ($user === null) {
            return false;
        }

        foreach (self::normalize($entries) as $entry) {
            if (str_contains($entry, '@')) {
                if (strcasecmp((string) ($user->email ?? ''), $entry) === 0) {
                    return true;
                }

                continue;
            }

            if (ctype_digit($entry) && (string) $user->getAuthIdentifier() === $entry) {
                return true;
            }

            try {
                if (method_exists($user, 'hasRole') && $user->hasRole($entry)) {
                    return true;
                }
            } catch (Throwable) {
                // A role that does not exist for this guard.
            }
        }

        return false;
    }
}
