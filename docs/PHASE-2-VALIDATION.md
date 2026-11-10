# Phase 2 validation record — 2026-11-10 (API + frontend lanes)

Every check below was actually executed in this session. Statuses are PASS / FAIL /
NOT RUN per the reporting rule. Commands ran from `/opt/hyperline-ott/services/api`
unless noted.

## Environment note (CORS_ALLOWED_ORIGINS)

The agent daemon environment exports `CORS_ALLOWED_ORIGINS` from the root `.env`, which
shadows `putenv()` inside `CredentialedCorsTest::…wildcard…` and makes it fail spuriously.
All suite runs below use `env -u CORS_ALLOWED_ORIGINS php artisan test`. This is an
environment artifact, not a code defect (verified: with the variable unset the test
passes; with it set the same test fails identically on the pre-Phase-2 tree).

## API test suite (SQLite, in-memory)

| Check | Command | Result |
| --- | --- | --- |
| Full suite | `env -u CORS_ALLOWED_ORIGINS php artisan test --compact` | **PASS — 275 tests, 1231 assertions** (Phase 1 baseline 221/975 + 54 new Phase 2 tests) |
| Phase 2 schema constraints | `tests/Feature/SeasonsSchemaTest.php` (9 tests) | PASS |
| Admin series/season/episode CRUD | `tests/Feature/AdminSeriesCrudTest.php` (16 tests) | PASS |
| Hierarchy visibility & playback denial | `tests/Feature/SeriesVisibilityTest.php` (9 tests) | PASS |
| Episode progress/resume/completion | `tests/Feature/EpisodeProgressTest.php` (8 tests) | PASS |
| Next-episode ordering & boundaries | `tests/Feature/EpisodeOrderingTest.php` (5 tests) | PASS |
| Season-aware import (incl. flat-manifest compat) | `tests/Feature/ImportSeriesSeasonsTest.php` (7 tests) | PASS |
| Phase 1 regressions (movies, playback, viewer API, tokens, signing, import) | pre-existing 221 tests within full-suite run | PASS |

Coverage mapping to the Phase 2 test requirements:

- DB ownership/numbering constraints → SeasonsSchemaTest
- Mismatched series/season/episode identifiers → SeasonsSchemaTest (composite FK),
  AdminSeriesCrudTest (404 guards), SeriesVisibilityTest (cross-series season number 404)
- Unauthorized CRUD and playback → AdminSeriesCrudTest (401/403), SeriesVisibilityTest
- Draft visibility at every hierarchy level → SeriesVisibilityTest (series/season/episode
  × detail/seasons/episodes/playback/renewal/progress), EpisodeProgressTest (home rail)
- Episode ordering and next-episode boundaries → EpisodeOrderingTest
- Independent progress per episode (and per user) → EpisodeProgressTest
- Completion and resume behavior → EpisodeProgressTest
- Source replacement and playback renewal → AdminSeriesCrudTest (preserve/replace),
  SeriesVisibilityTest (renewal refused after season unpublish), SeriesPlaybackTest
  (pre-existing signed renewal, still green)
- Existing movie regressions → full pre-existing suite green

## PostgreSQL 16 verification (disposable container)

Disposable container `hyperline-phase2-migtest` (postgres:16-alpine, bound to
`127.0.0.1:55432` only, removed after the run; production containers untouched).

| Check | Result |
| --- | --- |
| `php artisan migrate --force` (all migrations up, incl. 3 new) | PASS (after fixing one driver difference: boolean literal `1` → `TRUE` in the seasons backfill INSERT) |
| Unique `(series_id, season_number)` | PASS (UniqueConstraintViolationException) |
| Unique `(season_id, episode_number)` | PASS |
| Composite FK rejects episode→foreign-series season | PASS (QueryException) |
| `watch_progress` XOR CHECK (ownerless / dual-owner) | PASS |
| `watch_progress` partial unique `(user_id, episode_id)` | PASS |
| `video_sources` XOR CHECK | PASS |
| `playback_sessions` XOR CHECK | PASS |
| `hyperline:check-playable-ownership` on seeded data | PASS (exit 0, all counts zero) |
| Guarded rollback: refused while episode progress rows exist | PASS (RuntimeException "Refusing to roll back: 2 episode progress row(s) exist…") |
| Guarded rollback: refused while seasons beyond season 1 exist | PASS (RuntimeException "Refusing to roll back seasons…") |
| Clean rollback after explicit cleanup | PASS — movie data fully preserved (movies=2, movie sources=2, movie progress=2), season structure removed |
| Re-up (`migrate --force`) after rollback | PASS — season 1 backfilled for all 4 series, episodes re-attached (2/2 non-null season_id), movie progress intact |
| Post-re-up ownership audit | PASS (exit 0) |

Note: Laravel rolls back each migration file in its own transaction on PostgreSQL; a
refusing `down()` leaves earlier already-rolled-back files rolled back (observed:
`…120200` down committed, then `…120100` down refused). This matches Laravel semantics
and the forward-compatible-rollback design; re-running `migrate` restores.

## SQLite migration path

Exercised by every `RefreshDatabase` test (275 tests migrate the full stack per test
run), including both SQLite table-rebuild paths (`watch_progress`, `video_sources`,
`playback_sessions` CHECK rebuilds under `PRAGMA defer_foreign_keys`). PASS.

## Frontend lane (CMS season manager, web season selector/episode progress, browser E2E)

Delivered after the concurrent Phase 1 frontend session went quiet on source files
(agreed lane split). Typechecks and builds re-run after the video-format extension:

| Check | Command | Result |
| --- | --- | --- |
| Web typecheck | `npm run typecheck` (apps/web) | **PASS** |
| CMS typecheck | `npm run typecheck` (apps/cms) | **PASS** |
| Web production build | `npm run build` (apps/web) | **PASS** |
| CMS production build | `npm run build` (apps/cms) | **PASS** |
| Browser E2E, default fixture mode | `tests/run-browser-tests.sh` (run 5, fresh fixture DB) | **PASS — 13 passed, 2 skipped, exit 0** |
| Browser E2E, signed-fixture mode | `HYPERLINE_SIGNED_FIXTURE=1 tests/run-browser-tests.sh` | **PASS — 14 passed, 1 skipped, exit 0** (signed-url transport renewal + position preservation green; DASH plays through the signed transport) |
| Browser E2E, isolated CDN Nginx fixture | `python3 tests/cdn/run.py` (cdn.spec: signed HttpOnly cookies, nested HLS, renewal) | **PASS — all Nginx gate cases (missing/invalid/noncanonical/expired/cross-movie/traversal/encoded/query/unknown), cache isolation, credentialed CORS, and the browser spec (1 passed, exit 0)** |

Run 5 spec detail (all PASS unless noted): formats (DASH plays through dash.js;
YouTube renders the embed iframe), integration ×4 (CMS CRUD, catalog, HLS playback
with quality/fullscreen, CORS failure UX), phase-one ×3 (My List + revocation,
movie progress resume/throttle/completion, CMS unsaved-edit protection/filters/
audit), phase-two ×2 (CMS series→season→episode create/publish flow ending in
public web browse with season selector; viewer episode playback → progress POST
with `episode_id` → Resume + progress bar → typed Continue Watching rail),
series ×2 (public discovery/ordering, vertical player + prev/next navigation).

Skipped (explained): `cdn.spec.ts` and `signed-url.spec.ts` are gated behind
`HYPERLINE_SIGNED_FIXTURE=1` by harness design; `signed-url.spec` ran and passed
in the signed-fixture mode above. `cdn.spec.ts` additionally requires the
isolated real-Nginx secure-link fixture (`HYPERLINE_CDN_E2E` via
`tests/cdn/run.py`) and is executed through that separate harness.

E2E fixes made along the way (all test-side, product behavior deliberate):
continue-watching cards now deep-link straight into the player (spec updated);
`series.spec` accepts Watch|Resume because episode progress legitimately persists
in the fixture DB; harness now deletes `/tmp/hyperline-browser.sqlite` per run so
progress rows cannot leak across runs (run-4 flake root cause); episode seek in
`phase-two.spec` is relative so it can never collide with a restored resume
position (run-4 flake root cause #2).

## Video-format extension checks (DASH playable + YouTube embeds)

| Check | Result |
| --- | --- |
| API suite incl. `VideoFormatsTest` (6 tests) + updated `PhaseOneRegressionTest` | **PASS — 281 tests, 1231→1272 assertions** |
| YouTube link validation (watch/youtu.be/embed/shorts accepted; wrong host, bad id, playlist, empty id rejected 422) | PASS |
| YouTube session returns raw URL unsigned with `signature_required:false`, `renew_after_seconds:null` while a signer that would throw is bound (proves bypass) | PASS |
| YouTube sessions still enforce auth (401) and subscription (403) | PASS |
| DASH sessions are signed like other transports when signing is enabled | PASS |
| Unknown kinds (e.g. `bluray`) remain unplayable and fall back to supported sources | PASS |
| Browser: DASH fixture plays through dash.js (readyState ≥ 2, videoWidth > 0) | PASS (run 5, 4.1s) |
| Browser: YouTube source renders `iframe[title="YouTube player"]` with `youtube.com/embed/dQw4w9WgXcQ`, video element hidden | PASS (run 5, 2.1s) |

## Not run / out of scope for this record

- Production deployment, image builds, migration of any live database: NOT RUN at
  time of writing (separately approved step; pending concurrent-session quiescence).
- Real CDN playback and native-HLS device checks: NOT RUN (separate track; local
  fixtures use the loopback test stream). YouTube e2e asserts the embed element
  and src only, never third-party playback.
- Release scheduling: NOT IMPLEMENTED (documented as a limitation, not claimed).
