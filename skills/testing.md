# GestConv+ — tests and verification

*Read this file before writing or debugging any test, or before considering a task
that touches production code done.*

## Commands

```bash
php vendor/bin/phpstan analyse --memory-limit=1G   # max level over src/; must report 0 errors
php bin/phpunit                                     # or: make test
```

Run both before considering any task done. The project requires PHPStan level
`max` and the PHPUnit suite green **regardless of the day the tests happen to run
on** (see next section).

CI's `Tests` workflow runs `php bin/console tailwind:build` and `php bin/console
ux:icons:warm-cache` before `php bin/phpunit`. A missing/incorrectly-imported
icon (see `frontend.md`'s icon import workflow) or an invalid Tailwind class
typically fails **at this build/warm-cache step**, not inside the test suite
itself — if a PR's `Tests` workflow fails with no obvious PHPUnit assertion
failure, check the earlier CI steps before assuming it's a test flake.

`composer require`/`composer update`'s auto-scripts (`cache:clear`) can also
crash under the default 128M memory limit, the same way PHPStan does. If that
happens, re-run with `--no-scripts` and then clear the cache by hand with
`php -d memory_limit=1G bin/console cache:clear`. Watch out: a `composer
require` that failed mid-script can leave the new package's version constraint
as `"*"` in `composer.json` — re-run it with an explicit constraint (e.g.
`composer require vendor/pkg:^2.0 --no-scripts`) rather than leaving `"*"`.

## Use Clock, never `new \DateTimeImmutable()` for "now"/"today"

Explicit project preference: inject `Symfony\Component\Clock\ClockInterface` (the
`clock` service, autowireable) into container-managed classes, and use
`$this->clock->now()`. In entities (not instantiated by the container), use the
free function `Symfony\Component\Clock\now()`.

- **Don't** use `new \DateTimeImmutable()`, `new \DateTimeImmutable('today')`, or
  relative modifiers (`'+1 hour'`, etc.) to represent the current instant. It's
  still fine to use `\DateTimeImmutable` to **parse user-supplied dates** (forms,
  imports) — that's not "now", it's an input value.
- In tests, mock time with
  `Symfony\Component\Clock\Test\ClockSensitiveTrait::mockTime('2024-01-10')`
  instead of depending on the real execution date. Any test helper that computes
  "today" must read from the same mocked clock (`Clock::get()->now()`), not
  `new \DateTimeImmutable()` — otherwise the helper and the code under test
  diverge as soon as one respects the mock and the other doesn't (a real bug
  already fixed once in `CalendarControllerTest::weekdayInCurrentMonth()`).
- In Unit tests that instantiate a service manually (`new XxxService(...)` instead
  of via the container), pass `new \Symfony\Component\Clock\MockClock()` as a
  constructor argument — there's no DI container to resolve it for you.

## Test structure

- `tests/Integration/` — tests that boot the kernel (`ControllerTestCase`,
  `WebTestCase`), hit the real database via Doctrine, and cover
  Controller/Command/Component/EventSubscriber/MessageHandler/Repository/Security/Workflow.
- `tests/Unit/` — pure tests without a kernel, with manual stubs/mocks
  (Controller/Entity/Security/Service/Twig).

### `ControllerTestCase` (`tests/Integration/ControllerTestCase.php`)

Common base for integration tests:

- `setUp()` creates the client and **disables kernel reboot**
  (`$this->client->disableReboot()`) — necessary because with SQLite `:memory:`
  every kernel reboot opens a fresh connection (and an empty database); without
  this the schema created in `setUp()` wouldn't survive across requests within a
  single test.
- Creates the full schema with `SchemaTool::createSchema()` and drops it in
  `tearDown()` with `dropSchema()` — every test starts from a clean database. This
  is slow but deliberate: always use this base class for controller tests instead
  of building the schema by hand.
- Seeds default settings (`seedDefaultSettings()`) so that pages reading
  `AppSettingsInterface` don't fail for lack of a definition.

### Conventions for `*RepositoryTest`

Each file defines its own private helpers `makeWorld(string $suffix = '')`
(persists a centre + active academic year + programme + level + group + student
+ behavior category/behavior, returns an associative array of those) and
`makeTeacher(string $username, bool $admin = false)`. Role-visibility tests
(centre admin, committee member, counselor) follow the same shape every time:
`makeWorld()` → create and persist the teacher → add them to the role's
collection (`addAdmin`/`addCommitteeMember`/`addCounselor`) → `flush()` → assert
on the repository method's result.

### Conventions for `*ControllerTest extends ControllerTestCase` (Admin area)

`makeScenario()` typically creates a teacher with `setAdmin(true)` **and** adds
them as `$centre->addAdmin($cadmin)` — combining global admin and centre admin in
one test actor, so a single helper covers assertions that require either role.
Log in with `$this->loginAs($teacher)`. Always extract the CSRF token from the
already-rendered HTML (`$crawler->filter('[name="_token"]')->first()->attr('value')`),
never hardcode it.

## Known gotchas

- **`EXTRA_LAZY` + `contains()` after `$em->clear()`**: on a Doctrine `EXTRA_LAZY`
  collection, calling `contains()` after `$em->clear()` may not reflect the
  expected state, because the collection doesn't reinitialize the same way a
  regular collection does — if a test fails unintuitively after a `clear()`
  followed by a `contains()`, suspect this before assuming a business-logic bug.
- **Double `MessageEvent` (queued + sent) when testing the async mailer**: for
  any email routed through `async` (see `backend.md`'s Messenger routing note —
  password reset is the one exception, sent synchronously), `Mailer::send()`
  dispatches a "queued" `MessageEvent` before handing off to the bus, and a
  second real one fires when the message is actually processed —
  `getMailerMessages()`/`getMailerMessage($i)` return **both** per send, so
  literal indices land on `[queued1, sent1, queued2, sent2, ...]`, not
  `[sent1, sent2, ...]`. `assertEmailCount()` already filters correctly (it uses
  an `isQueued: false` constraint internally); for anything else, filter
  `getMailerEvents()` by `!$event->isQueued()` yourself before reading
  `->getMessage()` (see `IncidentEmailNotifierTest`'s private `sentMessage()`
  helper for the pattern).
- **Unit tests that instantiate services with `new`**: if you add a new parameter
  to a service's constructor (e.g. `ClockInterface $clock`), grep for every
  `new ServiceName(` under `tests/Unit/` — there's no DI to catch the mismatch for
  you, and PHPStan doesn't always flag it obviously; the failure shows up at
  PHPUnit runtime.
