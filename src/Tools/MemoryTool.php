<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Models\Memory;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Ui\Catalog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Lets the assistant keep something the user wants remembered across
 * conversations ("amounts always in MZN", "I look after the Maputo store").
 */
final class MemoryTool
{
    public const NAME = 'remember';

    public static function available(): bool
    {
        return (bool) config('chatai.memory.enabled', true);
    }

    public static function make(Authenticatable $user, ChatAiContext $context): ChatAiTool
    {
        return new ChatAiTool(
            name: self::NAME,
            description: 'Remember something about this user for future conversations: when they ask you to remember it, or state a lasting preference or fact about their work. '
                .'Store only what the user actually said, once: one call per request, combining related points in one short sentence in their language (e.g. "Prefers amounts in MZN."). '
                .'Never add things they did not say. Never store passwords, salaries, health or ID numbers.',
            inputSchema: [
                'type' => 'object',
                'properties' => ['fact' => ['type' => 'string', 'description' => 'What to remember, one short sentence.']],
                'required' => ['fact'],
            ],
            handler: function (array $args) use ($user, $context): ToolResult {
                $fact = trim(mb_substr((string) ($args['fact'] ?? ''), 0, 500));

                if ($fact === '') {
                    return ToolResult::error('Nothing to remember.');
                }

                $existing = Memory::forUser($user, $context->tenantId, $context->panelId);

                if ((clone $existing)->count() >= Memory::MAX_PER_USER) {
                    return ToolResult::error('The memory is full; the user can delete old items in the chat menu → Memory.');
                }

                if (! (clone $existing)->where('content', $fact)->exists()) {
                    /** @var Model $user */
                    Memory::query()->create([
                        'user_type' => $user->getMorphClass(),
                        'user_id' => $user->getKey(),
                        'tenant_id' => $context->tenantId,
                        'panel_id' => $context->panelId,
                        'content' => $fact,
                    ]);
                }

                return ToolResult::data(['remembered' => $fact], ['type' => Catalog::MEMORY, 'text' => $fact]);
            },
            readOnly: true,
            title: 'Remember',
        );
    }
}
