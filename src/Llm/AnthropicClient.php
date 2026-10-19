<?php

namespace Filament\ChatAi\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Closure;
use Generator;
use Throwable;

/**
 * Native Claude provider through the official anthropic-ai/sdk (Messages API,
 * streaming, tool use). The rest of the plugin speaks OpenAI-shaped messages;
 * this class translates both ways.
 */
class AnthropicClient implements LlmClient
{
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @param  (Closure(array<string, mixed>, LlmConfig): iterable<object>)|null  $streamer
     *                                                                                       Opens one stream; injectable for tests. Must throw LlmException with the
     *                                                                                       HTTP status as code (0 for connection errors).
     */
    public function __construct(protected ?Closure $streamer = null) {}

    public function stream(LlmConfig $config, array $messages, array $tools): iterable
    {
        $params = $this->params($messages, $tools, $config);
        $lastStatus = null;

        foreach ($config->models() as $model) {
            try {
                $events = ($this->streamer ?? $this->sdkStreamer(...))([...$params, 'model' => $model], $config);
            } catch (LlmException $e) {
                $lastStatus = $e->getCode();

                if ($lastStatus === 0 || $lastStatus === 429 || $lastStatus >= 500) {
                    continue; // rate limit, overload or network: another model may answer
                }

                break;
            }

            yield from $this->parse($events, $model);

            return;
        }

        throw new LlmException("The language model is unavailable (status {$lastStatus}).");
    }

    /**
     * @param  array<string, mixed>  $params
     * @return iterable<object>
     */
    protected function sdkStreamer(array $params, LlmConfig $config): iterable
    {
        $client = new Client(
            apiKey: (string) $config->apiKey,
            baseUrl: $config->baseUrl !== '' ? $config->baseUrl : null,
            requestOptions: ['timeout' => (float) $config->timeout],
        );

        try {
            // Beta endpoint for server-side refusal fallbacks ("default" routing).
            return $client->beta->messages->createStream(...$params, betas: [self::FALLBACK_BETA]);
        } catch (APIStatusException $e) {
            throw new LlmException('Anthropic request failed.', (int) $e->status);
        } catch (APIConnectionException) {
            throw new LlmException('Anthropic connection failed.', 0);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages  OpenAI-shaped
     * @param  array<int, array<string, mixed>>  $tools  OpenAI function definitions
     * @return array<string, mixed>
     */
    public function params(array $messages, array $tools, LlmConfig $config): array
    {
        $system = [];
        $converted = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';

            if ($role === 'system') {
                $system[] = (string) $message['content'];

                continue;
            }

            if ($role === 'tool') {
                $result = ['type' => 'tool_result', 'toolUseID' => (string) $message['tool_call_id'], 'content' => (string) $message['content']];
                $last = array_key_last($converted);

                // All results of one assistant turn travel together in a single user message.
                if ($last !== null && $converted[$last]['role'] === 'user' && is_array($converted[$last]['content'])
                    && ($converted[$last]['content'][0]['type'] ?? null) === 'tool_result') {
                    $converted[$last]['content'][] = $result;
                } else {
                    $converted[] = ['role' => 'user', 'content' => [$result]];
                }

                continue;
            }

            if ($role === 'assistant' && ! empty($message['tool_calls'])) {
                $blocks = filled($message['content'] ?? null) ? [['type' => 'text', 'text' => (string) $message['content']]] : [];

                foreach ($message['tool_calls'] as $call) {
                    $input = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => (string) $call['id'],
                        'name' => (string) $call['function']['name'],
                        'input' => is_array($input) && $input !== [] ? $input : new \stdClass,
                    ];
                }

                $converted[] = ['role' => 'assistant', 'content' => $blocks];

                continue;
            }

            if (blank($message['content'] ?? null)) {
                continue; // the Messages API rejects empty text turns
            }

            $converted[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => (string) $message['content']];
        }

        return array_filter([
            'maxTokens' => $config->maxTokens,
            'messages' => $converted,
            'system' => $system !== [] ? implode("\n\n", $system) : null,
            'tools' => $tools !== [] ? array_map(fn (array $tool): array => [
                'name' => $tool['function']['name'],
                'description' => (string) ($tool['function']['description'] ?? ''),
                'inputSchema' => $tool['function']['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass],
            ], $tools) : null,
            // No temperature: current Claude models reject it ("deprecated"); effort is the knob.
            'outputConfig' => $config->effort ? ['effort' => $config->effort] : null,
            // System prompt, tools and history repeat on every tool round and every
            // question: cache them so repeated input is billed at the cache-read price.
            'cacheControl' => ['type' => 'ephemeral'],
            'fallbacks' => 'default',
        ], fn ($value) => $value !== null);
    }

    /**
     * Events are read by their `type` so both the beta and the regular SDK
     * event classes (and plain objects in tests) work.
     *
     * @param  iterable<object>  $events
     * @return Generator<int, StreamChunk>
     */
    protected function parse(iterable $events, string $model): Generator
    {
        $calls = [];
        $tokensIn = $tokensOut = $cacheRead = $cacheWrite = null;
        $wroteText = false;

        try {
            foreach ($events as $event) {
                switch ($event->type ?? null) {
                    case 'message_start':
                        $tokensIn = $event->message->usage->inputTokens ?? $tokensIn;
                        $cacheRead = $event->message->usage->cacheReadInputTokens ?? $cacheRead;
                        $cacheWrite = $event->message->usage->cacheCreationInputTokens ?? $cacheWrite;
                        break;

                    case 'content_block_start':
                        if (($event->contentBlock->type ?? null) === 'tool_use') {
                            $calls[$event->index] = ['id' => $event->contentBlock->id, 'name' => $event->contentBlock->name, 'json' => ''];
                        }
                        break;

                    case 'content_block_delta':
                        $delta = $event->delta;

                        if (($delta->type ?? null) === 'text_delta' && ($delta->text ?? '') !== '') {
                            $wroteText = true;
                            yield StreamChunk::text($delta->text);
                        } elseif (($delta->type ?? null) === 'input_json_delta' && isset($calls[$event->index])) {
                            $calls[$event->index]['json'] .= $delta->partialJSON ?? '';
                        }
                        break;

                    case 'message_delta':
                        $tokensOut = $event->usage->outputTokens ?? $tokensOut;

                        if (($event->delta->stopReason ?? null) === 'refusal' && ! $wroteText) {
                            yield StreamChunk::text((string) __('chatai::chatai.refused'));
                        }
                        break;
                }
            }
        } catch (LlmException $e) {
            throw $e;
        } catch (APIStatusException $e) {
            // The SDK opens the HTTP request lazily, on first iteration.
            throw new LlmException("The language model is unavailable (status {$e->status}).", (int) $e->status);
        } catch (Throwable) {
            // Never chain the original: provider errors can echo the key or the prompt.
            throw new LlmException('The language model stream failed.');
        }

        ksort($calls);

        foreach ($calls as $call) {
            $arguments = json_decode($call['json'] ?: '{}', true);

            yield StreamChunk::toolCall($call['id'], $call['name'], is_array($arguments) ? $arguments : []);
        }

        yield StreamChunk::done($model, $tokensIn, $tokensOut, $cacheRead, $cacheWrite);
    }
}
