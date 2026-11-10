# Phase 1 frontend integration

The CMS and web use `NEXT_PUBLIC_API_URL`, ending in `/api/v1`. The value is embedded at build time. Both frontends call Laravel directly from the browser and send bearer tokens with `Accept: application/json`, without login cookies. Playback creation and renewal opt into credentialed CORS for compatibility with verified access providers. The current external CDN uses a separate registrable domain; Laravel cannot issue its cookies. The existing-CDN adapter defaults to anonymous signed-URL transport once the real protocol is configured. Set API `CORS_ALLOWED_ORIGINS` to the exact frontend origins. Enable `CORS_SUPPORTS_CREDENTIALS=true` for playback and leave these origins out of `SANCTUM_STATEFUL_DOMAINS` for bearer-only operation; Sanctum's stateful SPA mode requires a separate cookie/CSRF flow. Tokens stay in React memory, survive client-side navigation, and are lost on refresh. Logout calls the API to revoke the current token. No tokens are stored in localStorage.

Corrected contracts:

| Operation | Laravel contract |
| --- | --- |
| Admin login/logout | POST `/admin/login` → `data.token`; POST `/admin/logout` |
| Viewer login/register/logout | POST `/auth/login`, `/auth/register`, `/auth/logout` |
| Dashboard | GET `/admin/dashboard` → `data` |
| CMS movies | GET/POST `/admin/movies`; PATCH/DELETE `/admin/movies/{id}` |
| Metadata and origins | `synopsis`, `genres` as slug array, `sources[]` with label/kind/url/default/enabled |
| Catalog and filters | GET `/movies?q=...&genre=...&featured=1&page=...` |
| Movie detail | GET `/movies/{slug}` |
| Home sections | GET `/home` → hero/featured/trending/latest/continue_watching |
| Playback | authenticated POST `/playback/sessions` with `movie_id` → `data.url` |

The CMS supports create/edit/delete, publish/unpublish, dashboard, pagination, genre assignment, and source metadata. `/genres` lists existing genres; the API does not support genre create/delete, so the UI does not pretend to perform them. The browser UI renders field validation errors from Laravel, loading states, empty states and retries where relevant. Public catalog and details use the published-only API behavior.

The web includes home, catalog/search/genre/featured filters, slug details, viewer registration/login, My List addition/listing/removal, throttled movie progress/resume/completion, and the authenticated player. HLS.js uses automatic bitrate selection by default, with a manual quality selector. Browsers without MediaSource support use native HLS when available. MP4 works natively; DASH plays through dash.js (automatic ABR; the manual quality selector is HLS-only). YouTube links are accepted as a source kind and render as embedded iframes: they bypass URL signing/renewal (public embeds by design) and do not report watch progress.

Build and typecheck from the repository root:

```sh
npm ci --prefix apps/web
npm ci --prefix apps/cms
NEXT_PUBLIC_API_URL=https://api.zentunnel.net/api/v1 npm run build --prefix apps/web
NEXT_PUBLIC_API_URL=https://api.zentunnel.net/api/v1 npm run build --prefix apps/cms
npm run typecheck --prefix apps/web
npm run typecheck --prefix apps/cms
```

Both Dockerfiles use lockfiles with `npm ci` and exclude local dependencies, build output and secret files from their build contexts. No production container, proxy, certificate, DNS record, or existing Docker volume is changed by source builds.

# Isolated browser tests

Install dependencies, FFmpeg, OpenSSL, Python 3 and Chromium's system libraries. Download the test browser:

```sh
PLAYWRIGHT_BROWSERS_PATH=/tmp/hyperline-playwright apps/web/node_modules/.bin/playwright install chromium
bash tests/run-browser-tests.sh
```

The runner uses loopback ports 18081–18084, a secret-free temporary workspace copy with reused dependencies, a fixed test-only database `/tmp/hyperline-browser.sqlite`, generated media under `/tmp/hyperline-test-media`, and logs/results under `/tmp`. Fixture seeding refuses any other database or environment. It builds the apps with the local test API address and starts/stops only its own local test processes. These local build artifacts must be rebuilt with the production API URL before deployment. The test credentials exist only in the guarded fixture seeder, not in production CMS logic. It never migrates or seeds Docker PostgreSQL.

The tests exercise real Laravel responses for CMS CRUD/validation/publication/logout revocation, public search/filters/drafts/mobile width, and HLS loading/playback/fullscreen/CORS. The PHP suite separately exercises signed playback and entitlement policies. See [CDN-PLAYBACK.md](CDN-PLAYBACK.md) for signing requirements and verification limits.

Phase 1 follow-through: [contracts, source reconciliation, CMS components and architecture](PHASE-1-IMPLEMENTATION.md), [current validation](PHASE-1-VALIDATION.md). Authenticated 401 responses clear the matching in-memory session across both clients; no persistent credential store is introduced. The fixture runner excludes application .env files/config caches and builds inside /tmp rather than replacing checkout build assets.
