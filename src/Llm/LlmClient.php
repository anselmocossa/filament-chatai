<?php

namespace Filament\ChatAi\Llm;

interface LlmClient
{
    /**
     * Stream one model turn: text deltas as they arrive, then the complete
     * tool calls (if any), then a single DONE chunk with usage.
     *
     * @param  array<int, array<string, mixed>>  $messages  OpenAI chat messages
     * @param  array<int, array<string, mixed>>  $tools  OpenAI function definitions
     * @return iterable<StreamChunk>
     *
     * @throws LlmException
     */
    public function stream(LlmConfig $config, array $messages, array $tools): iterable;
}
