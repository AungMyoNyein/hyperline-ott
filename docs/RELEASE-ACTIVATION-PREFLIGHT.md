# Release activation preflight — 2026-11-09

**GO for artifact review; production execution remains pending explicit approval.** Backup/restore was subsequently completed: see [BACKUP-RECOVERY-VERIFICATION.md](BACKUP-RECOVERY-VERIFICATION.md). No deployment/recreation, service stop/restart, gateway/host reload, certificate issuance, CDN/origin change, database mutation or volume deletion was performed. Separate approvals remain required. This preflight refines the existing activation sequence; its service-specific commands below supersede the earlier grouped `up` commands.

## Verified artifacts

Release `runtime-20261109T003915Z`, project `hyperline`.

| Role | Verified immutable local image ID |
| --- | --- |
| API, worker, scheduler | `sha256:f41ea31f7a445fd53408b5d700006f7478a41e9a6f88534081748ec75084c933` |
| Web | `sha256:4112bc1c5bf3b0d32f3c689d94e9e094252aac44b6b018454f0dcbe15c5702f7` |
| CMS | `sha256:4fbd8f14bb7553c3d59584bba2ae6726027fec695e19e75422c7b35fb9706f3f` |
| Guarded API rollback | `sha256:6cc9cbfacafd1d043a254a0189282e1d6a2d187fbd55f09f7576ff2b486db837` |

The release tags are `hyperline-api:runtime-20261109T003915Z`, `hyperline-web:runtime-20261109T003915Z`, `hyperline-cms:runtime-20261109T003915Z`; guarded fallback is `hyperline-api:rollback-guarded-runtime-20261109T003915Z`. Captured Web/CMS/worker/scheduler rollback tags also match their original image IDs. Raw legacy API rollback is forbidden.

Reproducible read-only checker: `python3 deploy/check-release-preflight.py`. Sanitized evidence: `deploy/releases/runtime-20261109T003915Z/activation-preflight.json`, `dependency-preflight.json`, `preflight-api.log`, `preflight-guarded-api.log`. Full original/new image/container/mount metadata remains in `manifest.json`. No secret values are recorded in these reports.

## Recreation scope and dependencies

| Compose service / current container | Required action | Dependencies |
| --- | --- | --- |
| api / hyperline-api-1 | Recreate with verified API image | Existing HyperLine PostgreSQL/Redis |
| web / hyperline-web-1 | Recreate with verified Web image | None declared |
| cms / hyperline-cms-1 | Recreate with verified CMS image | None declared |
| worker / hyperline-worker-1 | Recreate with same API image; corrected probe | API/PostgreSQL/Redis |
| scheduler / hyperline-scheduler-1 | Recreate with same API image; new runner/probe | API/PostgreSQL/Redis |
| gateway / hyperline-gateway-1 | No recreation; separately approved graceful in-container reload | Web/CMS/API upstream resolution |
| postgres, redis | Keep running, no recreation | Existing data volumes |

The gateway uses static Nginx DNS resolution: reload its unchanged configuration after replacing API/Web/CMS to refresh upstream IPs. It stays bound only to `127.0.0.1:18080`. No service publishes PostgreSQL/Redis or application ports on the host. Optional gateway Upgrade configuration remains separate from this reload.

No `nginx.service`, `vpn-api.service`, `vpn-control-plane.service`, `vpn-node-agent.service`, `xray-vless.service`, `shadowsocks-libev.service`, WireGuard, host PHP-FPM, Docker Engine or host DB/Redis service is in the recreation commands. Existing host Nginx/Zentunnel file hash is unchanged. Protected services are active and `wg0` is UP; these observations do not prove live client traffic continuity.

## GO/NO-GO checklist

| Result | Gate |
| --- | --- |
| PASS | All four release/guarded image IDs verified; original rollback images present |
| PASS | All eight HyperLine container IDs/images/start times/restart policies unchanged; ownership is project hyperline |
| PASS | Release and running mount identities agree: hyperline_pgdata, hyperline_redisdata, hyperline_api_storage, hyperline_api_public; existing gateway bind unchanged |
| PASS | `.env` mode 0600; APP_KEY shape valid; existing API/worker/scheduler APP_KEY and DB/Redis secrets equal release configuration; production/debug=false |
| PASS | API/worker/scheduler target existing private HyperLine DB/Redis; credentials agree with running DB/cache containers; no secret values printed |
| PASS | PostgreSQL SELECT 1 and Redis PING succeed; all release migration files match running files and are already applied; no migration needed |
| PASS | Both API images pass isolated production-mode 401/403/422/opaque500, credentialed CORS and authenticated valid-movie fail-closed 503 checks; networking disabled, no production data/volumes/env used |
| PASS | Release pins exact OTT/CMS API origins, signing required, external/legacy CDN verification false |
| PASS | Compose release/guarded rollback validate; host/gateway Nginx syntax validates without reload |
| PASS | DNS for all three names resolves only to 157.85.104.49 in current resolver results |
| PASS / UPDATED | Protected configuration/storage/PostgreSQL backup exists at `/var/backups/hyperline/runtime-20261109T003915Z`; verified 2026-11-09T02:04:29Z |
| PASS / UPDATED | Full isolated PostgreSQL restore and all nine migrations verified; configuration/archive checks pass. Off-host retention NOT TESTED |
| PENDING APPROVAL | Recreate the five application roles and gracefully reload HyperLine gateway; no approval supplied |
| NOT TESTED | New images against live production configuration, post-activation probes/browser checks and real WG/VLESS client traffic/forwarding/DNS |
| FAIL / SEPARATE TLS GATE | HyperLine certificate/key absent; existing certificate covers zentunnel.net only; ACME email/external challenge checks and separate host-change/issuance approval required |
| NOT TESTED / PLAYBACK BLOCKER | Existing-CDN signing/access-control protocol, credentials, protected nested HLS/CORS/renewal; playback must stay unavailable |

Earlier full-suite evidence remains 178 Laravel tests/780 assertions, five browser tests passed, Web/CMS builds/typechecks passed. This phase reran the isolated image contracts and read-only infrastructure checks; it did not repeat the full source suite. Post-activation checker below was syntax-checked but deliberately not run against the legacy containers.

## Before any recreation: backup gate

This gate is now satisfied by [BACKUP-RECOVERY-VERIFICATION.md](BACKUP-RECOVERY-VERIFICATION.md). The original creation commands in [ACTIVATION-SEQUENCE.md](ACTIVATION-SEQUENCE.md), section 1, are retained as reference; do not rerun against the existing path. pg_dump/pg_restore tools are present. Require a successful custom-format dump, `pg_restore --list`, archive integrity checks, protected configuration copies, and isolated restore verification before application changes. A dump listing alone does not establish restorability. Never restore into the live DB as a test. The original preflight did not create a backup; the later authorized backup/recovery verification did, without production service changes.

Proposed isolated restore verification **after backup and explicit execution approval**, in the same root Bash session as the backup commands:

```bash
hyperline_restore_name=hyperline-restore-check-runtime-20261109T003915Z
hyperline_restore_image=sha256:57c72fd2a128e416c7fcc499958864df5301e940bca0a56f58fddf30ffc07777
# Abort on a name collision; never remove a pre-existing container.
if docker container inspect "$hyperline_restore_name" > /dev/null 2>&1; then
  echo 'Restore-check container already exists; stop for review' >&2
  exit 1
fi
test -s "$hyperline_runtime_backup/postgres.dump"
docker run -d --name "$hyperline_restore_name" --network none --memory 768m --cpus 0.5 \
  --label hyperline.purpose=isolated-restore-check \
  --mount type=tmpfs,destination=/var/lib/postgresql/data,tmpfs-size=536870912 \
  --env POSTGRES_HOST_AUTH_METHOD=trust "$hyperline_restore_image"
# No production network/ports/volumes; trust is confined to this disposable container.
hyperline_restore_ready=false
for attempt in $(seq 1 60); do
  if docker exec "$hyperline_restore_name" pg_isready -U postgres > /dev/null 2>&1; then
    hyperline_restore_ready=true
    break
  fi
  sleep 1
done
test "$hyperline_restore_ready" = true
docker exec "$hyperline_restore_name" createdb -U postgres hyperline_restore_check
docker exec -i "$hyperline_restore_name" pg_restore -U postgres -d hyperline_restore_check \
  --no-owner --no-privileges --exit-on-error < "$hyperline_runtime_backup/postgres.dump"
docker exec "$hyperline_restore_name" psql -U postgres -d hyperline_restore_check \
  -v ON_ERROR_STOP=1 -Atc 'SELECT count(*) FROM migrations;'
test "$(docker inspect --format '{{ index .Config.Labels "hyperline.purpose" }}' "$hyperline_restore_name")" = isolated-restore-check
docker rm -f "$hyperline_restore_name"
```

This removes only the new, verified restore-check container; its data is tmpfs, not a Docker volume. If verification fails, do not deploy; inspect privately and remove only that correctly labelled test container with the same checked cleanup commands. Adjust disposable resource limits after reviewing dump size and host capacity; never reuse a production volume. Preserve backup artifacts in a protected durable/off-host location. This is a restore test, not a rollback command or a production database restore.

## Ordered application activation — approval required, NOT executed

Proceed only after backup/restore and operator VPN traffic baselines pass. Run in root Bash; expect brief HyperLine app interruptions. Reverify the image/config preflight immediately before executing. `--no-deps`, explicit service names, `--no-build` and `--pull never` prevent unrelated recreation or image replacement. Timeouts allow legacy processes to stop safely.

```bash
set -euo pipefail
cd /opt/hyperline-ott
export HYPERLINE_RELEASE=runtime-20261109T003915Z
python3 deploy/check-release-preflight.py
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml config --quiet
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --pull never --timeout 30 --wait --wait-timeout 180 api
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --pull never --timeout 30 --wait --wait-timeout 180 web
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --pull never --timeout 30 --wait --wait-timeout 180 cms
# The following graceful gateway reload must be explicitly included in approval.
docker compose -p hyperline exec -T gateway nginx -t
docker compose -p hyperline exec -T gateway nginx -s reload
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --pull never --timeout 120 --wait --wait-timeout 240 worker
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --pull never --timeout 75 --wait --wait-timeout 240 scheduler
python3 deploy/check-activated-release.py
```

This does not reload host Nginx or edit gateway configuration. Stop on any failure; do not continue to TLS. Do not invoke the baseline checker after recreation as a rollout success check: it intentionally requires the original container IDs. The post-activation checker instead requires the new images and healthy application containers while retaining protected containers and all mounts.

## Immediate post-activation checks

`deploy/check-activated-release.py` checks new running image IDs, health of all five roles, worker/scheduler heartbeat/process/DB/Redis checks, protected container IDs, all persistent mounts, gateway loopback binding, Host-routed Web/CMS200, API health200, missing/JSON/HTML Accept401 JSON, invalid login422, both credentialed OPTIONS204 and actual401 CORS, and untrusted-origin rejection. It suppresses response bodies and credentials.

Authenticated checks require an approved existing ordinary viewer and published movie with an enabled source. No account/content seeding is authorized. Supply the token privately, never as a command argument or paste into logs:

```bash
read -r -s -p 'Approved existing ordinary-viewer token: ' HYPERLINE_CHECK_VIEWER_TOKEN
printf '\n'
export HYPERLINE_CHECK_VIEWER_TOKEN
read -r -p 'Approved existing movie ID: ' HYPERLINE_CHECK_MOVIE_ID
export HYPERLINE_CHECK_MOVIE_ID
# Optional: existing session public_id UUID; NOT the numeric DB ID.
read -r -p 'Existing session public_id UUID, or blank: ' HYPERLINE_CHECK_SESSION_ID
export HYPERLINE_CHECK_SESSION_ID
python3 deploy/check-activated-release.py
unset HYPERLINE_CHECK_VIEWER_TOKEN HYPERLINE_CHECK_MOVIE_ID HYPERLINE_CHECK_SESSION_ID
```

Expect ordinary viewer admin403, playback503 with no URL, renewal403/404/503 depending on ownership/session state, never an unsigned URL. Unavailable checks print NOT TESTED rather than claiming success. Use an approved administrator in a real browser for CMS login/dashboard/movie listing/logout and OTT browsing; no movie edits/deletions are needed for activation. Do not generate intentional 500s in production; opaque500 is proven by the isolated image test. Run real browser CORS/credential/TLS checks after the separate TLS phase. Keep WG/VLESS client transfers/DNS/forwarding checks running throughout; require protected service states and original Zentunnel vhost hash to agree afterward.

## Rollback triggers and exact application recovery

Stop the rollout and recover the affected role on wrong image/ownership/mount/port, dependency failure, readiness timeout, gateway502/503, unexpected API500, wrong API/CORS contract, unhealthy replacement CLI service, broken CMS/Web routing, any unsigned/source URL disclosure, or any VPN disruption. For a VPN disruption, do not restart VPN services: halt HyperLine changes, recover only approved app/gateway changes, and escalate to the operator. Source disclosure is a security incident: do not restore the raw legacy API.

Prefer Web/CMS-only fallback for frontend faults while retaining the secure new API. For API faults use the tested guarded fallback, which disables playback. Full application recovery, only with explicit approval:

```bash
cd /opt/hyperline-ott
docker compose -p hyperline -f compose.yaml -f deploy/releases/runtime-20261109T003915Z/rollback.compose.json config --quiet
docker compose -p hyperline -f compose.yaml -f deploy/releases/runtime-20261109T003915Z/rollback.compose.json up -d --no-deps --no-build --pull never --timeout 30 api
docker compose -p hyperline -f compose.yaml -f deploy/releases/runtime-20261109T003915Z/rollback.compose.json up -d --no-deps --no-build --pull never --timeout 30 web
docker compose -p hyperline -f compose.yaml -f deploy/releases/runtime-20261109T003915Z/rollback.compose.json up -d --no-deps --no-build --pull never --timeout 30 cms
docker compose -p hyperline exec -T gateway nginx -t
docker compose -p hyperline exec -T gateway nginx -s reload
docker compose -p hyperline -f compose.yaml -f deploy/releases/runtime-20261109T003915Z/rollback.compose.json up -d --no-deps --no-build --pull never --timeout 120 worker
docker compose -p hyperline -f compose.yaml -f deploy/releases/runtime-20261109T003915Z/rollback.compose.json up -d --no-deps --no-build --pull never --timeout 75 scheduler
```

Use only the required service line(s), then refresh gateway resolution if an HTTP upstream changed. Do not include the release override in fallback commands. Recovery now allows worker120s/scheduler75s; original commands/probes/restart policies are preserved. Legacy worker/scheduler probes remain unhealthy, so `--wait` is deliberately omitted. Verify process activity/dependencies privately; require API JSON/CORS and guarded playback503, Web/CMS routing, protected container/mount integrity and VPN traffic. The release post-checker is for the new release only, not fallback-image success. Never reset/restore the production DB as routine application recovery.

## Separate TLS/Nginx activation — NOT executed

After the application checks pass, obtain separate explicit approval for each phase in [../deploy/https/OPERATIONS.md](../deploy/https/OPERATIONS.md):

1. Phase A: configuration/certificate-renewal backups; only three new HTTP ACME vhosts/webroot; `nginx -t`; approved graceful host reload; external challenge GETs. Keep existing Zentunnel routes/certificate untouched.
2. Phase B: approved ACME email; new HyperLine-only SAN lineage `hyperline-ott`, webroot staging dry-run then approved real `certonly`; no Nginx installer, standalone mode or service stops.
3. Phase C: verify SAN/chain/key permissions, install only the three new TLS vhosts/map/snippet/scoped renewal hook; `nginx -t`; approved graceful host reload. Test normal HTTPS without `-k`, HTTP308, health, credentialed CORS, browser CMS/OTT, VPN continuity, and original vhost hash.
4. On TLS failure, use the exact OPERATIONS rollback to return only the three new links to HTTP503 bootstrap, validate and reload after approval. Do not overwrite all of `/etc/nginx`.

The HTTP bootstrap must precede webroot certificate issuance. Optional two-hop Upgrade changes require their own approval. Keep `cdn.goldendragon2000.com` and the VOD origin unchanged; fully protected playback remains NO-GO until its real authorization/CORS/renewal protocol is verified independently.
