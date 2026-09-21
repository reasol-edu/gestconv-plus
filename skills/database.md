# GestConv+ — database and repositories

*Read this file before creating a migration, a repository, or any new query, or
when adding/modifying Doctrine entities.*

## Migrations: three platforms, not one

The project supports PostgreSQL, MySQL/MariaDB, and SQLite. Migrations **don't**
live in a single folder: there are three, in parallel, with the same version
number:

```
migrations/mysql/VersionYYYYMMDDNNNNNN.php
migrations/postgresql/VersionYYYYMMDDNNNNNN.php
migrations/sqlite/VersionYYYYMMDDNNNNNN.php
```

`config/packages/doctrine_migrations.yaml` points to a single path via the
`MIGRATIONS_PATH` environment variable — Doctrine only sees and runs the
migrations for the active platform in each environment.

**When adding or modifying anything in the schema, write the migration in all
three folders**, with the same version timestamp and semantically equivalent
content — the concrete SQL differs per platform (e.g. `gen_random_uuid()` in
PostgreSQL, MySQL's own syntax, and SQLite's `ALTER TABLE`/`INTEGER PRIMARY KEY`
limitations).

Structure of each migration (see e.g. `migrations/postgresql/Version20260101000041.php`):

```php
final class VersionYYYYMMDDNNNNNN extends AbstractMigration
{
    public function getDescription(): string { /* in Spanish: explains what changed and why */ }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration can only run on PostgreSQL.'
        );
        $this->addSql(<<<'SQL' ... SQL);
    }

    public function down(Schema $schema): void
    {
        // same platform guard; undoes exactly what up() did
    }
}
```

The **platform guard** (`abortIf` against `PostgreSQLPlatform`/`MySQLPlatform`/
`SqlitePlatform` depending on the folder) is mandatory in every file — it prevents
a migration from the wrong folder from running by mistake if `MIGRATIONS_PATH`
were misconfigured.

Verify manually against a disposable database before considering a migration done
(there's no automated suite that runs them against all three platforms):

- SQLite: `DATABASE_URL="sqlite:////tmp/algo.sqlite" php bin/console doctrine:migrations:migrate`
  — note the **four** slashes for an absolute path (three fails with "unable to
  open database file"); `rm -f` the file afterward.
- PostgreSQL: no local `psql` is assumed — use `php bin/console doctrine:database:create`
  / `doctrine:database:drop --force` with a `DATABASE_URL` pointing at a disposable
  database name (same credentials as `.env.local`). Migrate **and** check `down()`
  before dropping it.

## Repositories: named methods only, never generic access

A project-specific PHPStan rule
(`src/PHPStan/Rules/ForbidGenericDoctrineMethodsRule.php`, level `max`)
**forbids** calling `find()`/`getRepository()` on `EntityManagerInterface` or
`getRepository()` on `ManagerRegistry` **outside of** classes under
`App\Repository\`. Static analysis fails if you do.

In practice:

- Never do `$this->em->find(Student::class, $id)` nor
  `$this->em->getRepository(Student::class)->...` from a controller, service, or
  handler.
- Inject the concrete repository (e.g. `StudentRepository`) and add an explicit,
  named method (`findByCentreAndId(...)`, `findByAcademicYearOrdered(...)`)
  instead of an ad-hoc query. This keeps all data-access logic — including the
  allowlisted sortable columns (`SORTABLE` constant in several repositories) and
  tenant filtering — centralized and reviewable in one place.
- Repositories extend `ServiceEntityRepository` with the standard constructor
  (`parent::__construct($registry, Entity::class)`).

## Academic year isolation

Pattern used in report, sanction, absence, etc. repositories: an **optional,
additive** `?AcademicYear $year = null` parameter at the end of the method
signature, which adds a `WHERE ... = :year` filter only when passed. This allows
reusing the same method both for views scoped to one year and for cross-year
historical queries.

`StudentController` **deliberately** omits `$year` in several of its queries: a
student's profile needs to show history across all years, not just the year being
viewed. Don't "fix" this by adding the filter without confirming the use case
requires it.

## Entities

- Primary key: `Uuid` (v7) via `symfony/uid`, not integers.
- For file-typed values attached to a setting/entity, use the generic,
  hash-deduplicated storage (a generic table + FK from the value row, keyed by
  SHA-256 hash) instead of creating a table specific to a single use case. This
  applies when a file could plausibly be referenced from more than one place
  (settings at multiple hierarchy levels, or a reused asset) — an attachment
  owned 1:1 by a single parent entity (e.g. `ActivityAttachment`,
  `SanctionTaskAttachment`) doesn't need it. On replace/delete, run the
  orphan-cleanup step so a file no longer referenced by any value row gets
  deleted rather than accumulating unbounded. See `backend.md` for how
  `AppSettings` resolves the effective value (including file-valued settings)
  across the global/centre/teacher hierarchy.
