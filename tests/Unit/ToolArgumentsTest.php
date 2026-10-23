<?php

use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\ToolResult;

function profileTestTool(Closure $handler): ChatAiTool
{
    return new ChatAiTool('profile-search', 'Search profiles', ['type' => 'object', 'properties' => [
        'term' => ['type' => 'string', 'minLength' => 1],
        'by' => ['type' => 'string', 'enum' => ['name', 'role', 'education']],
        'location' => ['type' => 'string'],
        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
        'filters' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['enabled' => ['type' => 'boolean']], 'required' => ['enabled']]],
    ], 'required' => ['term']], $handler, readOnly: true);
}

it('rejects an invalid enum before a tool can execute and gives the model its contract', function () {
    $ran = false;
    $result = profileTestTool(function () use (&$ran) {
        $ran = true;

        return ToolResult::data([]);
    })->call(['term' => 'SAP', 'by' => 'location']);
    expect($ran)->toBeFalse()->and($result->isError())->toBeTrue()
        ->and($result->error)->toContain('by')->toContain('role');
});

it('rejects missing required values wrong types nested arguments and limits', function () {
    $ran = 0;
    $tool = profileTestTool(function () use (&$ran) {
        $ran++;

        return ToolResult::data([]);
    });
    foreach ([[], ['term' => ''], ['term' => 'SAP', 'limit' => '4'], ['term' => 'SAP', 'limit' => 5], ['term' => 'SAP', 'filters' => [['enabled' => 'yes']]]] as $args) {
        expect($tool->call($args)->isError())->toBeTrue();
    }
    expect($ran)->toBe(0);
});

it('preserves valid arguments and permits additional properties unless the schema closes them', function () {
    $args = ['term' => 'SAP', 'by' => 'role', 'location' => 'Maputo', 'limit' => 4, 'extra' => 'kept'];
    expect(profileTestTool(fn ($received) => ToolResult::data($received))->call($args)->data)->toBe($args);
    $tool = new ChatAiTool('empty', 'Empty object', ['type' => 'object', 'properties' => [], 'additionalProperties' => false], fn ($args) => ToolResult::data($args));
    expect($tool->call([])->isError())->toBeFalse()->and($tool->call(['unknown' => 1])->isError())->toBeTrue();
});

it('validates nullable schemas and empty nested objects without coercing the handler input', function () {
    $tool = new ChatAiTool('options', 'Options', ['type' => 'object', 'properties' => [
        'options' => ['type' => 'object', 'properties' => []], 'note' => ['type' => ['string', 'null']],
    ]], fn ($args) => ToolResult::data($args));
    expect($tool->call(['options' => [], 'note' => null])->data)->toBe(['options' => [], 'note' => null]);
});

it('refuses unresolved remote schema references without executing the tool', function () {
    $ran = false;
    $tool = new ChatAiTool('remote', 'Remote schema', ['$ref' => 'https://schema.invalid/not-registered'], function () use (&$ran) {
        $ran = true;

        return ToolResult::data([]);
    });
    expect($tool->call([])->isError())->toBeTrue()->and($ran)->toBeFalse();
});
