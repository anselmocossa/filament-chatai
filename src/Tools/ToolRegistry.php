<?php

namespace Filament\ChatAi\Tools;

use Closure;
use Filament\ChatAi\Contracts\ToolProvider;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\Skill;
use Filament\ChatAi\Models\ToolOverride;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Support\ChatAiPermissions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The tools the model gets: code tools plus tools made on screen, with the
 * admin's adjustments (on/off, wording, profiles) and the skills tool.
 */
class ToolRegistry implements ToolProvider
{
    /**
     * @param  (Closure(Authenticatable): array<int, string>)|null  $userRoles
     */
    public function __construct(
        protected ToolProvider $base,
        protected ?Closure $userRoles = null,
    ) {}

    public function tools(Authenticatable $user, ChatAiContext $context): array
    {
        ChatAiSettings::apply();

        $overrides = self::table('chatai_tool_overrides') ? ToolOverride::query()->get()->keyBy('tool_name') : collect();
        $roles = $this->roles($user);
        $guarded = ChatAiPermissions::enabled();
        $actions = (bool) config('chatai.actions.enabled', true);
        $tools = [];

        foreach ([...$this->catalog($user, $context), ...$this->customTools()] as $tool) {
            /** @var ToolOverride|null $override */
            $override = $overrides->get($tool->name);

            if (! $tool->readOnly && ! $actions) {
                continue;
            }

            if ($override && (! $override->enabled || ($override->roles && ! array_intersect($override->roles, $roles)))) {
                continue;
            }

            // Shield: the tool's own permission, or the one chosen for it.
            if ($guarded && ! ChatAiPermissions::allows($user, $override?->permission ?: ChatAiPermissions::forTool($tool->name))) {
                continue;
            }

            $tools[$tool->name] = $override && ($override->title || $override->description)
                ? new ChatAiTool($tool->name, $override->description ?: $tool->description, $tool->inputSchema, $tool->handler, $tool->readOnly, $override->title ?: $tool->title)
                : $tool;
        }

        if (($skills = self::skills())->isNotEmpty()) {
            $tools[SkillTool::NAME] = SkillTool::make($skills);
        }

        return array_values($tools);
    }

    /**
     * Code tools as they are, before any adjustment (for the admin list).
     *
     * @return array<int, ChatAiTool>
     */
    public function catalog(Authenticatable $user, ChatAiContext $context): array
    {
        $tools = $this->base->tools($user, $context);

        // So Shield can list their permissions even for providers that filter per user.
        ChatAiPermissions::remember(array_map(fn (ChatAiTool $tool): string => $tool->name, $tools));

        return $tools;
    }

    /**
     * @return array<int, ChatAiTool>
     */
    protected function customTools(): array
    {
        if (! self::table('chatai_custom_tools')) {
            return [];
        }

        return CustomTool::query()->where('enabled', true)->get()
            ->map(fn (CustomTool $tool): ?ChatAiTool => match ($tool->type) {
                CustomTool::TYPE_RESOURCE => ResourceQueryTool::make($tool),
                CustomTool::TYPE_HTTP => HttpApiTool::make($tool),
                CustomTool::TYPE_METRICS => MetricsTool::make($tool),
                CustomTool::TYPE_CREATE, CustomTool::TYPE_UPDATE => RecordTool::make($tool),
                default => null,
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Skill>
     */
    public static function skills(): Collection
    {
        return self::table('chatai_skills') ? Skill::query()->where('enabled', true)->orderBy('title')->get() : collect();
    }

    /**
     * @return array<int, string>
     */
    protected function roles(Authenticatable $user): array
    {
        if ($this->userRoles) {
            return (array) ($this->userRoles)($user);
        }

        // spatie/laravel-permission, when present.
        return method_exists($user, 'getRoleNames') ? $user->getRoleNames()->all() : [];
    }

    protected static function table(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
