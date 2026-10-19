<?php

namespace Filament\ChatAi\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\ChatAi\Http\Controllers\PlaygroundController;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Models\Usage;
use Filament\ChatAi\Pages\Concerns\ChecksChatAiManager;
use Filament\ChatAi\Support\Pricing;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every answered question, like a request log: who asked what, which tools
 * ran, tokens (cache included), time and cost.
 */
class ChatAiLogsPage extends Page implements HasTable
{
    use ChecksChatAiManager;
    use InteractsWithTable;

    protected static ?string $slug = 'chatai/logs';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 1005;

    public static function getNavigationLabel(): string
    {
        return __('chatai::chatai.logs.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('chatai::chatai.logs.title');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $t = fn (string $key): string => __('chatai::chatai.logs.'.$key);
        $currency = Pricing::currency();
        $tokens = fn (Usage $u): int => $u->tokens_in + $u->cache_read_tokens + $u->cache_write_tokens + $u->tokens_out;

        return $table
            ->query(fn (): Builder => Usage::query()
                ->where('kind', Usage::KIND_CHAT)
                ->whereIn('panel_id', [$panel = Filament::getCurrentPanel()->getId(), $panel.PlaygroundController::PANEL_SUFFIX])
                ->with(['message', 'user']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label($t('when'))->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('user_id')->label($t('user'))->formatStateUsing(fn (Usage $record): string => (string) ($record->user?->name ?? $record->user?->email ?? '#'.$record->user_id)),
                TextColumn::make('question')->label($t('question'))->state(fn (Usage $record): ?string => self::question($record))->limit(70)->wrap(),
                TextColumn::make('tools')->label($t('tools'))->badge()->placeholder('—')
                    ->description(fn (Usage $record): ?string => str_ends_with((string) $record->panel_id, PlaygroundController::PANEL_SUFFIX) ? $t('test') : null),
                TextColumn::make('model')->label($t('model'))->toggleable(),
                TextColumn::make('tokens')->label($t('tokens'))->state($tokens)->numeric()->alignEnd(),
                TextColumn::make('latency_ms')->label($t('latency'))->formatStateUsing(fn (?int $state): string => $state ? number_format($state / 1000, 1).' s' : '—')->alignEnd()->sortable(),
                TextColumn::make('cost')->label($t('cost'))->formatStateUsing(fn ($state): string => $state === null ? '—' : number_format((float) $state, 4).' '.$currency)->alignEnd()->sortable(),
            ])
            ->filters([
                SelectFilter::make('model')->label($t('model'))->options(fn (): array => Usage::query()->where('kind', Usage::KIND_CHAT)->distinct()->pluck('model', 'model')->filter()->all()),
            ])
            ->recordActions([
                Action::make('details')
                    ->label($t('details'))
                    ->icon('heroicon-m-eye')
                    ->slideOver()
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('chatai::chatai.close'))
                    ->schema(fn (Usage $record): array => [
                        Section::make($t('question'))->schema([TextEntry::make('q')->hiddenLabel()->state(self::question($record) ?? '—')]),
                        Section::make($t('answer'))->schema([TextEntry::make('a')->hiddenLabel()->state($record->message?->content ?? '—')->markdown()]),
                        Grid::make(3)->schema([
                            TextEntry::make('model')->label($t('model'))->state($record->model),
                            TextEntry::make('latency')->label($t('latency'))->state($record->latency_ms ? number_format($record->latency_ms / 1000, 2).' s' : '—'),
                            TextEntry::make('cost')->label($t('cost'))->state($record->cost === null ? __('chatai::chatai.usage.no_price') : number_format((float) $record->cost, 6).' '.$currency),
                            TextEntry::make('in')->label($t('tokens_in'))->state(number_format($record->tokens_in)),
                            TextEntry::make('cache_read')->label($t('cache_read'))->state(number_format($record->cache_read_tokens)),
                            TextEntry::make('cache_write')->label($t('cache_write'))->state(number_format($record->cache_write_tokens)),
                            TextEntry::make('out')->label($t('tokens_out'))->state(number_format($record->tokens_out)),
                            TextEntry::make('tools')->label($t('tools'))->state($record->tools ?: ['—'])->badge(),
                        ]),
                    ]),
            ])
            ->emptyStateHeading(__('chatai::chatai.usage.empty'));
    }

    /**
     * The user question this answer replied to.
     */
    protected static function question(Usage $usage): ?string
    {
        if (! $usage->message) {
            return null;
        }

        return Message::query()
            ->where('conversation_id', $usage->message->conversation_id)
            ->where('role', Message::ROLE_USER)
            // ULIDs sort by time to the millisecond; created_at only has seconds.
            ->where('id', '<', $usage->message->id)
            ->orderByDesc('id')
            ->value('content');
    }
}
