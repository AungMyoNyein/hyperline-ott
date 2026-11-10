# Approval-only runtime rollout and HTTPS activation

Final preflight: [RELEASE-ACTIVATION-PREFLIGHT.md](RELEASE-ACTIVATION-PREFLIGHT.md) records the current gates and supersedes the grouped rollout/recovery commands below with service-specific commands, explicit stop timeouts and backup/restore verification.

**Prepared, not executed.** Review [RUNTIME-RELEASE-REVIEW.md](RUNTIME-RELEASE-REVIEW.md). Application recreation, gateway graceful reload, certificate operations and host Nginx changes each require explicit approval. Do not interpret this document as approval. No Zentunnel stop/restart, VPN change, CDN change, data-service recreation or database restore is included.

## 1. Baseline and backup

Backup update: [BACKUP-RECOVERY-VERIFICATION.md](BACKUP-RECOVERY-VERIFICATION.md) records the completed protected backup and successful isolated restore at 2026-11-09T02:04:29Z. Application/TLS changes remain unexecuted. Do not rerun the creation block against the existing backup directory.

Use an operator-controlled root Bash shell. Confirm the current containers still match the release manifest; refresh the routing/service inventory and DNS/AAAA checks. Record real WG/VLESS client handshakes, continuous transfers, forwarding and DNS before changes without logging keys or connection URLs. Verify Nginx, VPN API/control-plane/node-agent, Xray, Shadowsocks, Docker, PostgreSQL and Redis are active. Do not start/stop wg-quick: the existing interface has independent ownership.

The protected backup now exists. The following is the original creation procedure for reference; use a new reviewed snapshot path if a fresh backup is needed, never overwrite the verified backup:

```bash
set -euo pipefail
cd /opt/hyperline-ott
export HYPERLINE_RELEASE=runtime-20261109T003915Z
hyperline_runtime_backup=/var/backups/hyperline/runtime-20261109T003915Z
test ! -e "$hyperline_runtime_backup"
install -d -m 700 "$hyperline_runtime_backup"
install -m 600 .env "$hyperline_runtime_backup/hyperline.env"
cp -a compose.yaml deploy/runtime-release.compose.yaml "$hyperline_runtime_backup/"
cp -a deploy/releases/runtime-20261109T003915Z "$hyperline_runtime_backup/release"
cp -a /etc/nginx "$hyperline_runtime_backup/nginx"
cp -a /etc/letsencrypt/renewal "$hyperline_runtime_backup/renewal"
cp -a infra/nginx/conf.d/00-app.conf "$hyperline_runtime_backup/gateway.conf"
sha256sum /etc/nginx/sites-available/zentunnel > "$hyperline_runtime_backup/zentunnel.sha256"
docker compose exec -T postgres sh -c 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$hyperline_runtime_backup/postgres.dump"
docker compose exec -T api tar -C /var/www/html/storage -czf - . > "$hyperline_runtime_backup/api-storage.tar.gz"
docker compose exec -T api tar -C /var/www/html/public -czf - . > "$hyperline_runtime_backup/api-public.tar.gz"
docker compose exec -T postgres pg_restore --list < "$hyperline_runtime_backup/postgres.dump" > "$hyperline_runtime_backup/postgres.contents"
tar -tzf "$hyperline_runtime_backup/api-storage.tar.gz" > /dev/null
tar -tzf "$hyperline_runtime_backup/api-public.tar.gz" > /dev/null
```

The storage archive is a live file snapshot; coordinate an upload-free window if a consistent upload snapshot is required. Validate a DB restore only in an isolated database before execution. Preserve Redis volume and configuration; do not flush or replace shared Redis. Keep backups outside web roots and source control. No `.env` or database content should be printed. No migration is required for this runtime-only release; any future schema operation needs separate review.

## 2. Verify immutable artifacts and configuration

```bash
python3 - <<'PY'
import json, subprocess
from pathlib import Path
m=json.loads(Path('deploy/releases/runtime-20261109T003915Z/manifest.json').read_text())
for record in [*m['built_images'].values(),m['guarded_api_rollback']]:
    actual=subprocess.check_output(['docker','image','inspect',record['reference'],'--format','{{.Id}}'],text=True).strip()
    assert actual==record['image_id'], 'Image mismatch: stop rollout'
    print('Verified',record['reference'],actual)
PY
docker compose -f compose.yaml -f deploy/runtime-release.compose.yaml config --quiet
docker compose -f compose.yaml -f deploy/releases/runtime-20261109T003915Z/rollback.compose.json config --quiet
nginx -t
docker compose exec -T gateway nginx -t
```

API/Web/CMS release images are already built. Do not rebuild at activation unless new changes have been tested and assigned a fresh release/rollback manifest. Never deploy host browser-test `.next` artifacts: Docker release builds baked in `https://api.zentunnel.net/api/v1`. Preserve APP_KEY, database/cache credentials, project `hyperline`, volume names and loopback binding. The release override explicitly keeps signing required and both CDN verification flags false.

## 3. Approved HyperLine application rollout only

Expect a brief application interruption with single replicas. Leave database, Redis, gateway container and all Zentunnel services running.

```bash
docker compose -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --wait --wait-timeout 180 api web cms
docker compose -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --timeout 120 --wait --wait-timeout 240 worker scheduler
docker compose exec -T api php artisan migrate:status
docker compose exec -T worker php docker/healthcheck.php worker
docker compose exec -T scheduler php docker/healthcheck.php scheduler
```

The gateway's static Nginx upstream names resolve on load. Recreating applications can change their IPs. **Include a graceful HyperLine gateway reload in the explicit rollout approval**, without editing its mounted configuration or recreating it:

```bash
docker compose exec -T gateway nginx -t
docker compose exec -T gateway nginx -s reload
```

This reload refreshes upstream resolution; it is not a host Nginx reload. If it was not approved, stop before rollout rather than risk stale upstreams. Optional Upgrade/streaming configuration changes remain a distinct approval in OPERATIONS Phase D.

Verify before any certificate/host activation:

```bash
curl --fail --max-time 10 -H 'Host: ott.zentunnel.net' http://127.0.0.1:18080/ > /dev/null
curl --fail --max-time 10 -H 'Host: cms.zentunnel.net' http://127.0.0.1:18080/ > /dev/null
curl --fail --max-time 10 -H 'Host: api.zentunnel.net' http://127.0.0.1:18080/api/v1/health
curl -i --max-time 10 -H 'Host: api.zentunnel.net' http://127.0.0.1:18080/api/v1/me
curl -i --max-time 10 -H 'Host: api.zentunnel.net' -H 'Accept: application/json' http://127.0.0.1:18080/api/v1/me
for origin in https://ott.zentunnel.net https://cms.zentunnel.net; do
  curl -i -X OPTIONS --max-time 10 -H 'Host: api.zentunnel.net' -H "Origin: $origin" -H 'Access-Control-Request-Method: POST' -H 'Access-Control-Request-Headers: Authorization,Content-Type' http://127.0.0.1:18080/api/v1/playback/sessions
done
docker compose ps
```

Expect 200 pages/health, 401 JSON for both protected requests, and 204 preflights with exact Origin and Allow-Credentials:true. Require worker/scheduler health and production DB/Redis SELECT/PING via sanitized application dependency checks. Use an approved existing account to verify admin authorization and renewal denial without creating test production data or logging tokens. Do not add a deliberate server-error route to production. Stop and recover if critical checks fail.

## 4–7. Approved HTTP bootstrap, SAN issuance, TLS installation and host reload

Use the exact reviewed commands in [../deploy/https/OPERATIONS.md](../deploy/https/OPERATIONS.md), Phases A, B and C, in order. Phase A backs up host configuration and installs only three NEW HTTP ACME bootstrap vhosts, validates Nginx and gracefully reloads after approval. The HTTP bootstrap **must precede** webroot issuance; do not install final HTTPS files with nonexistent certificate paths.

Verify ACME challenge GET externally on all three names before issuance. Set the operator-approved `HYPERLINE_ACME_EMAIL`. Issue only lineage `hyperline-ott`, SANs ott/cms/api.zentunnel.net, using `certbot certonly --webroot` (staging dry-run, then separately approved real issuance). Preserve the existing zentunnel.net lineage/renewal and all VPN routes. Never use standalone or stop Nginx. Inspect the new SAN, chain, expiry and root-only private-key access without printing the key.

Install only the three separate TLS sites, scoped HTTP map/proxy snippet and HyperLine renewal hook, as specified in Phase C. Run `nginx -t`, then the approved graceful host Nginx reload. Compare the original Zentunnel hash. Do not replace any existing file on a name collision or edit a live gateway bind mount as part of host TLS setup.

## 8–9. Public HTTPS and VPN verification

Run the OPERATIONS verification checklist with normal certificate validation (never `-k`): all three public names, correct app identity, HTTP308/path/query, ACME exemption, API health and 401 JSON both Accept modes. Repeat exact-origin credentialed preflight over HTTPS and a real browser CMS login/logout/admin request plus OTT session/renewal handling. Check forbidden/validation behavior with authorized fixtures or existing accounts; no production test seeding.

Keep WG/VLESS client traffic and DNS checks running before/during/after both gateway and host reloads. Verify VPN API/provisioning, forwarding, host Nginx, PostgreSQL/Redis and existing CDN remain available. Active units alone cannot prove traffic continuity. Production video stays unavailable until real existing-CDN authorization is independently verified; API CORS success does not clear CDN CORS/security blockers.

## 10. Recovery if a critical check fails

For host TLS failures use OPERATIONS rollback: return only the three new symlinks to the HTTP503 bootstrap, `nginx -t`, approved reload. Do not overwrite the entire host configuration backup or change existing certificates. Keep optional gateway configuration recovery separate.

For application failures prefer rolling back only the affected role. Full application fallback commands, **approval required**, are:

```bash
docker compose -f compose.yaml -f deploy/releases/runtime-20261109T003915Z/rollback.compose.json up -d --no-deps --no-build api web cms
docker compose -f compose.yaml -f deploy/releases/runtime-20261109T003915Z/rollback.compose.json up -d --no-deps --no-build --timeout 120 worker scheduler
docker compose exec -T gateway nginx -t
docker compose exec -T gateway nginx -s reload
```

Do not add the release override to fallback commands. The fallback API is the tested **guarded** image and intentionally returns 503 for playback; the raw legacy API is forbidden. Captured worker/scheduler probes return to their known unhealthy baseline, so do not use `--wait` as a rollback success criterion; verify processes/dependencies and report the degraded health. Recheck gateway routes, API errors/CORS, dependencies and VPN traffic. No DB restore/reset, `compose down`, volume removal, Docker restart or VPN service restart is part of rollback.
