# TechnicalDocsBundle

**Type:** Documentation · **Name:** Technical Docs · **Edit route:** none (no admin config)

## What it does

Adds a "Technical Docs" section to the admin sidebar, listing every `.md` file under the repo's
`docs/` directory as a link. Clicking one opens that file's raw contents (plain text, unrendered
markdown — no parser/dependency involved) in a new tab.

Visible only to admins with `ROLE_TECH_SUPPORT`; everyone else never sees the nav section or its
routes exist. Like every bundle, it can also be turned off entirely from Bundle Management
(`/admin/bundle-management`).

## How it's configured

Nothing to configure — the doc list is discovered at request time by scanning `docs/` for `*.md`
files (`TechnicalDocsBundle\Docs\DocsRepository::list()`), so adding or removing a file under
`docs/` changes what shows up here automatically, no code change needed.

## Security

`DocsRepository` is the only thing in this bundle that touches the filesystem, and it's the sole
gatekeeper for `TechnicalDocsController::view()`:

- The requested relative path is resolved with `realpath()` and required to still be inside
  `docs/` after resolution — blocks `../` traversal and symlinks pointing outside `docs/`.
- The resolved path must end in `.md` — blocks reading any non-markdown file even if it somehow
  lived inside `docs/`.
- The controller route additionally requires `ROLE_TECH_SUPPORT` (`denyAccessUnlessGranted`), on
  top of the sidebar link being hidden from everyone else.

Nothing here accepts a raw filesystem path from outside the app — the only external input is the
`{path}` route parameter, and every value it can take is validated against `docs/` before any file
is read.

## External dependencies

None — `symfony/finder` (already a transitive Symfony dependency) for listing files; no markdown
parser, since content is served as raw text, not rendered HTML.

## See also

[docs/inventory-calculation.md](../../docs/inventory-calculation.md) — one of the docs this bundle
serves, as a concrete example.
