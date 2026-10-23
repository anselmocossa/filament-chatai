# Changelog

All notable changes to `filament-chatai` are documented in this file, following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The project uses [Semantic Versioning](https://semver.org/).

## Unreleased

### Added
- Interactive clarification questions in full-page, floating and tool editor chat: a compact Codex-style card fixed above the message composer with numbered options and an inline answer, one question per step with Back/Next and a progress indicator, selectable options, free-text answers and one submission that continues the conversation. The assistant pauses for answers; the original form keeps its answered summary after reload. Answers are validated against the saved conversation and cannot be submitted twice.

## [1.3.2] - 2026-10-23

### Security
- `describe_data` listed every resource of the panel and described the columns and relations of any of them, whatever the user's permissions. The data itself was never reachable — `query_data` and the resource tools check `canViewAny()` — but the shape of it was: a user who may only see job posts could read the column names of candidates, their attachments and their scores. It now lists only what the user may open, and refuses to describe anything else.

## [1.3.1] - 2026-10-23

### Fixed
- The picture on a record sheet was small enough to read as an icon next to the name; it is now the size of an avatar in a Filament list, and sits at the top of the heading.

## [1.3.0] - 2026-10-23

### Added
- **The model names each conversation**, from the first exchange, in a few words, instead of the history showing the question cut at sixty characters. One short call with no tools, right before the answer ends, so the browser gets the title with it; if it fails, is stopped, or comes from the tool editor's test chat, the conversation keeps the title it had. Switch: **Settings** / `chatai.conversation_title`.

### Fixed
- **Reading the screen missed two whole kinds of page.** A view page shows an infolist, not a form, so "summarise this record" on an open record answered that it could not see anything; and a table with a content layout draws blocks instead of rows, with no `<table>` at all, so its records were invisible too. Both are now read, beside the form fields and the table rows.

## [1.2.0] - 2026-10-23

### Added
- **Record sheets** (`records`), a component for lists the user has to read rather than scan: one full-width sheet per record, with the heading and its fields side by side, up to three badges, and up to four sections holding a paragraph (`text`) or a list (`items`) — a summary, a work history, an education. Cards stay for galleries; a sheet is for when a card is too small to tell two records apart. Max 10 per component.

### Fixed
- `show_ui` called without any component — typically `component` (an object) instead of `components` (a list) — was answered with «Shown to the user». The model believed it had drawn something, said nothing more, and the user was left with an empty answer. It is now an error that tells the model the expected shape, so it calls again. Smaller models hit this often.

### Security
- A security policy in [SECURITY.md](SECURITY.md): private advisory reporting, what counts as a vulnerability in an assistant that calls tools, and what to expect after a report.
- The actions in the test workflow are pinned to a commit SHA, so a moved tag cannot change what runs in CI. Dependabot now watches Composer and the actions weekly and holds a release back for a few days before proposing it.

## [1.1.0] - 2026-10-20

### Fixed
- A totals tool filtered by a name that matches several related records (three employees called «Murazik») added them all up as one; it now lists the candidates so the assistant asks which one. An exact name wins over names that merely contain it.
- A choice field (such as a list filter) given a list of values by the model received the first one instead of failing with «Array to string conversion».

### Added
- **The assistant improves itself**: managers can ask it for something no tool answers yet. It reads the panel's data (`describe_data`) and proposes a new list or totals tool (`create_tool`) or a skill (`create_skill`). Each proposal is a confirmation card; a tool must answer a test call before it is saved, and it works from the next question. Never code. Switch: **Settings → Permissions** / `chatai.builder.enabled`.
  When a manager asks something no tool answers, the assistant now proposes the tool right away instead of saying it cannot, and once it is confirmed the chat asks the question again so the answer comes straight away.
- **Reads any resource the user may see** (`query_data`): when no tool answers, the assistant filters and adds up a resource's records itself — e.g. one employee's billable timesheet hours — with the resource's own query, scopes and permissions; a related record is picked by name. Switch: **Settings → Permissions** / `chatai.query_data.enabled` (and the `UseChatAiTool:QueryData` permission when Shield permissions are on).
- **List filters and search on screen are applied**: asked to search or filter the list on screen («pesquisa por Camilla Schimmel»), the assistant types into the list's search box or sets Filament's filters and applies them (the list, its totals and the URL update); the card says «Filter applied to the list» instead of asking to press Save.
- **Tables page through every row**: a list with more rows than fit (e.g. 98 employees) gets ‹ › page buttons and «Page 1 of 2 · 98 rows». Each page is fetched from the list tool again, with the user's permissions; the model no longer copies rows. List tools take a `page` argument.
- **Totals for one person or value**: tools made on screen of the «Totals for a period» kind take `filters`: a column, or `rel:<relation>` to filter by a related record's name (e.g. the total timesheet of one employee).

## [1.0.0] - 2026-10-20

First stable release.

### Added
- **Install without code**: `php artisan chatai:install` publishes and runs the migrations, publishes the assets, adds the plugin to the chosen panels (`--panel`) and saves who manages the assistant (`--manager`). It can run again safely and also works without questions (`--no-interaction`).
- **Who may use it and who manages it**, set in **Settings → Permissions** or with `CHATAI_USERS` / `CHATAI_MANAGERS`. Each entry is an e-mail, a user id or a role name. Shield's super admin always manages, and a manager cannot remove themselves. `canAccess()` and `canManageSettings()` in code still win.
- **Filament Launchpad tiles** (with `anselmocossa/filament-launchpad` 1.16+): live KPIs for the user's questions, changes waiting for confirmation and this month's cost (managers only), plus one tile per starter question that opens the chat and asks it. A `?ask=` link opens a new conversation with that question, sent once. Switch it off with `chatai.launchpad.enabled`.
- **Pictures**: tools can name a record's picture column or Spatie Media Library collection, so tables get thumbnails and answers can show record cards with action buttons. An image attached in the chat can be saved onto a record, after confirmation.
- **Maps**: figures per country (ISO codes) or per place on a Leaflet map, loaded only when shown, with tile server and script URLs set in `chatai.maps`.
- **Drill-down**: a click on a chart bar or slice, or the «Ask» on a map point, asks the assistant about it.
- **Chat surfaces**: a floating chat on every page, aware of the record on screen, and a full-page chat with history. The history has pinned conversations, groups (folders) and renaming in place.
- **Generative UI**: KPIs, sortable tables, Filament-styled charts and follow-up suggestions. Every component is validated on the server before it reaches the browser.
- **Tools**: any `laravel/mcp` tool. Tools can also be made on screen without code: list records, totals for a period, create a record, change a record, or call an allowed API. Each one is tested in a chat next to its editor.
- **Changes with confirmation**: tools that change data show a form prefilled by the model and run only after **Confirm**. Many changes come as one paginated list with a single approval.
- **The screen**: the assistant reads the page the user is on, fills in the open form (every step of a wizard) without ever saving, and opens pages and records the user may open. While it works, the chat shrinks to a bar on the chat button.
- **Answer controls**: stop, copy, answer again, edit the last question, and 👍 / 👎 with a reason, shown in Logs.
- **Starter questions** for the page on screen and set by the admin. **⌘/Ctrl + J** opens the chat.
- **Memory**: the assistant keeps what each user asks it to remember. Users see and delete their own memories.
- **Files**: attachments, links to records of the panel, and downloadable CSV, Excel, Markdown, HTML, text or PDF files.
- **Voice**: dictation and reading aloud with the browser's voice, Azure or Google, with the voices users may pick.
- **Control**: several providers with encrypted keys and monthly and daily spending limits, models with fallbacks, usage and cost per user and model, logs of every question, Filament Shield permissions per tool, and rate limits.
- **Providers**: any OpenAI-compatible endpoint (OpenAI, OpenRouter, Ollama) or Claude natively through `anthropic-ai/sdk`, with prompt caching.
- English and Portuguese (pt_PT) translations.

[1.1.0]: https://github.com/anselmocossa/filament-chatai/releases/tag/v1.1.0
[1.0.0]: https://github.com/anselmocossa/filament-chatai/releases/tag/v1.0.0
