# Instructions for code agents

This file orients any code agent (Claude Code, Codex, Cursor, Copilot, or other)
working on **GestConv+**, a multi-tenant Symfony 8.1 / PHP 8.4+ web application for
managing student conduct and discipline across educational centres (incident
reports, sanctions, teacher absences, duty coverage, calendar).

**Before making any change, read [`skills/README.md`](skills/README.md)** and the
`skills/` file for the area you're about to touch (database, backend services,
tests, frontend, translations, commits/release, or manual/cheatsheet
screenshots). Those files document project conventions and several
already-debugged gotchas that aren't derivable just by reading the code —
repeating them costs real time.

Quick summary (full detail in `skills/overview.md`):

- Backend: Symfony 8.1, Doctrine ORM (PostgreSQL/MySQL/SQLite), PHPStan level `max`.
- Frontend: Symfony UX (Live Components, Stimulus), Tailwind, Asset Mapper — no external bundler.
- Multi-tenant: nearly all domain data hangs off `EducationalCentre` → `AcademicYear`; when
  writing a new query, explicitly decide whether it should filter by academic year (see
  `skills/database.md`). Entity PKs are `Uuid` v7, not auto-incrementing integers.
- Repositories: a project-specific PHPStan rule forbids `$em->find()`/`getRepository()` outside
  `App\Repository\` — always inject the concrete repository and add a named method instead of an
  ad-hoc query (see `skills/database.md`).
- Verification before considering a task done:
  ```bash
  php vendor/bin/phpstan analyse --memory-limit=1G
  php bin/phpunit   # or: make test
  ```
- Commit message format and allowed scopes: `CONTRIBUTING.md` (summarized in `skills/release.md`).
- The application's UI and commit messages are in **Spanish** (the project's
  target users and contributors) — this is deliberate, not something to "fix".

## Cross-cutting guardrails

These apply no matter which area you're touching, so they're listed here instead of buried in a
single `skills/` file:

- **Never publish a release on your own initiative.** Committing a fix does not imply cutting a
  version: don't touch the CHANGELOG version section, `app.version`/`app.pub_date`, run `make
  bump-readme`, or create/push a `vX.Y.Z` tag unless the user explicitly asks to publish (e.g.
  "publica", "saca una versión") — see `skills/release.md`. A pushed tag triggers the public
  build/release workflow and is hard to undo cleanly.
- **Before browser-based verification** of a UI/frontend change (disposable DB + Playwright, per
  `skills/screenshots.md`-style workflows), ask the user whether they've already checked it
  themselves — spinning up that cycle is comparatively expensive, and the user may be following
  along live and about to try it anyway.
- Don't hardcode translatable content (month/day names, labels…) in a PHP service — check
  `translations/*.yaml` first; if a deployment in another language or region would reasonably need
  to show it differently, it belongs in `translations/`, not in code (see `skills/i18n.md`).
- Use `ClockInterface`/`Symfony\Component\Clock\now()` for "now"/"today", never `new
  \DateTimeImmutable()`/`('today')` — see `skills/testing.md`. Still fine to use
  `\DateTimeImmutable` to parse a user-supplied date.

Other reference files in the repo root: `CONTRIBUTING.md` (commits, issues),
`CHANGELOG.md` (change history), `DEMO.md` (demo data), `docs/manual/` (end-user
documentation, not development documentation).
