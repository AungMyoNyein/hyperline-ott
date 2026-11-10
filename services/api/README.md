# HyperLine Laravel API

The API exposes routes under `/api/v1`: public health, home, movie catalog and slug detail, genres and search; subscriber registration/login/logout, profile, watchlist, progress, playback sessions and token renewal; administrator login/logout, dashboard, movie CRUD and audit logs. Run `php artisan route:list --path=api` for methods and paths.

JSON responses wrap resources in `data`. Login returns `data.token`. Movie metadata uses `synopsis`; stream inputs use `sources` (an array containing `label`, `kind`, `url`, and optional quality/language/default/enabled fields). Playback requires a bearer token and `POST /api/v1/playback/sessions` with `movie_id`; the URL is returned in `data.url`. There is no public legacy movie playback endpoint.

## Local development

Run commands from `services/api`. Copy `.env.example` to `.env` only if absent. Generate `php artisan key:generate` only if APP_KEY is empty; never rotate an existing stack key. Create `database/database.sqlite` only if absent, then run `php artisan migrate --force` and `php artisan serve`. This local SQLite database is separate from Docker PostgreSQL. Local secrets and SQLite files are ignored by Git.

`php artisan test` uses a disposable in-memory SQLite database, never Docker PostgreSQL. Movie create/update/delete operations include related sources, genres and audit logs in a transaction.

## Existing Docker stack

From the repository root, use `docker compose exec -T api php artisan migrate --force` for pending migrations and `docker compose exec -T api php artisan migrate:status` to inspect them. Do not use fresh/reset/refresh migrations or remove volumes. Check health using `curl -H 'Host: api.zentunnel.net' http://127.0.0.1:18080/api/v1/health`. This is liveness; migration status checks database connectivity separately.

## Frontend and CDN integration

The CMS and OTT web use the current API contract. See [frontend integration](../../docs/FRONTEND-INTEGRATION.md) for configuration, UI scope, and isolated browser tests. Production playback targets the existing CDN using `ExistingCdnPlaybackSigner` and an operator-specific `ExistingCdnAuthorizer`; the default unconfigured adapter refuses playback with 503. No additional CDN or origin is required. Mock signer tests verify the contract without real CDN credentials. See [CDN playback](../../docs/CDN-PLAYBACK.md) for origin protection, expiry and CORS requirements.

Worker/scheduler source now has role-specific heartbeat/process/database/Redis probes and scheduler signal handling. Current running images retain the old probes until an approved rollout. See ../../docs/DEPLOYMENT-READINESS-RESULTS.md.

Source edits require a separate image rebuild/recreation to take effect in running containers. No host Nginx, DNS, certificates, or existing Zentunnel services need changes for these local checks.

## Phase 1 follow-through

See [implementation contracts and model decisions](../../docs/PHASE-1-IMPLEMENTATION.md) and [current checks](../../docs/PHASE-1-VALIDATION.md). Token lifetimes are clamped to 1–10080 minutes (default 1440). Movie progress accepts an optional movie_id query filter; saved reads hide unpublished titles. HLS/MP4/DASH are supported playback transports; `youtube` sources are returned unsigned (public embeds) while entitlement checks still gate session creation. No Phase 1 schema changes are required.
