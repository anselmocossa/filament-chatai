<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Support\ChatAiContext;

/**
 * The prompt only carries a preview of each list on screen. This reads the
 * rest, a page at a time, when the model wants to count or analyse.
 */
final class ReadScreenListTool
{
    public const NAME = 'read_screen_list';

    /** Ceiling of rows in one call. A wide row stops earlier so the result is not cut mid-JSON. */
    public const PAGE = 80;

    private const BUDGET = 16_000;

    public static function available(ChatAiContext $context): bool
    {
        return $context->lists !== [];
    }

    public static function make(ChatAiContext $context): ChatAiTool
    {
        $summary = collect($context->lists)->map(function (array $list, int $index): string {
            $headers = implode(', ', $list['headers'] ?? []);
            $what = $headers !== '' ? $headers : 'records';
            $cut = ($list['capped'] ?? false) ? ', the page had still more' : '';

            return "{$index}: {$what} ({$list['total']}{$cut})";
        })->implode('; ');

        return new ChatAiTool(
            name: self::NAME,
            description: 'Reads rows of a list the user has open. The prompt only includes the first '.ChatAiContext::PREVIEW_ROWS.' rows of each list. '
                .'Call this when you need the whole list to count, compare or analyse. Lists: '.$summary.'. '
                .'If remaining is above 0, call again with that list\'s offset. Do not replace this with one lookup per row.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'index' => ['type' => 'integer', 'description' => 'Which list, from the description. Default 0.'],
                    'offset' => ['type' => 'integer', 'description' => 'First row to return. 0 is the start. Use the offset from the previous note.'],
                    'limit' => ['type' => 'integer', 'description' => 'How many rows, up to '.self::PAGE.'. Default '.self::PAGE.'.'],
                ],
            ],
            handler: fn (array $arguments): ToolResult => self::read($context, $arguments),
            readOnly: true,
            title: 'Read the list on screen',
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function read(ChatAiContext $context, array $arguments): ToolResult
    {
        $index = max(0, (int) ($arguments['index'] ?? 0));
        $list = $context->lists[$index] ?? null;

        if (! is_array($list)) {
            return ToolResult::error('There is no list '.$index.' on screen.');
        }

        $rows = array_values((array) ($list['rows'] ?? []));
        $total = count($rows);
        $offset = max(0, min((int) ($arguments['offset'] ?? 0), $total));
        $limit = max(1, min((int) ($arguments['limit'] ?? self::PAGE), self::PAGE));
        $page = [];

        foreach (array_slice($rows, $offset, $limit) as $row) {
            $next = $page;
            $next[] = $row;

            if ($page !== [] && strlen((string) json_encode($next, JSON_UNESCAPED_UNICODE)) > self::BUDGET) {
                break;
            }

            $page[] = $row;
        }

        $nextOffset = $offset + count($page);
        $remaining = $total - $nextOffset;
        $capped = (bool) ($list['capped'] ?? false);

        $data = [
            'index' => $index,
            'headers' => array_values((array) ($list['headers'] ?? [])),
            'total' => $total,
            'offset' => $offset,
            'returned' => count($page),
            'remaining' => $remaining,
            'rows' => $page,
        ];

        if ($capped) {
            $data['capped'] = true;
        }

        if ($remaining > 0) {
            $data['note'] = 'Call again with offset '.$nextOffset.' for the next rows.';
        } elseif ($capped) {
            $data['note'] = 'The page had more rows than were sent. This is everything captured from the screen.';
        }

        return ToolResult::data($data);
    }
}
