<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Language model
    |--------------------------------------------------------------------------
    |
    | provider = "openai": any OpenAI-compatible /chat/completions endpoint with
    | tool calling and streaming (OpenAI, OpenRouter, a local Ollama).
    | provider = "anthropic": Claude through the official SDK
    | (composer require anthropic-ai/sdk) — see the "anthropic" block below.
    |
    | Values saved on the ChatAI settings page take precedence over these.
    | A panel can override all of this at runtime, e.g. per tenant, with
    | ChatAiPlugin::make()->llm(fn () => new LlmConfig(...)).
    |
    */

    'provider' => env('CHATAI_PROVIDER', 'openai'),

    // Used when the main provider is down: openai | anthropic | null (with its own block below).
    'fallback_provider' => env('CHATAI_FALLBACK_PROVIDER'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CHATAI_ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'fallback_models' => array_values(array_filter(explode(',', (string) env('CHATAI_ANTHROPIC_FALLBACK_MODELS', '')))),
        // low | medium | high | xhigh | max. Low keeps chat replies fast (first text ~1.5s vs ~10s at medium).
        'effort' => env('CHATAI_ANTHROPIC_EFFORT', 'low'),
        'base_url' => env('CHATAI_ANTHROPIC_BASE_URL'),
    ],

    'max_tokens' => (int) env('CHATAI_MAX_TOKENS', 16000),

    'base_url' => env('CHATAI_BASE_URL', 'https://openrouter.ai/api/v1'),

    'api_key' => env('CHATAI_API_KEY'),

    'model' => env('CHATAI_MODEL', 'openai/gpt-4.1-mini'),

    // Tried in order when the main model answers 429 or 5xx before streaming.
    'fallback_models' => array_values(array_filter(explode(',', (string) env('CHATAI_FALLBACK_MODELS', '')))),

    // Seconds to wait for the model (per request) and for each tool call.
    'timeout' => (int) env('CHATAI_TIMEOUT', 60),

    'tool_timeout' => (int) env('CHATAI_TOOL_TIMEOUT', 15),

    // Model → tool → model round trips allowed for one question.
    'max_iterations' => 6,

    // Tool results kept in the conversation history are cut to this size.
    'max_tool_result_bytes' => 20_000,

    // Shown when the chat is empty: questions set here, plus ones for the page on screen
    // (edit, create, list). The shortcut opens/closes the chat with ⌘/Ctrl + that key (null = off).
    'starters' => [],
    'starters_from_screen' => true,
    'shortcut' => 'j',

    // With anselmocossa/filament-launchpad: the assistant's tiles (questions, pending approvals,
    // cost, one "ask" tile per suggested question) appear in the launchpad's card library.
    'launchpad' => [
        'enabled' => true,
    ],

    // Memory: the assistant keeps what each user asks it to remember, across conversations.
    'memory' => [
        'enabled' => true,
    ],

    // Longest a single answer may take (several tools plus a long text), in seconds.
    'max_turn_seconds' => 300,

    // The screen: what the user is looking at (page heading, form fields and their values,
    // visible table rows) is sent with each question, and the assistant may fill in the
    // form on screen — the user reviews it and saves it themselves.
    'screen' => [
        'read' => true,
        'fill' => true,
        'navigate' => true, // open pages and records of the panel for the user
    ],

    // Tools that change data: the assistant proposes, the user confirms on a card, then it runs.
    // Off = read-only tools only.
    'actions' => [
        'enabled' => true,
    ],

    /*
    | Who may use the assistant and who may manage it (providers and keys, models,
    | tools, usage and costs). Each entry is a user's e-mail, a user id or a role
    | name (spatie/laravel-permission). No users = every user of the panel.
    | No managers = only Shield's super admin. Also set in Settings → Permissions;
    | ChatAiPlugin::make()->canAccess() / ->canManageSettings() in code win over both.
    */
    'access' => [
        'users' => array_values(array_filter(array_map('trim', explode(',', (string) env('CHATAI_USERS', ''))))),
        'managers' => array_values(array_filter(array_map('trim', explode(',', (string) env('CHATAI_MANAGERS', ''))))),
    ],

    /*
    | Filament Shield / spatie-permission: when enabled, each tool needs its own
    | permission (UseChatAiTool:SalesStats) — granted per role in Shield's
    | "Custom permissions" tab — and the chat can require UseChatAi.
    */
    'permissions' => [
        'enabled' => false,
        'require_chat' => false,
        'tool_prefix' => 'UseChatAiTool',
        'chat' => 'UseChatAi',
    ],

    // Name, icon (any Heroicon) and optional image of the assistant; set on the settings page.
    'assistant' => [
        'name' => null,
        'icon' => null,
        'avatar' => null,
        'disk' => 'public',
    ],

    // Attachments and generated documents (private disk; downloads check the conversation owner).
    'files' => [
        'disk' => env('CHATAI_FILES_DISK', 'local'),
        'max_kb' => 10240,
        'max_per_message' => 5,
        'max_text_chars' => 100_000, // text/CSV/Excel content passed to the model
    ],

    // Pictures of records in answers (tables, cards) and images saved onto records.
    'images' => [
        'enabled' => true,
        'disk' => env('CHATAI_IMAGES_DISK', 'public'), // where an image saved to a plain column goes
        'hosts' => [], // outside https hosts pictures may come from, e.g. ['cdn.mystore.co.mz']
    ],

    // Maps in answers (Leaflet, loaded only when a map is shown). Point these at
    // your own copies or another tile server if the panel must not call outside hosts.
    'maps' => [
        'enabled' => true,
        'script' => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        'script_integrity' => 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=',
        'style' => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
        'style_integrity' => 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=',
        'tiles' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution' => '&copy; OpenStreetMap contributors',
    ],

    // Extra rules for the assistant written by the admin (tone, business terms).
    'instructions' => null,

    // Hosts that tools made on screen may call (GET only), e.g. ['api.mystore.co.mz'].
    'http_tools' => [
        'allowed_hosts' => [],
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Voice
    |--------------------------------------------------------------------------
    |
    | Server voice (neural text-to-speech and dictation), same on every device.
    | Without a provider the chat uses the browser's own voice.
    |
    */
    'speech' => [
        'enabled' => true, // dictation and read-aloud buttons
        'provider' => env('CHATAI_SPEECH_PROVIDER'), // azure | google | null
        'language' => env('CHATAI_SPEECH_LANGUAGE', 'pt-PT'),
        'timeout' => 30,
        'disk' => env('CHATAI_SPEECH_DISK', 'local'), // generated audio is kept here and reused
        'azure' => [
            'key' => env('AZURE_SPEECH_KEY'),
            'region' => env('AZURE_SPEECH_REGION'),
            'voice' => env('CHATAI_SPEECH_VOICE', 'pt-PT-RaquelNeural'),
        ],
        'google' => [
            'api_key' => env('GOOGLE_SPEECH_API_KEY'),
            'voice' => env('CHATAI_SPEECH_VOICE', 'pt-PT-Wavenet-A'),
        ],
        'user_choice' => true, // users may pick their own voice in the chat
        'voices' => [], // server voices offered to users; empty = every voice of the provider (see VoiceCatalog)
        'per_minute' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prices (for the usage page)
    |--------------------------------------------------------------------------
    |
    | Your provider's prices, to estimate costs. Set them on the settings page.
    | models: [['model' => 'claude-opus-5-5', 'input' => 5, 'output' => 25]]
    | (per million tokens; a name ending in "/" matches every model with that prefix).
    |
    */
    'pricing' => [
        'currency' => 'USD',
        'models' => [],
        'speech_per_million_characters' => null,
        'transcription_per_hour' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'rate_limit' => [
        'per_minute' => 20,
        'per_day' => 200,
    ],

];
