<?php

namespace Filament\ChatAi\Llm;

final readonly class StreamChunk
{
    public const TEXT = 'text';

    public const TOOL_CALL = 'tool_call';

    public const DONE = 'done';

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $type,
        public string $text = '',
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public array $arguments = [],
        public ?string $model = null,
        public ?int $tokensIn = null,
        public ?int $tokensOut = null,
        public ?int $cacheRead = null,
        public ?int $cacheWrite = null,
    ) {}

    public static function text(string $text): self
    {
        return new self(self::TEXT, text: $text);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function toolCall(string $id, string $name, array $arguments): self
    {
        return new self(self::TOOL_CALL, toolCallId: $id, toolName: $name, arguments: $arguments);
    }

    /**
     * $tokensIn excludes cached input; cache reads and writes are reported apart (priced differently).
     */
    public static function done(string $model, ?int $tokensIn = null, ?int $tokensOut = null, ?int $cacheRead = null, ?int $cacheWrite = null): self
    {
        return new self(self::DONE, model: $model, tokensIn: $tokensIn, tokensOut: $tokensOut, cacheRead: $cacheRead, cacheWrite: $cacheWrite);
    }
}
