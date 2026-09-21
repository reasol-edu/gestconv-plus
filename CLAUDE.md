# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

GestConv+ is a **multi-tenant Symfony 8.1 / PHP 8.4+** web application for managing student conduct (incident reports, sanctions, teacher absences, duty coverage, calendar) at Spanish secondary schools. UI text, translation files, commit messages, and comments are in **Spanish**. The application supports PostgreSQL, MySQL/MariaDB, and SQLite.

**Before making any change, read `AGENTS.md` and the relevant `skills/` file for the area being touched** — they document conventions and already-debugged gotchas that are not derivable from reading the code alone.

## Common commands

```bash
# Tests and static analysis
php bin/phpunit                                            # full test suite
php bin/phpunit tests/Integration/Controller/FooTest.php  # single test file
php vendor/bin/phpstan analyse --memory-limit=1G          # level max

# Database
make migrate    # doctrine:migrations:migrate
make setup      # app:setup (creates admin user + default settings)
make fixtures   # doctrine:fixtures:load --append

# Development server (requires .env.local + Symfony CLI + Docker for DB)
make dev        # starts Docker DB + Symfony dev server
make dev-stop   # docker compose down

# Documentation
make docs-web    # MkDocs → docs/manual-site/
make docs-pdf    # pandoc → docs/manual/gestconv-plus-manual.pdf
make docs-serve  # local preview at http://127.0.0.1:8000
make bump-readme # sync version badge in README.md after changing app.version
```

Verification before considering any task done:
```bash
php vendor/bin/phpstan analyse --memory-limit=1G
php bin/phpunit
```

## Architecture

### Multi-tenant model

`App\Service\TenantContext` (`src/Service/TenantContext.php`) resolves the **currently selected educational centre** from session per request. It is the authoritative source for both the active centre and the **view year** (which can differ from the centre's current academic year — a "historical lookup" mode). Inject `TenantContextInterface`, not the concrete class.

The `#[CurrentCentre]` attribute (`src/Attribute/`) on a controller argument triggers `CurrentCentreResolver` (`src/ValueResolver/`) to inject the current `EducationalCentre`; it throws `NoCentreSelectedException` when no centre is in session.

Nearly all domain data hangs off `EducationalCentre → AcademicYear`. When writing a query, decide explicitly whether it should filter by academic year. Repository methods accept `?AcademicYear $year = null` as a trailing optional parameter for this; cross-year views (e.g., student history) omit it deliberately.

Entity PKs are `Uuid` v7, not auto-incrementing integers.

### Repository pattern (enforced by PHPStan)

`App\PHPStan\Rules\ForbidGenericDoctrineMethodsRule` forbids calling `$em->find()`, `$em->getRepository()`, or `$registry->getRepository()` from outside `App\Repository\`. **Always inject the concrete typed repository** and access data through its named methods.

### Migrations: three platforms, one timestamp

Every schema change requires **three migration files** with the same timestamp:

```
migrations/postgresql/VersionYYYYMMDDNNNNNN.php
migrations/mysql/VersionYYYYMMDDNNNNNN.php
migrations/sqlite/VersionYYYYMMDDNNNNNN.php
```

Each must include a platform guard (`abortIf(!... instanceof PostgreSQLPlatform, ...)`) and a complete `down()`. `MIGRATIONS_PATH` env var selects which folder Doctrine uses.

### Frontend: Asset Mapper, no bundler

Tailwind CSS is compiled by `symfonycasts/tailwind-bundle`. JS uses Stimulus controllers (`assets/controllers/`) and Symfony UX Live Components (`src/Twig/Components/`). There is no webpack/Vite — everything goes through native Asset Mapper. See `skills/frontend.md` for conventions (icons, Tom Select, Live Components).
