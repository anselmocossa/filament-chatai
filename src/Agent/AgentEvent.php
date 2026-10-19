<?php

namespace Filament\ChatAi\Agent;

/**
 * One server-sent event of a turn. The same event types are the contract for
 * every client (web now, mobile later).
 */
final readonly class AgentEvent
{
    public const DELTA = 'delta';

    public const TOOL_START = 'tool_start';

    public const TOOL_END = 'tool_end';

    public const COMPONENT = 'component';

    /** A new round after tool calls: the answer text starts over (the earlier lines were only narration). */
    public const STEP = 'step';

    /** Values for the form on screen; the browser writes them into its fields. */
    public const FILL = 'fill';

    public const DONE = 'done';

    public const ERROR = 'error';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $type,
        public array $data = [],
    ) {}

    public function toSse(): string
    {
        return 'event: '.$this->type."\n"
            .'data: '.json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
    }
}
