<?php

namespace Filament\ChatAi\Tools;

use Filament\ChatAi\Tools\NoCode\ResourceInspector;
use Filament\ChatAi\Ui\Catalog;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Illuminate\Support\Str;
use Throwable;

/**
 * Takes the user to a place in the panel: a page of the menu, a resource's
 * list or create page, or one record (view / edit). Only destinations of this
 * panel the user may open; the browser does the navigation when the answer ends.
 */
final class NavigateTool
{
    public const NAME = 'navigate';

    public const ACTIONS = ['list', 'create', 'view', 'edit'];

    public static function available(): bool
    {
        return (bool) config('chatai.screen.navigate', true) && Filament::getCurrentPanel() !== null;
    }

    public static function make(): ChatAiTool
    {
        $resources = self::resources();
        $pages = self::pages();

        return new ChatAiTool(
            name: self::NAME,
            description: 'Open a place of this application for the user: a menu page, or a resource\'s list, create page, or one record (view or edit). '
                .'Use it when they ask to go to, open or show a screen, or to start creating something. '
                .'Resources: '.collect($resources)->map(fn ($r, $key) => "{$key} ({$r['label']})")->implode(', ').'. '
                .($pages ? 'Menu pages: '.implode(', ', array_keys($pages)).'.' : ''),
            inputSchema: [
                'type' => 'object',
                'properties' => array_filter([
                    'resource' => $resources ? ['type' => 'string', 'enum' => array_keys($resources)] : null,
                    'action' => ['type' => 'string', 'enum' => self::ACTIONS, 'description' => 'list (default), create, view or edit (view/edit need a record).'],
                    'record' => ['type' => 'string', 'description' => 'For view/edit: the record id, or its name/number/title.'],
                    'page' => $pages ? ['type' => 'string', 'enum' => array_keys($pages), 'description' => 'A menu page instead of a resource.'] : null,
                ]),
            ],
            handler: fn (array $args): ToolResult => self::go($args, $resources, $pages),
            readOnly: true,
            title: 'Open',
        );
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, array{class: class-string<resource>, label: string}>  $resources
     * @param  array<string, string>  $pages
     */
    protected static function go(array $args, array $resources, array $pages): ToolResult
    {
        if (filled($args['page'] ?? null) && isset($pages[$args['page']])) {
            return self::open($pages[$args['page']], (string) $args['page']);
        }

        $entry = $resources[$args['resource'] ?? ''] ?? null;

        if (! $entry) {
            return ToolResult::error('Unknown destination. Use one of the resources or menu pages listed in the tool description.');
        }

        /** @var class-string<resource> $resource */
        $resource = $entry['class'];
        $action = in_array($args['action'] ?? null, self::ACTIONS, true) ? $args['action'] : 'list';
        $search = trim((string) ($args['record'] ?? ''));

        if ($action === 'create') {
            return $resource::hasPage('create') && $resource::canCreate()
                ? self::open($resource::getUrl('create'), 'New '.$resource::getModelLabel())
                : ToolResult::error('The user cannot create '.$resource::getPluralModelLabel().' here.');
        }

        if ($search === '' || $action === 'list') {
            return $resource::canViewAny()
                ? self::open($resource::getUrl('index'), ucfirst((string) $resource::getPluralModelLabel()))
                : ToolResult::error('The user cannot see '.$resource::getPluralModelLabel().'.');
        }

        $model = ResourceInspector::model($resource);
        $title = $resource::getRecordTitleAttribute() ?: ResourceInspector::titleColumn($model);
        $query = $resource::getEloquentQuery();
        $matches = ctype_digit($search) ? (clone $query)->whereKey($search)->limit(2)->get() : collect();

        if ($matches->isEmpty() && in_array($title, ResourceInspector::columns($model), true)) {
            $matches = (clone $query)->where($title, $search)->limit(6)->get();
            $matches = $matches->isEmpty() ? (clone $query)->where($title, 'like', '%'.addcslashes($search, '%_').'%')->limit(6)->get() : $matches;
        }

        if ($matches->count() !== 1) {
            return ToolResult::error($matches->isEmpty()
                ? "No {$resource::getModelLabel()} matches \"{$search}\"."
                : 'More than one matches: '.$matches->pluck($title)->implode(', ').'. Ask which one.');
        }

        $record = $matches->first();
        $label = (string) ($record->getAttribute($title) ?? $record->getKey());

        foreach ($action === 'edit' ? ['edit', 'view'] : ['view', 'edit'] as $page) {
            $allowed = $page === 'edit' ? $resource::canEdit($record) : $resource::canView($record);

            if ($resource::hasPage($page) && $allowed) {
                return self::open($resource::getUrl($page, ['record' => $record]), $label);
            }
        }

        return ToolResult::error("The user cannot open this {$resource::getModelLabel()}.");
    }

    protected static function open(string $url, string $label): ToolResult
    {
        return ToolResult::data(
            ['opening' => $label, 'note' => 'The screen changes when your answer ends. Say in a few words where you are taking them.'],
            ['type' => Catalog::NAVIGATE, 'url' => $url, 'label' => $label],
        );
    }

    /**
     * @return array<string, array{class: class-string<resource>, label: string}>
     */
    protected static function resources(): array
    {
        return collect(Filament::getCurrentPanel()?->getResources() ?? [])
            ->mapWithKeys(fn (string $resource): array => [str_replace('/', '.', $resource::getSlug()) => ['class' => $resource, 'label' => ucfirst((string) $resource::getPluralModelLabel())]])
            ->all();
    }

    /**
     * Menu items that are not resources (dashboards, custom pages), by label.
     *
     * @return array<string, string>
     */
    protected static function pages(): array
    {
        try {
            $resourceUrls = collect(Filament::getCurrentPanel()->getResources())->map(fn ($r) => rescue(fn () => $r::getUrl('index'), null, false))->filter()->all();
            $items = collect(Filament::getCurrentPanel()->getNavigation())
                ->flatMap(fn ($group) => $group instanceof NavigationGroup ? $group->getItems() : [$group])
                ->filter(fn ($item) => $item instanceof NavigationItem && $item->isVisible());

            return $items->reject(fn (NavigationItem $i) => in_array($i->getUrl(), $resourceUrls, true))
                ->mapWithKeys(fn (NavigationItem $i): array => [Str::limit(strip_tags((string) $i->getLabel()), 60, '') => (string) $i->getUrl()])
                ->filter()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
