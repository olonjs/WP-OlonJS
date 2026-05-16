# ADR-002: WordPress plugin emits the full OlonJS MCP contract

## Status
Accepted

## Date
2026-05-16

## Context
ADR-001 established that Gutenberg blocks map 1:1 to OlonJS sections and that the plugin exposes pages as `Page` JSON at `/$slug.json`.

Analysis of `packages/core/src/contract/webmcp-contracts.ts` in OlonJS core shows that OlonJS does not consume the `Page` document alone. From a `PageConfig` plus a registry of section schemas, OlonJS core generates a coordinated family of artefacts that together form the runtime contract between a content source and the OlonJS frontend / MCP agents:

- `OlonJsPageContract` — per-page contract with section types, instances, JSON Schemas and MCP tool declarations.
- `OlonJsPageManifest` — per-page MCP manifest with transport and resource URIs.
- `OlonJsSiteManifestIndex` — site-wide index of all pages.
- `llms.txt` — Markdown site map for agents.

The `sectionInstances`, `sectionSchemas` and `update-section` / `save` tool declarations are all derived from these inputs. A WordPress source that emits only the `Page` JSON is therefore not a complete content source for OlonJS — it would force every consumer to fall back to a separate OlonJS-side configuration.

`sectionSchemas` are produced by converting Zod schemas declared in `JsonPagesConfig['schemas']` to JSON Schema. WordPress has no Zod, but every Gutenberg block already declares its payload as typed `attributes` in `block.json`. These attributes are structurally equivalent to the per-type schemas OlonJS core converts from Zod.

## Decision
The WordPress plugin is a complete implementation of an OlonJS content source. It exposes the full family of MCP endpoints, with the same URL shapes used by OlonJS core:

| Artefact | URL |
|---|---|
| `Page` | `/$slug.json` |
| `OlonJsPageContract` | `/schemas/$slug.schema.json` |
| `OlonJsPageManifest` | `/mcp-manifests/$slug.json` |
| `OlonJsSiteManifestIndex` | `/mcp-manifest.json` |
| `llms.txt` | `/llms.txt` |

`sectionSchemas` are derived directly from the `attributes` map declared by each registered `olon/*` block in its `block.json`, converted to JSON Schema. Only the section types actually present on the requested page are emitted, matching the projection performed by `buildPageContract`.

## Alternatives Considered

### Emit only `/$slug.json`
- Pros: Smaller surface; faster to ship.
- Cons: OlonJS frontend and MCP agents would have to source schemas, manifests and the site index from a parallel non-WordPress system; the WordPress instance would not be a self-sufficient content source.
- Rejected: Forces every deployment to maintain two coordinated content systems.

### Mirror the Zod schema registry inside the plugin
- Pros: Bit-for-bit alignment with OlonJS core's `JsonPagesConfig['schemas']`.
- Cons: Duplicates the schema definitions across two ecosystems (TypeScript + PHP/JS), creating a drift surface.
- Rejected: `block.json` already declares the schema authoritatively where the section is edited. The block is the schema.

### Compute the MCP family on the OlonJS frontend from the `Page` JSON
- Pros: Plugin only needs to emit one document.
- Cons: Recreates inside the consumer the work core already centralises; loses parity with the file-system-backed `JsonPagesConfig` source; breaks the assumption that any compliant content source emits the full contract.
- Rejected: The contract belongs at the source, not at the consumer.

## Consequences

### Positive
- A WordPress instance becomes a drop-in replacement for a file-system OlonJS project, indistinguishable from the consumer side.
- MCP tools (`update-section`, `save`) are emitted automatically whenever block attributes are registered, with no additional configuration.
- `block.json` is the single source of truth for both the editing experience and the published schema.

### Negative / Trade-offs
- The plugin must implement a `block.json` → JSON Schema converter (at activation/build time, with cached output).
- `sectionInstances[].id` stability (established in ADR-001 via `attrs.olonId`) is now a hard requirement, since the same ids are used by the `update-section` tool.
