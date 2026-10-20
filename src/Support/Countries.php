<?php

namespace Filament\ChatAi\Support;

/**
 * Where each country sits on a map (its centre), by ISO 3166-1 alpha-2 code,
 * so a map can place a country from its code instead of trusting coordinates
 * the model would make up.
 */
final class Countries
{
    /** @var array<string, array{0: float, 1: float, 2: string}>|null */
    protected static ?array $all = null;

    /**
     * @return array{lat: float, lng: float, name: string}|null
     */
    public static function find(mixed $code): ?array
    {
        if (! is_string($code) || strlen(trim($code)) !== 2) {
            return null;
        }

        $row = self::all()[strtoupper(trim($code))] ?? null;

        return $row ? ['lat' => $row[0], 'lng' => $row[1], 'name' => $row[2]] : null;
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: string}>
     */
    protected static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];

            foreach (file(__DIR__.'/../../resources/data/countries.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                [$code, $lat, $lng, $name] = str_getcsv($line, escape: '\\') + [null, null, null, null];
                self::$all[(string) $code] = [(float) $lat, (float) $lng, (string) $name];
            }
        }

        return self::$all;
    }
}
