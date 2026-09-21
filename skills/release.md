# GestConv+ — commits, CHANGELOG, documentation, and releases

*Read this file before writing a commit message, updating CHANGELOG.md, touching
`.github/workflows/build.yml`, or generating the manual/slides.*

## Commit format

Defined in `CONTRIBUTING.md` (read it in full before a non-trivial commit). Note
that commit descriptions themselves are written **in Spanish**, per the project's
convention — its contributors and audience are Spanish-speaking. Keep following
that convention even though this document is in English.

```
<type>[(<scope>)][!]: <short description in Spanish, lowercase start, ≤70 chars>

[optional body]

[Closes #N | Refs #N]
```

- Types: `feat`, `fix`, `chore`, `refactor`, `test`, `docs`, `perf`, `style`.
  `feat(model)` = expands what the system can represent (a new entity/field);
  `refactor(model)` = reorganizes what already exists without expanding capacity.
- `!` right after the type (and scope, if any) for backward-incompatible changes
  (migrations that alter existing columns, console command signature changes,
  manual steps required at deploy time).
- Scope is **not free text**: use one of the values already defined in
  `CONTRIBUTING.md`. Technical layers: `model`, `migrations`, `command`, `i18n`,
  `ui`, `a11y`, `adjuntos` (Spanish for "attachments" — the literal scope value to
  type), `assets`, `quality`, `release`, `dist`, `ci`, `deps`. Application domains:
  `incident`, `sanction`, `absence`, `guards`, `calendar`, `notification`,
  `dashboard`, `reports`, `student`, `tutorship`, `centre`, `admin`, `security`.
  Combine them with `/` when a change crosses dimensions (`centre/i18n`). If a
  change doesn't clearly fit any of them, omit the scope rather than forcing one.

## CHANGELOG.md

Keep a Changelog format. Section headers (`Added`, `Changed`, `Fixed`…) are in
**English**; the content of each entry is in **Spanish**, aimed at the end user,
without jargon. This repo does **not** keep a running `[Unreleased]` section:
a user-visible commit lands with no CHANGELOG entry of its own, and the entry is
added only as part of the release commit (see "Release process" below), inside a
new `## [X.Y.Z] - <date>` section added at the **top** of the file. Breaking (`!`)
commits need an entry under `Fixed` or `Changed` stating whether a manual step is
needed when upgrading. Purely internal changes (`ci`, `test`, `docs`, `refactor`
with no visible impact) **don't** get an entry.

## Documentation generation

Always use the `Makefile` targets, don't invoke pandoc/mkdocs/marp by hand — direct
invocation fails or silently skips substitutions the targets otherwise handle (e.g. a bare
`mkdocs build` errors with "docs_dir should not be the parent directory of the config file";
a bare `marp` leaves `{{VERSION}}`/`{{PUB_DATE}}` markers unsubstituted):

```bash
make docs-web     # manual → docs/manual-site/ (MkDocs Material; requires docs/manual/requirements.txt)
make docs-pdf     # manual → docs/manual/gestconv-plus-manual.pdf (pandoc + pagedjs-cli, reuses system Chrome via PUPPETEER_EXECUTABLE_PATH)
make docs-serve   # local preview of docs-web at http://127.0.0.1:8000
make docs         # both docs-web and docs-pdf
make slides       # docs/slides/gestconv-plus.pdf (Marp)
make cheatsheets  # quick reference sheets
make bump-readme  # updates the vX.Y.Z badge in README.md
```

`app.version`/`app.pub_date` in `config/services.yaml` is the **single source** for almost
everything derived from the version: the in-app UI (sidebar/login), the manual's PDF/web cover
(`MANUAL_COPYRIGHT`), and the slide deck (`{{VERSION}}`/`{{PUB_DATE}}`, substituted by `make
slides` into a temporary `_build.md`, never edited directly) — updating just `services.yaml` keeps
all of those coherent. The **one exception** is the `<strong>vX.Y.Z</strong>` badge in
`README.md`: it's a hand-maintained file, not a generated artifact, so it needs its own step
(`make bump-readme`) after editing `services.yaml`. `package.json`'s `"version"` field is a
deliberately-unsynced vestige of Playwright being a devDependency (not published to npm, not read
anywhere) — don't "fix" it to match `app.version`.

After editing the manual, verify with `make docs-web 2>&1 | grep -i warning` — broken links/anchors
surface as `WARNING`; the red "MkDocs 2.0" banner from Material is just a deprecation notice, not
an error.

When a code change affects content already covered by the slide deck (new screens, flows,
settings), also update the relevant bullet(s) in `docs/slides/gestconv-plus.md` — don't treat
`docs/manual/` and `CHANGELOG.md` as the only docs that need updating. The deck is a terse,
role/block-organized practical guide, not an exhaustive feature list — use judgement on whether a
brand-new feature area is slide-worthy at all. Rebuild with `make slides` and spot-check the
rendered PDF before considering the docs update complete.

For manual/cheatsheet screenshots specifically (disposable DB, Playwright gotchas, image sizing),
see `skills/screenshots.md`.

## Release process

**Never publish a release on your own initiative.** Committing a fix or feature does not imply
cutting a version — after committing, stop. Don't touch the CHANGELOG version section,
`app.version`/`app.pub_date`, run `make bump-readme`, or create/push a `vX.Y.Z` tag unless the user
explicitly asks to publish (e.g. "publica", "saca una versión", "haz el release"). A pushed tag is
a public, hard-to-reverse action — it triggers `.github/workflows/build.yml` (tests, standalone
binaries, Docker image publish) and real deployment scripts pull from it. If it's unclear whether a
request implies a release, ask rather than assume.

As of 2026-09-11 the project cuts **real semver patch/minor tags** (`v1.0.1`, `v1.1.0`, `v1.2.1`…)
instead of the earlier "single live version" convention that force-moved a `v1.0.0` tag forward —
`v1.0.0` itself is left untouched as a historical tag, don't revive the move/force-push pattern.
Whether a given release bumps patch, minor, or major is the user's call; confirm if it's not
obvious from the change (a bug fix is a patch; a new user-facing capability is usually a minor).

Once a release is actually requested, the mechanics are:

1. The fix/feature itself lands in its own `fix(...)`/`feat(...)` commit(s), separate from the
   release commit.
2. A release commit `chore(release): publica la versión X.Y.Z` that: adds a new `## [X.Y.Z] -
   <date>` section at the **top** of `CHANGELOG.md` (existing sections stay below, untouched),
   bumps `app.version`/`app.pub_date` in `config/services.yaml`, and runs `make bump-readme`.
3. `git push origin main`, then `git tag -a vX.Y.Z -m vX.Y.Z` + `git push origin vX.Y.Z` — this tag
   push is what triggers `.github/workflows/build.yml`.

### Gotcha: `softprops/action-gh-release` accumulates the body on every re-release

`.github/workflows/build.yml` publishes the release with
`softprops/action-gh-release@v2` and `generate_release_notes: true`. If the tag
already has a release (as happened on every re-publish under the old
move/force-push `v1.0.0` convention, and would still happen if a `vX.Y.Z` release
step is ever re-run against the same tag), the action
internally computes `body = workflowBody || existingReleaseBody` — and in
JavaScript `""` is *falsy*, so an explicit `body: ""` does **not** prevent it from
falling back to the previous release's body (which already includes the "Full
Changelog" text from last time), and `generate_release_notes` prepends to it again
→ the text keeps accumulating on every re-release. The correct fix, already
applied in the workflow, is to pass a `body` that is *truthy* even though it's
effectively empty:

```yaml
body: " "   # a single space: truthy, avoids falling back to the previous release's body
generate_release_notes: true
```

If you ever touch this step, don't simplify it to `body: ""` — that's the bug
that was already fixed once.
