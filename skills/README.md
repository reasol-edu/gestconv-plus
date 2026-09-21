# GestConv+ skills for code agents

Orientation documentation for any code agent (Claude Code, Codex, Cursor, Copilot,
etc.) that's about to modify this repository. Read it **before** touching code, not
only when something breaks.

Each file covers one specific area; start with `overview.md`, then check the one
that matches the kind of change you're making:

| File | When to consult it |
|---|---|
| [`overview.md`](overview.md) | Always, when starting any task: architecture, stack, multi-tenant model, directory map |
| [`database.md`](database.md) | Before creating a migration, a repository, or any new query, or when touching Doctrine entities |
| [`backend.md`](backend.md) | Before touching a Security Voter, a Messenger message/handler, an EventSubscriber, `AppSettings`, or `PdfRenderer` |
| [`testing.md`](testing.md) | Before writing or debugging a test, or before considering a task done |
| [`frontend.md`](frontend.md) | Before touching a Twig template, a Stimulus controller, a Live Component, or adding/editing an icon |
| [`i18n.md`](i18n.md) | Before adding any user-facing text |
| [`release.md`](release.md) | Before a commit, updating CHANGELOG.md, touching `.github/workflows/build.yml`, publishing a version, or generating the manual/slides |
| [`screenshots.md`](screenshots.md) | Before capturing screenshots for the manual or cheatsheets (`docs/manual/img/`, `docs/cheatsheets/img/`) |

This documentation complements, not replaces, the code: if something here and the
code disagree, the code wins — update this file.
