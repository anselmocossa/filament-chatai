<?php

namespace Filament\ChatAi\Testing;

use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Llm\LlmException;
use Filament\ChatAi\Llm\StreamChunk;

/**
 * Scripted model for tests: each call to stream() plays the next turn.
 *
 *     $fake = FakeLlmClient::script([
 *         [FakeLlmClient::call('sales-stats', ['period' => 'month'])],
 *         ['Vendas de 45 300 MZN.'],
 *     ]);
 */
class FakeLlmClient implements LlmClient
{
    /** @var array<int, array{messages: array<int, array<string, mixed>>, tools: array<int, array<string, mixed>>}> */
    public array $requests = [];

    /** @var array<int, LlmConfig> */
    public array $configs = [];

    /**
     * @param  array<int, array<int, string|StreamChunk>|LlmException>  $turns
     */
    public function __construct(protected array $turns = []) {}

    /**
     * @param  array<int, array<int, string|StreamChunk>|LlmException>  $turns
     */
    public static function script(array $turns): static
    {
        return new static($turns);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function call(string $name, array $arguments = [], ?string $id = null): StreamChunk
    {
        return StreamChunk::toolCall($id ?? 'call_'.$name, $name, $arguments);
    }

    public function stream(LlmConfig $config, array $messages, array $tools): iterable
    {
        $this->requests[] = ['messages' => $messages, 'tools' => $tools];
        $this->configs[] = $config;

        $turn = array_shift($this->turns) ?? ['(no more scripted turns)'];

        if ($turn instanceof LlmException) {
            throw $turn;
        }

        foreach ($turn as $chunk) {
            yield is_string($chunk) ? StreamChunk::text($chunk) : $chunk;
        }

        yield StreamChunk::done($config->model, 100, 20);
    }
}
