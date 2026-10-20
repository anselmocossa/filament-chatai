# Changelog

All notable changes to `filament-chatai` are documented in this file, following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The project uses [Semantic Versioning](https://semver.org/).

## Unreleased

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
