# Phase 2 implementation record — Series → Seasons → Episodes (API vertical) — 2026-11-10

## Scope and basis

This record covers the **API/data layer of Phase 2** executed in the shared checkout at
`/opt/hyperline-ott` (single commit `495ab2d`, no remotes, all Phase 1 work uncommitted).
It follows the migration strategy documented in
[PHASE-1-IMPLEMENTATION.md](PHASE-1-IMPLEMENTATION.md) §"Multi-playable model decision for
the next phase" steps 1–5, and the Phase 2 requirement list in the master plan.

A second agent session was concurrently finishing Phase 1 frontend work in the same tree.
Per the agreed lane split, this task changed **only** `services/api/*`,
`tests/fixtures/seed.php` (broken by the new NOT NULL `season_id`, repaired minimally),
and `docs/`. CMS/Web season UI and browser E2E updates are tracked separately in
[PHASE-2-VALIDATION.md](PHASE-2-VALIDATION.md) once that lane opens.

## Data model decisions

### Season layer

- New table `seasons`: `series_id` FK→`series` (cascade), `season_number` (0–999),
  nullable `title`/`synopsis`/`poster_url`/`backdrop_url`, `published` (default **true**),
  timestamps. Unique `(series_id, season_number)`.
- **Specials policy:** season number `0` is the reserved specials bucket (`Season::SPECIALS`).
  It is a normal, fully playable season; catalog ordering places it **last**, and the
  next-episode rule skips over it only in the sense that it is ordered last
  (main seasons ascending, specials last, episode numbers ascending).
- `series_episodes` gains `season_id` FK→`seasons` (cascade, NOT NULL after backfill).
  Uniqueness moves from `(series_id, episode_number)` to `(season_id, episode_number)` —
  the same number may exist once per season.
- **Ownership constraint (DB-enforced):** `series_episodes.series_id` is retained and a
  composite foreign key `(series_id, season_id) → seasons(series_id, id)` makes it
  impossible at the database level for an episode's season to belong to a different
  series. `seasons` carries `UNIQUE(series_id, id)` as the parent key. Mismatched nested
  identifiers are therefore rejected by both the DB and route-level guards (404).
- New pivot `series_genre` (mirrors `movie_genre`): series now carry genres like movies.

### Deletion behavior (explicit)

- `series` delete → cascades seasons → episodes → video sources, playback sessions,
  episode watch-progress rows. CMS must require typed confirmation (UI lane).
- `season` delete → cascades its episodes (response reports `episodes_deleted`).
- `episode` delete → cascades its sources/sessions/progress.
- Rollback of the season migration is **forward-compatible**: it refuses while seasons
  beyond season 1 exist, while per-series episode numbers would collide, or while
  episode-progress rows exist. It never silently deletes viewer history.

### Backfill (migration `2026_11_10_120000`)

Every existing series gains a published season 1; every existing episode is attached to
it with its identity (id, number) preserved. The migration **refuses** to install
constraints if any episode cannot be mapped (step 1 of the strategy: no silent repairs).

### Exactly-one-owner CHECK constraints

Migrations `2026_11_10_120100` (watch_progress) and `2026_11_10_120200`
(video_sources, playback_sessions) install real `CHECK ((movie_id IS NULL) <>
(episode_id IS NULL))` constraints plus per-owner partial unique indexes on
`watch_progress` (`(user_id, movie_id) WHERE movie_id IS NOT NULL`, and the episode
equivalent). PostgreSQL uses `ALTER TABLE ADD CONSTRAINT`; SQLite uses twelve-step
table rebuilds under `PRAGMA defer_foreign_keys` (no `ALTER ADD CONSTRAINT` exists
there). Both drivers verified — see validation record.

## API surface

All paths relative to `/api/v1`. Envelope, pagination, error contracts, throttles,
audit logging, SSRF-guarded source validation and signed playback are reused unchanged.

### Public (additive; existing movie and series contracts preserved)

| Endpoint | Behavior |
| --- | --- |
| `GET /series` | Unchanged filters/sort/pagination. `episode_count` and new `season_count` count only published-chain rows; `genres` added. |
| `GET /series/{slug}` | Adds `seasons: [{id, season_number, title, synopsis, poster_url, backdrop_url, episode_count, episodes[]}]`. Flat `episodes` kept for compatibility, ordered by the catalog rule, each episode gains `season_id`/`season_number` and sources gain `supported`. Unpublished series 404 (admin draft preview sees everything). |
| `GET /series/{slug}/seasons` | **New.** Published seasons (admins see all incl. `published` flag), catalog order, with visible `episode_count`. |
| `GET /series/{slug}/seasons/{seasonNumber}/episodes` | **New.** Season addressed by number *within* the series (mismatch ⇒ 404). Published episodes only for viewers; `published` flag included for admins. |
| `POST /playback/sessions` `{episode_id}` | Contract unchanged; entitlement now requires episode **and season and series** published (shared 403 `movie_unpublished` reason, no URL leak). Renewal re-checks the full chain. |
| `POST /watch-progress` | `{movie_id}` **XOR** `{episode_id}` (both/neither ⇒ 422), plus existing `position_seconds`, optional `duration_seconds`/`completed`. Episode must pass the full publication chain (422 otherwise). Upsert per (user, owner): 201 create / 200 update. |
| `GET /watch-progress` | Adds optional `episode_id` filter (mutually exclusive with `movie_id`, 422 if both). Rows gain `type: "movie"\|"episode"`; episode rows carry `episode {id, episode_number, season_number, title, duration_seconds, still_url, series {id, slug, title, poster_url}}`. Movie rows keep their Phase 1 shape exactly. Rows whose content was unpublished afterwards are hidden but retained. |
| `GET /home` | `continue_watching` now mixes movie rows (unchanged Phase 1 shape, `type:"movie"`) and episode rows (`type:"episode"` with `episode`/`series` context and progress fields). Still owner-scoped, incomplete-only, hierarchy-visibility-filtered, empty for anonymous. |

Series-level watchlist ("My List for series") is **deliberately deferred**: the
architecture plan requires deciding container-bookmark semantics before touching the
watchlist contract. Movie watchlist is untouched.

### Admin (`role:admin`; permissions `series.view/create/update/delete/publish` added to RBAC)

| Endpoint | Behavior |
| --- | --- |
| `POST /admin/series` | **New.** Full create (title required, slug unique, genres by slug, published/featured). Auto-creates season 1. 201 + audit `series.created`. |
| `PUT/PATCH /admin/series/{id}` | Now full `UpdateSeriesRequest` validation (superset of the legacy inline rules); genres sync when supplied. Audit `series.updated`. |
| `DELETE /admin/series/{id}` | **New.** Documented cascade; audit `series.deleted` with pre-delete snapshot (never source URLs). |
| `GET /admin/series`, `GET /admin/series/{id}` | Additive `season_count` / nested `seasons[].episodes[]` (admin shape incl. source URLs); flat `episodes` kept for the existing CMS. |
| `POST /admin/series/{id}/seasons` | **New.** `season_number` 0–999 unique per series (0 = specials). Audit `series_season.created`. |
| `GET/PATCH/DELETE /admin/series/{id}/seasons/{season}` | **New.** Ownership guard: season of another series ⇒ 404. Delete cascades episodes and reports `episodes_deleted`. Audits `series_season.updated` / `.deleted`. |
| `POST /admin/series/{id}/seasons/{seasonId}/episodes` | **New.** `episode_number` unique per season; sources required on create with Phase 1 movie semantics (SSRF-guarded URLs, HLS path rule, one default, unique labels, `in:hls,dash,mp4` with DASH storable-but-unsupported). Audit `series_episode.created`. |
| `GET/PATCH/DELETE .../episodes/{episode}` | **New.** Guards both parents (404 on mismatch). `sources` omitted ⇒ preserved; supplied ⇒ wholesale replacement (Phase 1 semantics). Audits `.updated` / `.deleted`. |
| `PATCH /admin/series/{id}/episodes/{episode}` | **Legacy route kept** for the current CMS publication toggle; same guards and audit as before. |

Responses for nested writes return the refreshed parent (`AdminSeriesResource` /
`AdminSeasonResource`) so the CMS can re-render in place.

### Example: episode playback + progress round trip

```jsonc
POST /api/v1/playback/sessions          // Authorization: Bearer …
{"episode_id": 42}
// 201 → {"data":{"session_id":"…","movie":null,
//   "episode":{"id":42,"episode_number":3,"title":null,
//     "season":{"id":7,"season_number":1},
//     "series":{"id":5,"slug":"…","title":"…"}},
//   "source":{"label":"main","kind":"hls",…},
//   "url":"https://…signed…","signature_required":true,
//   "credentials_required":false,"renew_after_seconds":540,"expires_at":"…"}}

POST /api/v1/watch-progress
{"episode_id":42,"position_seconds":310,"duration_seconds":1200}
// 201 → {"data":{"id":9,"type":"episode","episode":{…,"season_number":1,…},
//   "position_seconds":310,"duration_seconds":1200,"completed":false,…}}

POST /api/v1/watch-progress
{"episode_id":42,"position_seconds":1200,"duration_seconds":1200,"completed":true}
// 200 (upsert) → completed:true; drops off /home continue_watching
```

## Visibility rule (single source of truth)

An episode is publicly exposed/playable **iff** `episode.published && season.published
&& series.published`. Enforced in: `Entitlements::denyReasonForEpisode` (playback +
renewal), `Series::publishedEpisodes()/catalogEpisodes()` (public detail, seasons,
episode listings, index counts, home series rail), `StoreWatchProgressRequest`
(progress writes), and progress/continue-watching reads. Admins keep the explicit
draft-preview exception everywhere.

## Other changes

- `ImportSeries` (`hyperline:import-series`): optional `seasons[]` block and per-episode
  `season` number; flat manifests keep working (everything lands in season 1, matching
  the backfilled shape). Episode keys are now (season, number); duplicates within a
  season are rejected before the transaction. Idempotency, dry-run, SSRF guard,
  never-touch-movies and publication semantics unchanged. Summary gains `seasons` plan.
- New read-only audit command `hyperline:check-playable-ownership [--json]`
  (strategy step 1): inventories migrations and counts ownerless/dual-owner rows in
  `video_sources`, `playback_sessions`, `watch_progress` plus episode↔season mismatches.
  Exit 1 on violations. The constraint migrations additionally refuse to install over
  violating rows.
- `tests/fixtures/seed.php` (browser-test seeder): episodes now attach to a
  `firstOrCreate`d season 1 — required by NOT NULL `season_id`; fixture data unchanged.

## Regression posture

- Bounded token expiry, in-memory auth, 401 event dispatch: untouched.
- HLS/MP4 playback policy; DASH stored-but-unsupported: untouched (episode sources use
  the identical rules; `supported` flag surfaced in public episode source metadata).
- Movie source wholesale-replacement semantics: untouched; mirrored for episodes.
- Movie watchlist/progress/resume/completion: untouched (movie rows byte-identical plus
  additive `type`).
- Signed playback fail-closed + origin protection: untouched (episode path already
  existed; renewal now also re-checks season publication).
- Existing movie URLs and API behavior: untouched.

## Files changed (this task only)

- Migrations: `2026_11_10_120000_add_seasons_and_series_genres.php`,
  `2026_11_10_120100_add_episode_watch_progress.php`,
  `2026_11_10_120200_add_playable_owner_checks.php`
- Models: `Season.php` (new), `Series.php`, `SeriesEpisode.php`, `WatchProgress.php`,
  `Permission.php`
- Support: `Entitlements.php`
- Factories: `SeasonFactory.php` (new), `SeriesEpisodeFactory.php`,
  `WatchProgressFactory.php`
- Requests: `StoreSeriesRequest.php`, `UpdateSeriesRequest.php`,
  `StoreSeasonRequest.php`, `UpdateSeasonRequest.php`, `StoreEpisodeRequest.php`,
  `UpdateEpisodeRequest.php` (all new), `StoreWatchProgressRequest.php`
- Resources: `EpisodeMapper.php`, `AdminSeasonResource.php` (new),
  `SeriesResource.php`, `SeriesDetailResource.php`, `AdminSeriesResource.php`,
  `WatchProgressResource.php`, `PlaybackSessionResource.php`
- Controllers: `Admin/SeriesController.php`, `Admin/SeasonController.php` (new),
  `Admin/EpisodeController.php` (new), `SeriesController.php`,
  `WatchProgressController.php`, `HomeController.php`, `PlaybackSessionController.php`
- Commands: `ImportSeries.php`, `CheckPlayableOwnership.php` (new)
- Routes: `routes/api.php`
- Tests: `SeasonsSchemaTest.php`, `AdminSeriesCrudTest.php`, `SeriesVisibilityTest.php`,
  `EpisodeProgressTest.php`, `EpisodeOrderingTest.php`, `ImportSeriesSeasonsTest.php`
  (all new)
- Fixtures/docs: `tests/fixtures/seed.php`, this file, `PHASE-2-VALIDATION.md`

## Video source formats (Phase 2 extension, user-approved 2026-11-10)

The Phase 1 "HLS/MP4 playable, DASH stored-but-unsupported" policy was
explicitly superseded by the product owner. Current behavior:

- **HLS** — hls.js with manual quality selector; unchanged.
- **MP4** — native `<video>`; unchanged.
- **DASH** — now playable via `dashjs` v5 (dynamic import, automatic ABR; the
  manual quality selector remains HLS-only). DASH rides the same signed-session
  transport and renewal timer as HLS (`dash.attachSource` on renewal). An
  unknown/legacy kind (e.g. stored rows of other values) remains unplayable and
  falls back to a supported source exactly as before.
- **YouTube** — new `youtube` kind. Admin validation accepts
  `youtube.com/watch?v=`, `youtu.be/`, `/embed/`, `/shorts/`, `/live/` links
  with a valid 11-character video id (`App\Rules\YouTubeUrl`), HTTPS enforced by
  the existing SSRF-guarded `SafeStreamUrl`. Playback semantics, deliberately:
  - Sessions are still created and entitlement-gated (publication chain +
    subscription), preserving the authorization contract and audit trail.
  - The URL is returned **unsigned** (`signature_required:false`,
    `renew_after_seconds:null`): signing/origin-protection is meaningless for a
    public third-party embed, and the signer must never see it.
  - The web player renders an `<iframe>` embed (watch/short/youtu.be links are
    converted to `youtube.com/embed/<id>?rel=0&modestbranding=1`); the
    `<video>` element stays mounted but hidden so session plumbing is unchanged.
  - No watch-progress reporting or resume for YouTube embeds (no media element
    events); documented limitation, revisit only with the YouTube IFrame API.

Kinds are validated in `StoreMovieRequest`/`StoreEpisodeRequest`
(`in:hls,dash,mp4,youtube`); `VideoSource::SUPPORTED_KINDS` gained `dash` and
`youtube`, so `playable`/`supported` flags and source selection follow
automatically. CMS format selectors list all four; the "DASH — unsupported"
label is gone.

## Remaining limitations

- Series-level watchlist deferred pending the bookmark-semantics decision (see plan).
- Release scheduling is **not implemented**; `published` is an immediate boolean and no
  scheduling behavior should be assumed.
- Episode progress rows cascade away when an episode/season/series is deleted (documented
  deletion behavior); there is no soft-delete/archive yet.
- CMS season manager UI, web season selector/episode-progress UI and browser E2E
  coverage are the remaining Phase 2 lanes (see validation record for status).
- Production deployment/activation remains a separately approved step; nothing here
  touches the running containers or host configuration.
