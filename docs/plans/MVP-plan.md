# Implementation Plan: WP-OlonJS MVP

Derived from [docs/specs/MVP.md](../specs/MVP.md). Scope = single endpoint `/$slug.json` emitting a `Page`-schema-conformant document for every published page, with full recursive block projection and stable section ids.

## Dependency Graph

```
Composer + plugin bootstrap (T1)
        │
        ▼
Rewrite rule + Router (T2)  ◄── proves URL wiring
        │
        ▼
BlockToSection (T3) ────► PageProjector (T4) ────► JsonResponse + Router wire-up (T5)
        │                                                  │
        ▼                                                  ▼
                                              End-to-end happy path delivers Page JSON
                                                           │
                                                           ▼
                                              IdAssigner + save_post hook (T6) ── stable ids
                                                           │
                                                           ▼
                                              404 / not-found (T7)
                                                           │
                                                           ▼
                                              Schema validation in tests (T8)
                                                           │
                                                           ▼
                                              Deactivation cleanup + build zip (T9)
```

## Architecture Decisions

- Single bounded context, three namespaces: `Rewrite/`, `Projection/`, `Http/`. Mirrors the spec's project structure.
- Projection is pure: it takes arrays in, returns arrays out. No WP globals inside `Projection/`.
- WP integration lives only in `Plugin.php`, `Rewrite/*`, and `IdAssigner` (which subscribes to `save_post`).
- Tests are split: `tests/unit/` does not boot WordPress, `tests/integration/` uses the WP test suite and `opis/json-schema` for conformance.

## Task List

### Phase 1 — Walking Skeleton

#### Task 1: Composer + plugin bootstrap

**Description:** Create the plugin bootstrap file with the WordPress plugin header, set up Composer with PSR-4 autoload (`Olon\WP\OlonJs\` → `src/`), wire dev dependencies (PHPUnit, WPCS, opis/json-schema), and provide `composer test`, `composer lint`, `composer lint:fix` scripts. Plugin activates and deactivates cleanly on a stock WP install with no side effects yet.

**Acceptance criteria:**
- [ ] `composer install` succeeds with zero warnings.
- [ ] Activating the plugin in WordPress causes no PHP notices, warnings or errors.
- [ ] `composer lint` runs PHPCS with WPCS and passes on the empty source tree.

**Verification:**
- [ ] `composer test` runs PHPUnit with zero tests (exits 0).
- [ ] `composer lint` exits 0.
- [ ] Manual: activate/deactivate plugin in `wp-env` admin, check `debug.log` is clean.

**Dependencies:** None
**Files:** `wp-olonjs.php`, `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`, `src/Plugin.php`
**Scope:** S

---

#### Task 2: Rewrite rule + Router stub

**Description:** Register the rewrite rule `^(.+)\.json/?$ → index.php?olon_page=$matches[1]`, register the query var `olon_page`, and intercept the request in a `Router` on `template_redirect`. For this task the Router returns a stub `{"ok": true, "slug": "<requested>"}` JSON so the wiring is observable end-to-end before projection exists. Rewrite rules are flushed on activation/deactivation.

**Acceptance criteria:**
- [ ] `GET /anything.json` on a fresh wp-env returns HTTP 200 with the stub JSON.
- [ ] The HTML route `/anything` is unchanged (still returns the normal WP response).
- [ ] Activating the plugin flushes rewrites once; subsequent activations are idempotent.

**Verification:**
- [ ] `curl -i http://localhost:8888/about.json` shows `Content-Type: application/json` and the stub body.
- [ ] Integration test: `tests/integration/RouterStubTest.php` asserts status, content-type, and body.
- [ ] `composer lint && composer test` green.

**Dependencies:** T1
**Files:** `src/Plugin.php`, `src/Rewrite/JsonEndpoint.php`, `src/Rewrite/Router.php`, `src/Http/JsonResponse.php`, `tests/integration/RouterStubTest.php`
**Scope:** M

---

### Checkpoint A — Wiring proven
- [ ] URL responds with JSON
- [ ] HTML route untouched
- [ ] Plugin activate/deactivate clean

---

### Phase 2 — Projection

#### Task 3: BlockToSection (recursive)

**Description:** Implement `BlockToSection::project()` exactly as specified in the spec's code snippet. Pure function operating on `parse_blocks()` output. Recurses into `innerBlocks`, placing children under `data.innerBlocks` with the same `{id, type, data, settings?}` shape at every depth. Skips blocks with `null` `blockName` at every depth. Uses a stub `IdAssigner` that returns a path-based deterministic id (real persistence comes in T6).

**Acceptance criteria:**
- [ ] Single-block fixture → one section with correct `type`/`data`.
- [ ] Block with `attrs.settings` → emits `section.settings`.
- [ ] Block without `attrs.settings` → no `settings` key.
- [ ] Nested fixture (Columns → Column → Paragraph) → three levels reflected in `data.innerBlocks`.
- [ ] `null`-name blocks at any depth are skipped.
- [ ] Element order preserved at every depth.

**Verification:**
- [ ] `composer test -- --filter BlockToSection` green, ≥ 6 cases.
- [ ] 100% line coverage of `BlockToSection.php`.

**Dependencies:** T1
**Files:** `src/Projection/BlockToSection.php`, `src/Projection/IdAssigner.php` (stub interface + path-based impl), `tests/unit/Projection/BlockToSectionTest.php`, `tests/unit/Projection/fixtures/*.php`
**Scope:** M

---

#### Task 4: PageProjector

**Description:** `PageProjector::project(WP_Post $post): array` produces the full `Page` document. Maps `id` (`sanitize_title . '-page'`), `slug` (`post_name`), `meta.title` (`get_the_title`), `meta.description` (`get_the_excerpt`), and `sections[]` via `BlockToSection` over `parse_blocks($post->post_content)`. Top-level blocks with `null` `blockName` are skipped.

**Acceptance criteria:**
- [ ] Output matches the `Page` schema for every fixture (validated via opis/json-schema in T8; in this task asserted by hand-written shape checks).
- [ ] Empty `post_content` → `sections: []`.
- [ ] Excerpt fallback works (no explicit excerpt → WP-generated excerpt is used).

**Verification:**
- [ ] `composer test -- --filter PageProjector` green.
- [ ] Unit tests cover empty content, single block, nested blocks, missing excerpt.

**Dependencies:** T3
**Files:** `src/Projection/PageProjector.php`, `tests/integration/Projection/PageProjectorTest.php`
**Scope:** S

---

#### Task 5: Wire projection into Router

**Description:** Replace the T2 stub. Router resolves the requested path to a `WP_Post` via `get_page_by_path()`, calls `PageProjector`, and emits the result through `JsonResponse` with `Content-Type: application/json; charset=utf-8` and `X-Olon-Schema: https://olon.js.org/schemas/v1/page.schema.json`. Includes nested paths (`/parent/child.json`). For non-page post types, returns 404 (handled fully in T7; here just don't crash).

**Acceptance criteria:**
- [ ] `GET /<slug>.json` for a published page returns the page projected via `PageProjector`.
- [ ] `X-Olon-Schema` header present with the contract URL.
- [ ] `GET /parent/child.json` works for nested pages.

**Verification:**
- [ ] Integration test creates a published page with two blocks, hits the endpoint, asserts JSON body and headers.
- [ ] `composer lint && composer test` green.

**Dependencies:** T2, T4
**Files:** `src/Rewrite/Router.php`, `src/Http/JsonResponse.php`, `tests/integration/EndpointHappyPathTest.php`
**Scope:** S

---

### Checkpoint B — End-to-end happy path
- [ ] A real published page returns a real, complete `Page` JSON
- [ ] Headers correct
- [ ] Nested URL paths work

---

### Phase 3 — Stability and edge cases

#### Task 6: IdAssigner with save_post persistence

**Description:** Replace the T3 stub `IdAssigner` with the real one. On `save_post` for the `page` post type, walk the block tree of the saved content, assign a uuid to any block missing `attrs.olonId` (at every depth), and write the updated content back via `serialize_blocks()`. The single mutation point is `save_post`; reads never mutate. `BlockToSection` then trusts `attrs.olonId` if present, falling back to the path-based id only for never-saved fixtures (used in unit tests).

**Acceptance criteria:**
- [ ] First save of a page populates `attrs.olonId` on every block including nested ones.
- [ ] Subsequent saves with unchanged blocks leave existing ids untouched.
- [ ] Editing one block's content does not change other blocks' ids.
- [ ] Adding a new block produces a new id; reordering does not.

**Verification:**
- [ ] Integration test: create page → save → assert ids → edit one block → save → assert ids stable on the others.
- [ ] No write occurs during `GET /$slug.json` (assert post `modified` timestamp unchanged after the request).

**Dependencies:** T3, T5
**Files:** `src/Projection/IdAssigner.php`, `src/Plugin.php` (hook registration), `tests/integration/IdStabilityTest.php`
**Scope:** M

---

#### Task 7: 404 for missing / unpublished pages

**Description:** Router returns HTTP 404 with `{"error":"not_found"}` for: unknown slug, draft/private/trashed page, non-`page` post types. Response keeps the same JSON content-type. Standard WP `is_404()` is still set so caches behave.

**Acceptance criteria:**
- [ ] `GET /no-such-page.json` → 404 JSON.
- [ ] `GET /<draft-slug>.json` → 404 JSON.
- [ ] `GET /<post-slug>.json` (a `post`, not a `page`) → 404 JSON.

**Verification:**
- [ ] Three integration tests, one per case.
- [ ] `composer test` green.

**Dependencies:** T5
**Files:** `src/Rewrite/Router.php`, `tests/integration/NotFoundTest.php`
**Scope:** S

---

#### Task 8: Schema-conformance test harness

**Description:** Wire `opis/json-schema` into the integration test base class. Add a helper `assertConformsToPageSchema(array $body)` that loads `vendor/olon/schemas/page.schema.json` (committed under `tests/fixtures/schemas/`) and validates the response. Retrofit every integration test from T5/T6/T7 to call it on success responses.

**Acceptance criteria:**
- [ ] Every existing integration test asserting a 200 response now also asserts schema conformance.
- [ ] A deliberately broken projection in a temporary test makes the assertion fail (sanity check).

**Verification:**
- [ ] `composer test` green; failure injected and reverted is observed.
- [ ] CI run uploads PHPUnit log; conformance assertion is visible.

**Dependencies:** T5
**Files:** `tests/integration/IntegrationTestCase.php`, `tests/fixtures/schemas/page.schema.json`, retrofits in existing integration tests
**Scope:** S

---

#### Task 9: Deactivation cleanup + build zip

**Description:** On deactivation, remove the rewrite rule and flush. Add a `composer build` script that produces `dist/wp-olonjs.zip` with only the runtime files (no `tests/`, no dev deps). Add a minimal `README.md` linking to the spec and ADRs.

**Acceptance criteria:**
- [ ] After deactivation, `GET /<slug>.json` returns the normal WP 404 (HTML), not the JSON 404.
- [ ] `composer build` produces a zip ≤ 200 KB containing only `wp-olonjs.php`, `src/`, `vendor/` (prod only), `README.md`.
- [ ] Installing the zip on a fresh WP install reproduces the happy path.

**Verification:**
- [ ] Integration test for activation→deactivation→404 HTML.
- [ ] Manual: install zip in wp-env, smoke-test one page.

**Dependencies:** T6, T7, T8
**Files:** `src/Plugin.php`, `composer.json` (scripts), `README.md`, `.distignore`
**Scope:** S

---

### Checkpoint C — Ready to ship
- [ ] All 9 tasks complete
- [ ] All success criteria from the spec verified

## Parallelization

- T3 and T4 can be drafted in parallel by two agents if a shared `IdAssigner` interface is agreed first.
- T7 and T8 are independent after T5 lands.
- Everything else is strictly sequential.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| `parse_blocks()` edge cases (mixed valid + invalid markup) produce surprising trees | M | T3 fixtures include real Gutenberg exports, not hand-written arrays |
| `serialize_blocks()` round-trip in T6 alters attribute formatting and causes diffs at every save | M | T6 includes a round-trip equality test for unchanged content; mitigate by only re-serializing when an id was actually added |
| Rewrite rule conflicts with other plugins using `.json` URLs (e.g. SEO sitemaps) | M | Rule is registered with `top` priority and scoped to `^(.+)\.json/?$`; document the conflict in README; integration test with Yoast active is a follow-up |
| `opis/json-schema` becomes a runtime dependency by accident | L | Declared in `require-dev` only; `composer build` strips dev deps; CI lints the zip contents |
