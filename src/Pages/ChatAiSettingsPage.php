<?php

namespace Filament\ChatAi\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmException;
use Filament\ChatAi\Llm\StreamChunk;
use Filament\ChatAi\Pages\Concerns\ChecksChatAiManager;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Speech\VoiceCatalog;
use Filament\ChatAi\Support\ChatAiPermissions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;

/**
 * Model, fallback, keys, voice and prices — saved over config/chatai.php.
 */
class ChatAiSettingsPage extends Page
{
    use ChecksChatAiManager;

    protected static ?string $slug = 'chatai/settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 1001;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(ChatAiSettings::formState());
    }

    /** Icons offered for the assistant (any Heroicon name also works in config). */
    public const ICONS = [
        'heroicon-o-sparkles' => 'Sparkles',
        'heroicon-o-chat-bubble-left-right' => 'Chat',
        'heroicon-o-light-bulb' => 'Light bulb',
        'heroicon-o-cpu-chip' => 'Chip',
        'heroicon-o-bolt' => 'Bolt',
        'heroicon-o-academic-cap' => 'Cap',
        'heroicon-o-face-smile' => 'Smile',
        'heroicon-o-star' => 'Star',
        'heroicon-o-rocket-launch' => 'Rocket',
        'heroicon-o-command-line' => 'Terminal',
        'heroicon-o-shopping-bag' => 'Shop',
        'heroicon-o-user-circle' => 'Person',
    ];

    public static function getNavigationLabel(): string
    {
        return __('chatai::chatai.settings.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('chatai::chatai.settings.title');
    }

    public function form(Schema $schema): Schema
    {
        $t = fn (string $key): string => __('chatai::chatai.settings.'.$key);

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('settings')->persistTabInQueryString('tab')->tabs([
                    Tab::make($t('assistant'))->icon('heroicon-m-chat-bubble-left-right')->schema([
                        Grid::make(2)->schema([
                            TextInput::make('assistant.name')->label($t('assistant_name'))->maxLength(40)->placeholder('ChatAI'),
                            Select::make('assistant.icon')->label($t('assistant_icon'))->native(false)->placeholder('Sparkles')
                                ->allowHtml()
                                ->options(collect(self::ICONS)->mapWithKeys(fn (string $label, string $icon): array => [
                                    $icon => '<span style="display:inline-flex;align-items:center;gap:.5rem">'.svg($icon, '', ['style' => 'width:1.1rem;height:1.1rem'])->toHtml().e($label).'</span>',
                                ])->all()),
                        ]),
                        FileUpload::make('assistant.avatar')->label($t('assistant_avatar'))->helperText($t('assistant_avatar_help'))
                            ->image()->avatar()->disk(fn (): string => (string) config('chatai.assistant.disk', 'public'))->directory('chatai')->maxSize(1024),
                        Textarea::make('instructions')->label($t('instructions'))->rows(8)->helperText($t('instructions_help')),
                        Toggle::make('screen.read')->label($t('screen_read'))->helperText($t('screen_read_help'))->live(),
                        Toggle::make('screen.fill')->label($t('screen_fill'))->helperText($t('screen_fill_help'))
                            ->visible(fn (Get $get): bool => (bool) $get('screen.read')),
                        TagsInput::make('http_tools.allowed_hosts')->label($t('allowed_hosts'))->helperText($t('allowed_hosts_help'))->placeholder('api.minhaloja.co.mz'),
                    ]),

                    Tab::make($t('voice'))->icon('heroicon-m-speaker-wave')->columns(2)->schema([
                        Toggle::make('speech.enabled')->label($t('voice_enabled'))->columnSpanFull(),
                        Select::make('speech.provider')->label($t('voice_provider'))->live()->native(false)
                            ->options(['azure' => 'Azure AI Speech', 'google' => 'Google Cloud'])
                            ->placeholder($t('voice_browser')),
                        TextInput::make('speech.language')->label($t('voice_language'))->placeholder('pt-PT'),
                        Grid::make(2)->columnSpanFull()->visible(fn (Get $get): bool => $get('speech.provider') === 'azure')->schema([
                            $this->secret('speech.azure.key'),
                            TextInput::make('speech.azure.region')->label($t('azure_region'))->placeholder('westeurope'),
                            Select::make('speech.azure.voice')->label($t('default_voice'))->options(VoiceCatalog::all('azure'))->native(false),
                        ]),
                        Grid::make(2)->columnSpanFull()->visible(fn (Get $get): bool => $get('speech.provider') === 'google')->schema([
                            $this->secret('speech.google.api_key'),
                            Select::make('speech.google.voice')->label($t('default_voice'))->options(VoiceCatalog::all('google'))->native(false),
                        ]),
                        Toggle::make('speech.user_choice')->label($t('user_choice'))->helperText($t('user_choice_help'))->columnSpanFull(),
                        CheckboxList::make('speech.voices')->label($t('offered_voices'))->helperText($t('offered_voices_help'))
                            ->options(fn (Get $get): array => VoiceCatalog::all($get('speech.provider')))
                            ->visible(fn (Get $get): bool => filled($get('speech.provider')))
                            ->columns(2)->columnSpanFull()->searchable()->bulkToggleable(),
                    ]),

                    Tab::make($t('permissions'))->icon('heroicon-m-lock-closed')->schema([
                        Text::make($t('permissions_help'))->color('gray'),
                        Toggle::make('actions.enabled')->label($t('actions_enabled'))->helperText($t('actions_enabled_help')),
                        Toggle::make('permissions.enabled')->label($t('permissions_enabled'))->live()->helperText($t('permissions_enabled_help')),
                        Toggle::make('permissions.require_chat')->label($t('permissions_require_chat'))
                            ->helperText(fn (): string => __('chatai::chatai.settings.permissions_require_chat_help', ['permission' => ChatAiPermissions::forChat()]))
                            ->visible(fn (Get $get): bool => (bool) $get('permissions.enabled')),
                        Text::make(fn (): string => __('chatai::chatai.settings.permissions_example', ['permission' => ChatAiPermissions::forTool('sales-stats')]))
                            ->color('gray')
                            ->visible(fn (Get $get): bool => (bool) $get('permissions.enabled')),
                    ]),

                    Tab::make($t('files'))->icon('heroicon-m-paper-clip')->columns(2)->schema([
                        Select::make('files.disk')->label($t('files_disk'))->native(false)->required()
                            ->options(fn (): array => collect(array_keys((array) config('filesystems.disks', [])))->mapWithKeys(fn (string $disk): array => [$disk => $disk.' ('.config("filesystems.disks.{$disk}.driver").')'])->all())
                            ->helperText($t('files_disk_help')),
                        TextInput::make('files.max_kb')->label($t('files_max_kb'))->numeric()->minValue(100)->maxValue(102400)->suffix('KB'),
                        TextInput::make('files.max_per_message')->label($t('files_max_per_message'))->numeric()->minValue(1)->maxValue(20),
                    ]),

                    Tab::make($t('limits'))->icon('heroicon-m-shield-check')->columns(2)->schema([
                        TextInput::make('rate_limit.per_minute')->label($t('per_minute'))->numeric()->minValue(1)->helperText($t('per_user')),
                        TextInput::make('rate_limit.per_day')->label($t('per_day'))->numeric()->minValue(1)->helperText($t('per_user')),
                        TextInput::make('max_iterations')->label($t('max_iterations'))->numeric()->minValue(1)->maxValue(20)->helperText($t('max_iterations_help')),
                        TextInput::make('timeout')->label($t('timeout'))->numeric()->minValue(5)->maxValue(600)->suffix('s')->helperText($t('timeout_help')),
                        TextInput::make('max_tool_result_bytes')->label($t('max_tool_result'))->numeric()->minValue(1000)->suffix('bytes')->helperText($t('max_tool_result_help')),
                    ]),

                    Tab::make($t('prices'))->icon('heroicon-m-banknotes')->columns(2)->schema([
                        Text::make($t('prices_models_help'))->color('gray')->columnSpanFull(),
                        TextInput::make('pricing.currency')->label($t('currency'))->maxLength(3)->placeholder('USD'),
                        TextInput::make('pricing.speech_per_million_characters')->label($t('speech_price'))->numeric()->minValue(0),
                        TextInput::make('pricing.transcription_per_hour')->label($t('transcription_price'))->numeric()->minValue(0),
                    ]),
                ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('chatai::chatai.settings.save'))->submit('save')->keyBindings(['mod+s']),
                    ])->sticky()->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $values = [];

        // Only keys on this form: models and their prices live on the Models page.
        foreach (ChatAiSettings::KEYS as $key) {
            if (Arr::has($state, $key)) {
                $value = data_get($state, $key);
                $values[$key] = is_array($value) ? array_values($value) : $value;
            }
        }

        ChatAiSettings::save($values);

        // Never keep a typed key in the component state after saving.
        $this->form->fill(ChatAiSettings::formState());

        Notification::make()->success()->title(__('chatai::chatai.settings.saved'))->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label(__('chatai::chatai.settings.test'))
                ->icon('heroicon-m-signal')
                ->color('gray')
                ->action(fn () => $this->testModel()),
        ];
    }

    /**
     * One tiny question to the saved model (and fallback, if the main one fails).
     */
    public function testModel(): void
    {
        ChatAiSettings::flush();
        $config = ChatAiPlugin::get()->resolveLlmConfig();
        $started = hrtime(true);
        $model = null;

        try {
            foreach (app(LlmClient::class)->stream($config, [['role' => 'user', 'content' => 'Reply with: ok']], []) as $chunk) {
                if ($chunk->type === StreamChunk::DONE) {
                    $model = $chunk->model;
                }
            }
        } catch (LlmException $e) {
            Notification::make()->danger()->title(__('chatai::chatai.settings.test_failed'))->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()
            ->title(__('chatai::chatai.settings.test_ok', ['model' => $model ?? $config->model, 'seconds' => number_format((hrtime(true) - $started) / 1e9, 1)]))
            ->send();
    }

    protected function secret(string $key): TextInput
    {
        $stored = ChatAiSettings::hasSecret($key);

        return TextInput::make($key)
            ->label(__('chatai::chatai.settings.api_key'))
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->placeholder($stored ? '••••••••••••' : null)
            ->helperText(__($stored ? 'chatai::chatai.settings.key_stored' : 'chatai::chatai.settings.key_missing'));
    }
}
