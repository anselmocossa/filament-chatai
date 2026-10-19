<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Models\Skill;
use Illuminate\Support\Collection;

/**
 * Skills are saved instructions for a kind of task. The model sees their
 * names and "when to use" in the system prompt, and loads one with this tool.
 */
final class SkillTool
{
    public const NAME = 'use_skill';

    /**
     * @param  Collection<int, Skill>  $skills
     */
    public static function make(Collection $skills): ChatAiTool
    {
        $byName = $skills->keyBy('name');

        return new ChatAiTool(
            name: self::NAME,
            description: 'Load the instructions of a skill before doing a task it covers. Skills are listed in the system prompt.',
            inputSchema: [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string', 'enum' => $byName->keys()->values()->all()]],
                'required' => ['name'],
            ],
            handler: function (array $args) use ($byName): ToolResult {
                /** @var Skill|null $skill */
                $skill = $byName->get((string) ($args['name'] ?? ''));

                if (! $skill) {
                    return ToolResult::error('Unknown skill.');
                }

                $tools = array_filter((array) $skill->tools);

                return ToolResult::text(trim($skill->instructions."\n\n".($tools ? 'Tools for this task: '.implode(', ', $tools).'.' : '')));
            },
            readOnly: true,
            title: 'Skill',
        );
    }
}
