# ADR-001: Gutenberg blocks map 1:1 to OlonJS sections

## Status
Accepted

## Date
2026-05-16

## Context
We are building a WordPress plugin that must expose every published page both as the usual HTML at `/$slug` and as a JSON document at `/$slug.json` conforming to the OlonJS `Page` contract (`https://olon.js.org/schemas/v1/page.schema.json`).

The `Page` schema requires:
- `sections`: an ordered array of objects shaped as `{ id, type, data, settings? }`
- `section.type`: a free-form `string` (no enum, no naming constraint)
- `section.data`: a free-form `object` (no nested schema imposed by `Page`)

WordPress, by default, stores page content as an HTML blob in `wp_posts.post_content`. To produce conformant JSON we need an authoritative source of truth for the *structured* representation of a page — i.e. where its `sections[]` actually live in the WordPress data model.

## Decision
Use **Gutenberg blocks as the canonical representation of OlonJS sections**. One block = one section. The mapping is:

| OlonJS `section` field | Source in Gutenberg block |
|---|---|
| `type` | `blockName` (e.g. `olon/hero` → `"olon/hero"`) |
| `data` | `attrs` (the block's declared attributes) |
| `id` | `attrs.olonId` if present, otherwise generated and persisted on first save |
| `settings` | `attrs.settings` (optional) |

The endpoint `/$slug.json` is produced by calling `parse_blocks( $post->post_content )` and projecting each block into the section shape above, in order.

## Alternatives Considered

### ACF / Meta Box Flexible Content
- Pros: schema-first editing, structured by design.
- Cons: hard dependency on a paid third-party plugin; duplicates a capability Gutenberg already provides natively; redactors lose the standard WordPress editing experience.
- Rejected: re-implements, behind a paywall, what Gutenberg gives for free.

### Custom Post Type "section" + relational composition
- Pros: maximum reuse of sections across pages.
- Cons: redactor UX is significantly more complex (composing a page means picking and ordering CPT instances, not editing in place); reuse is a feature we do not need at the MVP stage.
- Rejected: solves a problem we do not have yet, at high UX cost.

### Raw JSON metabox validated against the schema
- Pros: maximum fidelity to the contract.
- Cons: unusable by non-technical editors; defeats the purpose of using WordPress as the CMS.
- Rejected: the agency + client audience explicitly includes non-technical editors.

## Rationale
- Gutenberg is the default WordPress editing experience and the most widely adopted block editor; targeting it maximises compatibility and editor familiarity.
- Each Gutenberg block already declares its own schema via `block.json` (`attributes` with `type`, `default`, `source`). This is structurally equivalent to a section type with a typed `data` payload.
- `parse_blocks()` returns an ordered array of `{ blockName, attrs, innerBlocks, … }` — the same shape, semantics and ordering required by `sections[]`. The mapping is deterministic and requires no HTML parsing.
- The `Page` schema imposes no naming constraint on `section.type`, so block names can be used directly without a translation table.

## Consequences

### Positive
- `post_content` becomes the single source of truth for both the HTML render and the JSON contract — no parallel data store, no sync problem.
- Editors keep the native Gutenberg UX; the plugin does not invent its own editing surface.
- New section types can be added by registering new blocks; no migration of stored content is required.
- The `/$slug.json` endpoint is a pure projection of existing data, which keeps it trivially cacheable.

### Negative / Trade-offs
- The shape of `section.data` for each section type is defined by the corresponding block's `attributes`, not by a separate schema document. Keeping block attributes and the OlonJS section-type expectations in sync is a per-block responsibility.
- `section.id` must be stable across edits to allow downstream caching and diffing; this requires persisting a generated id into `attrs.olonId` on first save.
