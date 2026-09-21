# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

GestConv+ is a **multi-tenant Symfony 8.1 / PHP 8.4+** web application for managing student conduct (incident reports, sanctions, teacher absences, duty coverage, calendar) at Spanish secondary schools. UI text, translation files, commit messages, and comments are in **Spanish**. The application supports PostgreSQL, MySQL/MariaDB, and SQLite.

## Before making any change

Read [`AGENTS.md`](AGENTS.md) first — it's the canonical, tool-agnostic orientation doc for this repo (architecture, domain glossary, cross-cutting guardrails) — and then the `skills/` file for the area you're about to touch (`skills/README.md` indexes them: database, backend services, testing, frontend, i18n, release/docs, screenshots). They document conventions and already-debugged gotchas that aren't derivable from reading the code alone; repeating them costs real time. This file only adds Claude-Code-specific shortcuts on top of AGENTS.md — it is not a second copy of the architecture notes, so if something here and `AGENTS.md`/`skills/` disagree, treat `AGENTS.md`/`skills/` as authoritative and fix this file.

## Common commands

```bash
# Tests and static analysis
php bin/phpunit                                            # full test suite
php bin/phpunit tests/Integration/Controller/FooTest.php  # single test file
php vendor/bin/phpstan analyse --memory-limit=1G          # level max — always pass --memory-limit, the default 128M crashes it

# Database
make migrate    # doctrine:migrations:migrate
make setup      # app:setup (creates admin user + default settings)
make fixtures   # doctrine:fixtures:load --append (the --append matters, see skills/database.md)

# Development server (requires .env.local + Symfony CLI + Docker for DB)
make dev        # starts Docker DB + Symfony dev server
make dev-stop   # docker compose down

# Documentation (always via these targets, never mkdocs/marp/pandoc directly — see skills/release.md)
make docs-web    # MkDocs → docs/manual-site/
make docs-pdf    # pandoc → docs/manual/gestconv-plus-manual.pdf
make docs-serve  # local preview at http://127.0.0.1:8000
make bump-readme # sync version badge in README.md after changing app.version
```

## Verification before considering any task done

```bash
php vendor/bin/phpstan analyse --memory-limit=1G
php bin/phpunit
```

## Guardrails that apply regardless of task

- **Never publish a release on your own initiative.** After a fix, commit it and stop — don't touch the CHANGELOG version section, `app.version`/`app.pub_date`, `make bump-readme`, or create/push a `vX.Y.Z` tag unless the user explicitly asks to publish/release (see `skills/release.md`).
- **Before browser-based verification** (disposable DB + Playwright, etc.) for a UI/frontend change, ask the user whether they've already checked it themselves — it's expensive to set up and they may be following along live.
- Don't hardcode translatable content (month/day names, labels…) in PHP — check `translations/*.yaml` first (see `skills/i18n.md`).
- Use `ClockInterface`/`Symfony\Component\Clock\now()`, never `new \DateTimeImmutable()`/`('today')`, to represent "now" (see `skills/testing.md`).
