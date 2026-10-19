<p align="center">
    <img src="art/banner.png" alt="Filament ChatAI" width="100%" class="filament-hidden">
</p>

<h1 align="center">Filament ChatAI</h1>

<p align="center">
    An AI assistant inside your Filament panel — it answers with KPIs, tables and charts from your own data.
</p>

<p align="center">
    <img src="https://img.shields.io/badge/Filament-v5-FDAE4B?style=flat-square" alt="Filament v5">
    <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2+">
    <img src="https://img.shields.io/badge/Laravel-11%20|%2012%20|%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 11, 12, 13">
    <img src="https://img.shields.io/badge/tests-176%20passing-brightgreen?style=flat-square" alt="Tests">
    <img src="https://img.shields.io/badge/license-MIT-blue?style=flat-square" alt="MIT License">
</p>

---

- **Floating chat** in the corner of every page, aware of the record on screen.
- **Full-page chat** (`/{panel}/chatai`) with a history you can organise: pinned conversations, groups, renaming — the same conversation as the floating one.
- **Generative UI**: the model answers with KPIs, sortable tables, Filament-styled charts and follow-up suggestions, not just text.
- **Your tools**: plug in existing `laravel/mcp` tools, or build tools on screen (Resource queries, HTTP APIs) and test them in a chat next to the form.
- **Changes with confirmation**: tools that change data never run straight away — the user gets a form prefilled by the model, or one paginated list for many changes, and approves once.
- **Sees the screen and acts on it**: answers about the page the user is on, fills in the open form (every step of a wizard), and opens pages and records — while it works the chat shrinks to a bar on the chat button. It never saves on its own.
- **Files**: attach documents, paste links to records of the panel, and get CSV, Excel or PDF files back.
- **Voice and export**: dictate, hear answers read aloud, export a conversation to Markdown.
- **Control**: models with fallbacks, several provider keys with spending limits, usage and cost per user and model, a log of every question, and Filament Shield permissions per tool.
- **Providers**: any OpenAI-compatible endpoint (OpenAI, OpenRouter, Ollama) or Claude natively through `anthropic-ai/sdk`.

## Screenshots

#### Full-page chat — KPIs and charts from your data

<img src="art/screenshots/full-page.png" alt="Full-page chat with KPIs and a chart" width="100%">

#### History — pin conversations, group them in folders, rename in place

<img src="art/screenshots/history.png" alt="History with pinned conversations and groups" width="280">

#### Floating chat — knows the record on screen

<img src="art/screenshots/floating.png" alt="Floating chat over a product edit page" width="100%">

#### Fills in the form on screen — the chat shrinks to a bar on the chat button while it works

<img src="art/screenshots/agent.png" alt="The assistant filling a form, minimised to a bar" width="100%">

#### …every step of a wizard in one go; the user reviews and saves

<img src="art/screenshots/wizard.png" alt="Wizard step filled in by the assistant" width="100%">

<img src="art/screenshots/screen-fill.png" alt="Fields filled in on the page and summary in the chat" width="100%">

#### Opens pages and records for the user

<img src="art/screenshots/navigate.png" alt="The assistant opening a product for editing" width="100%">

#### Changes ask first — a form prefilled by the model

<img src="art/screenshots/form.png" alt="A form in the chat to register a customer" width="100%">

#### …and many at once as one paginated list with a single approval

<img src="art/screenshots/batch.png" alt="Fifteen customers from a CSV, approved at once" width="100%">

#### After Confirm, the card shows what changed

<img src="art/screenshots/actions.png" alt="Confirmation card with before and after" width="100%">

#### Attach a file, get an Excel back

<img src="art/screenshots/files.png" alt="Attached CSV and generated Excel file" width="100%">

#### `@` picks a tool, `/` applies a skill

<img src="art/screenshots/mentions.png" alt="@ to pick a tool" width="60%">

#### Tools made on screen — no code

<img src="art/screenshots/tools.png" alt="Tools page with no-code tools" width="100%">

<img src="art/screenshots/nocode-editor.png" alt="Editor of a Totals for a period tool" width="60%">

#### Tool editor — test the tool by chatting before saving

<img src="art/screenshots/tool-editor.png" alt="Tool editor with a test chat" width="100%">

#### Usage — cost, tokens and cache, per user and per model

<img src="art/screenshots/usage.png" alt="Usage page" width="100%">

#### Models — the first enabled answers, the next take over

<img src="art/screenshots/models.png" alt="Models in fallback order" width="100%">

#### Logs — question, tools, model, tokens and time

<img src="art/screenshots/logs.png" alt="Logs of every question" width="100%">

#### Settings — assistant, screen, voice, permissions, files, limits, prices

<img src="art/screenshots/settings.png" alt="Settings tabs" width="100%">

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

## Set it up on screen

Everything is configured in the panel, under the **ChatAI** menu. No `.env` editing is needed.
The admin pages are closed until you say who may open them (they hold API keys):

```php
ChatAiPlugin::make()->canManageSettings(fn ($user) => $user->is_admin)
```

| Page | What you set |
|---|---|
| **Providers** | Each account/endpoint with its own key (encrypted): Anthropic, OpenRouter, OpenAI, a local Ollama… Monthly and daily **spending limits** per provider. |
| **Models** | The models in order: the first enabled one answers, the next ones take over if it fails (any number of providers). Effort and price per model. "Test" per model. |
| **Settings** | Assistant instructions and allowed API hosts · Voice (browser, Azure or Google, keys, voices users may pick) · Limits (messages per user, tool steps, timeout) · Currency and voice prices. |
| **Usage** | Estimated cost, questions, tokens (with the share served from prompt cache), voice, per day, user and model. |
| **Tools** | Adjust code tools (on/off, wording, profiles) and build new ones without code: query a panel resource, or call an allowed GET API. Full-page editor with a live test chat and the tool as JSON / cURL / laravel/mcp code. |
| **Skills** | Saved instructions for a kind of task, linked to the tools to use. |
| **Logs** | Every question: who, tools used, tokens, time and cost. |

Claude requests use prompt caching automatically: system prompt, tools and history are billed at the
cache-read price on every tool round and follow-up question.

## Tools made on screen (no code)

**Tools → New tool** builds a tool from a form, tried in a test chat next to it before saving. Five kinds:

| Kind | What it does | Example |
|---|---|---|
| **List records** | Rows of a resource: fields (also of related records, `customer.name`), a search over several columns, filters, fixed conditions (`status ≠ cancelled`, `qty < security_stock`), counts per value, totals of related records (orders, total spent) and their latest rows | orders, low stock, a customer card |
| **Totals for a period** | Count / sum / average… for today, the week, month, year or custom dates, compared with the previous period, broken down by day, month, a column or a related record — over the records or a relation of them | sales of the month, best sellers, headcount per department |
| **Create a record** | The chosen columns become the form; unique columns are checked | new customer |
| **Change a record** | Finds the record by one of the lookup columns; each column is set or added to, never below zero | adjust stock, change order status |
| **API call** | GET to an allowed host, with query/path parameters and a key | an external service |

They always run through the resource's own query (scopes, tenancy) and its permissions (`canViewAny`, `canCreate`, `canEdit`). Create and change tools go through the confirmation form below.

The demo's nine code tools were rebuilt this way and answered the same questions with the same figures.

## The screen: what the user sees, filling in its form, opening pages

- **Read the screen** — with each question the chat sends what the user is looking at: the page heading and breadcrumbs, every form field with its current value (and the shown name of a choice, not just its id) and the visible table rows. "What am I looking at?" or "is this price right?" are answered from the page itself.
- **Fill in forms on screen** — on an edit or create page the assistant can write values into the open form (`fill_form`), including **every step of a wizard or every tab in one go**, choices by the name the user reads ("Finance" → its id), checkbox lists and the format each field expects (colours as hex, dates as YYYY-MM-DD). While it works the chat **shrinks to a small bar on the chat button** (like ChatGPT's agent mode): it fills the fields one by one so the user can follow, shows the field and progress ("Skills · 10/14"), can be stopped, and ends with ✓ "14 fields — review and save"; a click reopens the chat. The fields it changed are highlighted and listed in the chat. **It never saves**: the user reviews and presses Save.
- **Open pages and records** — "take me to the orders", "open product X to edit", "I want to create a customer": the assistant opens a menu page, a resource's list or create page, or one record (view / edit). Only places of this panel, only what the user may open (`canViewAny`, `canCreate`, `canView`, `canEdit`); when several records match it asks which one. The floating chat stays open on the new page, same conversation.
- All three are switches in **Settings → Assistant**, or in code:

```php
ChatAiPlugin::make()
    ->readScreen()        // false: only the URL and the record id are sent
    ->fillForms(false)    // read the screen, but never touch the form
    ->navigate(false);    // never change the page
```

The server applies the same switches: with reading off, a snapshot sent by the browser is ignored; only fields that are on screen can be filled.

## Several changes at once

When one answer proposes several changes with the same tool — e.g. *"create these customers"* with a CSV attached — they come as **one list** to approve: paginated (10 per page), each row can be left out, one **Confirm all (n)** button. Rows are checked and run one by one, so a bad row is marked without stopping the others.

## Tools that change data

A `laravel/mcp` tool **without** `#[IsReadOnly]` changes data. The assistant never runs it straight away:

1. It calls the tool with the values it understood — even with some missing — and the app shows a **form** on the card, built from the tool's schema: one field per property (`title` as label, `required`, `enum` → select, `integer`/`number` → number, `boolean` → checkbox, `format: email|date` → email/date input), filled with what the model gave. The user completes or corrects it.
2. **Confirm** checks the values against the schema (missing or wrong ones are marked on the form), then runs it — with the user's permissions checked again at that moment — and the card shows what the tool returned. **Cancel** changes nothing.
3. Either way, the outcome goes into the conversation, so the model knows it in the next question.

```php
#[Name('adjust-stock')]
#[Title('Adjust stock')]
class AdjustStockTool extends Tool   // no #[IsReadOnly]: needs confirmation
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'product' => $schema->string()->title('Product')->required(),
            'quantity' => $schema->integer()->title('Units to add')->required(),
        ];
    }
    // handle() makes the change and returns what changed (e.g. before / after)
}
```

Switch it off in **Settings → Permissions** (read-only tools only), or keep a provider read-only with `new LaravelMcpToolProvider($tools, readOnlyOnly: true)`.

## Filament Shield

Turn on **Settings → Permissions** and each tool needs its own permission, named like Shield's own
(`UseChatAiTool:SalesStats`); using the chat can also require `UseChatAi`. They show up in Shield's roles
editor, **Custom permissions** tab (enable `shield_resource.tabs.custom_permissions`), so you tick per role
who may use what. A user without a tool's permission does not get that tool, so the assistant tells them it
has no access to that data. A tool can require an existing permission instead (e.g. `ViewAny:Order`).
Resource tools also follow the resource's policies, and Shield's super admin always passes.

## Answer controls

Every answer can be **copied**, **answered again**, and rated **👍 / 👎** (a 👎 can say what went wrong); the last question can be **edited** — the last exchange is replaced, not added to. **Stop** ends an answer mid-way: the server stops at its next safe point and keeps what was written. Ratings and comments show in **Logs**, with a filter, so whoever looks after the assistant sees what went wrong.

> Stop and long answers need the server to take more than one request at a time (php-fpm, Octane…). With `php artisan serve` use `PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload`. A turn may take up to `chatai.max_turn_seconds` (300 s).

## Files

- **Attach** with the paperclip, by dropping files on the box or pasting an image. Images and PDF go to the model as they are; text, CSV, JSON and Excel go as text.
- **Links** to records of this panel pasted in the question are recognised: the assistant is told which resource and record they point to.
- **Documents**: the `create_document` tool makes CSV, Excel, Markdown, HTML, text or PDF (PDF needs `dompdf/dompdf`). The user gets a download card; only the conversation owner can download.
- Where files are kept (`local`, `s3`, any disk in `config/filesystems.php`), the size limit and files per message are set in **Settings → Files**, or with `->filesDisk('s3')` on the plugin.

## Optional: `.env` defaults

Handy on servers or CI. A provider saved on screen without a key uses these; values saved on screen win.

```dotenv
ANTHROPIC_API_KEY=...                  # Claude (composer require anthropic-ai/sdk)
CHATAI_API_KEY=...                     # OpenAI-compatible
CHATAI_BASE_URL=https://openrouter.ai/api/v1

CHATAI_SPEECH_PROVIDER=azure           # or google
AZURE_SPEECH_KEY=...
AZURE_SPEECH_REGION=westeurope
```

On first visit, the Providers and Models pages import whatever is set here, so nothing is typed twice.
At runtime a panel or tenant can still override the model (`->llm(fn () => new LlmConfig(...))`) and the
voice (`->speech(fn () => new AzureSpeech(...))`).

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
