<?php

namespace Filament\ChatAi\Agent;

/**
 * Keeps the recent turns verbatim and replaces older ones with a short memory
 * once a conversation outgrows its character budget. A tool call and its
 * results stay together so the provider still receives a valid transcript.
 */
final class HistoryWindow
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    public static function fit(array $messages, int $budget, int $keep): array
    {
        if ($budget <= 0 || $messages === [] || self::size($messages) <= $budget) {
            return $messages;
        }

        $keep = max(1, min($keep, $budget));
        $count = count($messages);
        $start = $count;
        $used = 0;

        for ($index = $count - 1; $index >= 0; $index--) {
            $piece = self::size([$messages[$index]]);
            if ($start < $count && $used + $piece > $keep) {
                break;
            }
            $used += $piece;
            $start = $index;
        }

        while ($start > 0 && ($messages[$start]['role'] ?? '') === 'tool') {
            $start--;
        }

        $head = array_slice($messages, 0, $start);
        if ($head === []) {
            return $messages;
        }

        return [self::memory($head), ...array_slice($messages, $start)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public static function size(array $messages): int
    {
        $total = 0;
        foreach ($messages as $message) {
            $total += strlen(json_encode($message, JSON_UNESCAPED_UNICODE) ?: '');
        }

        return $total;
    }

    /**
     * @param  array<int, array<string, mixed>>  $head
     * @return array<string, mixed>
     */
    private static function memory(array $head): array
    {
        $names = [];
        $objectives = [];
        $decisions = [];
        $results = [];

        foreach ($head as $message) {
            foreach ($message['tool_calls'] ?? [] as $call) {
                $id = $call['id'] ?? null;
                $name = $call['function']['name'] ?? null;
                if (is_string($id) && is_string($name)) {
                    $names[$id] = $name;
                }
            }

            $text = trim(self::text($message['content'] ?? ''));
            if ($text === '') {
                continue;
            }

            $role = $message['role'] ?? '';
            if ($role === 'user') {
                $objectives[] = self::clip($text, 240);

                continue;
            }

            if ($role !== 'tool') {
                continue;
            }

            $name = $names[$message['tool_call_id'] ?? ''] ?? 'tool';
            $results[] = $name.': '.self::clip($text, 400);
            $decision = self::decision($name, $text);
            if ($decision !== null) {
                $decisions[] = $decision;
            }
        }

        return ['role' => 'system', 'content' => implode("\n\n", [
            'Earlier turns were compacted into this memory. The messages after it are unchanged. Answer the latest user message; do not treat this memory as a new question.',
            "## Objective\n".self::bullets($objectives),
            "## Decisions\n".self::bullets($decisions),
            "## Tool results\n".self::bullets($results),
        ])];
    }

    private static function decision(string $name, string $content): ?string
    {
        if (str_contains($content, 'awaiting_confirmation')) {
            return $name.': waiting for confirmation. '.self::clip($content, 300);
        }

        if (str_contains($content, 'cancelled') || str_contains($content, 'canceled')) {
            return $name.': cancelled. '.self::clip($content, 300);
        }

        if (preg_match('/"status"\s*:\s*"done"/', $content) === 1) {
            return $name.': confirmed. '.self::clip($content, 300);
        }

        return null;
    }

    /**
     * @param  array<int, string>  $items
     */
    private static function bullets(array $items): string
    {
        $items = array_slice($items, -6);
        if ($items === []) {
            return '- (none)';
        }

        return implode("\n", array_map(fn (string $item): string => '- '.$item, $items));
    }

    private static function text(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part) && isset($part['text'])) {
                $parts[] = (string) $part['text'];
            }
        }

        return implode("\n", $parts);
    }

    private static function clip(string $text, int $limit): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit).'…';
    }
}
