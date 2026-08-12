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
without jargon. New entries go **at the top** of their section within
`[Unreleased]`. Breaking (`!`) commits need an entry under `Fixed` or `Changed`
stating whether a manual step is needed when upgrading. Purely internal changes
(`ci`, `test`, `docs`, `refactor` with no visible impact) **don't** get an entry.

## Documentation generation

Always use the `Makefile` targets, don't invoke pandoc/mkdocs/marp by hand:

```bash
make docs-pdf     # manual → docs/manual/gestconv-plus-manual.pdf (pandoc + pagedjs-cli)
make docs-web     # manual → docs/manual-site/ (MkDocs Material)
make docs-serve   # local preview
make slides       # docs/slides/gestconv-plus.pdf (Marp)
make cheatsheets  # quick reference sheets
make bump-readme  # updates the version number in README.md
```

Version and publication date come from `config/services.yaml`
(`app.version`, `app.pub_date`) — don't duplicate them by hand elsewhere; if they
change, also run `make bump-readme`.

When adding any change that affects the slide deck's content (new screens, flows,
screenshots), also update `docs/slides/gestconv-plus.md`, not just the
manual/CHANGELOG.

## Release process

The project uses a **single "live" version** (currently `1.0.0`, tag `v1.0.0` in
git): instead of bumping semver on every batch of changes, the tag is **moved
forward** (`git tag -f v1.0.0 <commit>` + `git push origin v1.0.0 --force`) to
republish with the accumulated work, with a commit
`chore(release): re-publica la versión 1.0.0 con el trabajo acumulado`
("chore(release): republishes version 1.0.0 with the accumulated work") that
updates `app.pub_date` in `config/services.yaml` and adds the corresponding
CHANGELOG entries. Don't assume strict semantic versioning in this repo; confirm
with the user before proposing a bump to `1.1.0`/`2.0.0`.

Moving and force-pushing a tag is an action visible to others and triggers
`.github/workflows/build.yml` (binary build + GitHub release) — treat it like any
other high-impact action: confirm it explicitly with the user before running it,
don't do it proactively.

### Gotcha: `softprops/action-gh-release` accumulates the body on every re-release

`.github/workflows/build.yml` publishes the release with
`softprops/action-gh-release@v2` and `generate_release_notes: true`. If the tag
already has a release (as happens every time `v1.0.0` is moved), the action
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
