# WP-OlonJS

WordPress plugin that exposes every published page at the twin URL `/$slug.json` as an OlonJS `Page` document (`https://olon.js.org/schemas/v1/page.schema.json`).

## How it works

- A rewrite rule maps `/$slug.json` to an internal query.
- The router resolves the slug to a published `page` post.
- Each top-level Gutenberg block becomes one OlonJS `section`; `blockName` → `section.type`. Nested blocks are recursively projected under `data.innerBlocks`.
- Before projection, each block is **hydrated** against its `block.json` attribute schema (read from `WP_Block_Type_Registry`): values declared with a `source` (`text`, `rich-text`, `html`, `attribute`, `tag`, `query`) are extracted from the block's `innerHTML` server-side, exactly like Gutenberg's JS editor does client-side. The result: `data` is **content only**, no HTML markup leaks into the JSON. `rich-text` values are converted to inline Markdown.
- On `save_post`, every block missing `attrs.olonId` gets a stable uuid persisted into the post content. Reads never mutate.

See the specs and ADRs:
- [docs/specs/MVP.md](docs/specs/MVP.md) — the `/$slug.json` endpoint
- [docs/specs/content-hydration.md](docs/specs/content-hydration.md) — the hydration layer
- [docs/decisions/](docs/decisions/) — architectural decisions

## Requirements

- WordPress ≥ 6.4
- PHP ≥ 8.1
- Composer (only for development / building releases)

## Install

From a release zip:

1. Upload `wp-olonjs.zip` via *Plugins → Add New → Upload*.
2. Activate.
3. `GET /<published-page-slug>.json` returns the projected JSON.

From source (production):

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Then symlink or copy the directory into `wp-content/plugins/`.

## Local development with Docker

A `docker-compose.yml` provides a zero-host-install environment: MariaDB + WordPress 6.7 (Apache), with the plugin live-bind-mounted into `wp-content/plugins/wp-olonjs`. Two helper one-shot containers (`composer`, `wpcli`) run on demand.

### First-time setup

From the project root:

```bash
# 1. Install Composer dependencies into vendor/
docker compose run --rm composer install

# 2. Start the database and WordPress containers
docker compose up -d

# 3. Install WordPress, activate the plugin, and create test pages
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/seed.sh
```

The seed script is idempotent — running it again will not duplicate the test pages.

### Verify it works

```bash
curl -sL http://localhost:8080/about.json | python3 -m json.tool
curl -sL http://localhost:8080/parent/child.json | python3 -m json.tool
```

You should see a `Page` document with content-only `data` fields (no HTML markup, `rich-text` values rendered as inline Markdown).

### WP admin

- URL: <http://localhost:8080/wp-admin>
- User: `admin`
- Password: `admin`

Edit a page in Gutenberg, save, and re-curl the JSON to see the change live (the plugin source is bind-mounted).

### Stopping / resetting

```bash
docker compose down            # stop containers, keep volumes (data persists)
docker compose down -v         # stop and wipe DB + WP files (full reset)
```

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

All commands also work from inside the Docker stack, e.g. `docker compose run --rm composer test`.

## License

MIT — see [LICENSE](LICENSE).
