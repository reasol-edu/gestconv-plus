# GestConv+ — frontend

*Read this file before touching any Twig template, Stimulus controller
(`assets/controllers/`), Live Component, or adding/editing an SVG icon or any
asset.*

No external bundler: native Symfony **Asset Mapper**. Stimulus via
`assets/controllers/*.js` + `assets/controllers.json`. Tailwind via
`symfonycasts/tailwind-bundle`. Interactive components with **Symfony UX Live
Components** (`src/Twig/Components/`), not a separate SPA.

## Asset Mapper: the `public/assets/` cache can go stale

In environments where the app is already running (e.g. a disposable database and
server spun up to verify something), `public/assets/` may hold a **stale,
precompiled** build. After adding or editing any asset (a Stimulus controller,
JS, etc.), run:

```bash
php bin/console asset-map:compile --memory-limit=1G
```

If you see an error about a half-rebuilt cache (e.g. messages about static
analysis library classes "not found"), don't read it as a missing dependency —
it's likely exactly this caching issue.

## Autocomplete dropdowns (Tom Select / `symfony/ux-autocomplete`)

Always use `dropdownParent: "body"` in the Tom Select configuration — otherwise
the dropdown gets visually clipped inside cards with `overflow-hidden`. Search
requires a minimum of 2 characters. The autocompleters under `src/Autocomplete/`
(`TeacherAutocompleter`, `AbsenceTeacherAutocompleter`, `TeacherCentreAutocompleter`)
need a centre selected in session (`TenantContext`) to resolve meaningful results.

## Live Components: `checked` state on radios/checkboxes

On selection radios or checkboxes inside a Live Component, always use
`data-model` on the input. **Don't** rely on the
`data-action="live#action" data-live-id-param="..."` pattern alone to keep the
checked state in sync — it desyncs (the visual `checked` doesn't always reflect
the component's real state). `data-model` is the only reliable pattern here.

## Forms with `submit`: watch out for microtasks

If a `submit` event listener uses `queueMicrotask()`, that microtask can fire
**before** another listener on the same `submit` event gets a chance to run
(microtasks interleave between listeners, not just at the end of the event cycle).
If you need to guarantee your code runs after all `submit` listeners have
finished, use `setTimeout(0)`, not `queueMicrotask()`.

## Icons

SVGs under `assets/icons/heroicons/` are **not copied by hand**. Before committing
a new or modified icon, regenerate it with:

```bash
php bin/console ux:icons:import
```

Manually copying the SVG without going through this command produces
inconsistencies with the rest of the set (names, registered paths).

## Radio vs. checkbox for a binary scope choice

For a binary choice that has its own "name" on each side (e.g. general vs.
group-restricted event, as in `daily_note`), use a pair of radio cards (pattern
already present in the daily note form) rather than a plain checkbox — it reads
more clearly when both states carry explicit meaning, instead of one state simply
being "switch an option on".

## Mobile: buttons must be reachable and touch-sized

The app is used from phones (teachers registering partes, notes, exits…). For every new or changed screen, check it at **320, 390
and 768 px** — buttons are where it usually breaks:

- **Rows of action buttons** (page headers with PDF / Edit / Delete, list-row actions) must be allowed to wrap:
  `flex flex-wrap items-center gap-2`, and `sm:shrink-0` instead of a bare `shrink-0` (a non-wrapping `shrink-0` row pushes the
  buttons off the screen and the page scrolls sideways — it happened in the parte, student, absence and sanction detail pages).
- **List rows** (name on the left, buttons on the right): in narrow screens give the name the whole row and put the buttons
  below (`basis-full sm:flex-1 sm:basis-0` on the text, a `grid grid-flow-col auto-cols-fr gap-2 sm:flex` for the buttons), and
  let long names break with `break-words`.
- **Primary actions in a form/card footer**: full width on mobile (`w-full sm:w-auto`), stacked, with the dangerous one on top
  (`flex-col-reverse sm:flex-row`).
- **Tables** wider than the screen go inside `<div class="overflow-x-auto">`. A card with `overflow-hidden` and a bare table
  *clips* the last column (the action button) with no way to reach it (sanction tasks and absences lists did).
- **Selects/inputs next to icons** inside `shrink-0` groups need `min-w-0 max-w-full`, or they overflow at 320 px.
- **Don't expand things horizontally past the screen edge**: an absolutely positioned child sticking out a few pixels widens the
  whole page on mobile browsers.

Touch target size is handled globally in `assets/styles/app.css` (section «Objetivos táctiles», `@media (pointer: coarse)`):
buttons/links with compact padding (`py-1`…`py-2.5`) get `min-height: 2.5rem`; icon buttons and pills (`p-0.5/1/1.5`,
`py-0.5`, `h-6…h-9`, header sort buttons, icon-only buttons) get an invisible vertical `::after` hit area. So write compact
buttons normally; if a new kind of control still ends up under ~40 px on a phone, extend that section (don't hand-patch
templates). Never set `display` there (it would un-hide `hidden sm:inline-flex` buttons).

**Verify with the audit**, not by eye: `scripts/audit-touch-targets.mjs [width]` visits ~180 screens as admin/jefatura/teacher
in an emulated phone and reports page overflow, controls past the screen edge, and controls under 40 px grouped by class
signature (needs a disposable DB with the screenshot seed, see `skills/screenshots.md`). It must report 0 overflows and no
signatures at 320, 360, 390 and 768 px.

## Non-working days in date pickers

There's no date-picker library: everything is a native `<input type="date">`,
which doesn't allow disabling individual days in the browser's native dropdown.
The adopted pattern: when a non-working date is picked (weekend or a holiday
declared in `NonWorkingDay`), the Stimulus controller reverts to the last valid
value with a warning, and the server **always** validates authoritatively on
submit (via `NonWorkingDayChecker`) — never rely on client-side validation alone
for this. Shared logic lives in `assets/utils/non_working_days.js`
(`isNonWorkingDate`/`countSchoolDays`/`addSchoolDays`), consumed by
`non_working_day_controller.js` (generic) and `sanction_date_range_controller.js`
(school-day counter on sanction creation).
