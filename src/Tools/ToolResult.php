<?php

namespace Filament\ChatAi\Tools;

final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>|null  $data  structured output handed to the model
     * @param  array<string, mixed>|null  $component  a catalog component to render as-is
     */
    public function __construct(
        public ?array $data = null,
        public ?string $text = null,
        public ?array $component = null,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $component
     */
    public static function data(array $data, ?array $component = null): self
    {
        return new self(data: $data, component: $component);
    }

    public static function text(string $text): self
    {
        return new self(text: $text);
    }

    public static function error(string $message): self
    {
        return new self(error: $message);
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * What the model reads back as the tool message.
     */
    public function toModelContent(int $maxBytes): string
    {
        $content = match (true) {
            $this->isError() => json_encode(['error' => $this->error], JSON_UNESCAPED_UNICODE),
            $this->data !== null => json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $this->text,
        };

        return strlen((string) $content) > $maxBytes
            ? mb_strcut((string) $content, 0, $maxBytes).'…[truncated]'
            : (string) $content;
    }
}
