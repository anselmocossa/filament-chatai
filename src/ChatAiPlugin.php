<?php

namespace Filament\ChatAi;

use Closure;
use Filament\ChatAi\Contracts\ToolProvider;
use Filament\ChatAi\Http\Controllers\ConversationController;
use Filament\ChatAi\Http\Controllers\ConversationGroupController;
use Filament\ChatAi\Http\Controllers\FileController;
use Filament\ChatAi\Http\Controllers\MessageStreamController;
use Filament\ChatAi\Http\Controllers\PlaygroundController;
use Filament\ChatAi\Http\Controllers\PreferenceController;
use Filament\ChatAi\Http\Controllers\SpeechController;
use Filament\ChatAi\Http\Middleware\EnsureChatAiAccess;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\Preference;
use Filament\ChatAi\Models\Skill;
use Filament\ChatAi\Pages\ChatAiLogsPage;
use Filament\ChatAi\Pages\ChatAiModelsPage;
use Filament\ChatAi\Pages\ChatAiPage;
use Filament\ChatAi\Pages\ChatAiProvidersPage;
use Filament\ChatAi\Pages\ChatAiSettingsPage;
use Filament\ChatAi\Pages\ChatAiSkillsPage;
use Filament\ChatAi\Pages\ChatAiToolEditorPage;
use Filament\ChatAi\Pages\ChatAiToolsPage;
use Filament\ChatAi\Pages\ChatAiUsagePage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Speech\SpeechManager;
use Filament\ChatAi\Speech\SpeechProvider;
use Filament\ChatAi\Speech\VoiceCatalog;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Support\ChatAiPermissions;
use Filament\ChatAi\Support\ContextResolver;
use Filament\ChatAi\Tools\ArrayToolProvider;
use Filament\ChatAi\Tools\SkillTool;
use Filament\ChatAi\Tools\ToolRegistry;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatAiPlugin implements Plugin
{
    protected string|Closure|null $label = null;

    protected ?Closure $tools = null;

    protected ?Closure $llm = null;

    protected ?Closure $canAccess = null;

    protected bool $floatingWidget = true;

    protected bool $page = true;

    protected string|Closure|null $systemPrompt = null;

    protected bool $voice = true;

    protected ?string $speechLanguage = null;

    protected ?Closure $speech = null;

    protected ?Closure $canManageSettings = null;

    protected ?Closure $userRoles = null;

    protected ?string $filesDisk = null;

    protected string|Closure|null $adminNavigationGroup = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function getId(): string
    {
        return 'chatai';
    }

    public function register(Panel $panel): void
    {
        $routes = function (): void {
            Route::prefix('chatai/api')
                ->name('chatai.')
                ->middleware(EnsureChatAiAccess::class)
                ->group(function (): void {
                    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
                    Route::post('conversations', [ConversationController::class, 'store'])->name('conversations.store');
                    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
                    Route::patch('conversations/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
                    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');
                    Route::post('groups', [ConversationGroupController::class, 'store'])->name('groups.store');
                    Route::patch('groups/{group}', [ConversationGroupController::class, 'update'])->whereNumber('group')->name('groups.update');
                    Route::delete('groups/{group}', [ConversationGroupController::class, 'destroy'])->whereNumber('group')->name('groups.destroy');
                    Route::post('conversations/{conversation}/messages', MessageStreamController::class)->name('messages.store');
                    Route::post('messages/{message}/speech', [SpeechController::class, 'synthesize'])->name('speech.synthesize');
                    Route::post('transcriptions', [SpeechController::class, 'transcribe'])->name('speech.transcribe');
                    Route::post('speech/sample', [SpeechController::class, 'sample'])->name('speech.sample');
                    Route::post('preferences', [PreferenceController::class, 'update'])->name('preferences.update');
                    Route::post('conversations/{conversation}/files', [FileController::class, 'store'])->name('files.store');
                    Route::get('files/{file}', [FileController::class, 'download'])->name('files.download');
                    Route::post('playground', [PlaygroundController::class, 'store'])->name('playground.store');
                    Route::get('playground/{conversation}', [ConversationController::class, 'show'])->name('playground.show');
                    Route::post('playground/{conversation}/messages', [PlaygroundController::class, 'message'])->name('playground.messages');
                });
        };

        $panel->hasTenancy()
            ? $panel->authenticatedTenantRoutes($routes)
            : $panel->authenticatedRoutes($routes);

        if ($this->page) {
            $panel->pages([ChatAiPage::class]);
        }

        // Always registered; each page checks canManage() itself.
        $panel->pages([ChatAiProvidersPage::class, ChatAiModelsPage::class, ChatAiSettingsPage::class, ChatAiUsagePage::class, ChatAiToolsPage::class, ChatAiToolEditorPage::class, ChatAiSkillsPage::class, ChatAiLogsPage::class]);

        $panel
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => $this->isAccessible()
                ? '<link rel="stylesheet" href="'.e(FilamentAsset::getStyleHref('chatai', 'anselmokossa/filament-chatai')).'">'
                : '')
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => $this->isAccessible()
                ? '<script type="application/json" id="chatai-context">'.json_encode(ContextResolver::current(), JSON_HEX_TAG | JSON_HEX_AMP).'</script>'
                : '')
            // Before Alpine starts, so the chataiChat component is defined in time.
            ->renderHook(PanelsRenderHook::SCRIPTS_BEFORE, fn (): string => $this->isAccessible()
                ? '<script src="'.e(FilamentAsset::getScriptSrc('chatai', 'anselmokossa/filament-chatai')).'"></script>'
                : '')
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => $this->floatingWidget && $this->isAccessible()
                ? Blade::render("@livewire('chatai-floating')")
                : '');
    }

    /**
     * Settings handed to the Alpine chat component.
     *
     * @return array<string, mixed>
     */
    public function clientConfig(bool $remember, bool $withSidebar = false, ?string $conversationId = null): array
    {
        $panel = Filament::getCurrentPanel();

        return [
            'endpoint' => $panel->route('chatai.conversations.index'),
            'pageUrl' => $this->page ? ChatAiPage::getUrl() : null,
            'conversationId' => $conversationId,
            'remember' => $remember,
            'storageKey' => 'chatai:'.$panel->getId().':'.auth()->id().':'.(Filament::getTenant()?->getKey() ?? '-'),
            'withSidebar' => $withSidebar,
            'csrf' => csrf_token(),
            'locale' => str_replace('_', '-', app()->getLocale()),
            'label' => $this->getLabel(),
            ...$this->composerLists(),
            'voice' => $voice = $this->hasVoice(),
            'speechLang' => $this->getSpeechLanguage(),
            'serverVoice' => $serverVoice = $voice && $this->resolveSpeech() !== null,
            'speechUrl' => $panel->route('chatai.speech.synthesize', ['message' => '__message__']),
            'transcribeUrl' => $panel->route('chatai.speech.transcribe'),
            'voiceChoice' => $voice && (bool) config('chatai.speech.user_choice', true),
            'voices' => $serverVoice ? collect(VoiceCatalog::offered())->map(fn (string $label, string $id): array => ['id' => $id, 'label' => $label])->values()->all() : [],
            'userVoice' => $serverVoice && auth()->check() ? Preference::voiceFor(auth()->user()) : null,
            'sampleUrl' => $panel->route('chatai.speech.sample'),
            'preferencesUrl' => $panel->route('chatai.preferences.update'),
            'groupsUrl' => $panel->route('chatai.groups.store'),
            'filesUrl' => $panel->route('chatai.files.store', ['conversation' => '__conversation__']),
            'files' => [
                'accept' => '.'.str_replace(',', ',.', FileController::ACCEPTED),
                'maxKb' => (int) config('chatai.files.max_kb', 10240),
                'max' => (int) config('chatai.files.max_per_message', 5),
            ],
            'i18n' => [
                'unavailable' => __('chatai::chatai.unavailable'),
                'confirmDelete' => __('chatai::chatai.confirm_delete'),
                'micDenied' => __('chatai::chatai.mic_denied'),
                'you' => __('chatai::chatai.you'),
                'exportedOn' => __('chatai::chatai.exported_on'),
                'speechUnavailable' => __('chatai::chatai.speech_unavailable'),
                'preparing' => __('chatai::chatai.preparing'),
                'fileTooBig' => __('chatai::chatai.file_too_big'),
                'tooManyFiles' => __('chatai::chatai.too_many_files'),
                'confirmDeleteGroup' => __('chatai::chatai.history.confirm_delete_group'),
                'newGroupName' => __('chatai::chatai.history.new_group_name'),
                'pinned' => __('chatai::chatai.history.pinned'),
                'today' => __('chatai::chatai.history.today'),
                'yesterday' => __('chatai::chatai.history.yesterday'),
                'week' => __('chatai::chatai.history.week'),
                'month' => __('chatai::chatai.history.month'),
                'older' => __('chatai::chatai.history.older'),
                'voiceSample' => __('chatai::chatai.voice_sample', [], str_replace('-', '_', (string) ($this->getSpeechLanguage() ?? app()->getLocale()))),
            ],
        ];
    }

    /**
     * Code tools plus the ones made on screen, with the admin's adjustments.
     */
    public function resolveTools(): ToolRegistry
    {
        $tools = $this->tools ? app()->call($this->tools) : [];

        return new ToolRegistry($tools instanceof ToolProvider ? $tools : new ArrayToolProvider((array) $tools), $this->userRoles);
    }

    public function resolveLlmConfig(): LlmConfig
    {
        $config = $this->llm ? app()->call($this->llm) : null;

        return $config instanceof LlmConfig ? $config : LlmConfig::fromConfig();
    }

    public function boot(Panel $panel): void
    {
        // Code default for the files disk; a disk chosen on the settings page still wins.
        if ($this->filesDisk !== null) {
            config(['chatai.files.disk' => $this->filesDisk]);
            ChatAiSettings::flush();
        }

        // Lists the chat/tool permissions in Shield's roles editor (and creates them) when enabled.
        ChatAiPermissions::register($this);
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The name set on the settings page, else the one given in code.
     */
    public function getLabel(): string
    {
        ChatAiSettings::apply();

        return (string) (config('chatai.assistant.name') ?: value($this->label) ?? 'ChatAI');
    }

    public function getIcon(): string
    {
        ChatAiSettings::apply();

        return (string) (config('chatai.assistant.icon') ?: 'heroicon-o-sparkles');
    }

    /**
     * An uploaded image used instead of the icon, if any.
     */
    public function getAvatarUrl(): ?string
    {
        ChatAiSettings::apply();
        $path = config('chatai.assistant.avatar');

        return filled($path) ? Storage::disk((string) config('chatai.assistant.disk', 'public'))->url((string) $path) : null;
    }

    /**
     * The tools the assistant can call: a ToolProvider, or a closure that
     * returns one, resolved per request so it can depend on the user/tenant.
     */
    public function tools(?Closure $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    public function getToolsResolver(): ?Closure
    {
        return $this->tools;
    }

    /**
     * Override config('chatai.*') for the model at runtime (e.g. per tenant).
     */
    public function llm(Closure $llm): static
    {
        $this->llm = $llm;

        return $this;
    }

    public function getLlmResolver(): ?Closure
    {
        return $this->llm;
    }

    public function canAccess(Closure $canAccess): static
    {
        $this->canAccess = $canAccess;

        return $this;
    }

    public function isAccessible(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        // Shield: UseChatAi, when the settings page requires it.
        if (ChatAiPermissions::chatRequired() && ! ChatAiPermissions::allows(auth()->user(), ChatAiPermissions::forChat())) {
            return false;
        }

        return $this->canAccess === null || (bool) app()->call($this->canAccess, ['user' => auth()->user()]);
    }

    /**
     * Who may see the settings (keys included) and the usage/costs page. Closed by default.
     */
    public function canManageSettings(?Closure $condition): static
    {
        $this->canManageSettings = $condition;

        return $this;
    }

    public function canManage(): bool
    {
        return $this->canManageSettings !== null
            && auth()->check()
            && (bool) app()->call($this->canManageSettings, ['user' => auth()->user()]);
    }

    public function adminNavigationGroup(string|Closure|null $group): static
    {
        $this->adminNavigationGroup = $group;

        return $this;
    }

    public function getAdminNavigationGroup(): ?string
    {
        return value($this->adminNavigationGroup) ?? $this->getLabel();
    }

    /**
     * Where attachments and generated documents are kept: any filesystem disk (local, s3…).
     */
    public function filesDisk(?string $disk): static
    {
        $this->filesDisk = $disk;

        return $this;
    }

    public function floatingWidget(bool $condition = true): static
    {
        $this->floatingWidget = $condition;

        return $this;
    }

    public function hasFloatingWidget(): bool
    {
        return $this->floatingWidget;
    }

    /**
     * Dictation and read-aloud through the browser's own speech engine.
     */
    public function voice(bool $condition = true): static
    {
        $this->voice = $condition;

        return $this;
    }

    /**
     * On in the plugin and not switched off on the settings page.
     */
    public function hasVoice(): bool
    {
        ChatAiSettings::apply();

        return $this->voice && (bool) config('chatai.speech.enabled', true);
    }

    /**
     * BCP 47 tag such as "pt-PT". Defaults to the browser's language, which is
     * usually what the user speaks even when the panel runs in English.
     */
    public function speechLanguage(?string $language): static
    {
        $this->speechLanguage = $language;

        return $this;
    }

    public function getSpeechLanguage(): ?string
    {
        return $this->speechLanguage ?? (config('chatai.speech.provider') ? config('chatai.speech.language') : null);
    }

    /**
     * Server voice per panel or tenant: return a SpeechProvider, or null for the browser voice.
     */
    public function speech(Closure $speech): static
    {
        $this->speech = $speech;

        return $this;
    }

    public function resolveSpeech(): ?SpeechProvider
    {
        if (! $this->hasVoice()) {
            return null;
        }

        $speech = $this->speech ? app()->call($this->speech) : SpeechManager::fromConfig();

        return $speech instanceof SpeechProvider ? $speech : null;
    }

    public function page(bool $condition = true): static
    {
        $this->page = $condition;

        return $this;
    }

    public function hasPage(): bool
    {
        return $this->page;
    }

    public function systemPrompt(string|Closure|null $prompt): static
    {
        $this->systemPrompt = $prompt;

        return $this;
    }

    public function getSystemPrompt(): ?string
    {
        return value($this->systemPrompt);
    }

    /**
     * The panel's prompt, the admin's instructions and the list of skills.
     */
    /**
     * @param  Collection<int, Skill>|null  $skills  null = every enabled skill
     */
    public function composeSystemPrompt(?Collection $skills = null): ?string
    {
        ChatAiSettings::apply();

        $skills = ($skills ?? ToolRegistry::skills())
            ->map(fn (Skill $skill): string => "- {$skill->name}: {$skill->when_to_use}")
            ->implode("\n");

        $parts = array_filter([
            $this->getSystemPrompt(),
            filled(config('chatai.instructions')) ? "Instructions from the administrator:\n".config('chatai.instructions') : null,
            $skills !== '' ? 'Skills (call '.SkillTool::NAME." with the name before doing a task one covers):\n{$skills}" : null,
        ]);

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * What the user picked in the composer for this question: tools mentioned
     * with @ (only ones they may use) and a skill chosen with /.
     *
     * @param  array<int, string>  $mentions
     */
    public function turnHints(Authenticatable $user, ChatAiContext $context, array $mentions, ?string $skill): string
    {
        $hints = [];
        $tools = collect($this->resolveTools()->tools($user, $context))->keyBy('name')->except(SkillTool::NAME);
        $mentioned = $tools->only($mentions);

        if ($mentioned->isNotEmpty()) {
            $hints[] = 'For this question the user explicitly picked these tools; use them: '
                .$mentioned->map(fn ($tool): string => "{$tool->name} ({$tool->label()})")->implode(', ').'.';
        }

        $chosen = filled($skill) ? ToolRegistry::skills()->firstWhere('name', $skill) : null;

        if ($chosen) {
            $hints[] = "For this question the user chose the skill \"{$chosen->title}\". Follow its instructions:\n{$chosen->instructions}"
                .($chosen->tools ? "\nTools for this task: ".implode(', ', $chosen->tools).'.' : '');
        }

        return implode("\n\n", $hints);
    }

    /**
     * What the composer offers after @ and /, for the signed-in user.
     *
     * @return array{mentions: array<int, array<string, string>>, skills: array<int, array<string, string>>}
     */
    protected function composerLists(): array
    {
        try {
            $user = auth()->user();
            $panel = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $tools = $user ? $this->resolveTools()->tools($user, new ChatAiContext($panel)) : [];

            return [
                'mentions' => collect($tools)->reject(fn ($tool): bool => $tool->name === SkillTool::NAME)
                    ->map(fn ($tool): array => ['name' => $tool->name, 'label' => $tool->label(), 'description' => Str::limit($tool->description, 90)])
                    ->values()->all(),
                'skills' => ToolRegistry::skills()
                    ->map(fn (Skill $skill): array => ['name' => $skill->name, 'label' => $skill->title, 'description' => Str::limit($skill->when_to_use, 90)])
                    ->values()->all(),
            ];
        } catch (\Throwable) {
            return ['mentions' => [], 'skills' => []];
        }
    }

    /**
     * The user's profiles, for tools limited to some of them. Defaults to
     * spatie/laravel-permission role names when available.
     *
     * @param  (Closure(Authenticatable): array<int, string>)|null  $resolver
     */
    public function userRoles(?Closure $resolver): static
    {
        $this->userRoles = $resolver;

        return $this;
    }
}
