# Scoped UAT activation and rollback procedure

Prepared for review only. Do not execute until an explicit UAT activation authorization is given. GitHub PR approval does not authorize deployment. DNS, gateway/Nginx, TLS, CDN/origin and Zentunnel VPN are outside this procedure.

1. Verify the approved Git commit, `source-files.sha256`, source archive SHA-256, three application image IDs, and API/worker/scheduler identity in `release-manifest.json`. Verify the active runtime still matches `runtime_before`. Refuse activation if any baseline or live migration status differs from the reviewed packet. Preserve the active runtime Compose configuration and environment privately; never commit resolved environment or credentials.
2. Take a fresh consistent `pg_dump -Fc --no-owner --no-privileges` backup using the existing database service and credentials without printing them. Store it and its SHA-256 in an operator-controlled private directory (0700 directory, 0600 dump). Back up runtime configuration and asset inventory without modifying original URLs or files. Verify free disk space and retained rollback images. Do not delete volumes or prune shared images.
3. Restore the fresh backup into a unique disposable PostgreSQL 16 container on an isolated network/tmpfs. Use test credentials and prevent egress. Run the candidate Laravel migration there; verify row counts/fingerprints for original genre fields, both pivots, movies, series, seasons, episodes, sources, watch history and users. Confirm ownership audit and migration count 13 -> 14, with only the approved migration pending. Do not run the optional genre seed against UAT.
4. Schedule a short controlled window and prevent concurrent editorial writes through the normal operational process. Record all five current application images/container IDs and keep the gateway, Redis and database running. Run exactly the new migration once using a one-off candidate API process with the existing approved UAT database configuration; do not run `migrate:fresh`, reset, import or seed commands. Confirm `migrate:status` and verify all original genre/pivot/publication data unchanged. This step requires separate activation authorization.
5. Apply the candidate image override to `api`, `worker` and `scheduler` first. Verify API health and availability of the new admin genre routes before replacing `web` and `cms`; the new CMS requires the new API. Retain existing runtime configuration and volumes, with no dependency recreation and no build at activation time. API/worker/scheduler must share the exact immutable API ID. Keep gateway, database, Redis, VPN and other services untouched. Record resulting immutable IDs.
6. Verify health, anonymous home/fallback and genre discovery; Burmese/English search, filtering, paging and return navigation; admin authentication, genre validation and counts; movie/series editors and playable-episode actions; official YouTube iframe, HLS/DASH, resume and session expiration; fail-closed protected playback. Use disposable test identities. Read-only checks are preferred; any write probes require explicit test records and must never change existing publication states.
7. Observe application errors and queue health. Release the editorial window only after checks pass. Retain the backup, old images, source manifest, runtime packet and test evidence.

Rollback: use `rollback.compose.json` with the same approved runtime configuration. Restore `web` and `cms` first, then `api`, `worker` and `scheduler`, so new CMS requests never reach an older API. Replace only those five application images. API, worker and scheduler must all return to the prior matching API ID. Keep the new genre columns and homepage table: old code ignores them. Do not invoke migration down/rollback or restore a database merely to roll back application images; those actions could discard editorial updates. Verify health, catalog/publication state, authentication, progress and protected playback after rollback. If database recovery is needed for a separately diagnosed incident, stop and obtain a specific restore authorization with a reviewed recovery point; never overwrite live data as part of routine rollback.

After authorization and all preconditions above, these are the scoped command templates. The operator must supply the privately reviewed runtime file and the candidate/rollback files from this packet; do not substitute a development Compose file.

```bash
# Approved migration only; do not apply unrelated pending migrations.
docker compose -p hyperline -f "$REVIEWED_RUNTIME_COMPOSE" -f "$CANDIDATE_OVERRIDE" run --rm --no-deps --entrypoint php api artisan migrate --path=database/migrations/2026_11_22_120000_add_genre_management_fields.php --force

# Activate backend first; verify health/new genre routes, then activate clients.
docker compose -p hyperline -f "$REVIEWED_RUNTIME_COMPOSE" -f "$CANDIDATE_OVERRIDE" up -d --no-deps --no-build api worker scheduler
docker compose -p hyperline -f "$REVIEWED_RUNTIME_COMPOSE" -f "$CANDIDATE_OVERRIDE" up -d --no-deps --no-build web cms

# Application rollback when required; retain the additive schema and data.
docker compose -p hyperline -f "$REVIEWED_RUNTIME_COMPOSE" -f "$ROLLBACK_OVERRIDE" up -d --no-deps --no-build web cms
docker compose -p hyperline -f "$REVIEWED_RUNTIME_COMPOSE" -f "$ROLLBACK_OVERRIDE" up -d --no-deps --no-build api worker scheduler
```
