<?php

use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Llm\LlmException;
use Filament\ChatAi\Llm\OpenAiCompatibleClient;
use Filament\ChatAi\Llm\StreamChunk;
use Illuminate\Support\Facades\Http;

function sse(array $events): string
{
    return collect($events)->map(fn ($e) => 'data: '.(is_string($e) ? $e : json_encode($e))."\n\n")->implode('');
}

function llmConfig(array $fallbacks = []): LlmConfig
{
    return new LlmConfig(baseUrl: 'https://llm.test/v1', apiKey: 'sk-secret-123', model: 'main-model', fallbackModels: $fallbacks);
}

function collectChunks(iterable $stream): array
{
    return is_array($stream) ? $stream : iterator_to_array($stream, false);
}

it('streams text deltas in order and reports usage', function () {
    Http::fake(['llm.test/*' => Http::response(sse([
        ['choices' => [['delta' => ['content' => 'Olá']]]],
        ['choices' => [['delta' => ['content' => ', Boss']]]],
        ['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 3]],
        '[DONE]',
    ]), 200, ['Content-Type' => 'text/event-stream'])]);

    $chunks = collectChunks((new OpenAiCompatibleClient)->stream(llmConfig(), [['role' => 'user', 'content' => 'oi']], []));

    $text = collect($chunks)->where('type', StreamChunk::TEXT)->pluck('text')->implode('');
    $done = collect($chunks)->firstWhere('type', StreamChunk::DONE);

    expect($text)->toBe('Olá, Boss')
        ->and($done->tokensIn)->toBe(11)
        ->and($done->tokensOut)->toBe(3)
        ->and($done->model)->toBe('main-model');
});

it('reassembles tool calls split across deltas', function () {
    Http::fake(['llm.test/*' => Http::response(sse([
        ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'sales-stats', 'arguments' => '']]]]]]],
        ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"peri']]]]]]],
        ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'od":"month"}']]]]]]],
        ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        '[DONE]',
    ]))]);

    $chunks = collectChunks((new OpenAiCompatibleClient)->stream(llmConfig(), [], []));
    $call = collect($chunks)->firstWhere('type', StreamChunk::TOOL_CALL);

    expect($call->toolCallId)->toBe('call_1')
        ->and($call->toolName)->toBe('sales-stats')
        ->and($call->arguments)->toBe(['period' => 'month']);
});

it('falls back to the next model on 429 before streaming', function () {
    Http::fakeSequence('llm.test/*')
        ->push('rate limited', 429)
        ->push(sse([['choices' => [['delta' => ['content' => 'ok']]]], '[DONE]']), 200);

    $chunks = collectChunks((new OpenAiCompatibleClient)->stream(llmConfig(['backup-model']), [], []));

    expect(collect($chunks)->firstWhere('type', StreamChunk::DONE)->model)->toBe('backup-model');

    Http::assertSent(fn ($request) => $request['model'] === 'backup-model');
});

it('sends the key only in the Authorization header and never leaks it in errors', function () {
    Http::fake(['llm.test/*' => Http::response('{"error":"bad key sk-secret-123"}', 401)]);

    $client = new OpenAiCompatibleClient;

    try {
        collectChunks($client->stream(llmConfig(), [], []));
        $this->fail('expected an exception');
    } catch (LlmException $e) {
        expect($e->getMessage())->not->toContain('sk-secret-123');
    }

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-secret-123')
        && ! str_contains($request->body(), 'sk-secret-123'));
});
