<?php

namespace Filament\ChatAi\Support;

/**
 * Where the user was when they asked: lets "how much stock does this have?"
 * resolve "this" to the record open on screen. Indicative only — tools must
 * still check access to whatever record they load.
 */
final readonly class ChatAiContext
{
    public function __construct(
        public string $panelId,
        public ?string $url = null,
        public ?string $resource = null,
        public ?string $recordId = null,
        public ?string $tenantId = null,
        public ?string $pageTitle = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $panelId, array $data, ?string $tenantId = null): self
    {
        $clean = fn (string $key, int $max = 255): ?string => is_scalar($data[$key] ?? null) && (string) $data[$key] !== ''
            ? mb_substr((string) $data[$key], 0, $max)
            : null;

        return new self(
            panelId: $panelId,
            url: $clean('url', 500),
            resource: $clean('resource'),
            recordId: $clean('record_id', 64),
            tenantId: $tenantId,
            pageTitle: $clean('page_title'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'panel' => $this->panelId,
            'url' => $this->url,
            'resource' => $this->resource,
            'record_id' => $this->recordId,
            'tenant' => $this->tenantId,
            'page_title' => $this->pageTitle,
        ], fn (?string $value): bool => $value !== null);
    }
}
