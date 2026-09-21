# GestConv+ — backend services, security, and async

*Read this file before touching a Security Voter (`src/Security/Voter/`), a
Messenger message/handler (`src/Message/`, `src/MessageHandler/`), an
`EventSubscriber`, the settings-resolution service (`AppSettings`), or PDF
generation (`PdfRenderer`).*

## Settings resolution precedence (`AppSettings`)

`src/Service/AppSettings.php`'s resolution methods (`get`, `getForCentre`,
`getForTeacher`, `getForTeacherInCentre`, `getGlobal`, `getFileForCentre`, the
internal `load()`) each hand-implement the **same** precedence chain: a
**locked** value at a higher level (global > centre > teacher) always wins
outright over any lower level, and only when nothing relevant is locked does the
most specific *unlocked* value apply, falling back to the setting definition's
default. There's no shared helper enforcing this — any new resolution method
(a new scope, or a new value type alongside the existing file-valued settings)
must replicate this exact `match(true)` order. Copy an existing method rather
than improvising; a subtly different order silently breaks lock semantics.

## Security voters (`src/Security/Voter/`)

Every voter follows the same check order in `voteOnAttribute()`, by convention
only — there's no shared base class enforcing it, so copy an existing voter
(e.g. `SanctionVoter`) rather than writing the order from scratch:

1. Global admin (`$user->isAdmin()`) short-circuits to `true` first.
2. Centre-level admin/committee-member membership short-circuits next.
3. Only then does subject-specific logic run (ownership, tutoring, notification
   settings…).

Some attributes are **deliberately** hardcoded to always deny — e.g.
`SanctionVoter`'s `EDIT`/`DELETE` return `false` unconditionally because a
sanction becomes immutable once notified (only `EDIT_FOLLOWUP`'s narrow fields
stay editable). Don't "complete" a voter attribute that looks unimplemented
without first confirming the underlying business rule actually changed.

## Async email and Messenger routing

`config/packages/messenger.yaml` routes `SendEmailMessage` to the `async`
transport for every transactional email (report/sanction notifications,
tutorship, verification) **except password reset**, which is sent synchronously
outside the bus on purpose — the reset token is only valid 1h and can't wait on
a queue. `when@test` forces `SendEmailMessage` routing to `sync`. This is the
actual root cause behind the "double `MessageEvent` (queued + sent)" testing
gotcha in `testing.md` — cross-reference it there if you hit that again.

## Tenant context and admin/centre routes (`TenantContextSubscriber`)

`TenantContextSubscriber` skips the "must have a centre selected" redirect for
any route name starting with `app_admin` or `app_centre` (they resolve their
centre from the URL `{id}` path parameter via a value resolver, not session
state) plus a small `EXCLUDED_ROUTES` allowlist (login, centre selection,
profile). A teacher with access to exactly one centre gets it auto-selected
silently on first request; a session pointing at a since-deleted centre is
cleared rather than treated as an error. **When adding a new admin/centre-hub
controller, don't assume `TenantContext::getSelectedCentre()` is populated** —
it deliberately isn't for these routes.

## PDF generation (`PdfRenderer`)

`PdfRenderer::applyDocTemplate()` writes the centre's configured PDF background
template (stored as bytes via the generic file-storage pattern, see
`database.md`) to a temp file (`tempnam()`) before calling mPDF's
`SetDocTemplate()`, because mPDF's API needs a real filesystem path, not raw
bytes — and cleans it up in a `finally` block. Any new PDF feature that reuses
mPDF templates must follow the same temp-file-then-unlink pattern rather than
trying to pass content directly.
