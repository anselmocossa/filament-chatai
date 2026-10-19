<?php

namespace Filament\ChatAi\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\ToolOverride;
use Filament\ChatAi\Pages\Concerns\ChecksChatAiManager;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Every tool the assistant can use — from code or made here — and what the
 * model is told about it.
 */
class ChatAiToolsPage extends Page implements HasTable
{
    use ChecksChatAiManager;
    use InteractsWithTable;

    protected static ?string $slug = 'chatai/tools';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 1003;

    public static function getNavigationLabel(): string
    {
        return __('chatai::chatai.tools.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('chatai::chatai.tools.title');
    }

    public function getSubheading(): ?string
    {
        return __('chatai::chatai.tools.subheading');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $t = fn (string $key): string => __('chatai::chatai.tools.'.$key);

        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('title')->label($t('tool'))->weight('medium')->description(fn (array $record): string => $record['name']),
                TextColumn::make('description')->label($t('description'))->wrap()->lineClamp(2),
                TextColumn::make('source')->label($t('source'))->badge()
                    ->formatStateUsing(fn (string $state): string => $t('sources.'.$state))
                    ->color(fn (string $state): string => $state === 'code' ? 'gray' : 'primary'),
                TextColumn::make('roles')->label($t('roles'))->badge()->placeholder($t('everyone')),
                IconColumn::make('enabled')->label($t('enabled'))->boolean(),
                IconColumn::make('adjusted')->label($t('adjusted'))->boolean()->trueIcon('heroicon-m-pencil')->falseIcon('heroicon-m-minus')->falseColor('gray'),
            ])
            ->recordActions([
                Action::make('adjust')
                    ->label($t('adjust'))
                    ->icon('heroicon-m-adjustments-horizontal')
                    ->visible(fn (array $record): bool => $record['source'] === 'code')
                    ->url(fn (array $record): string => ChatAiToolEditorPage::getUrl(['tool' => $record['name'], 'mode' => ChatAiToolEditorPage::MODE_CODE])),
                Action::make('edit')
                    ->label($t('edit'))
                    ->icon('heroicon-m-pencil-square')
                    ->visible(fn (array $record): bool => $record['source'] !== 'code')
                    ->url(fn (array $record): string => ChatAiToolEditorPage::getUrl(['tool' => $record['name']])),
                Action::make('reset')
                    ->label($t('reset'))
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (array $record): bool => $record['adjusted'])
                    ->requiresConfirmation()
                    ->action(function (array $record): void {
                        ToolOverride::query()->where('tool_name', $record['name'])->delete();
                        $this->notify();
                    }),
                Action::make('delete')
                    ->label($t('delete'))
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->visible(fn (array $record): bool => $record['source'] !== 'code')
                    ->requiresConfirmation()
                    ->action(function (array $record): void {
                        CustomTool::query()->where('name', $record['name'])->delete();
                        ToolOverride::query()->where('tool_name', $record['name'])->delete();
                        $this->notify();
                    }),
            ])
            ->headerActions([
                Action::make('create')
                    ->label($t('create'))
                    ->icon('heroicon-m-plus')
                    ->url(fn (): string => ChatAiToolEditorPage::getUrl()),
            ])
            ->emptyStateHeading($t('empty'))
            ->paginated(false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function rows(): array
    {
        $plugin = ChatAiPlugin::get();
        $overrides = ToolOverride::query()->get()->keyBy('tool_name');
        $rows = [];

        $code = $plugin->resolveTools()->catalog(auth()->user(), new ChatAiContext(Filament::getCurrentPanel()->getId()));

        foreach ($code as $tool) {
            $rows[$tool->name] = ['name' => $tool->name, 'title' => $tool->label(), 'description' => $tool->description, 'source' => 'code', 'enabled' => true];
        }

        foreach (CustomTool::query()->orderBy('title')->get() as $tool) {
            $rows[$tool->name] = ['name' => $tool->name, 'title' => $tool->title, 'description' => $tool->description, 'source' => $tool->type, 'enabled' => $tool->enabled];
        }

        foreach ($rows as $name => $row) {
            /** @var ToolOverride|null $override */
            $override = $overrides->get($name);

            $rows[$name] = [
                ...$row,
                'key' => $name,
                'title' => $override?->title ?: $row['title'],
                'description' => $override?->description ?: $row['description'],
                'enabled' => $row['enabled'] && ($override?->enabled ?? true),
                'roles' => $override?->roles ?? [],
                'adjusted' => $override !== null,
                'original_description' => $row['description'],
            ];
        }

        return $rows;
    }

    protected function notify(): void
    {
        Notification::make()->success()->title(__('chatai::chatai.settings.saved'))->send();
    }
}
