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

## Query count: no N+1, and the page-wide costs are paid once

A page's number of queries must not grow with the data it lists. Measured on the demo data, pages ran
10–30 queries, most of them one lazy query per row. What keeps them flat:

- **A list or record that callers walk comes with what they read.** Fetch-join the *to-one* associations
  (and at most one collection) in the repository method itself — `SanctionRepository::findById()` brings
  student, group+course, author, year and the tasks with their teacher; `findEligibleReports()` brings
  behaviours, category and author. Joining several collections into one query multiplies rows: for the
  second collection run a separate preloading query that hydrates the already-managed entities
  (`SanctionRepository::preloadReportDetails()`, `SchoolEventRepository::preloadGroups()` — the latter
  can't join in the list query because the "visible" variant filters on that very join and would leave
  half-filled collections).
- **Don't loop over a repository call per row.** The catalogue indexes (behaviours, measures, locations)
  load all items of the centre once (`findByCentreOrdered()`) and group them in PHP; the weekly time-slot
  grid loads the week with one query instead of one per weekday.
- **`EXTRA_LAZY` + `contains()`/`isEmpty()` per iteration is one query each.** Initialise the collection
  once (iterate it, or `toArray()`), build an id set, and test against that.
- **Page-wide flags are resolved once.** `TeacherYearRoles` answers "tutors / teaches / guards / belongs to
  the year" with one query (`TeacherRepository::findYearRoles()`) and remembers it. A memo on a service must
  implement `ResetInterface` (the app may run in a long-lived worker) and be dropped by a Doctrine
  `postFlush` listener, because a write can change the answer. `EducationalCentreRepository::
  findAccessibleByTeacher()` runs on every request (menu) and is a single query with `EXISTS` subqueries.
- **Tenant resolution:** `TenantContext::getSelectedCentre()` must not `refresh()` a centre it has just
  loaded — decide that *before* the query (`tryGetById()`), since afterwards it is always in the identity map.

To measure, use `ControllerTestCase::countQueries($url)` (profiler's query count with a cleared identity
map) and assert the count with N items is `<=` the count with one — see
`SanctionControllerTest::testShowQueryCountDoesNotGrowWithTheNumberOfTasksAndReports`. To audit the whole
app, boot the test kernel against a seeded SQLite file DB, crawl the GET links as several roles and list,
for each page, the SQL statements that repeat — those are the N+1.
