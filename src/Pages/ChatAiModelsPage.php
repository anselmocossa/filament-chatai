<?php

namespace Filament\ChatAi\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Llm\LlmException;
use Filament\ChatAi\Models\AiConnection;
use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Pages\Concerns\ChecksChatAiManager;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\Pricing;
use Filament\ChatAi\Support\ProviderSetup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The models the assistant may use, one per row. Order decides: the first
 * enabled model answers; the next ones take over if it fails.
 */
class ChatAiModelsPage extends Page implements HasTable
{
    use ChecksChatAiManager;
    use InteractsWithTable;

    protected static ?string $slug = 'chatai/models';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 1000;

    public function mount(): void
    {
        if (AiModel::listed()->isEmpty()) {
            $this->importFromConfig();
        }

        ProviderSetup::ensure();
    }

    public static function getNavigationLabel(): string
    {
        return __('chatai::chatai.models.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('chatai::chatai.models.title');
    }

    public function getSubheading(): ?string
    {
        return __('chatai::chatai.models.subheading');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $t = fn (string $key): string => __('chatai::chatai.models.'.$key);
        $currency = Pricing::currency();
        $price = fn ($state): string => $state === null ? '—' : rtrim(rtrim(number_format((float) $state, 4), '0'), '.').' '.$currency;
        $firstEnabled = fn (): ?int => AiModel::chain()->first()?->id;

        return $table
            ->query(AiModel::query())
            ->modelLabel($t('singular'))
            ->pluralModelLabel($t('title'))
            ->defaultSort('sort')
            ->reorderable('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('role')->label($t('role'))->badge()
                    ->state(fn (AiModel $record): string => ! $record->enabled ? $t('off') : ($record->id === $firstEnabled() ? $t('main') : $t('fallback')))
                    ->color(fn (string $state): string => match ($state) {
                        $t('main') => 'success',
                        $t('fallback') => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('model')->label($t('model'))->weight('medium')->searchable()->copyable(),
                TextColumn::make('providerConnection.name')->label($t('provider'))->badge()->color('gray')
                    ->placeholder(fn (AiModel $record): string => __('chatai::chatai.settings.provider_'.$record->provider)),
                TextColumn::make('effort')->label($t('effort'))->badge()->color('gray')->placeholder('—'),
                TextColumn::make('input_price')->label($t('input'))->formatStateUsing($price)->placeholder('—')->alignEnd(),
                TextColumn::make('output_price')->label($t('output'))->formatStateUsing($price)->placeholder('—')->alignEnd(),
                ToggleColumn::make('enabled')->label($t('enabled')),
            ])
            ->recordActions([
                Action::make('test')->label($t('test'))->icon('heroicon-m-signal')->color('gray')
                    ->action(fn (AiModel $record) => $this->testModel($record)),
                EditAction::make()->schema($this->form(...))->mutateDataUsing($this->withDriver(...)),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make()->label($t('create'))->model(AiModel::class)
                    ->schema($this->form(...))
                    ->fillForm(['enabled' => true, 'connection_id' => AiConnection::query()->where('enabled', true)->orderBy('id')->value('id')])
                    ->mutateDataUsing(fn (array $data): array => [...$this->withDriver($data), 'sort' => (int) AiModel::query()->max('sort') + 1]),
            ])
            ->emptyStateHeading($t('empty'));
    }

    /**
     * @return array<int, mixed>
     */
    public function form(): array
    {
        $t = fn (string $key): string => __('chatai::chatai.models.'.$key);

        return [
            Grid::make(2)->schema([
                Select::make('connection_id')->label($t('provider'))->required()->native(false)->live()
                    ->options(fn (): array => AiConnection::query()->orderBy('name')->get()
                        ->mapWithKeys(fn (AiConnection $c): array => [$c->id => $c->name.' — '.__('chatai::chatai.settings.provider_'.$c->driver)])->all())
                    ->helperText($t('provider_help')),
                TextInput::make('model')->label($t('model'))->required()->maxLength(120)->placeholder('claude-opus-5-5'),
                Select::make('effort')->label($t('effort'))->helperText($t('effort_help'))->native(false)->placeholder($t('effort_default'))
                    ->options(fn (Get $get): array => LlmConfig::effortOptions(AiConnection::query()->whereKey($get('connection_id'))->value('driver')))
                    ->in(fn (Get $get): array => LlmConfig::EFFORTS[AiConnection::query()->whereKey($get('connection_id'))->value('driver')] ?? [])
                    ->columnSpanFull(),
            ]),
            Section::make($t('prices'))->description($t('prices_help'))->compact()->columns(2)->schema([
                TextInput::make('input_price')->label($t('input'))->numeric()->minValue(0),
                TextInput::make('output_price')->label($t('output'))->numeric()->minValue(0),
                TextInput::make('cache_read_price')->label(__('chatai::chatai.settings.cache_read_price'))->numeric()->minValue(0)->placeholder(__('chatai::chatai.settings.cache_read_default')),
                TextInput::make('cache_write_price')->label(__('chatai::chatai.settings.cache_write_price'))->numeric()->minValue(0)->placeholder(__('chatai::chatai.settings.cache_write_default')),
            ]),
            Toggle::make('enabled')->label($t('enabled')),
        ];
    }

    /**
     * One tiny question to this model alone (no fallback).
     */
    public function testModel(AiModel $record): void
    {
        ChatAiSettings::flush();
        $config = LlmConfig::fromModels([$record->load('providerConnection')]);
        $started = hrtime(true);

        try {
            foreach (app(LlmClient::class)->stream($config, [['role' => 'user', 'content' => 'Reply with: ok']], []) as $chunk) {
                // drain
            }
        } catch (LlmException $e) {
            Notification::make()->danger()->title(__('chatai::chatai.settings.test_failed'))->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()
            ->title(__('chatai::chatai.settings.test_ok', ['model' => $record->model, 'seconds' => number_format((hrtime(true) - $started) / 1e9, 1)]))
            ->send();
    }

    /**
     * The model's kind follows its provider.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withDriver(array $data): array
    {
        $driver = AiConnection::query()->whereKey($data['connection_id'] ?? null)->value('driver');

        return $driver ? [...$data, 'provider' => $driver] : $data;
    }

    /**
     * First visit: the models already set in .env / settings become rows,
     * with any prices that were set for them.
     */
    protected function importFromConfig(): void
    {
        ChatAiSettings::apply();

        $chain = [];
        $add = function (string $provider) use (&$chain): void {
            $anthropic = $provider === LlmConfig::PROVIDER_ANTHROPIC;
            $models = [config($anthropic ? 'chatai.anthropic.model' : 'chatai.model'), ...(array) config($anthropic ? 'chatai.anthropic.fallback_models' : 'chatai.fallback_models', [])];

            foreach (array_filter($models) as $model) {
                $chain[] = ['provider' => $provider, 'model' => (string) $model];
            }
        };

        $main = (string) config('chatai.provider', LlmConfig::PROVIDER_OPENAI);
        $add($main);

        if (in_array($fallback = config('chatai.fallback_provider'), [LlmConfig::PROVIDER_OPENAI, LlmConfig::PROVIDER_ANTHROPIC], true) && $fallback !== $main) {
            $add($fallback);
        }

        $prices = collect((array) config('chatai.pricing.models', []))->keyBy('model');

        foreach (collect($chain)->unique('model')->values() as $i => $row) {
            $price = (array) $prices->get($row['model'], []);

            AiModel::query()->create([
                ...$row,
                'effort' => $row['provider'] === LlmConfig::PROVIDER_ANTHROPIC ? config('chatai.anthropic.effort') : null,
                'sort' => $i + 1,
                'enabled' => true,
                'input_price' => $price['input'] ?? null,
                'output_price' => $price['output'] ?? null,
                'cache_read_price' => $price['cache_read'] ?? null,
                'cache_write_price' => $price['cache_write'] ?? null,
            ]);
        }
    }
}
