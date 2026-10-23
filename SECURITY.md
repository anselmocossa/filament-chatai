# Security Policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 1.1.x   | Yes, fixes and security advisories |
| 1.0.x   | Security fixes only |
| < 1.0   | No |

Fixes land on the latest minor release. Please upgrade before reporting something you found on an older version.

## Reporting a vulnerability

Please do not open a public issue, discussion or pull request for a security problem.

Report it in one of two ways:

1. **GitHub private vulnerability reporting (preferred):** open
   [a draft advisory](https://github.com/anselmocossa/filament-chatai/security/advisories/new).
   Only the maintainer sees it, the fix is prepared in a private fork, and you are credited on the published advisory.
2. **E-mail:** anselmo.cossa@enh.co.mz, with `[filament-chatai]` in the subject.

Please include:

- the package version, plus the Filament, Laravel and PHP versions;
- what an attacker gains (reads another user's records, runs a tool they may not use, reads the provider API key, and so on);
- the shortest steps that reproduce it, or a small proof of concept;
- the configuration that matters: the provider, which tools are enabled, and whether Filament Shield permissions are on.

### What to expect

- **First reply:** within 5 working days.
- **Assessment and severity:** within 10 working days of that reply.
- **Fix:** a patch release for a confirmed high or critical issue as soon as it is ready, lower severities on the next minor release.
- **Credit:** your name on the advisory and in the changelog, unless you prefer to stay anonymous.

If you get no reply within 10 working days, please send the e-mail again; it is a single maintainer, not a team.

## Scope

This package puts a language model next to a Filament panel and lets it call tools, so the interesting boundary is what a user can make it do on their behalf.

**In scope:**

- Authorization bypass: data or a tool action that `canAccess()`, `canManageSettings()`, a resource's own query and scopes, or Shield permissions should have refused.
- Prompt injection, in a question or in record data the model reads, that leads to a real effect: a write tool running without its confirmation card, or records reaching the answer that the user may not see.
- Leaking the provider API key, another user's conversation, or settings values into the chat, the logs or the browser.
- SQL injection, stored XSS in the chat or in the generated UI (tables, cards, charts), SSRF through the maps tile server or the provider base URL, and path traversal in file or picture handling.
- Code or command execution through a tool or skill created on screen.

**Out of scope:**

- A wrong, invented or refused answer from the model. That is a bug, so please open an issue.
- Prompt injection that only changes what the model says, with no action taken and no data the user could not already read.
- Anything that requires the manager role: a manager is trusted by design and may create tools and change settings.
- Findings against an unsupported version, or that depend on a deliberately insecure configuration of the host application.
- Vulnerabilities in Filament, Laravel, or the model provider itself. Please report those upstream.

## Hardening the assistant in your own panel

Not vulnerabilities, but worth checking in production:

- Keep the provider API key in `.env`, never in `config/chatai.php` or in the database.
- Grant `chatai.builder.enabled` and `chatai.query_data.enabled` to the people who really need them. Both widen what the assistant can reach.
- Tools that change data should stay behind their confirmation card.
- Review the tools and skills created on screen the way you would review a migration.
