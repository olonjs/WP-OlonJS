# Spec: WP-OlonJS MVP — `/$slug.json` page endpoint

## Objective

Ship a WordPress plugin that exposes every published page at the twin URL `/$slug.json` as a JSON document conforming to the OlonJS `Page` contract (`https://olon.js.org/schemas/v1/page.schema.json`).

The mapping is the one fixed in [ADR-001](../decisions/ADR-001-gutenberg-blocks-as-olonjs-sections.md): one Gutenberg block = one OlonJS section, with `blockName` → `section.type` and `attrs` → `section.data`.

Out of scope for this MVP (deferred): the rest of the MCP contract family (`/schemas/$slug.schema.json`, `/mcp-manifests/$slug.json`, `/mcp-manifest.json`, `/llms.txt`) — those land in a follow-up driven by ADR-002.

### User stories
- As an OlonJS frontend, when I GET `/about.json` from a WordPress site running this plugin, I receive a valid `Page` document for the `about` page.
- As a WordPress editor, I author pages with the native Gutenberg editor and the JSON output is updated automatically — no parallel editing surface.

## Tech Stack

- WordPress ≥ 6.4
- PHP ≥ 8.1
- No required dependencies beyond WordPress core
- Dev tooling: Composer (autoload + dev deps), wp-env (local WP), PHPUnit + WP test suite

## Commands

| Task | Command |
|---|---|
| Install PHP deps | `composer install` |
| Start local WP | `npx wp-env start` |
| Run tests | `composer test` (wraps PHPUnit) |
| Lint | `composer lint` (PHPCS with WPCS ruleset) |
| Lint fix | `composer lint:fix` |
| Build zip | `composer build` (produces `dist/wp-olonjs.zip`) |

## Project Structure

```
wp-olonjs.php              # Plugin bootstrap (header + autoloader + activation hooks)
src/
  Plugin.php               # Main plugin class, registers hooks
  Rewrite/
    JsonEndpoint.php       # Registers /$slug.json rewrite rule and query var
    Router.php             # Intercepts the request, dispatches to PageProjector
  Projection/
    PageProjector.php      # WP_Post → Page (array)
    BlockToSection.php     # Gutenberg block → section (array)
    IdAssigner.php         # Generates and persists attrs.olonId on first save
  Http/
    JsonResponse.php       # Sends JSON with correct headers
tests/
  unit/                    # Pure PHPUnit, no WP bootstrap
  integration/             # WP test suite (real DB, real posts)
docs/
  decisions/               # ADRs
  specs/                   # This file and future specs
```

## Code Style

- WordPress Coding Standards (WPCS) enforced via PHPCS.
- One class per file, namespace `Olon\WP\OlonJs\…`, PSR-4 autoload.
- Type declarations on every method signature, `declare(strict_types=1);` in every file.
- No static singletons except the plugin bootstrap.

Example (the heart of the mapping):

```php
<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Projection;

final class BlockToSection
{
    public function __construct(private readonly IdAssigner $ids) {}

    /**
     * @param array<string,mixed> $block  Result of parse_blocks()[$i]
     * @param list<int>           $path   Indices from root, used for stable id derivation
     * @return array{id:string,type:string,data:array<string,mixed>,settings?:array<string,mixed>}
     */
    public function project(array $block, int $postId, array $path): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $id    = $this->ids->ensure($postId, $path, $attrs);
        $data  = $attrs;

        $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        if ($inner !== []) {
            $data['innerBlocks'] = [];
            foreach ($inner as $i => $child) {
                if (($child['blockName'] ?? null) === null) {
                    continue;
                }
                $data['innerBlocks'][] = $this->project($child, $postId, [...$path, $i]);
            }
        }

        $section = [
            'id'   => $id,
            'type' => (string) ($block['blockName'] ?? ''),
            'data' => $data,
        ];

        if (isset($attrs['settings']) && is_array($attrs['settings'])) {
            $section['settings'] = $attrs['settings'];
        }

        return $section;
    }
}
```

## Mapping Contract (the heart of the MVP)

| OlonJS `Page` field | Source |
|---|---|
| `id` | `sanitize_title($post->post_name) . '-page'` |
| `slug` | `$post->post_name` |
| `meta.title` | `get_the_title($post)` |
| `meta.description` | `get_the_excerpt($post)` |
| `sections[]` | `parse_blocks($post->post_content)`, every top-level block with a non-empty `blockName` projected via `BlockToSection`, in document order |
| `section.id` | `attrs.olonId` (generated + persisted on first save if missing) |
| `section.type` | `block.blockName` (verbatim, no namespace stripping) |
| `section.data` | `block.attrs`, plus `innerBlocks` (see below) when the block has nested children |
| `section.data.innerBlocks` | Recursive projection of `block.innerBlocks` using the same `BlockToSection` rules; each entry has the same `{id, type, data, settings?}` shape |
| `section.settings` | `block.attrs.settings` when present, omitted otherwise |

Empty blocks (no `blockName`, e.g. raw HTML whitespace separators from the parser) are skipped at every depth.

The top-level `sections[]` stays flat as required by the `Page` schema; nesting is preserved by attaching the children recursively under `data.innerBlocks`, which is the only place the schema allows arbitrary structured payload.

## URL Behaviour

- `GET /$slug.json` → `200 application/json; charset=utf-8` with the `Page` document.
- `GET /$slug.json` for a non-existent or non-published page → `404` JSON `{ "error": "not_found" }`.
- `Content-Type: application/json; charset=utf-8` and `X-Olon-Schema: https://olon.js.org/schemas/v1/page.schema.json`.
- The existing HTML route `/$slug` is left untouched.

## Testing Strategy

- **Unit (`tests/unit/`)**: pure PHPUnit. Covers `BlockToSection`, `IdAssigner`, `PageProjector` with fixtures of `parse_blocks()` output.
- **Integration (`tests/integration/`)**: WP test suite. Creates posts, hits the endpoint, asserts JSON shape and headers. At least one fixture per `Page` field above.
- **Schema conformance**: every integration response is validated against `page.schema.json` via `opis/json-schema`. Plugin CI fails on any non-conformance.
- Target: 100% line coverage for the `Projection/` namespace.

## Boundaries

- **Always:**
  - Run `composer lint && composer test` before any commit.
  - Validate every response in tests against `page.schema.json`.
  - Persist `attrs.olonId` only via `wp_update_post` on `save_post` — never mutate `post_content` on read.
  - Keep `parse_blocks()` as the only source of structural truth.

- **Ask first:**
  - Adding any runtime PHP dependency.
  - Adding a new HTTP endpoint beyond `/$slug.json`.
  - Touching anything related to the MCP contract family (deferred per ADR-002).

- **Never:**
  - Parse `post_content` as HTML.
  - Mutate post data during a GET request.
  - Skip schema validation in tests.
  - Introduce custom `olon/*` blocks in the MVP (mapping is block-agnostic; custom blocks are a later milestone).

## Success Criteria

1. Activating the plugin on a stock WP install requires zero configuration; `/$slug.json` works immediately for every published page.
2. `GET /<any-published-page-slug>.json` returns a document that validates against `page.schema.json` for every page in the test fixtures.
3. Pages authored in Gutenberg produce a `sections[]` array whose length equals the number of top-level blocks with a non-empty `blockName`, in document order.
4. `section.id` is stable across edits: editing unrelated content of a page does not change existing section ids.
5. Deactivating the plugin removes the rewrite rule cleanly; `/$slug.json` returns the normal WP 404.

## Open Questions

- Should the endpoint support pages nested under a parent (`/parent/child.json`) in this MVP, or only top-level pages? Default if unanswered: support nested paths, since `$post->post_name` resolution via `get_page_by_path` handles them with no extra work.
