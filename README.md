# Filament ChatAI

An AI assistant for Filament v5 panels:

- **Floating chat** in the corner of every page, aware of the record on screen.
- **Full-page chat** (`/{panel}/chatai`) with conversation history — the same conversation as the floating one.
- **Generative UI**: the model answers with KPIs, sortable tables, Filament-styled charts and follow-up suggestions, not just text.
- **Your tools**: plug in existing `laravel/mcp` tools as they are. Read-only tools only, for now.
- **Voice and export**: dictate with the microphone, hear any answer read aloud (the browser's own speech engine, no extra service) and export a conversation to Markdown, tables included.
- **Providers**: any OpenAI-compatible endpoint (OpenAI, OpenRouter, Ollama) or Claude natively through `anthropic-ai/sdk`.

## Install

```bash
composer require anselmokossa/filament-chatai
php artisan vendor:publish --tag=chatai-migrations && php artisan migrate
php artisan filament:assets
```

```php
use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Tools\LaravelMcpToolProvider;

$panel->plugins([
    ChatAiPlugin::make()
        ->label('Assistant')
        ->tools(fn () => new LaravelMcpToolProvider([
            \App\Mcp\Tools\SalesStatsTool::class,
            \App\Mcp\Tools\LowStockTool::class,
        ]))
        ->canAccess(fn ($user) => $user->can('use-assistant')),
]);
```

## Settings and usage pages

Two admin pages, closed until you say who may open them (they hold API keys):

```php
ChatAiPlugin::make()->canManageSettings(fn ($user) => $user->is_admin)
```

- **Settings**: main and fallback provider, models, API keys (stored encrypted, never sent back to the
  browser), voice service and the voices users may pick, and your prices. Saved values override `.env`;
  values equal to `.env` are not stored, so later `.env` changes still apply. "Test model" sends one tiny request.
- **Usage**: estimated cost, questions, tokens (with the share served from prompt cache), read-aloud
  characters and dictation minutes, per day, user and model. Costs use the prices from Settings.

Claude requests use prompt caching automatically: system prompt, tools and history are billed at the
cache-read price on every tool round and follow-up question.

## Configure the model

```dotenv
# OpenAI-compatible (default)
CHATAI_PROVIDER=openai
CHATAI_BASE_URL=https://openrouter.ai/api/v1
CHATAI_API_KEY=...
CHATAI_MODEL=openai/gpt-4.1-mini

# or Claude (composer require anthropic-ai/sdk)
CHATAI_PROVIDER=anthropic
ANTHROPIC_API_KEY=...
CHATAI_ANTHROPIC_MODEL=claude-opus-5-5
CHATAI_ANTHROPIC_EFFORT=low   # medium/high think longer before answering
```

To choose per tenant at runtime: `->llm(fn () => new LlmConfig(...))`.

## Voice

Out of the box the chat uses the browser's own voice (free, but it sounds different on every machine).
For natural neural voices on every device, add a speech provider:

```dotenv
CHATAI_SPEECH_PROVIDER=azure          # or google
AZURE_SPEECH_KEY=...
AZURE_SPEECH_REGION=westeurope
CHATAI_SPEECH_VOICE=pt-PT-RaquelNeural  # pt-PT-DuarteNeural, pt-PT-FernandaNeural…

# GOOGLE_SPEECH_API_KEY=...            # voice e.g. pt-PT-Wavenet-A
```

Answers are read from the stored message (never free text) and each audio is generated once, kept on
`CHATAI_SPEECH_DISK` and reused. Dictation records in the browser, converts to 16 kHz WAV and is
transcribed by the same provider (up to 55 s per question). If the provider fails, the browser voice takes over.

Per panel or tenant: `->speech(fn () => new AzureSpeech($key, $region, 'pt-PT-DuarteNeural'))`.
Language: `CHATAI_SPEECH_LANGUAGE` (default `pt-PT`), or `->speechLanguage('pt-PT')`. Off: `->voice(false)`.

## How it works

`POST /{panel}/chatai/api/conversations/{id}/messages` streams server-sent events:
`delta`, `tool_start`, `tool_end`, `component`, `done`, `error`. The model renders UI by calling the
internal `show_ui` tool. Every component is validated server-side (`Ui\ComponentValidator`) before it reaches
the browser. The catalog lives in `Ui\Catalog`, and it is the contract any client (web, mobile) renders.

Each answered question fires `Filament\ChatAi\Events\TurnCompleted` (model, tokens, latency, tools used),
which you can use for usage logs or quotas.

## Test

```bash
composer test
```

Use `Filament\ChatAi\Testing\FakeLlmClient::script([...])` to script the model in your own tests.
