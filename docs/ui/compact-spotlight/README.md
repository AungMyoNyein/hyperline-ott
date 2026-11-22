# Compact Spotlight and Movies — UAT candidate

Branch: `feat/compact-spotlight-catalog`. This continues `feat/movies-catalog-ux` at `f884702` and preserves the existing source commits. The PR targets that branch so the new layout changes can be reviewed independently.

The Spotlight uses a 460px desktop, 380px tablet and 320px mobile minimum height. Headings scale from 48–60px desktop to 38–46px tablet and 30–36px mobile; actions are 46px high. Long titles can expand the section instead of being clipped. Burmese titles and descriptions retain Unicode font selection and `lang="my"`. Existing artwork, gradients and black/red design tokens are retained. Trending now appears in the first 1080p viewport for anonymous viewers; signed-in viewers can still have their existing Continue watching row first.

The Movies heading is 132px desktop / 112px mobile, with 36–44px / 29px titles. The desktop toolbar keeps search, Genre and Featured on one row, caps the search group at 600px, and uses 46px controls. Mobile controls wrap with their labels and clear actions. Search behavior, API counts, public filter URLs and detail-return navigation from the earlier Movies UX change are preserved.

Movie cards reserve a consistent 16:9 preview frame before artwork loads. Supplied portrait posters remain complete on a neutral surface; landscape thumbnails fill their native frame. For the known YouTube `default`, `hqdefault` and `sddefault` URLs, the browser requests the same video's native `mqdefault` 16:9 variant directly from `i.ytimg.com`, avoiding the thumbnail's baked-in bands. There is no image proxy, image download pipeline, rehosting, fabricated poster, or destructive crop. Failed artwork uses the existing neutral branded fallback. Full titles wrap and action rows align within grid rows. Home movie rails use the same preview treatment; detail pages retain full portrait artwork.

## Screenshot evidence

Before screenshots come from public UAT. After screenshots replay the same anonymous `/home`, `/movies`, `/genres` and vertical-series API response snapshots against the candidate. Snapshot JSON is kept under `/tmp` and is never imported into any database. At capture time UAT has no featured hero, so the screenshots show the branded Spotlight fallback; browser tests also cover movie-backed artwork, long Burmese titles and missing images.

| Width | Homepage before | Homepage after | Movies before | Movies after |
| --- | --- | --- | --- | --- |
| 360 | [Before](screenshots/before-home-360.png) | [After](screenshots/after-home-360.png) | [Before](screenshots/before-movies-360.png) | [After](screenshots/after-movies-360.png) |
| 390 | [Before](screenshots/before-home-390.png) | [After](screenshots/after-home-390.png) | [Before](screenshots/before-movies-390.png) | [After](screenshots/after-movies-390.png) |
| 768 | [Before](screenshots/before-home-768.png) | [After](screenshots/after-home-768.png) | [Before](screenshots/before-movies-768.png) | [After](screenshots/after-movies-768.png) |
| 1366 | [Before](screenshots/before-home-1366.png) | [After](screenshots/after-home-1366.png) | [Before](screenshots/before-movies-1366.png) | [After](screenshots/after-movies-1366.png) |
| 1920 | [Before](screenshots/before-home-1920.png) | [After](screenshots/after-home-1920.png) | [Before](screenshots/before-movies-1920.png) | [After](screenshots/after-movies-1920.png) |

Geometry, first-card position and overflow are recorded in [before-metrics.json](before-metrics.json) and [after-metrics.json](after-metrics.json). Reproduce captures with `PLAYWRIGHT_BROWSERS_PATH=/tmp/hyperline-playwright node tests/capture-compact-ui.cjs`; set `CAPTURE_PHASE=after` and `CAPTURE_URL` to the local candidate for the second pass.

At 360px and 390px, the toolbar measures 191px and the first movie row moves from y=497px to y=429px. At desktop widths the toolbar measures 116px and the first row moves from approximately y=418px to y=383px. The Spotlight moves the first homepage movie row from y=737px to y=492px on mobile and from y=918px to y=671px at 1920px. No horizontal overflow was measured at any requested viewport.

## Validation

- `npm run typecheck --prefix apps/web`
- `npm run lint --prefix apps/web`
- `apps/web/node_modules/.bin/tsc -p tests/e2e/tsconfig.json`
- `HYPERLINE_SIGNED_FIXTURE=1 HYPERLINE_UPDATE_UI_SNAPSHOTS=1 bash tests/run-browser-tests.sh`
- `npm audit --prefix apps/web --json` and the equivalent CMS audit: **zero reported vulnerabilities** in [web-audit.json](web-audit.json) and [cms-audit.json](cms-audit.json).

The runner builds isolated production copies under `/tmp`, refreshes changed visual baselines, then compares them in the full regression suite. It creates a unique disposable SQLite fixture database for each run instead of resetting an existing database. Tests cover Burmese/English searches, Enter, Search, clear, invalid-query correction, empty results, loading, retry, filters, pagination and detail-return state. New checks cover Spotlight dimensions, typography, visible Trending content at 1080p, control sizing and alignment, stable mixed-artwork geometry, fallback artwork, long Burmese headings, keyboard focus order and full-page axe scans for WCAG 2 A/AA and 2.1 AA at all five sizes. Automated checks use Chromium; native devices and a Burmese reader's visual acceptance remain manual review.

The final build and test phases run separately against the same isolated production copy. The 60 tracked viewer/CMS source and configuration files were compared with the checkout before the clean test run; generated Next.js declarations and omitted environment example files were excluded. Build IDs and verification metadata are in [prebuilt-validation.json](prebuilt-validation.json). The final test phase creates another new temporary database and compares snapshots without updating them. A cancelled earlier run left a negative-CORS marker in the loopback media fixture; runner startup now clears that marker after verifying the test ports are free. Test-only SQLite guards were also updated for the unique temporary filenames. No production signer or authorization code changed.

Final full-suite result: **63 passed, 3 skipped, 0 failures** (3.8 minutes). All ten Movies tests and seven new compact-layout/accessibility tests passed. Skips require optional CDN, catalog-import CSV or live third-party YouTube fixtures. See [production-build.txt](production-build.txt), [browser-regression.txt](browser-regression.txt) and [validation-results.json](validation-results.json). TypeScript, viewer lint, browser-test TypeScript and both production builds passed. All five changed visual baselines passed their final comparisons without updates. Both dependency audits reported zero vulnerabilities.

## Release boundary

`deploy/prepare-compact-web-candidate.py` builds only the committed viewer tree, records the source revision and immutable image ID, and prepares viewer-only candidate/rollback overrides. It records container IDs, image IDs and start timestamps before and after the build without reading credentials. It does not start or stop containers or update live image tags.

Candidate: `hyperline-web:uat-compact-20261122T105516Z-edb8b98bd846`, built from source commit `edb8b98bd846c38f74ff1929efb66a5b67d5c034`. Immutable image ID: `sha256:9a6c0876f46fbfbbe4de5da084f6a44bb90b48ba5db2441414bbdd89ae8b7666`. Rollback image ID: `sha256:353ddc2c7e2b4a41f75e82763b8eeed54382ff62191e143e8a75ce92082b27ce`, retained under `hyperline-web:rollback-uat-compact-20261122T105516Z-edb8b98bd846`.

The built image passed **7 smoke tests**, including ten axe scans with zero violations across the five requested viewports. It ran only in a temporary localhost container with fixture API responses and no live configuration or data mounts; that container has been removed. All eight existing UAT containers retained their IDs, image IDs and start timestamps. See [release-manifest.json](release-manifest.json), [image-smoke.txt](image-smoke.txt), [candidate.compose.json](candidate.compose.json) and [rollback.compose.json](rollback.compose.json). The image's viewer tree is unchanged by later test-harness and evidence commits.

**Ready for controlled UAT deployment review. No deployment performed.** The prepared release directory is `/opt/hyperline-ott/deploy/releases/uat-compact-20261122T105516Z-edb8b98bd846`. The GitHub PR remains separate from deployment authorization.

Deployment requires separate authorization. Retain the existing UAT runtime configuration, use the reviewed immutable viewer image, and verify the public home/search/detail-return flows after an authorized rollout. Rollback replaces only the viewer image with the recorded prior image; no database restore or migrations are involved. API, CMS, workers, database, publication state, authentication, playback authorization, CDN/origin and VPN services are outside this release. The pre-existing API Dockerfile edit and unrelated local work are excluded.
