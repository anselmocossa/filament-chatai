<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Support\ChatAiContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The tool as it stands in the editor, saved or not, so the admin can try it
 * in the test chat before saving.
 */
final class ToolDraft
{
    public const MODE_CODE = 'code';

    public const MODE_CUSTOM = 'custom';

    /**
     * @param  array<string, mixed>  $data  the editor's form state
     */
    public static function build(string $mode, ?string $name, array $data, ToolRegistry $registry, Authenticatable $user, ChatAiContext $context): ?ChatAiTool
    {
        return $mode === self::MODE_CODE
            ? self::code((string) $name, $data, $registry, $user, $context)
            : self::custom($name, $data);
    }

    /**
     * A code tool with the wording being edited.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function code(string $name, array $data, ToolRegistry $registry, Authenticatable $user, ChatAiContext $context): ?ChatAiTool
    {
        $tool = collect($registry->catalog($user, $context))->firstWhere('name', $name);

        if (! $tool instanceof ChatAiTool) {
            return null;
        }

        return new ChatAiTool(
            $tool->name,
            filled($data['description'] ?? null) ? (string) $data['description'] : $tool->description,
            $tool->inputSchema,
            $tool->handler,
            $tool->readOnly,
            filled($data['title'] ?? null) ? (string) $data['title'] : $tool->title,
        );
    }

    /**
     * A resource or API tool built from the form, never saved here. A blank
     * key field uses the stored key of the tool being edited.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function custom(?string $editing, array $data): ?ChatAiTool
    {
        $stored = $editing ? CustomTool::query()->where('name', $editing)->first() : null;
        $config = (array) ($data['config'] ?? []);

        if (blank($config['secret'] ?? null)) {
            $config['secret'] = $stored?->config['secret'] ?? null;
        }

        $config['params'] = array_values((array) ($config['params'] ?? []));

        $tool = new CustomTool([
            'name' => $stored?->name ?? (filled($data['name'] ?? null) ? (string) $data['name'] : 'draft-tool'),
            'title' => (string) ($data['title'] ?? '') ?: 'Draft',
            'description' => (string) ($data['description'] ?? '') ?: 'Tool being tested.',
            'type' => (string) ($data['type'] ?? ''),
            'config' => $config,
        ]);

        return match ($tool->type) {
            CustomTool::TYPE_RESOURCE => ResourceQueryTool::make($tool),
            CustomTool::TYPE_HTTP => HttpApiTool::make($tool),
            default => null,
        };
    }
}
