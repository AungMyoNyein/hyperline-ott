# Premium UI UAT release procedure

Target: **157.85.104.49**, Compose project **hyperline**. This procedure is prepared for approval; no activation is authorized by this document. The release packet is under `/root/hyperline-ott/deploy/releases/`; its `source.json` identifies the exact feature-branch commit and `images.json` identifies immutable API/Web/CMS images. Worker and scheduler use the identical API image ID. Packet contents and resolved Compose files are private and must never be committed or pasted into PRs.

The restored running baseline is `runtime-20261109T183604Z`. Its API has nine migration files, while the UAT database already has all **13 applied migrations** after the aborted Phase 2 activation. This UI release runs **no live migrations**. Preserve PostgreSQL, Redis, all named volumes, existing APP_KEY and runtime secrets. The candidate includes the previously reviewed ownership-audit correction. Database compatibility must be checked with both candidate and rollback images against the expanded schema.

## Packet and preflight

The packet contains `inventory.json`, `migrations.json`, `backup.json`, `source.json`, `images.json`, `postgres-validation.json`, build/test logs and a sealed checksum manifest. `private/candidate.compose.json` and `private/rollback.compose.json` pin image IDs and preserve captured environment, commands, health checks and mount mappings. They are complete Compose configurations, used alone with `-p hyperline`; do not combine them with an older release override. Their dollar signs are escaped for normal Compose interpolation. Never print resolved configuration or run with shell tracing.

Set the exact sealed packet path from the release report in an operator root shell:

```bash
set -euo pipefail
umask 077
hyperline_packet=/root/hyperline-ott/deploy/releases/REPLACE_WITH_REVIEWED_PACKET
test -f "$hyperline_packet/SEALED.json"
python3 "$hyperline_packet/release-control.py" verify "$hyperline_packet"
docker compose -p hyperline -f "$hyperline_packet/private/candidate.compose.json" config --quiet
docker compose -p hyperline -f "$hyperline_packet/private/rollback.compose.json" config --quiet
```

Verify host identity, available disk/memory, exact remote feature commit, all image IDs, all 13 migration names, zero pending source migrations, current healthy containers, unchanged environment and mount mappings, and the original gateway/PostgreSQL/Redis container IDs. Stop if there is drift; prepare a fresh packet rather than silently changing a sealed candidate. Confirm protected playback signing remains required and both actual-CDN verification flags remain false. Confirm no concurrent importer/uploads or release operation.

## Fresh backup and isolated restore gate

The preparation snapshot is a fresh, transaction-consistent PostgreSQL logical backup taken while UAT stays running. It proves schema compatibility and ownership auditing on an isolated PostgreSQL 16 restore. It is **not** a quiesced database-plus-files recovery checkpoint. Do not reuse it as the activation-window checkpoint.

Only after activation approval, schedule a brief UAT interruption and block application writers. Gracefully stop **only** the five application roles, including worker/scheduler, then create a new protected backup directory outside the sealed packet. Do not stop gateway/PostgreSQL/Redis. No `compose down`, database restore, volume removal, or host/VPN change is part of this procedure.

```bash
docker compose -p hyperline -f "$hyperline_packet/private/rollback.compose.json" stop --timeout 120 worker
docker compose -p hyperline -f "$hyperline_packet/private/rollback.compose.json" stop --timeout 90 scheduler
docker compose -p hyperline -f "$hyperline_packet/private/rollback.compose.json" stop --timeout 30 api web cms
hyperline_checkpoint=/var/backups/hyperline/premium-ui-$(date -u +%Y%m%dT%H%M%SZ)
test ! -e "$hyperline_checkpoint"
install -d -m 700 "$hyperline_checkpoint"
cp -a "$hyperline_packet/private/runtime.env" "$hyperline_checkpoint/runtime.env"
cp -a "$hyperline_packet/private/rollback.compose.json" "$hyperline_checkpoint/rollback.compose.json"
cp -a /opt/hyperline-ott/compose.yaml /opt/hyperline-ott/deploy/runtime-release.compose.yaml "$hyperline_checkpoint/"
docker exec hyperline-postgres-1 sh -c 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$hyperline_checkpoint/postgres.dump"
docker exec hyperline-postgres-1 sh -c 'exec pg_dumpall -U "$POSTGRES_USER" --roles-only' > "$hyperline_checkpoint/roles.sql"
hyperline_pg_image=$(docker inspect hyperline-postgres-1 --format '{{.Image}}')
docker run --rm --pull never --network none --entrypoint tar --mount type=volume,src=hyperline_api_storage,dst=/snapshot,readonly "$hyperline_pg_image" -C /snapshot -czf - . > "$hyperline_checkpoint/api-storage.tar.gz"
docker run --rm --pull never --network none --entrypoint tar --mount type=volume,src=hyperline_api_public,dst=/snapshot,readonly "$hyperline_pg_image" -C /snapshot -czf - . > "$hyperline_checkpoint/api-public.tar.gz"
tar -tzf "$hyperline_checkpoint/api-storage.tar.gz" > /dev/null
tar -tzf "$hyperline_checkpoint/api-public.tar.gz" > /dev/null
sha256sum "$hyperline_checkpoint"/* > "$hyperline_checkpoint/SHA256SUMS"
```

Before deployment, independently restore that new dump into a uniquely named PG16 container with `--network none`, no published ports, tmpfs data and no existing volume mounts. Use the packet's recorded PostgreSQL image ID. Start candidate API CLI containers sharing only the disposable database network namespace, with generated APP_KEY and array cache/session. Run JSON/text ownership audits (zero violations, 13 migrations), application boot/read checks, and the original API compatibility check. Compare every restored table's row count/fingerprint before and after. Verify storage/public archive contents and checksums. Remove only owned disposable containers. Never restore the checkpoint into live PostgreSQL during normal rollout or rollback. Roles replay, Redis recovery, off-host retention and PITR are separate recovery work.

If this gate fails, leave candidate images inactive and recover the stopped original application roles using the rollback block below. The preparation restore results do not waive the activation-window backup gate.

## Scoped activation — approval required

The future approval must cover application recreation **and** the internal gateway graceful reload. Gateway upstreams use static container DNS; changed application IPs require this reload. Host Nginx/TLS remain untouched. Do not start with this block before the backup/restore gate has passed.

```bash
docker compose -p hyperline -f "$hyperline_packet/private/candidate.compose.json" up -d --no-deps --no-build --pull never --timeout 30 --wait --wait-timeout 180 api
docker exec hyperline-api-1 php artisan hyperline:check-playable-ownership --json
docker compose -p hyperline -f "$hyperline_packet/private/candidate.compose.json" up -d --no-deps --no-build --pull never --timeout 30 --wait --wait-timeout 180 web cms
docker compose -p hyperline -f "$hyperline_packet/private/candidate.compose.json" up -d --no-deps --no-build --pull never --timeout 120 --wait --wait-timeout 240 worker
docker compose -p hyperline -f "$hyperline_packet/private/candidate.compose.json" up -d --no-deps --no-build --pull never --timeout 90 --wait --wait-timeout 240 scheduler
docker exec hyperline-gateway-1 nginx -t
docker exec hyperline-gateway-1 nginx -s reload
curl --fail --silent --show-error https://api.zentunnel.net/api/v1/health
curl --fail --silent --show-error --output /dev/null https://ott.zentunnel.net/
curl --fail --silent --show-error --output /dev/null https://cms.zentunnel.net/
```

Check exact running image IDs, seven healthy services, original protected-service container IDs/mounts, JSON 401 for anonymous private APIs, invalid-login rejection and exact credentialed CORS. Abort on a failed audit, readiness failure, unintended media request or unexpected data/configuration change. Keep the interruption open until browser acceptance succeeds; do not claim zero downtime.

## Scoped rollback — approval required

Use the captured original image IDs and retained expanded schema. No database downgrade or data restore is required. The original API does not have the corrected JSON audit command; its compatibility was checked in the isolated restore. Use the candidate audit against the disposable restore for evidence, not a live migration or an improvised patch to the old container.

```bash
docker compose -p hyperline -f "$hyperline_packet/private/rollback.compose.json" up -d --no-deps --no-build --pull never --timeout 30 --wait --wait-timeout 180 api web cms
docker compose -p hyperline -f "$hyperline_packet/private/rollback.compose.json" up -d --no-deps --no-build --pull never --timeout 120 --wait --wait-timeout 240 worker
docker compose -p hyperline -f "$hyperline_packet/private/rollback.compose.json" up -d --no-deps --no-build --pull never --timeout 90 --wait --wait-timeout 240 scheduler
docker exec hyperline-gateway-1 nginx -t
docker exec hyperline-gateway-1 nginx -s reload
```

Verify original image IDs, commands, environment, mounts, service health, HTTPS and fail-closed playback policy. Preserve gateway/PostgreSQL/Redis container IDs and all persistent volumes. Escalate a failed recovery; never reset data, remove volumes, restart Docker/VPN, or restore unrelated host configuration as a shortcut.

## Browser acceptance and remaining limitations

Use approved existing dedicated UAT viewer/admin accounts and content references after activation. Do not run the disposable fixture harness against UAT. Supply credentials through a protected operator session without logging them. Until accounts are available, authenticated UAT acceptance remains **NOT RUN**.

Check desktop/mobile navigation, sign-in/expiry/logout, catalog and draft visibility, search/genres/artwork fallbacks, series/season ordering and episode switching. Using authorized playable references, check resume and completion, owner-specific progress, transient progress errors preserving media/controls, 401 expiry removing media, HLS/DASH error states, renewal failure detaching transport, and safe unavailable UX. CMS create/edit/delete acceptance needs separately approved disposable content and scoped cleanup; existing content must not be changed for testing.

Actual protected CDN authorization, nested HLS/key/DASH resources, credentialed CORS, renewal and physical Safari/iOS/Android remain independently unverified. Keep signing required, verification flags false, and playback HTTP 503 without unsigned URL/key disclosure. The separate local signed-cookie harness does not prove UAT CDN readiness; YouTube tests verify embed construction only. A UI/API UAT activation can be approved with protected playback disabled, but full playback readiness remains **NO-GO**.
