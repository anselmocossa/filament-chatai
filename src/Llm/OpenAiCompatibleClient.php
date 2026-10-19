<?php

namespace Filament\ChatAi\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * One client for OpenAI, OpenRouter and Ollama: all speak the OpenAI
 * /chat/completions dialect with `tools` and `stream: true`.
 */
class OpenAiCompatibleClient implements LlmClient
{
    public function stream(LlmConfig $config, array $messages, array $tools): iterable
    {
        [$response, $model] = $this->open($config, $messages, $tools);

        yield from $this->parse($response->toPsrResponse()->getBody(), $model);
    }

    /**
     * Try each model until one accepts the request. Fallback only happens
     * before any byte is streamed, so the user never sees two answers.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array{0: Response, 1: string}
     */
    protected function open(LlmConfig $config, array $messages, array $tools): array
    {
        $lastStatus = null;

        foreach ($config->models() as $model) {
            try {
                $response = Http::withToken((string) $config->apiKey)
                    ->acceptJson()
                    ->timeout($config->timeout)
                    ->withOptions(['stream' => true])
                    ->post($config->baseUrl.'/chat/completions', array_filter([
                        'model' => $model,
                        'messages' => $messages,
                        'tools' => $tools ?: null,
                        'tool_choice' => $tools ? 'auto' : null,
                        // Reasoning models (o-series, gpt-5…) only; other models reject it, so it is sent only when set.
                        'reasoning_effort' => $config->effort,
                        'max_tokens' => $config->outputTokens,
                        'temperature' => $config->temperature,
                        'stream' => true,
                        'stream_options' => ['include_usage' => true],
                    ], fn ($value) => $value !== null));
            } catch (ConnectionException) {
                $lastStatus = 'connection';

                continue;
            }

            if ($response->successful()) {
                return [$response, $model];
            }

            $lastStatus = $response->status();

            if ($lastStatus !== 429 && $lastStatus < 500) {
                break; // 4xx other than rate limit: another model won't help (bad key, bad request)
            }
        }

        // Status only: provider bodies can echo the key or the prompt.
        throw new LlmException("The language model is unavailable (status {$lastStatus}).");
    }

    /**
     * @return \Generator<int, StreamChunk>
     */
    protected function parse(StreamInterface $body, string $model): \Generator
    {
        $buffer = '';
        $calls = [];
        $tokensIn = $tokensOut = $cacheRead = null;

        while (! $body->eof()) {
            $buffer .= $body->read(2048);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));

                if ($payload === '[DONE]') {
                    break 2;
                }

                $event = json_decode($payload, true);

                if (! is_array($event)) {
                    continue;
                }

                if (isset($event['usage'])) {
                    $cacheRead = $event['usage']['prompt_tokens_details']['cached_tokens'] ?? $cacheRead;
                    $tokensIn = isset($event['usage']['prompt_tokens']) ? $event['usage']['prompt_tokens'] - (int) $cacheRead : $tokensIn;
                    $tokensOut = $event['usage']['completion_tokens'] ?? $tokensOut;
                }

                $delta = $event['choices'][0]['delta'] ?? [];

                if (($delta['content'] ?? '') !== '') {
                    yield StreamChunk::text($delta['content']);
                }

                foreach ($delta['tool_calls'] ?? [] as $part) {
                    $index = $part['index'] ?? 0;
                    $calls[$index]['id'] = $part['id'] ?? ($calls[$index]['id'] ?? 'call_'.$index);
                    $calls[$index]['name'] = ($calls[$index]['name'] ?? '').($part['function']['name'] ?? '');
                    $calls[$index]['arguments'] = ($calls[$index]['arguments'] ?? '').($part['function']['arguments'] ?? '');
                }
            }
        }

        ksort($calls);

        foreach ($calls as $call) {
            $arguments = json_decode($call['arguments'] ?: '{}', true);

            yield StreamChunk::toolCall($call['id'], $call['name'], is_array($arguments) ? $arguments : []);
        }

        yield StreamChunk::done($model, $tokensIn, $tokensOut, $cacheRead);
    }
}
