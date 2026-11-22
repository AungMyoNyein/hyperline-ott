# Genre management and focused Content Studio — review candidate

This branch continues `feat/compact-spotlight-catalog` at `b789e57428b88a289abae5f536fa81d8e744f933` (PR #3). It preserves the commits in open PRs #1 (premium UI and official YouTube/HLS/DASH), #2 (safe catalog import), #3 (compact Spotlight and movie catalog), and `feat/movies-catalog-ux`. No existing branch was reset, rebased, force-pushed or merged. The parent checkout's API Dockerfile edit, catalog CSV, artifacts and font/remediation worktrees are untouched.

## Findings and implementation

The base already had the approximately 460px Spotlight, eligible featured backdrop and branded fallback, playable-only watch actions, compact Movies search/genre/featured/sort controls, URL-preserved filters, pagination/counts, retry/loading/empty states, keyboard navigation, viewer Myanmar fonts, progress/resume and protected playback. These are retained.

Missing genre administration is now implemented using the existing `genres`, `movie_genre` and `series_genre` tables. Genre administration supports English `name` (also returned as `name_en`), optional Myanmar `name_my`, a unique lowercase hyphenated slug, enabled state, sort order, all-content movie/series counts, audited create/edit/delete and assignment-aware deletion refusal. Disabling removes a genre from public discovery and search suggestions while preserving assignments and existing catalog URLs. Movie/series resources retain editorial genre labels, including disabled assignments. Both assignment transactions lock selected genre rows to serialize with deletion and reject a genre removed after validation.

The public homepage previously repeated identical movie sets and displayed a vertical promotion without any vertical catalog. It now suppresses repeated complete movie rows, omits empty vertical promotion, and includes real enabled-genre discovery links/counts. Dashboard administrators can reorder the seven existing section types; empty sections remain hidden. The featured toggle stays in the movie editor. No content is promoted, published or assigned automatically.

Content Studio now has Dashboard, Movies, Series & episodes, Genres, Artwork, Video Sources, Content QA and Audit & activity. Genre management has bilingual search, CRUD, enable/disable, sort order and safe deletion. Series editors expose the existing multi-genre API instead of silently omitting assignments. Existing season/episode managers and ordering are retained. Artwork previews report natural image dimensions, aspect mismatch and low resolution without replacing any URL. The focused Artwork, Sources and QA panels page through real movie records and open their existing editors; episode artwork/sources remain in the series manager. The CMS uses the same licensed self-hosted Noto Sans Myanmar files and `lang="my"` for added Myanmar text.

No player, signer, session, authentication, CDN, origin, DNS, Nginx, TLS or VPN configuration was changed. No live publication state or genre relationship was modified. Artwork loads directly from its existing location in the browser; screenshot tests intercept artwork with generated local SVG fixtures and do not download third-party assets.

## API contracts

Existing public contracts remain compatible. Additions:

- `GET /api/v1/admin/genres`: all genres, including disabled and draft assignment counts.
- `POST /api/v1/admin/genres`: required `name`, `slug`; optional `name_my`, `enabled`, `sort_order`.
- `PATCH /api/v1/admin/genres/{id}`: partial update with unique slug validation.
- `DELETE /api/v1/admin/genres/{id}`: 204 if unused, 409 if any movie or series assignment exists, including drafts.
- `GET|PATCH /api/v1/admin/homepage`: seven distinct allowed section keys, persisted order. All new admin routes require Sanctum authentication and administrator role.
- Public genre responses add `name_en`, `name_my`, `enabled`, `sort_order`; enabled lists sort by order then English name. Search includes Myanmar genre labels. `/home` adds `section_order` and enabled genre/count data.
- Existing movie/series assignment payloads remain `genres: string[]` of slugs; multiple assignments are preserved.

## Migration and restore validation

`2026_11_22_120000_add_genre_management_fields` adds nullable `genres.name_my`, `genres.enabled DEFAULT true`, `genres.sort_order DEFAULT 0`, an enabled/order index and a singleton `homepage_settings` table with JSON section order. Existing English `name`, unique `slug`, IDs and both pivots are reused. No catalog backfill, publication update or automatic seeding occurs.

This is forward-only: its `down()` explicitly refuses metadata destruction. Application rollback leaves new columns/table in place, which previous images ignore. Do not use `migrate:rollback` as an application rollback.

`tests/validate-genre-postgres.py` takes a private read-only custom-format snapshot and restores it to a unique PostgreSQL 16 container on `--network none` with tmpfs data. The disposable Laravel process shares only that container's network namespace and uses test credentials. The actual migration changed the migration count from 13 to 14; fingerprints of all 25 pre-existing application tables, including original genre columns, were identical before/after. Default enabled/order values were checked, then the optional seed was tested repeatedly only on the disposable restore. See [postgres-restore.json](postgres-restore.json). No UAT migration ran.

Optional suggestions: run `php artisan ott:seed-suggested-genres` only with an isolated local/testing database. Production environments are refused. The command creates missing slugs only, leaves existing editorial labels/state unchanged, and assigns no content. Suggestions include Action/အက်ရှင်, Comedy/ဟာသ, Romance/အချစ်, Drama/ဒရာမာ, Horror/သရဲ, Thriller/ရင်ဖို, Family/မိသားစု, Adventure/စွန့်စားခန်း, Fantasy/စိတ်ကူးယဉ်, Historical/သမိုင်း, Mystery/လျှို့ဝှက်ဆန်းကြယ် and Documentary/မှတ်တမ်း.

## Validation and screenshots

See [laravel-tests.txt](laravel-tests.txt), [web-lint.txt](web-lint.txt), [cms-lint.txt](cms-lint.txt), and the npm/Composer audit JSON files. The browser runner creates disposable fixtures and builds both production Next.js applications under `/tmp`; it never reads deployed environment files or resets a database. Existing visual baselines are refreshed for intentional homepage/CMS changes and then compared by the complete suite.

The added `genre-cms.spec.ts` tests real admin CRUD, Myanmar label filtering, disabling, homepage order saving, and full-page WCAG 2 A/AA and 2.1 AA axe scans at 360, 390, 768, 1366 and 1920 pixels. Screenshots cover Home, filtered Movies, Genres, movie details, Series, CMS dashboard, CMS genres and CMS movie editor at every width. Fixture content is generated testing data only and is never published to UAT.

## Candidate and activation boundary

`deploy/prepare-genre-candidate.py` builds only committed, secret-free Git archives using the repository Dockerfiles, records OCI source revision, source/archive SHA-256, Git tree IDs and immutable Docker image IDs. Worker and scheduler use exactly the API candidate image. It generates scoped five-service image overrides and rollback overrides. It compares all eight runtime container IDs/image IDs/start times before/after preparation and has no activation code. Local image IDs must be loaded or pushed/pulled to the activation host and verified before use; a local ID is not a published registry digest.

See [UAT-PROCEDURE.md](UAT-PROCEDURE.md) for the separately authorized activation sequence. No deployment is included in this work.

## Limits

Automated browser coverage uses Chromium and deterministic local media/YouTube API fixtures. Native HLS on Safari/iOS, physical devices, live third-party YouTube playback, real CDN authorization and a Burmese reader's typography acceptance require separate manual checks. Existing optional tests skip without their specific CDN/live YouTube/import CSV fixtures. Automated axe checks do not replace screen-reader usability review. Artwork checks are advisory because legitimate thumbnails and portrait/landscape sources vary; original URLs are never rewritten by CMS checks. Focused QA/artwork/source review panels currently cover movies; episodes use the existing series manager. The additive migration must be applied before new API code serves requests.
