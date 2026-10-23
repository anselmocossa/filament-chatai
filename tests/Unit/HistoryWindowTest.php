<?php

use Filament\ChatAi\Agent\HistoryWindow;

it('leaves a short transcript unchanged', function () {
    $messages = [
        ['role' => 'user', 'content' => 'Olá'],
        ['role' => 'assistant', 'content' => 'Olá.'],
    ];

    expect(HistoryWindow::fit($messages, 24_000, 8_000))->toBe($messages);
});

it('turns older turns into memory and keeps the recent question verbatim', function () {
    $old = str_repeat('ANTIGO-UNICO-', 700);
    $recent = str_repeat('RECENTE-', 300);
    $messages = [
        ['role' => 'user', 'content' => $old],
        ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'call-grupo',
            'type' => 'function',
            'function' => ['name' => 'criar-grupo', 'arguments' => '{"nome":"Grupo Norte"}'],
        ]]],
        ['role' => 'tool', 'tool_call_id' => 'call-grupo', 'content' => '{"status":"awaiting_confirmation","nome":"Grupo Norte"}'],
        ['role' => 'user', 'content' => $recent],
        ['role' => 'user', 'content' => 'AGORA-RESPONDE'],
    ];

    $fitted = HistoryWindow::fit($messages, 3_000, 2_500);
    $encoded = json_encode($fitted, JSON_UNESCAPED_UNICODE);

    expect($fitted[0]['role'])->toBe('system')
        ->and($fitted[0]['content'])->toContain('Earlier turns were compacted')
        ->and($fitted[0]['content'])->toContain('Grupo Norte')
        ->and($fitted[0]['content'])->toContain('waiting for confirmation')
        ->and($encoded)->toContain($recent)
        ->and($encoded)->toContain('AGORA-RESPONDE')
        ->and($encoded)->not->toContain($old)
        ->and(array_column($fitted, 'role'))->not->toContain('tool');
});

it('keeps a tool call together with its result when the cut falls inside the group', function () {
    $messages = [
        ['role' => 'user', 'content' => str_repeat('A', 5_000)],
        ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'call-1',
            'type' => 'function',
            'function' => ['name' => 'search', 'arguments' => '{}'],
        ]]],
        ['role' => 'tool', 'tool_call_id' => 'call-1', 'content' => '{"count":2}'],
        ['role' => 'user', 'content' => 'continua'],
    ];

    $fitted = HistoryWindow::fit($messages, 400, 120);

    expect($fitted[0]['role'])->toBe('system')
        ->and(array_column(array_slice($fitted, 1), 'role'))->toBe(['assistant', 'tool', 'user'])
        ->and($fitted[2]['tool_call_id'])->toBe('call-1');
});

it('does nothing when the budget is disabled', function () {
    $messages = [
        ['role' => 'user', 'content' => str_repeat('A', 5_000)],
        ['role' => 'assistant', 'content' => 'ok'],
    ];

    expect(HistoryWindow::fit($messages, 0, 100))->toBe($messages);
});
