<?php

namespace Filament\ChatAi\Llm;

use Anthropic\Client;
use Closure;
use Generator;

/**
 * The default LlmClient: picks the implementation from LlmConfig::$provider,
 * so a panel (or a tenant) can switch provider at runtime via ->llm().
 * When the main provider fails before answering, the fallback provider takes over.
 */
class ProviderLlmClient implements LlmClient
{
    /**
     * @param  (Closure(LlmConfig): LlmClient)|null  $resolve  picks the client per config; injectable for tests
     */
    public function __construct(
        protected ?OpenAiCompatibleClient $openAi = null,
        protected ?AnthropicClient $anthropic = null,
        protected ?Closure $resolve = null,
    ) {}

    public function stream(LlmConfig $config, array $messages, array $tools): Generator
    {
        $started = false;

        try {
            foreach ($this->client($config)->stream($config, $messages, $tools) as $chunk) {
                $started = true;

                yield $chunk;
            }
        } catch (LlmException $e) {
            // Half an answer cannot be finished by another model: only switch before the first chunk.
            if ($started || $config->fallback === null) {
                throw $e;
            }

            yield from $this->stream($config->fallback, $messages, $tools);
        }
    }

    protected function client(LlmConfig $config): LlmClient
    {
        if ($this->resolve) {
            return ($this->resolve)($config);
        }

        if ($config->provider === LlmConfig::PROVIDER_ANTHROPIC) {
            if ($this->anthropic === null && ! class_exists(Client::class)) {
                throw new LlmException('The anthropic provider needs the anthropic-ai/sdk package: composer require anthropic-ai/sdk');
            }

            return $this->anthropic ??= new AnthropicClient;
        }

        return $this->openAi ??= new OpenAiCompatibleClient;
    }
}
