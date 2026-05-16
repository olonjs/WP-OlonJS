# Spec: Content Hydration (Option C — registry-driven)

## Objective

Replace the current `data: { innerHTML, innerContent, ... }` output with structured, content-only `data` by applying server-side the same attribute source extraction that Gutenberg's JS editor applies client-side.

The plugin reads each block's `attributes` schema from `WP_Block_Type_Registry` (populated by `block.json` files of core and third-party blocks) and re-hydrates attribute values from `innerHTML` using the declared `source` rules. The output `section.data` is then **content only** — no HTML markup leaks into the JSON.

This is the only path consistent with "the plugin is content-source-agnostic and OlonJS is just a JSON schema": no hardcoded per-block extractors, no `olon/*` block library — whatever WordPress has registered hydrates correctly.

### Target output

For the test page that currently looks like:

```json
{ "type": "core/paragraph", "data": { "innerHTML": "<p>Left column content.</p>", "innerContent": ["<p>Left column content.</p>"] } }
```

we want:

```json
{ "type": "core/paragraph", "data": { "content": "Left column content." } }
```

and for the wrapping columns block (with no own textual content):

```json
{ "type": "core/columns", "data": { "innerBlocks": [...] } }
```

## Tech Stack

- PHP ≥ 8.1
- WordPress ≥ 6.4 (for `WP_Block_Type_Registry` semantics we rely on)
- `DOMDocument` + `DOMXPath` from PHP standard library for HTML parsing and selector → XPath translation
- `league/html-to-markdown ^5.1` (runtime dep) for HTML → Markdown conversion of `rich-text` source values

## Project Structure (delta)

```
src/
  Hydration/                ← NEW namespace
    BlockHydrator.php          ← Recursive hydrator: enriches block attrs from innerHTML
    SchemaSource.php           ← Single-attribute extractor (dispatches on `source`)
    SelectorToXPath.php        ← Tiny CSS-selector → XPath converter (only the subset Gutenberg uses)
    RichTextToMarkdown.php     ← One-method wrapper around league/html-to-markdown for inline subset
    BlockTypeSchemaProvider.php ← Thin adapter around WP_Block_Type_Registry; isolates the WP global
  Rewrite/
    Router.php              ← MODIFIED: calls BlockHydrator between parse_blocks and PageProjector
  Projection/
    BlockToSection.php      ← MODIFIED: stop emitting innerHTML / innerContent in data
tests/
  unit/Hydration/
    BlockHydratorTest.php          ← Pure unit: hydrate(blocks, schemaMap) → enriched blocks
    SchemaSourceTest.php           ← Per-source extraction cases
    SelectorToXPathTest.php        ← Selector translation
  integration/
    HydratedEndpointTest.php       ← End-to-end against real registered blocks (paragraph, heading, list, image, columns, quote, code)
```

`Hydration/` is the layer where the WP boundary lives (`BlockTypeSchemaProvider` is the only file that touches `WP_Block_Type_Registry`). `BlockHydrator` itself stays pure: it receives the schema map as input.

## Sources In Scope

Per Gutenberg's documented `source` set, the hydrator implements exactly:

| Source | What it does | Example block field |
|---|---|---|
| (no source) | Value already comes from the block comment JSON. Apply schema `default` if absent. | `core/heading.level` |
| `attribute` | Read an HTML attribute from a selector match. | `core/image.url` (`img@src`) |
| `text` | Plain text content of a selector match (no tags). | `core/code.content` |
| `rich-text` | Inner HTML of a selector match, converted to Markdown (inline subset). | `core/paragraph.content` |
| `html` | Inner HTML of a selector match (kept as HTML). | `core/quote.value` |
| `tag` | Tag name of the selector match (lowercased). | (rarely used; covered for completeness) |
| `query` | Array of items: for each match of `selector`, apply a sub-schema to extract per-item fields. | `core/list.values` (legacy) |

### Rich-text → Markdown subset

`rich-text` source values are converted to Markdown using `league/html-to-markdown`, configured for **inline-only** output (Gutenberg already separates block-level structure into separate blocks, so a single `rich-text` value never legitimately contains paragraphs, headings, or lists).

Supported inline conversions:

| HTML | Markdown |
|---|---|
| `<strong>x</strong>` / `<b>x</b>` | `**x**` |
| `<em>x</em>` / `<i>x</i>` | `*x*` |
| `<code>x</code>` | `` `x` `` |
| `<a href="u">x</a>` | `[x](u)` |
| `<br>` | newline |
| `<s>x</s>` / `<del>x</del>` | `~~x~~` |
| anything else | tag stripped, inner text preserved |

The converter is wrapped in a small `RichTextToMarkdown` helper (`src/Hydration/RichTextToMarkdown.php`) so the library choice stays swappable behind a one-method interface.

### Out of scope

| Source | Why omitted |
|---|---|
| `children` | Deprecated by Gutenberg, only legacy blocks; not worth the complexity for MVP. |
| `raw` | No widespread use; trivially fallback to current innerHTML if ever needed. |
| `meta` | Post-meta source; not relevant for a page-content projection. |
| Block Bindings API (WP 6.5+) | Different model; future enhancement. |

A block whose schema uses an out-of-scope source still hydrates everything else correctly; the unsupported attribute is simply not enriched (consumers see whatever was in the block comment, or absent).

## Code Style

```php
<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Hydration;

final class BlockHydrator
{
    public function __construct(private readonly BlockTypeSchemaProvider $schemas)
    {
    }

    /**
     * Walk a parse_blocks() tree and return it with each block's `attrs`
     * enriched from `innerHTML` using its block type's attribute schema.
     *
     * @param  array<string,mixed> $block
     * @return array<string,mixed>
     */
    public function hydrate(array $block): array
    {
        $name = $block['blockName'] ?? null;
        if (is_string($name) && $name !== '') {
            $schema = $this->schemas->forBlock($name);
            if ($schema !== []) {
                $attrs   = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $html    = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
                $block['attrs'] = SchemaSource::applyAll($schema, $attrs, $html);
            }
        }

        if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as &$child) {
                if (is_array($child)) {
                    $child = $this->hydrate($child);
                }
            }
            unset($child);
        }

        return $block;
    }
}
```

Conventions kept from the rest of the codebase:
- `declare(strict_types=1);` everywhere
- `final` classes, constructor property promotion, `readonly`
- One class per file, PSR-4 (`Olon\WP\OlonJs\Hydration\…`)
- Pure functions in `Hydration/` except `BlockTypeSchemaProvider` (the boundary)

## Wire-up Changes (existing files)

### `src/Rewrite/Router.php`
Add `BlockHydrator` as a constructor dependency. Between `parse_blocks` and `PageProjector::project`, run hydration:

```php
$blocks = parse_blocks($post->post_content);
$blocks = array_map(fn (array $b) => $this->hydrator->hydrate($b), $blocks);

$page = $this->projector->project([
    'postId'      => $post->ID,
    'slug'        => $slug,
    'title'       => get_the_title($post),
    'description' => get_the_excerpt($post),
    'blocks'      => $blocks,
]);
```

### `src/Projection/BlockToSection.php`
Remove the `innerHTML` and `innerContent` injection into `data` that the previous fix added. Revert to: `data = attrs (minus olonId, settings) + innerBlocks (when present)`. Hydrated attrs now carry the content; no need to dump raw HTML.

### `src/Plugin.php`
Wire `BlockHydrator(new BlockTypeSchemaProvider())` into `Router`'s constructor.

## Selector Subset

Gutenberg block.json selectors in core are simple. The hydrator supports exactly this subset (compiled to XPath):

- Tag-only: `p`, `h1`, `h2`, `h3`, `h4`, `h5`, `h6`, `img`, `a`, `ul`, `ol`, `li`, `pre`, `code`, `blockquote`, `cite`, `figcaption`, `div`, `span`
- Tag list: `h1,h2,h3,h4,h5,h6` (comma-separated alternatives) — matches first hit
- Tag with class: `div.wp-block-quote`
- Descendant: `figure img`, `blockquote p`

A selector outside this subset → the attribute is left unhydrated and a debug-only entry is logged via `error_log` (only when `WP_DEBUG` is true). No fallback to the old `innerHTML`-in-data behaviour: the spec is "content only".

## Testing Strategy

Three test levels:

### Unit (`tests/unit/Hydration/`)
- `SelectorToXPathTest`: every selector in the supported subset → expected XPath
- `SchemaSourceTest`: one test per source (`attribute`, `text`, `rich-text`, `html`, `tag`, `query`), each with hand-crafted HTML and schema
- `BlockHydratorTest`: feed hand-crafted block trees with hand-crafted schema maps (no WP), assert hydrated `attrs`

These tests do not boot WordPress; the schema map is a plain PHP array.

### Integration (`tests/integration/HydratedEndpointTest.php`)
For each real registered block type, create a published page containing it and `curl` the JSON endpoint. Assert the projected `data` matches expectations. Blocks covered:

- `core/paragraph` → `data.content` is Markdown (e.g. `"Hello **world**, [link](/x)"` for an inline-formatted paragraph)
- `core/heading` → `data.level` (default 2 or explicit) + `data.content`
- `core/list` + `core/list-item` → nested structure with text items
- `core/image` → `data.url`, `data.alt` from `<img>` attributes
- `core/quote` → `data.value` (HTML allowed) + `data.citation`
- `core/code` → `data.content` plain text
- `core/columns` + `core/column` → no own content, only `data.innerBlocks`
- A block unknown to the registry → `data` falls back to attrs verbatim, no crash

### Schema conformance
Every integration response continues to pass `assertConformsToPageSchema()`.

## Boundaries

- **Always:**
  - Keep `Hydration/BlockHydrator`, `SchemaSource`, `SelectorToXPath` pure (no WP globals).
  - Only `BlockTypeSchemaProvider` may call `WP_Block_Type_Registry`.
  - Use `DOMDocument` with `LIBXML_NOERROR | LIBXML_NOWARNING` to avoid HTML5 noise on stderr.
  - Cache the schema-per-blockName lookup per request (in `BlockTypeSchemaProvider`) — the same block type is queried for every instance on a page.

- **Ask first:**
  - Extending the selector subset (each addition needs a unit test).
  - Supporting an additional `source` type beyond the in-scope set.

- **Never:**
  - Hardcode per-`blockName` extractors (`if (name === 'core/paragraph') ...`). Everything goes through the schema-driven path.
  - Emit raw `innerHTML` or `innerContent` in `data`. The whole point of this spec is to remove them.
  - Mutate `post_content` during hydration (it is read-only).

## Success Criteria

1. `curl http://localhost:8080/about.json` returns a body where no `data` key contains an HTML tag (verifiable by `grep -E '"<' response.json` returning nothing).
2. For the demo seeded page `/parent/child.json`, both paragraphs surface their text in `data.content`, and the wrapping `core/columns` has no `data.innerHTML`/`data.innerContent`, only `data.innerBlocks`.
3. A page containing each of the seven listed core blocks produces a JSON document that:
   - validates against `page.schema.json`;
   - exposes every block's main editorial content as a primitive field in `data` (no HTML);
   - preserves nesting via `data.innerBlocks` recursively.
4. A block whose `blockName` is not registered (or whose schema uses only out-of-scope sources) does not crash the request: its `data` is the original `attrs` (minus `olonId`/`settings`).
5. All existing unit tests for `BlockToSection`, `PageProjector`, `IdAssigner` continue to pass without modification of their assertions on `data`-shape beyond the documented removal of `innerHTML` / `innerContent`.

## Out of Scope (so we don't drift)

- The MCP contract family (`/schemas/...`, `/mcp-manifests/...`) — still ADR-002 territory.
- Block Bindings API (WP 6.5+).
- Caching the hydrated output across requests (per-request memoisation is enough; full HTTP cache is a separate concern).
- A consumer-facing structured rich-text format (Slate/Portable Text/AST). Today: Markdown for `rich-text`, HTML for `html`. If a downstream consumer needs an AST, that's a new spec.
