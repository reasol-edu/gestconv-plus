# GestConv+ — manual and cheatsheet screenshots

*Read this file before capturing screenshots for the manual (`docs/manual/img/`)
or cheatsheets (`docs/cheatsheets/img/`), or before writing/editing a
`scripts/capture-*.mjs` script.*

## Always use a disposable database, never the real dev DB

`doctrine:fixtures:load` calls `wipeDatabase()` unconditionally, and
`.env.local`'s `DATABASE_URL` points at the real dev database by default (real
school data — dozens of teachers, hundreds of students). Create a separate
disposable database (e.g. on the same local Postgres container, a throwaway
name), point a scripted `DATABASE_URL` override at it, migrate, and load
fixtures against *that* — never against the default connection. Drop the
database and stop the temp server when done, unless the user has explicitly
asked to leave a disposable environment running across sessions for repeated
reuse (confirm it's still alive before reusing it — it may have been torn down
or the machine restarted since).

## Gotcha: the PHP built-in server silently ignores a `DATABASE_URL` override

`php -S` inherits shell env vars into `getenv()`, but PHP's default
`variables_order` (`GPCS`, no `E`) leaves `$_ENV` empty — and Symfony's Dotenv
reads `$_ENV`/`$_SERVER`, not `getenv()`, so it falls back to `.env.local`'s real
`DATABASE_URL` even though the override was exported correctly. **Always launch
with `-d variables_order=EGPCS`.** Don't trust `ps eww <pid>` showing the right
env var as proof the override took effect — verify against actual DB content
(e.g. a known seeded name/value) before trusting the app is pointed where
intended.

## Gotcha: pointing the built-in server straight at `index.php` breaks assets

`php -S host:port -t public public/index.php` routes **every** request — including
real static files — through the full app, because Symfony's bare `index.php`
doesn't check `is_file($path)` first. Every `.js`/`.css` asset then comes back
as `Content-Type: text/html`, silently breaking ESM imports ("Failed to load
module script") even with a 200 status — breaking every JS-dependent widget
(Tom Select, Quill, Stimulus), not just one page. Use a tiny router script
instead:

```php
<?php
$projectPublic = '/absolute/path/to/public';
$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = $projectPublic . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require $projectPublic . '/index.php';
```

Launch with `php -d variables_order=EGPCS -S 127.0.0.1:PORT -t public /path/to/router.php`.

## Fixtures gotchas

- **Reload with `--append`**: `doctrine:fixtures:load` (the bundle itself, before
  `AppFixtures::load()` even runs) purges every Doctrine-mapped table, including
  `setting_definition` — which `AppFixtures`'s own scoped `wipeDatabase()`
  deliberately excludes. Without `--append` you get a "Division by zero" 500
  (pagination code dividing by the now-missing `page.size` setting) that's easy
  to misdiagnose as an app bug. Always run
  `doctrine:fixtures:load --no-interaction --append` on a disposable DB.
- **`setting_definition` still needs seeding after migrate+fixtures**: that table
  is only populated by specific data migrations, not by `AppFixtures`. If a
  fresh disposable DB shows 0 rows there, re-run the relevant `INSERT`
  statements from those migration files directly.
- **`AppFixtures` creates teachers/students/groups/programmes but no
  `IncidentReport`/`Sanction`/behavior catalog rows** — behavior/measure/
  communication-method *catalogs* are auto-seeded per centre by
  `CentreProvisioner` as soon as the centre exists, so referencing them works,
  but there's no report/sanction data out of the box. For a screenshot that
  needs it, write a throwaway console command that looks up existing
  behaviors/students/groups and persists a handful of records — delete the
  command before committing.
- A leftover `public/assets/manifest.json` from a prior `asset-map:compile`
  freezes AssetMapper in "compiled" mode even in dev — a newly added Stimulus
  controller can be correctly detected by every introspection tool
  (`debug:asset-map`, a `Finder` scan) yet still never actually load in the
  browser. `rm -rf public/assets` before verifying any new/changed frontend
  asset in a disposable-server session — it's gitignored and regenerates on
  demand.

## Browser automation, not `curl`

The login form's CSRF token is a JS-injected placeholder
(`<input name="_csrf_token" value="csrf-token">` literally) substituted
client-side by a Stimulus controller before submit — `curl`/plain HTTP clients
never run that JS and always get rejected (silent 302 back to `/login`). Any
scripted verification needing an authenticated session must drive a real
browser (Playwright, already a real devDependency with
`scripts/capture-*.mjs`), not `curl` with a scraped token. GET-only routes that
don't need CSRF (PDF/XLSX export links) can still be fetched efficiently via
`page.context().request.get(url)`, reusing the browser session's cookies. Note
`/seleccion/centro` renders each centre as a `<button type="submit">` inside a
per-centre POST form, not an `<a>` — target it with `button:has-text(...)`, not
an `a:` locator.

## Hiding the web-debug toolbar

Inject a style tag before every screenshot targeting the toolbar's per-request
DOM id (`sfwdt{token}`, varies each request — use a prefix selector):

```js
page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' })
```

Don't disable the toolbar in `config/packages/web_profiler.yaml` — that would
degrade the real dev experience for everyone, not just screenshots.

## "Modo tablón" traps the whole browser session

Once a Playwright page navigates to `/calendario/tablon`, the app redirects
**any** later navigation in that same session back to the tablón (documented
behavior). Capture it **last**, from a dedicated `browser.newPage()`/context
that's never reused afterward — otherwise every subsequent `page.goto()`
silently re-renders the tablón instead of the intended page, and steps waiting
on page content time out confusingly.

## `/admin/centros/*` requires `ROLE_ADMIN`, not just centre-admin

`security.yaml`'s `access_control` gates the whole `^/admin` path on
`ROLE_ADMIN` at the firewall level — a centre admin (in
`EducationalCentre::getAdmins()` but `isAdmin() === false`) gets a 403 on pages
the manual documents as centre-admin-configurable, regardless of what the
controller's own voter would allow. Log in as the global `admin` account for
these screenshots.

## Image sizing for the manual PDF

Capture listings/tables at viewport height (1280×900), **not** `fullPage: true`
— images 1600–2200px tall descuadran la maquetación de `pagedjs` (the manual's
PDF layout engine); the sidebar nav is only 900px, so anything taller is
repetitive table rows with no value. `fullPage: true` is only justified for long
forms where seeing every field matters (e.g. an edit/creation form). If a
capture already came out too tall, crop it anchored at the top with PIL
(`im.crop((0, 0, 1280, 900))`) — **not** `sips --cropOffset`, which doesn't
anchor top reliably on this project's images. Before finalizing a batch, check
heights (`sips -g pixelHeight docs/manual/img/**/*.png`) and treat anything over
~1000px on a listing as suspect.
