<?php

namespace Filament\ChatAi\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Llm\LlmException;
use Filament\ChatAi\Llm\StreamChunk;
use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\Skill;
use Filament\ChatAi\Pages\Concerns\BuildsToolForms;
use Filament\ChatAi\Pages\Concerns\ChecksChatAiManager;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\HttpApiTool;
use Filament\ChatAi\Tools\ToolCode;
use Filament\ChatAi\Tools\ToolDraft;
use Filament\Facades\Filament;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Create or edit a tool on a full page, with a test chat next to the form
 * that uses the tool exactly as it is on screen.
 */
class ChatAiToolEditorPage extends Page
{
    use BuildsToolForms;
    use ChecksChatAiManager;

    public const MODE_CODE = ToolDraft::MODE_CODE;

    public const MODE_CUSTOM = ToolDraft::MODE_CUSTOM;

    protected static ?string $slug = 'chatai/tools/editor';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament-chatai::pages.tool-editor';

    #[Url]
    public ?string $tool = null;

    #[Url]
    public string $mode = self::MODE_CUSTOM;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        if ($this->mode === self::MODE_CODE) {
            abort_unless($this->codeTool() !== null, 404);
            $this->form->fill([...$this->overrideState((string) $this->tool), 'skills' => $this->linkedSkills()]);

            return;
        }

        $this->mode = self::MODE_CUSTOM;

        if ($this->tool !== null) {
            abort_unless(CustomTool::query()->where('name', $this->tool)->exists(), 404);
            $this->form->fill([...$this->customState($this->tool), 'skills' => $this->linkedSkills()]);

            return;
        }

        $this->form->fill(['type' => CustomTool::TYPE_RESOURCE, 'enabled' => true, 'config' => ['limit' => 20, 'auth' => 'none', 'params' => []]]);
    }

    public function form(Schema $schema): Schema
    {
        $t = fn (string $key): string => __('chatai::chatai.editor.'.$key);

        if ($this->mode === self::MODE_CODE) {
            $tool = $this->codeTool();
            $components = $this->overrideForm([
                'name' => $this->tool,
                'title' => $tool?->label() ?? $this->tool,
                'original_description' => $tool?->description,
            ]);
        } else {
            $components = $this->customForm($this->tool);
        }

        return $schema->statePath('data')->components([
            Tabs::make('editor')->contained(false)->tabs([
                Tab::make($t('form_tab'))->icon('heroicon-m-pencil-square')->schema([
                    ...$components,
                    ...$this->schemaComponents(),
                    Select::make('skills')->label($t('skills'))->helperText($t('skills_help'))->multiple()->native(false)
                        ->options(fn (): array => Skill::query()->orderBy('title')->pluck('title', 'id')->all()),
                ]),
                Tab::make($t('code_tab'))->icon('heroicon-m-code-bracket')->schema([
                    View::make('filament-chatai::components.code-view')->viewData(fn (): array => [
                        'blocks' => ToolCode::blocks($this->draftTool(), $this->data ?? [], $this->mode),
                        'empty' => $t('incomplete'),
                    ]),
                ]),
            ]),
        ]);
    }

    /**
     * Editable input schema for API tools. Other tools show theirs in the Code tab (JSON).
     *
     * @return array<int, mixed>
     */
    protected function schemaComponents(): array
    {
        $t = fn (string $key): string => __('chatai::chatai.editor.'.$key);
        $isHttp = fn (Get $get): bool => $this->mode === self::MODE_CUSTOM && $get('type') === CustomTool::TYPE_HTTP;

        return [
            CodeEditor::make('config.schema')
                ->label($t('schema'))
                ->language(Language::Json)
                ->helperText($t('schema_help'))
                ->visible($isHttp)
                ->hintActions([
                    Action::make('schemaFromParams')->label($t('from_params'))->icon('heroicon-m-list-bullet')
                        ->action(fn (Get $get, Set $set) => $set('config.schema', $this->pretty(HttpApiTool::schemaFromParams(array_values((array) $get('config.params')))))),
                    Action::make('schemaWithAi')->label($t('ai_generate'))->icon('heroicon-m-sparkles')
                        ->action(fn (Get $get, Set $set) => $this->generateSchema($get, $set)),
                ])
                ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && HttpApiTool::customSchema($value) === null) {
                        $fail(__('chatai::chatai.editor.invalid_schema'));
                    }
                }),
        ];
    }

    /**
     * Asks the configured model for an input schema from the description and URL.
     */
    public function generateSchema(Get $get, Set $set): void
    {
        $url = (string) $get('config.url');
        $prompt = "Write the JSON Schema (type object) for the arguments of a tool that calls GET {$url}.\n"
            .'Tool description: '.$get('description')."\n"
            .'Every {placeholder} in the URL must be a required property. Add query parameters that the description implies. '
            .'Give each property a short description in the language of the tool description. Reply with the JSON only.';

        $text = '';

        try {
            foreach (app(LlmClient::class)->stream(ChatAiPlugin::get()->resolveLlmConfig(), [['role' => 'user', 'content' => $prompt]], []) as $chunk) {
                $text .= $chunk->type === StreamChunk::TEXT ? $chunk->text : '';
            }
        } catch (LlmException) {
            // handled below
        }

        $schema = HttpApiTool::customSchema(preg_match('/\{.*\}/s', $text, $m) ? $m[0] : null);

        if ($schema === null) {
            Notification::make()->danger()->title(__('chatai::chatai.editor.ai_failed'))->send();

            return;
        }

        $set('config.schema', $this->pretty($schema));
    }

    /**
     * @return array<int, int>
     */
    protected function linkedSkills(): array
    {
        return Skill::query()->get()
            ->filter(fn (Skill $skill): bool => in_array($this->tool, (array) $skill->tools, true))
            ->pluck('id')->values()->all();
    }

    /**
     * Adds the tool to the chosen skills and takes it out of the others.
     *
     * @param  array<int, int|string>  $skillIds
     */
    protected function linkSkills(string $tool, array $skillIds): void
    {
        foreach (Skill::query()->get() as $skill) {
            $tools = array_values(array_filter((array) $skill->tools, fn (string $name): bool => $name !== $tool));

            if (in_array($skill->id, array_map('intval', $skillIds), true)) {
                $tools[] = $tool;
            }

            if ($tools !== array_values((array) $skill->tools)) {
                $skill->update(['tools' => $tools]);
            }
        }
    }

    public function draftTool(): ?ChatAiTool
    {
        return ToolDraft::build($this->mode, $this->tool, $this->data ?? [], ChatAiPlugin::get()->resolveTools(), auth()->user(), new ChatAiContext(Filament::getCurrentPanel()->getId()));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function pretty(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        if ($this->mode === self::MODE_CODE) {
            $this->saveOverride((string) $this->tool, $data);
            $this->linkSkills((string) $this->tool, (array) ($data['skills'] ?? []));
        } else {
            $existing = $this->tool ? CustomTool::query()->where('name', $this->tool)->firstOrFail() : new CustomTool;
            $saved = $this->saveCustom($data, $existing);
            $this->linkSkills($saved->name, (array) ($data['skills'] ?? []));

            if ($this->tool === null) {
                Notification::make()->success()->title(__('chatai::chatai.settings.saved'))->send();
                $this->redirect(static::getUrl(['tool' => $saved->name]), navigate: true);

                return;
            }
        }

        Notification::make()->success()->title(__('chatai::chatai.settings.saved'))->send();
    }

    public function getTitle(): string|Htmlable
    {
        return match (true) {
            $this->mode === self::MODE_CODE => __('chatai::chatai.editor.adjust_title', ['tool' => $this->codeTool()?->label() ?? $this->tool]),
            $this->tool !== null => __('chatai::chatai.editor.edit_title', ['tool' => $this->tool]),
            default => __('chatai::chatai.tools.create'),
        };
    }

    public function getBreadcrumbs(): array
    {
        return [ChatAiToolsPage::getUrl() => __('chatai::chatai.tools.title'), __('chatai::chatai.editor.breadcrumb')];
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label(__('chatai::chatai.editor.back'))->color('gray')->icon('heroicon-m-arrow-left')->url(ChatAiToolsPage::getUrl()),
            Action::make('save')->label(__('chatai::chatai.settings.save'))->action('save')->keyBindings(['mod+s']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $plugin = ChatAiPlugin::get();

        return [
            'label' => $plugin->getLabel(),
            'icon' => $plugin->getIcon(),
            'avatarUrl' => $plugin->getAvatarUrl(),
            'config' => [
                ...$plugin->clientConfig(remember: false),
                'endpoint' => Filament::getCurrentPanel()->route('chatai.playground.store'),
                'playground' => [
                    'mode' => $this->mode,
                    'tool' => $this->tool,
                    'models' => AiModel::listed()->map(fn (AiModel $m): array => [
                        'id' => $m->id,
                        'driver' => $m->providerConnection?->driver ?? $m->provider,
                        'effort' => $m->effort,
                        'efforts' => filled($m->effort) ? LlmConfig::EFFORTS[$m->providerConnection?->driver ?? $m->provider] ?? [] : [],
                        'label' => $m->model.' — '.($m->providerConnection?->name ?? $m->provider).($m->enabled ? '' : ' ('.__('chatai::chatai.models.off').')'),
                    ])->values()->all(),
                ],
            ],
        ];
    }

    protected function codeTool(): ?ChatAiTool
    {
        return collect(ChatAiPlugin::get()->resolveTools()->catalog(auth()->user(), new ChatAiContext(Filament::getCurrentPanel()->getId())))
            ->firstWhere('name', $this->tool);
    }
}
