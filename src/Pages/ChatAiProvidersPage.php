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
use Filament\ChatAi\Pages\Concerns\ChecksChatAiManager;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\Budget;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Accounts/endpoints the models come from, each with its own key:
 * OpenRouter, OpenAI, a local Ollama, one or more Anthropic accounts…
 */
class ChatAiProvidersPage extends Page implements HasTable
{
    use ChecksChatAiManager;
    use InteractsWithTable;

    protected static ?string $slug = 'chatai/providers';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 999;

    public function mount(): void
    {
        ProviderSetup::ensure();
    }

    public static function getNavigationLabel(): string
    {
        return __('chatai::chatai.providers.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('chatai::chatai.providers.title');
    }

    public function getSubheading(): ?string
    {
        return __('chatai::chatai.providers.subheading');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $t = fn (string $key): string => __('chatai::chatai.providers.'.$key);

        return $table
            ->query(AiConnection::query()->withCount('models'))
            ->modelLabel($t('singular'))
            ->pluralModelLabel($t('title'))
            ->defaultSort('name')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label($t('name'))->weight('medium')->searchable(),
                TextColumn::make('driver')->label($t('driver'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => __('chatai::chatai.settings.provider_'.$state)),
                TextColumn::make('base_url')->label($t('base_url'))->placeholder($t('default_url'))->limit(40),
                IconColumn::make('has_key')->label($t('key'))->state(fn (AiConnection $record): bool => filled($record->api_key))
                    ->boolean()->trueIcon('heroicon-m-lock-closed')->falseIcon('heroicon-m-arrow-uturn-down')->falseColor('gray')
                    ->tooltip(fn (AiConnection $record): string => filled($record->api_key) ? $t('key_own') : $t('key_env')),
                TextColumn::make('models_count')->label($t('models'))->numeric()->alignEnd(),
                TextColumn::make('spend')->label($t('spend_month'))->alignEnd()
                    ->state(fn (AiConnection $record): string => $this->spendLabel($record))
                    ->color(fn (AiConnection $record): ?string => Budget::exceeded($record) ? 'danger' : null)
                    ->tooltip(fn (AiConnection $record): ?string => Budget::exceeded($record) ? $t('limit_reached') : null),
                ToggleColumn::make('enabled')->label($t('enabled')),
            ])
            ->recordActions([
                Action::make('test')->label(__('chatai::chatai.models.test'))->icon('heroicon-m-signal')->color('gray')
                    ->action(fn (AiConnection $record) => $this->testConnection($record)),
                EditAction::make()->schema(fn (?AiConnection $record): array => $this->form($record))
                    ->fillForm(fn (AiConnection $record): array => [...$record->only(['name', 'driver', 'base_url', 'enabled', 'monthly_limit', 'daily_limit']), 'api_key' => null])
                    ->mutateDataUsing(function (array $data): array {
                        if (blank($data['api_key'] ?? null)) {
                            unset($data['api_key']); // blank keeps the stored key
                        }

                        return $data;
                    }),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make()->label($t('create'))->model(AiConnection::class)
                    ->schema(fn (): array => $this->form(null))
                    ->fillForm(['driver' => LlmConfig::PROVIDER_OPENAI, 'enabled' => true]),
            ])
            ->emptyStateHeading($t('empty'));
    }

    /**
     * @return array<int, mixed>
     */
    protected function form(?AiConnection $editing): array
    {
        $t = fn (string $key): string => __('chatai::chatai.providers.'.$key);

        return [
            Grid::make(2)->schema([
                TextInput::make('name')->label($t('name'))->required()->maxLength(80)->placeholder('OpenRouter'),
                Select::make('driver')->label($t('driver'))->required()->native(false)->live()->options([
                    LlmConfig::PROVIDER_OPENAI => __('chatai::chatai.settings.provider_openai'),
                    LlmConfig::PROVIDER_ANTHROPIC => __('chatai::chatai.settings.provider_anthropic'),
                ]),
            ]),
            TextInput::make('base_url')->label($t('base_url'))->url()->maxLength(255)
                ->placeholder(fn (Get $get): string => $get('driver') === LlmConfig::PROVIDER_ANTHROPIC ? 'https://api.anthropic.com' : 'https://openrouter.ai/api/v1')
                ->helperText($t('base_url_help')),
            TextInput::make('api_key')->label(__('chatai::chatai.settings.api_key'))->password()->revealable()->autocomplete('new-password')
                ->helperText(filled($editing?->api_key) ? __('chatai::chatai.settings.key_stored') : $t('key_help')),
            Section::make($t('limits'))->description($t('limits_help'))->compact()->columns(2)->schema([
                TextInput::make('monthly_limit')->label($t('monthly_limit'))->numeric()->minValue(0)->suffix(Pricing::currency()),
                TextInput::make('daily_limit')->label($t('daily_limit'))->numeric()->minValue(0)->suffix(Pricing::currency()),
            ]),
            Toggle::make('enabled')->label($t('enabled')),
        ];
    }

    protected function spendLabel(AiConnection $record): string
    {
        $money = fn (float $v): string => number_format($v, 2);
        $spent = $money(Budget::spent($record, 'month'));

        return $record->monthly_limit !== null
            ? $spent.' / '.$money((float) $record->monthly_limit).' '.Pricing::currency()
            : $spent.' '.Pricing::currency();
    }

    /**
     * One tiny question with the first model of this provider.
     */
    public function testConnection(AiConnection $record): void
    {
        $model = $record->models()->orderBy('sort')->first();

        if (! $model) {
            Notification::make()->warning()->title(__('chatai::chatai.providers.no_model'))->send();

            return;
        }

        ChatAiSettings::flush();
        $config = LlmConfig::fromModels([$model]);
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
            ->title(__('chatai::chatai.settings.test_ok', ['model' => $model->model, 'seconds' => number_format((hrtime(true) - $started) / 1e9, 1)]))
            ->send();
    }
}
