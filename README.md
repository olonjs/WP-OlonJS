# WP-OlonJS

WordPress plugin that exposes every published page at the twin URL `/$slug.json` as an OlonJS `Page` document (`https://olon.js.org/schemas/v1/page.schema.json`).

## How it works

- A rewrite rule maps `/$slug.json` to an internal query.
- The router resolves the slug to a published `page` post and projects it.
- Each top-level Gutenberg block in `post_content` becomes one OlonJS `section`; `blockName` → `section.type`, `attrs` → `section.data`. Nested blocks are recursively projected into `data.innerBlocks` with the same shape at every depth.
- On `save_post`, every block missing `attrs.olonId` gets a stable uuid persisted into the post content. Reads never mutate.

See [docs/specs/MVP.md](docs/specs/MVP.md) and the ADRs in [docs/decisions/](docs/decisions/) for the full design.

## Requirements

- WordPress ≥ 6.4
- PHP ≥ 8.1

## Install

From a release zip:

1. Upload `wp-olonjs.zip` via *Plugins → Add New → Upload*.
2. Activate.
3. `GET /$slug.json` for any published page returns the projected JSON.

From source:

```bash
composer install
```

Then symlink or copy the directory into `wp-content/plugins/`.

## Endpoints (MVP)

| URL | Returns |
|---|---|
| `GET /$slug.json` | `Page` document for a published page |
| `GET /$slug.json` (unpublished / wrong type / unknown slug) | `404 {"error":"not_found"}` |

The MCP contract family (`/schemas/...`, `/mcp-manifests/...`, `/mcp-manifest.json`, `/llms.txt`) is intentionally out of scope for the MVP — see [ADR-002](docs/decisions/ADR-002-full-mcp-contract-emission.md).

## Commands

| Task | Command |
|---|---|
| Install deps | `composer install` |
| Unit tests | `composer test` |
| Integration tests | `composer test:integration` |
| All tests | `composer test:all` |
| Lint | `composer lint` |
| Auto-fix lint | `composer lint:fix` |
| Build release zip | `composer build` (produces `dist/wp-olonjs.zip`) |

## License

GPL-2.0-or-later.
