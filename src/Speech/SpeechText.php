<?php

namespace Filament\ChatAi\Speech;

use Illuminate\Support\Str;

final class SpeechText
{
    public const MAX_LENGTH = 3000;

    /**
     * Markdown → what a voice should say: no asterisks, backticks or link syntax.
     */
    public static function from(?string $markdown): string
    {
        $text = preg_replace(
            ['/```.*?```/s', '/\[([^\]]+)\]\([^)]+\)/', '/[*_`#>]+/', '/^\s*[-•]\s+/m', '/\s+/'],
            [' ', '$1', '', '', ' '],
            (string) $markdown,
        );

        return Str::limit(trim((string) $text), self::MAX_LENGTH, '…');
    }
}
