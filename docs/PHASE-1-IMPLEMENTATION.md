# Phase 1 implementation record — 2026-11-10

## Source and inspection basis

The supplied workspace is a combined Git checkout at `/opt/hyperline-ott`, not three independently cloned repositories. `git rev-parse HEAD` in the root, `services/api`, `apps/cms`, and `apps/web` returns **495ab2dd0068e1223049d88a5e85813b7752f0ea**. `git remote -v` is empty. The working tree already contained extensive modified/untracked application and deployment work when this task began. That commit alone does not identify the inspected source contents. No upstream commit for AungMyoNyein/hyperline-api, hyperline-cms, or hyperline-web can be asserted from this checkout. Existing work was extended without reset, commit, push, or deployment. A [SHA-256 manifest](PHASE-1-SOURCE-MANIFEST.sha256) records the final application/configuration/test files touched by this task, since the base commit does not represent the dirty source.

No AGENTS.md was found in the workspace or parent search. Inspection covered: root/API READMEs; frontend manifests and TypeScript configuration; Sanctum/token issuer/controllers/tests; API routes; catalog/viewer/Series migrations; movie/source/episode/session relationships; movie validation, update controller and audit resources; viewer controllers/resources; entitlement/signing policy; CMS page/API client; Web session/API client/detail/home/player; PHP test setup, auth/viewer/CRUD/entitlement tests; fixture runner/seeder, Playwright configuration/integration and signed-renewal tests. Existing frontend, runtime-release and auth/CMS preparation documents were also read.

Verified differences from the request's assumed baseline:

- Laravel 12/PHP 8.3 API and Next 15/React 19 clients exist. Local build reports Next **15.5.29** from installed dependencies. The API composer constraint permits PHP ^8.2; these checks actually run on PHP 8.3.6.
- Source already sets a 1,440-minute Sanctum expiry and explicitly dates registration/viewer/admin tokens through IssueAccessToken. This task adds a seven-day upper bound and regression tests, rather than introducing an unrelated auth system.
- Partial Series tables/import/publication/catalog/playback and Web screens already exist. There are no seasons or episode watch-progress relations in that implementation. It is not the complete Phase 2 roadmap.
- `/home` already explicitly resolves the optional Sanctum guard for Continue Watching. Tests verify owner-only published, incomplete movies; no redundant rewrite was required.
- API My List/progress/audit endpoints already exist. Web had addition only and no progress persistence. CMS rendered validation in a global summary only and had no library filters, unsaved protection or full audit view.
- Movie source updates replace the complete source collection only when `sources` is supplied. Metadata/publication patches omit `sources` and preserve rows. This contract remains unchanged.

Existing deployment documents report earlier deployed images with null expiry, but those are historical observations. This task does **not** establish current production configuration or deployed commits. Production behavior is unverified here. Reconcile immutable image/source identity, effective non-secret expiry/guard configuration, applied migrations and public error contracts in a separately authorized operational review before claiming the source policy is deployed. No live config, credentials, tokens, users, containers, DB or CDN were inspected or modified for this task.

## Dependency order and implemented behavior

1. Retain absolute server token enforcement and bearer-only guards; bound configuration to **1–10080 minutes**, default 1440. Explicit issued deadlines cannot slide or be extended by later configuration. Existing null-deadline tokens remain bounded by creation age. The issuer refuses runtime policies outside the range. Authenticated frontend 401 responses notify the matching in-memory session, clear it and show sign-in; invalid login and 403 responses do not discard an unrelated session. No browser storage, refresh token or silent reauthentication was introduced. Tabs can appear signed in while idle; the server enforces expiry on requests.
2. Define supported source kinds centrally as **HLS and MP4**. DASH can still be stored and edited, with an explicit unsupported label in CMS. Public detail source metadata includes `supported`; `playable` and source selection exclude DASH/unknown kinds. An unsupported default falls back to a supported enabled source; an explicit unsupported source fails rather than silently changing the requested label. Renewal rejects sources that become unsupported. Existing source rows are not migrated, disabled, removed or rewritten by the format policy. Codec/device compatibility is still required within HLS/MP4.
3. Add `/my-list` with authenticated paginated listing/removal and empty/error/loading states. List and progress reads exclude movies subsequently unpublished, preventing draft metadata disclosure through saved rows. Existing saved records remain in the database.
4. Add owner-scoped progress lookup by movie, restore after metadata loads, restart completed movies at zero, and write through a 15-second gate with duplicate suppression. Pause, ended, hidden-page, pagehide and component cleanup attempt a final save. Requests are serialized and the latest forced snapshot is queued; failures display an error and permit retry. `ended` records completion; replay produces incomplete progress once playback advances again. Completion is event-based, not an invented percentage threshold. Abrupt process/device termination can lose the latest interval; keepalive/pagehide are best effort. No request is sent for every timeupdate event. Episode progress remains Phase 2.
5. Extract CMS MovieEditor, MovieLibrary, SeriesLibrary, AuditScreen and draft helpers. Expose q/published/featured/genre/per_page plus pagination. Render dotted API validation errors beside relevant movie/source fields, keeping a global summary. Guard dirty editor replacement, clearing, logout and browser unload. Expiry preserves unsaved fields only in tab memory for reauthentication. Audit history supports action filtering, pagination, actor, changes and timestamps displayed with explicit local timezone names. It is read-only and uses the existing admin authorization. Web markup is aligned with the existing theme for responsive navigation, hero/card/detail spacing, and My List panels.

No new content type or schema is needed for these Phase 1 changes: all relationships and token-expiry columns already exist. No production or local application database migration was run; browser migrations use only the guarded disposable SQLite fixture.

## API compatibility and error contracts

All paths below are relative to `/api/v1`. Laravel resources retain their `data` envelope and collection pagination `links`/`meta`.

| Operation | Contract |
| --- | --- |
| POST `/auth/register`, `/auth/login`, `/admin/login` | Existing credential fields; response `data.token`, `data.user`, `data.expires_at` (UTC ISO timestamp). Admin response also includes token_type. Registration 201, login 200. Invalid credentials 401; validation 422. |
| GET `/watchlist?page=1` | Bearer required. `data: [{id, added_at, movie: MovieResource}]`, with published movies only and pagination. |
| POST `/watchlist` | `{movie_id}`; idempotent 201/200. Unpublished/missing movie 422. |
| DELETE `/watchlist/{movie_id}` | Deletes only current user's row; 200 `{status:"removed"}`. No matching row 404. |
| GET `/watch-progress?movie_id=123&page=1` | Optional positive integer filter, scoped to authenticated owner and published movie; `data: [{id,movie,position_seconds,duration_seconds,completed,last_watched_at}]` and pagination. Empty array for absent/inaccessible progress. Invalid filter 422. |
| POST `/watch-progress` | `{movie_id, position_seconds, duration_seconds?, completed?}`. Seconds are integers; position 0–864000, duration 1–864000, position cannot exceed supplied duration. Published movie required. Existing per-user/movie upsert, 201/200. Invalid data 422. |
| GET `/home` | Optional bearer; `data.continue_watching` contains current owner's incomplete published movies and progress fields. Anonymous, revoked or expired optional bearer gets an empty personalized section; protected endpoints return 401 for the same token. |
| GET `/movies/{slug}` | Published detail; source metadata includes `supported: boolean`, never origin URLs. Missing/draft 404. `playable` indicates enabled supported format availability, not CDN readiness or entitlement. |
| POST `/playback/sessions` | Existing `{movie_id, source?:label}` contract preserved; only supported enabled sources eligible. No eligible/requested source: 403 with existing `no_video_source` reason. No origin returned on denial. |
| POST `/playback/sessions/{public_id}/renew` | Existing ownership, expiry, entitlement and source checks retained; unsupported/removed/disabled source 403. Signing unavailable remains 503 and production never falls back unsigned. |
| GET `/admin/movies` | Existing q (2–120 chars), genre slug, published/featured (0/1), per_page (1–100), page parameters surfaced in CMS. |
| PATCH `/admin/movies/{id}` | Omit sources to preserve them; include complete nonempty sources array to replace. All writes, genre/source updates and audit records remain transactional. |
| GET `/admin/audit-logs` | Existing action/user_id/from/to/per_page/page filters; CMS exposes action and pages. Read-only admin response includes actor/properties/created_at. |

Protected guest/expired/revoked requests return JSON 401 (`code: unauthenticated`); denied roles/permissions or playback authorization return 403. Validation uses 422 `{message,errors:{"sources.0.url":[...]}}`. Standard opaque server errors, SSRF/origin validation, throttles, entitlement checks and signed renewal remain in place. Admin draft preview is an existing explicit entitlement exception; ordinary viewers cannot fetch draft detail or authorize its playback.

## Multi-playable model decision for the next phase

Current coupling is concrete: watchlists and watch_progress have non-null movie_id FKs and unique `(user_id,movie_id)` constraints. video_sources and playback_sessions originally had non-null movie_id FKs. The pre-existing Series migration widens those two to nullable movie_id and adds nullable episode_id FKs. It claims application-level exactly-one-owner enforcement, but the inspected models do not enforce that invariant and the DB has no XOR check. SQLite supports CHECK constraints, though adding them to existing tables requires a rebuild. Do not treat the migration's portability comment as a guarantee of integrity.

**Proposed approach:** keep dedicated domain tables and typed foreign keys for Phase 2, with shared playback services and resources. Add seasons, season_id on existing episodes, and additive episode_id on viewer tables; enforce exactly one typed owner with DB checks, real FK constraints, and owner-specific unique indexes. This is simpler to migrate safely from the existing movie/episode work than introducing polymorphic `(type,id)` references without real FKs. Movie/episode-specific policies remain explicit, so live events cannot accidentally inherit VOD progress/completion semantics. A saved series is an editorial/container bookmark and is distinct from progress on a playable episode.

A single shared playable identity could reduce repeated owner columns across many content types and centralize analytics. It requires a carefully enforced one-to-one subtype registry, a separate distinction between catalog containers and playable assets, backfilling every relation and translating all legacy movie requests. Generic polymorphic IDs alone lose database referential integrity. Defer a shared-entity migration until Live/Sports scheduling and asset reuse requirements are concrete; introduce it behind the same movie compatibility boundary if needed. Do not perform both models' migrations speculatively in Phase 1.

Migration sequence for Phase 2:

1. Inventory applied migration versions and detect ownerless/dual-owner/source-session mismatches in a disposable restored database; refuse constraint installation until reviewed repair rules exist. Never silently delete ambiguous rows.
2. Add seasons with unique `(series_id,season_number)`. Create season 1 for existing series and backfill existing episodes' season_id; preserve IDs. Replace episode uniqueness by `(season_id,episode_number)` only after backfill checks. Make season ownership required after validation.
3. Add nullable episode_id FKs and indexes to watch_progress; widen movie_id using an additive migration and install movie/episode XOR checks and unique owner constraints. Decide series-level bookmark semantics before changing watchlists. Retain all current movie request/response forms.
4. Add XOR checks to existing source/session ownership and enforce source-target consistency (e.g. typed composite keys/FKs or equivalent DB constraints with tested PostgreSQL and SQLite paths). Do not rely solely on request validation. Preserve cascade and source-null-on-delete behavior deliberately.
5. Add episode requests/resources/policies/services and tests before CMS season/episode CRUD and Web progress/next-episode integration. Use staged dual-version compatibility; retain old movie columns/contracts until all clients and jobs migrate. Rollback after episode writes must be a forward-compatible rollback, not the existing down migration that attempts non-null movie_id restoration.

## Later phases — separate work

- **Phase 2:** complete the partial Series implementation: seasons; artwork; full admin CRUD and policies; publication controls; episode sources; public catalog/detail; episode authorization/progress/next episode. Test uniqueness, nested ownership, unpublished parents, completed episode handling and source/session integrity. Do not call the current import/publication UI full CRUD.
- **Phase 3:** channels, ordering/categories and source models; validated EPG ingestion and mapping; UTC programme storage with explicit source/display timezones; Now/Next and guide APIs/resources, CMS and Web; authorized live playback and unavailable states. Automated failover and catch-up are separate work.
- **Phase 4:** sports/competitions/seasons/participants/events; validated Upcoming/Live/Finished/Postponed/Cancelled transition policy; UTC starts; live/replay/highlight assets; admin calendar/editor and Web discovery/detail/playback. Use no fabricated scores; optional external feed adapters require actual providers/contracts.
- **Phase 5:** genre CRUD, upload/processing pipeline, homepage curation, draft/review/scheduled/published/archived workflow, editor/publisher permissions and audit coverage.

Each phase must deliver additive migrations, real model relations, request validation and policies, public/admin resources, documented response/error contracts, CMS and viewer integration, and API/browser tests together. Use disposable PostgreSQL as well as SQLite for future constraint migrations. Keep signed playback fail-closed, protect origin URLs, preserve SSRF and entitlement checks, and never place keys in clients.

## Validation and changed-file inventory

Current-run results are recorded in [PHASE-1-VALIDATION.md](PHASE-1-VALIDATION.md); earlier README/report counts are historical. Actual CDN credentials/protocol, production deployment reconciliation, native HLS devices and production acceptance remain outside this local implementation.

Files changed by this task (pre-existing unrelated dirty files are excluded from this list):

- API: config/sanctum.php; Support/Authentication/IssueAccessToken.php; Models/VideoSource.php, Movie.php, SeriesEpisode.php; Controllers/Api/V1/PlaybackSessionController.php, WatchlistController.php, WatchProgressController.php; Resources/MovieDetailResource.php; tests/Feature/TokenExpirationTest.php and PhaseOneRegressionTest.php.
- CMS: app/page.tsx, app/style.css, lib/api.ts; new app/components/{movie-draft.ts,movie-editor.tsx,movie-library.tsx,series-library.tsx,audit-screen.tsx}.
- Web: lib/api.ts, app/session.tsx, app/movies/[slug]/detail.tsx, app/watch/player.tsx, app/page.tsx, app/cards.tsx, app/style.css; new lib/progress.ts, app/watch/use-movie-progress.ts and app/my-list/page.tsx.
- Configuration/docs/tests: root and API .env.example; README.md and services/api/README.md; docs/FRONTEND-INTEGRATION.md, PHASE-1-IMPLEMENTATION.md and PHASE-1-VALIDATION.md; tests/run-browser-tests.sh and tests/e2e/phase-one.spec.ts.
