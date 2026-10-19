<?php

use Filament\ChatAi\Llm\AnthropicClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Llm\LlmException;
use Filament\ChatAi\Llm\ProviderLlmClient;
use Filament\ChatAi\Llm\StreamChunk;

function anthropicConfig(array $fallbacks = []): LlmConfig
{
    return new LlmConfig(baseUrl: '', apiKey: 'sk-ant-secret', model: 'claude-opus-5-5', fallbackModels: $fallbacks, provider: 'anthropic');
}

function ev(array $data): object
{
    return json_decode(json_encode($data));
}

it('converts OpenAI-style history, tools and system prompt to the Messages API shape', function () {
    $sent = null;
    $client = new AnthropicClient(function (array $params) use (&$sent) {
        $sent = $params;

        return [];
    });

    iterator_to_array($client->stream(anthropicConfig(), [
        ['role' => 'system', 'content' => 'Be brief.'],
        ['role' => 'user', 'content' => 'Vendas?'],
        ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'sales', 'arguments' => '{"period":"month"}']],
            ['id' => 'toolu_2', 'type' => 'function', 'function' => ['name' => 'stock', 'arguments' => '{}']],
        ]],
        ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => '{"total":1}'],
        ['role' => 'tool', 'tool_call_id' => 'toolu_2', 'content' => '{"qty":2}'],
    ], [
        ['type' => 'function', 'function' => ['name' => 'sales', 'description' => 'Sales', 'parameters' => ['type' => 'object', 'properties' => []]]],
    ]), false);

    expect($sent['system'])->toBe('Be brief.')
        ->and($sent['model'])->toBe('claude-opus-5-5')
        ->and($sent['tools'][0])->toBe(['name' => 'sales', 'description' => 'Sales', 'inputSchema' => ['type' => 'object', 'properties' => []]])
        ->and($sent['messages'][1]['content'][0])->toMatchArray(['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'sales', 'input' => ['period' => 'month']])
        // Parallel tool results go back in ONE user message.
        ->and($sent['messages'][2]['role'])->toBe('user')
        ->and($sent['messages'][2]['content'])->toHaveCount(2)
        ->and($sent['messages'][2]['content'][1])->toBe(['type' => 'tool_result', 'toolUseID' => 'toolu_2', 'content' => '{"qty":2}'])
        ->and($sent['fallbacks'])->toBe('default')
        ->and($sent['cacheControl'])->toBe(['type' => 'ephemeral']);
});

it('streams text, assembles tool calls from input_json_delta and reports usage', function () {
    $client = new AnthropicClient(fn () => [
        ev(['type' => 'message_start', 'message' => ['usage' => ['inputTokens' => 50, 'outputTokens' => 1]]]),
        ev(['type' => 'content_block_start', 'index' => 0, 'contentBlock' => ['type' => 'text', 'text' => '']]),
        ev(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'A ver']]),
        ev(['type' => 'content_block_start', 'index' => 1, 'contentBlock' => ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'sales', 'input' => []]]),
        ev(['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partialJSON' => '{"peri']]),
        ev(['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partialJSON' => 'od":"month"}']]),
        ev(['type' => 'message_delta', 'delta' => ['stopReason' => 'tool_use'], 'usage' => ['outputTokens' => 30]]),
        ev(['type' => 'message_stop']),
    ]);

    $chunks = collect(iterator_to_array($client->stream(anthropicConfig(), [['role' => 'user', 'content' => 'x']], []), false));
    $call = $chunks->firstWhere('type', StreamChunk::TOOL_CALL);
    $done = $chunks->firstWhere('type', StreamChunk::DONE);

    expect($chunks->firstWhere('type', StreamChunk::TEXT)->text)->toBe('A ver')
        ->and($call->toolName)->toBe('sales')
        ->and($call->toolCallId)->toBe('toolu_9')
        ->and($call->arguments)->toBe(['period' => 'month'])
        ->and($done->tokensIn)->toBe(50)
        ->and($done->tokensOut)->toBe(30);
});

it('falls back to the next model when the first is rate limited, and hides the key on failure', function () {
    $models = [];
    $client = new AnthropicClient(function (array $params) use (&$models) {
        $models[] = $params['model'];

        throw new LlmException('rate limited sk-ant-secret', 429);
    });

    expect(fn () => iterator_to_array($client->stream(anthropicConfig(['claude-sonnet-5-5']), [['role' => 'user', 'content' => 'x']], []), false))
        ->toThrow(fn (LlmException $e) => expect($e->getMessage())->not->toContain('sk-ant-secret'));

    expect($models)->toBe(['claude-opus-5-5', 'claude-sonnet-5-5']);
});

it('routes to the client that matches the configured provider', function () {
    $anthropic = new AnthropicClient(fn () => [ev(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'claude']])]);
    $router = new ProviderLlmClient(anthropic: $anthropic);

    $chunks = iterator_to_array($router->stream(anthropicConfig(), [['role' => 'user', 'content' => 'x']], []), false);

    expect($chunks[0]->text)->toBe('claude');
});

it('never sends temperature to Claude', function () {
    $params = (new AnthropicClient)->params([['role' => 'user', 'content' => 'x']], [], new LlmConfig('', 'k', 'claude-opus-5-5', provider: 'anthropic', temperature: 0.3));

    expect($params)->not->toHaveKey('temperature');
});
