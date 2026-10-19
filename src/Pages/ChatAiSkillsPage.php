<?php

namespace Filament\ChatAi\Pages;

use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\Skill;
use Filament\ChatAi\Pages\Concerns\ChecksChatAiManager;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rule;

/**
 * Saved instructions for a kind of task ("for the month report, use these
 * tools in this order"), loaded by the model when the task comes up.
 */
class ChatAiSkillsPage extends Page implements HasTable
{
    use ChecksChatAiManager;
    use InteractsWithTable;

    protected static ?string $slug = 'chatai/skills';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?int $navigationSort = 1004;

    public static function getNavigationLabel(): string
    {
        return __('chatai::chatai.skills.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('chatai::chatai.skills.title');
    }

    public function getSubheading(): ?string
    {
        return __('chatai::chatai.skills.subheading');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $t = fn (string $key): string => __('chatai::chatai.skills.'.$key);

        return $table
            ->query(Skill::query())
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->label($t('skill'))->weight('medium')->description(fn (Skill $record): string => $record->name)->searchable(),
                TextColumn::make('when_to_use')->label($t('when'))->wrap()->lineClamp(2),
                TextColumn::make('tools')->label($t('tools'))->badge()->placeholder('—'),
                IconColumn::make('enabled')->label($t('enabled'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()->slideOver()->schema(fn (?Skill $record): array => $this->form($record)),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make()->label($t('create'))->slideOver()->model(Skill::class)
                    ->fillForm(['enabled' => true])
                    ->schema(fn (): array => $this->form(null)),
            ])
            ->emptyStateHeading($t('empty'))
            ->emptyStateDescription($t('empty_help'));
    }

    /**
     * @return array<int, mixed>
     */
    protected function form(?Skill $editing): array
    {
        $t = fn (string $key): string => __('chatai::chatai.skills.'.$key);

        $tools = collect(ChatAiPlugin::get()->resolveTools()->tools(auth()->user(), new ChatAiContext(Filament::getCurrentPanel()->getId())))
            ->mapWithKeys(fn ($tool): array => [$tool->name => $tool->label()." ({$tool->name})"])
            ->except('use_skill')
            ->all();

        return [
            Grid::make(2)->schema([
                TextInput::make('name')->label(__('chatai::chatai.tools.name'))->required()->maxLength(64)
                    ->helperText(__('chatai::chatai.tools.name_help'))->regex('/^[a-z0-9][a-z0-9_-]*$/')
                    ->rule(Rule::unique('chatai_skills', 'name')->ignore($editing?->id)),
                TextInput::make('title')->label(__('chatai::chatai.tools.title_field'))->required()->maxLength(120),
            ]),
            Textarea::make('when_to_use')->label($t('when'))->required()->rows(2)->helperText($t('when_help')),
            Textarea::make('instructions')->label($t('instructions'))->required()->rows(10)->helperText($t('instructions_help')),
            Select::make('tools')->label($t('tools'))->multiple()->options($tools)->helperText($t('tools_help'))->native(false),
            Toggle::make('enabled')->label($t('enabled')),
        ];
    }
}
