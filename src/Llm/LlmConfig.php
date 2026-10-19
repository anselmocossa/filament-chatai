<?php

namespace Filament\ChatAi\Llm;

use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\Budget;

final readonly class LlmConfig
{
    public const PROVIDER_OPENAI = 'openai';

    public const PROVIDER_ANTHROPIC = 'anthropic';

    /** Effort levels each kind of API accepts. */
    public const EFFORTS = [
        self::PROVIDER_ANTHROPIC => ['low', 'medium', 'high', 'xhigh', 'max'],
        self::PROVIDER_OPENAI => ['low', 'medium', 'high'],
    ];

    /**
     * @return array<string, string>
     */
    public static function effortOptions(?string $provider): array
    {
        $levels = self::EFFORTS[$provider] ?? [];

        return array_combine($levels, $levels);
    }

    /**
     * @param  array<int, string>  $fallbackModels
     * @param  string  $provider  openai (any OpenAI-compatible endpoint: OpenAI, OpenRouter, Ollama) or anthropic
     */
    public function __construct(
        public string $baseUrl,
        public ?string $apiKey,
        public string $model,
        public array $fallbackModels = [],
        public int $timeout = 60,
        public string $provider = self::PROVIDER_OPENAI,
        public ?string $effort = null,
        public int $maxTokens = 16000,
        public ?LlmConfig $fallback = null,
        public ?int $outputTokens = null, // explicit answer-length cap (sent to OpenAI-compatible APIs only when set)
        public ?float $temperature = null,
    ) {}

    /**
     * From config/chatai.php plus what was saved on the settings page.
     */
    public static function fromConfig(): self
    {
        ChatAiSettings::apply();

        // Providers over their spending limit are skipped (see Budget::allExceeded for when all are).
        $chain = AiModel::chain()->reject(fn (AiModel $m): bool => Budget::exceeded($m->providerConnection));

        if ($chain->isNotEmpty()) {
            return self::fromModels($chain->values()->all());
        }

        $provider = (string) config('chatai.provider', self::PROVIDER_OPENAI);
        $fallback = config('chatai.fallback_provider');

        return self::forProvider($provider, in_array($fallback, [self::PROVIDER_OPENAI, self::PROVIDER_ANTHROPIC], true) && $fallback !== $provider
            ? self::forProvider($fallback)
            : null);
    }

    /**
     * From the models list, grouped by provider connection in order of first
     * appearance: the first group answers, each next group is the fallback of
     * the one before (any number of providers).
     *
     * @param  array<int, AiModel>  $models
     */
    public static function fromModels(array $models): self
    {
        $groups = [];

        foreach ($models as $model) {
            // A group shares one address, key and effort; a different effort starts a new step.
            $key = ($model->connection_id ? 'c'.$model->connection_id : 'p'.$model->provider).'|'.$model->effort;
            $groups[$key] ??= ['connection' => $model->providerConnection, 'provider' => $model->providerConnection?->driver ?? $model->provider, 'effort' => $model->effort, 'models' => []];
            $groups[$key]['models'][] = $model->model;
        }

        $config = null;

        foreach (array_reverse($groups) as $group) {
            $base = self::forProvider($group['provider'], $config);
            $connection = $group['connection'];

            $base = new self(
                filled($connection?->base_url) ? rtrim($connection->base_url, '/') : $base->baseUrl,
                filled($connection?->api_key) ? $connection->api_key : $base->apiKey, // empty = key from .env
                $base->model, [], $base->timeout, $base->provider,
                $group['effort'] ?: null, // per model; null = the model's default
                $base->maxTokens, $config,
            );

            $config = $base->withModels($group['models'][0], array_slice($group['models'], 1));
        }

        return $config;
    }

    /**
     * The same model with test options (tool editor): effort, answer length, temperature.
     */
    public function withOptions(?string $effort, ?int $outputTokens, ?float $temperature): self
    {
        return new self(
            $this->baseUrl, $this->apiKey, $this->model, $this->fallbackModels, $this->timeout, $this->provider,
            $effort ?? $this->effort, $outputTokens ?? $this->maxTokens, $this->fallback, $outputTokens, $temperature,
        );
    }

    /**
     * @param  array<int, string>  $fallbackModels
     */
    public function withModels(string $model, array $fallbackModels = []): self
    {
        return new self($this->baseUrl, $this->apiKey, $model, $fallbackModels, $this->timeout, $this->provider, $this->effort, $this->maxTokens, $this->fallback, $this->outputTokens, $this->temperature);
    }

    public static function forProvider(string $provider, ?self $fallback = null): self
    {
        $anthropic = $provider === self::PROVIDER_ANTHROPIC;

        return new self(
            baseUrl: rtrim((string) ($anthropic ? config('chatai.anthropic.base_url') : config('chatai.base_url')), '/'),
            apiKey: $anthropic ? config('chatai.anthropic.api_key') : config('chatai.api_key'),
            model: (string) ($anthropic ? config('chatai.anthropic.model') : config('chatai.model')),
            fallbackModels: (array) ($anthropic ? config('chatai.anthropic.fallback_models', []) : config('chatai.fallback_models', [])),
            timeout: (int) config('chatai.timeout', 60),
            provider: $provider,
            effort: $anthropic ? config('chatai.anthropic.effort') : null,
            maxTokens: (int) config('chatai.max_tokens', 16000),
            fallback: $fallback,
        );
    }

    /**
     * @return array<int, string>
     */
    public function models(): array
    {
        return array_values(array_unique([$this->model, ...$this->fallbackModels]));
    }
}
