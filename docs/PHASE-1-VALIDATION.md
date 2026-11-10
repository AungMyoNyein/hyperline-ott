# Phase 1 validation — 2026-11-10

This report describes checks actually executed for this task, not earlier README counts. The source basis and exact changed-file inventory are in [PHASE-1-IMPLEMENTATION.md](PHASE-1-IMPLEMENTATION.md). The checkout has extensive pre-existing uncommitted work and no remote; its base commit is `495ab2dd0068e1223049d88a5e85813b7752f0ea` for all three application directories.

| Check | Current-run result |
| --- | --- |
| API PHPUnit, full suite | **PASS: 221 tests, 975 assertions** using forced in-memory SQLite; PHP 8.3.6 / PHPUnit 11.5.57. |
| New focused API regressions | **PASS: 15 tests, 76 assertions** for PhaseOneRegressionTest + TokenExpirationTest, included in the full suite. |
| CMS `npm run typecheck` | **PASS**. |
| Web `npm run typecheck` | **PASS**. |
| CMS `npm run build` | **PASS**, including the final isolated production build (Next 15.5.29). |
| Web `npm run build` | **PASS**, including the final isolated production build (Next 15.5.29). |
| Signed disposable browser suite | **PASS: 10 passed, 1 skipped** in 41.8 seconds. The skipped legacy HttpOnly-cookie test needs a separate fixture. |
| Shell syntax | `bash -n tests/run-browser-tests.sh`: **PASS**. |
| Tracked whitespace check | `git diff --check`: **PASS**. |

The browser command is `HYPERLINE_SIGNED_FIXTURE=1 bash tests/run-browser-tests.sh`. Local socket binding was denied by the sandbox, so the same runner was executed with escalation. It creates a secret-free source copy beneath `/tmp/hyperline-phase1-e2e-*`, excludes .env and build/config caches, reuses installed dependencies, clears DB_URL, pins a local config-cache path, builds both clients for the test API and starts only its own loopback services on 18081–18084. Migrations/seeding target only `/tmp/hyperline-browser.sqlite`. Signed HLS is original FFmpeg test-pattern media served over temporary local TLS, authorized by the test-only signer. The cleanup trap stops only the processes the runner started. No Docker service, production DB, CDN/origin or deployment operation is part of these checks.

Full PHP coverage includes existing signing fail-closed, SSRF, entitlement, rate-limit/authorization, CRUD/source replacement, audit and expiry tests. New assertions exercise the hard lifetime bounds, disabled runtime policy refusal, supported-source fallback, DASH exclusion without deletion, renewal rejection after a format change, owner-specific filtered progress, expired optional home authentication, completion removing Continue Watching, and exclusion of unpublished movies from saved reads.

Browser coverage includes CMS authentication, movie/source validation, CRUD, publish/unpublish and logout revocation; search/genre/featured filters and draft exclusion; generated HLS loading, bitrate selection, fullscreen, CORS error reporting and signed-URL renewal preserving position; My List addition/listing/removal and keyboard navigation; progress restoration after metadata, event-burst throttling, pause persistence and real ended completion; CMS dirty-edit dismissal/confirmation, library filters, audit browsing and revoked sessions; desktop/mobile width checks and screenshots. Existing partial Series discovery and authorized episode navigation are regression-tested, without claiming full Phase 2 completion.

Intermediate browser failures were investigated: the first progress expectation incorrectly required a write immediately after a restore-triggered timeupdate, which is intentionally throttled; CMS filter controls needed explicit accessible names and the Featured filter test needed a combobox role to distinguish the editor checkbox. Screenshot review also found existing header/theme markup mismatches; the final source aligns navigation, hero, cards, detail and My List spacing with the existing stylesheet.

Known limits:

- The old HttpOnly same-site CDN cookie scenario requires a separate fixture and is skipped in this signed-URL run. The active signed-URL renewal test uses the current transport policy.
- Existing Web CSS emits non-failing autoprefixer warnings for `align-items:end` and `justify-content:start`; builds still compile. No dependency migration was bundled.
- PostgreSQL-specific migration behavior was not exercised; Phase 1 adds no migration. Future typed-owner/season constraint migrations require disposable PostgreSQL coverage.
- Real CDN signing/protocol/CORS/renewal and native-HLS device testing remain unverified. Local tests never prove production CDN readiness.
- Production version/configuration, production accounts, live HTTPS and deployment acceptance were not tested or modified. Earlier operational documents are historical evidence only.
- There is no Playwright execution blocker after local socket escalation. Artifact paths under /tmp contain disposable fixture data only.

The next implementation phase is **Phase 2: complete Series**, starting with additive seasons/owner-integrity migrations and tests, followed by full admin CRUD, episode progress and next-episode behavior. Live TV, Sports and editorial/media workflows remain separately documented work.

Final artifact review: `/tmp/hyperline-phase1-my-list-desktop.png`, `/tmp/hyperline-phase1-my-list-mobile.png`, `/tmp/hyperline-phase1-cms-desktop.png`, and `/tmp/hyperline-phase1-cms-mobile.png` were opened and visually inspected. Desktop captures include initial loading states; mobile captures show the loaded saved item and CMS audit/library controls. The Web navigation now wraps with spacing and visible keyboard focus rather than running links together. Final fixture workspace: `/tmp/hyperline-phase1-e2e-Br9VpI`.

A [source manifest](PHASE-1-SOURCE-MANIFEST.sha256) records hashes of the final touched application, configuration and test files. It is a review aid for this dirty checkout, not a claim about upstream or deployed commit identity.
