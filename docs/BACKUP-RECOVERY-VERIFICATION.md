# Backup and recovery verification — 2026-11-09

**GO to request scoped HyperLine application activation approval. Production activation is still on hold.** Backup/isolated-restore blockers have cleared. TLS/host Nginx activation and functional protected CDN playback remain separate NO-GO stages until their requirements/approvals are satisfied. No production container was recreated, stopped or restarted, no Nginx process reloaded, and no certificate or VPN/CDN/origin configuration changed.

## Backup and restore results

Verified at `2026-11-09T02:04:29Z` (09:04:29 Asia/Bangkok). A fresh backup was created at `/var/backups/hyperline/runtime-20261109T003915Z` because the documented backups did not yet exist. The backup directory is root-owned mode 0700; secret configuration and database contents were not printed or copied into source control.

| Result | Verification |
| --- | --- |
| PASS | Root-only environment, Compose/release override, release/rollback manifest, gateway configuration, complete host Nginx tree, certificate renewal configuration/hooks and staged HTTPS files backed up |
| PASS | Configuration source/copy comparisons and private per-file SHA256 index agree; files readable |
| PASS | Fresh consistent PostgreSQL custom-format logical dump; archive TOC includes table data |
| PASS | API storage/public archives fully decompressed/read without extraction |
| PASS | Full `pg_restore --exit-on-error --no-owner --no-privileges` into disposable PostgreSQL database; all nine expected migrations present |
| PASS | Restore target used immutable existing PostgreSQL image, network none, no published ports, tmpfs data and no production/Docker data volumes |
| PASS | Only the uniquely owned restore-test container was removed; no Docker volumes removed |
| PASS | Production container IDs/images/start times and persistent mounts unchanged after backup/restore |

The PostgreSQL image was `sha256:57c72fd2a128e416c7fcc499958864df5301e940bca0a56f58fddf30ffc07777`. Restore database: `hyperline_restore_check`. Roles/ownership/privileges were deliberately omitted on the isolated restore; this verifies application schema/data restorability, not a full PostgreSQL cluster/role disaster recovery. Production roles/credentials are preserved in place. Storage/public archives are live file snapshots rather than an upload-quiesced transaction. Off-host replication is NOT TESTED. Refresh into a new protected snapshot if data/configuration change or activation is delayed; do not overwrite this verified backup.

Private backup records: `file-index.json`, `postgres.contents`, `verification.json`. Sanitized repository evidence: `deploy/releases/runtime-20261109T003915Z/backup-recovery-verification.json`. The verifier is `deploy/verify-backup-recovery.py`: `--create-backup` refuses to overwrite an existing snapshot; without that option it rechecks the existing snapshot and runs another isolated restore. No live database restore is part of application activation or rollback.

## Release and independent recovery checks

The 55 configuration preflight checks pass: immutable release/guarded image IDs, original rollback images, project ownership, environment/secret preservation, private DB/cache destinations, mount identities, fail-closed release settings, dependency scope and loopback gateway binding.

Final checks also PASS: all ten single-service activation/guarded recovery dry-run plans, backup permissions/checksums revalidation, no remaining restore-test containers, original Zentunnel vhost SHA256, protected service states and wg0 UP. Eight mocked deployment tests pass. Dry-run plans deliberately omit simulated health waits: Compose's exact `--wait` simulation inspects the currently unhealthy legacy CLI probes and cannot establish replacement health. Actual activation retains `--wait` and requires the replacement probes to pass; no bypass is proposed.

| Role | Verified immutable image ID |
| --- | --- |
| API/worker/scheduler release | `sha256:f41ea31f7a445fd53408b5d700006f7478a41e9a6f88534081748ec75084c933` |
| Web release | `sha256:4112bc1c5bf3b0d32f3c689d94e9e094252aac44b6b018454f0dcbe15c5702f7` |
| CMS release | `sha256:4fbd8f14bb7553c3d59584bba2ae6726027fec695e19e75422c7b35fb9706f3f` |
| Guarded API fallback | `sha256:6cc9cbfacafd1d043a254a0189282e1d6a2d187fbd55f09f7576ff2b486db837` |

Guarded API was retested by immutable ID in a network-disabled fixture container with disposable SQLite and no production environment/data/volumes. PASS: missing/JSON/HTML Accept401, forbidden403, validation422, opaque500 even with debug, both exact-origin credentialed preflights/actual responses, hostile-origin denial, and authenticated valid-movie playback503 without source disclosure. The fixture container was removed automatically. Raw legacy API rollback remains forbidden.

Legacy worker/scheduler Docker health probes test FPM and remain unhealthy. Independent checks PASS: actual queue worker process, scheduler loop process, PostgreSQL SELECT/Redis PING from both containers, recent normal successful empty scheduler passes, and Host-routed Web/CMS/API health/public catalog200. The initial `docker top -eo args` check failed because Docker required a PID column; direct in-container `/proc` signatures resolved the inspection error. No production queue jobs were injected/consumed by the verification, and no scheduled tasks were forced.

`python3 deploy/check-legacy-recovery.py` performs these independent legacy recovery checks before rollout or after worker/scheduler rollback. It is deliberately for captured legacy CLI images, not replacement-role health. Its evidence is `legacy-cli-recovery-checks.json`. Actual production job processing, due-task execution, browser admin login/CRUD and real VPN client traffic/forwarding/DNS are NOT TESTED. Active protected services/listeners do not substitute for operator client continuity checks before/during/after approved activation.

## Exact application activation — approval required, NOT executed

The backup is already created: do not rerun the old backup-creation block, which intentionally rejects an existing path. Refresh preflight and require operator VPN continuity baselines immediately before application changes. Expect a brief application interruption with single replicas. Only api/web/cms/worker/scheduler may be recreated. Gateway requires its own explicitly approved graceful in-container reload to refresh static upstream DNS; no gateway config edit or container recreation is included.

```bash
set -euo pipefail
cd /opt/hyperline-ott
export HYPERLINE_RELEASE=runtime-20261109T003915Z
python3 deploy/check-release-preflight.py
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml config --quiet
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --pull never --timeout 30 --wait --wait-timeout 180 api
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --pull never --timeout 30 --wait --wait-timeout 180 web
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --pull never --timeout 30 --wait --wait-timeout 180 cms
docker compose -p hyperline exec -T gateway nginx -t
docker compose -p hyperline exec -T gateway nginx -s reload
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --pull never --timeout 120 --wait --wait-timeout 240 worker
docker compose -p hyperline -f compose.yaml -f deploy/runtime-release.compose.yaml up -d --no-deps --no-build --pull never --timeout 75 --wait --wait-timeout 240 scheduler
python3 deploy/check-activated-release.py
```

The future gateway reload affects only HyperLine's internal Nginx, not host Nginx. PostgreSQL/Redis containers, project/volume identities, APP_KEY/secrets, database contents, host Nginx, Zentunnel VPN/API/control-plane/transport services, Docker Engine, external CDN and origin are excluded. API startup copies packaged public files into the existing `api_public` volume; it does not remove/recreate that volume. No migration/reset/down/volume-delete command is included. Production playback remains blocked with signing required and CDN verification false.

Stop and recover the affected application role on readiness timeout, wrong image/mount/binding, API/CORS failures, broken routes, dependency failure or any unsigned/source URL disclosure. On VPN disruption halt HyperLine changes, recover only approved application/gateway changes and involve the operator; do not restart VPN services. Use the exact [guarded fallback commands](RELEASE-ACTIVATION-PREFLIGHT.md#rollback-triggers-and-exact-application-recovery), then `python3 deploy/check-legacy-recovery.py` for the legacy CLI roles. Do not use the release-only post-activation checker as proof of fallback image success. Restore worker120s/scheduler75s stop allowances; known legacy probes remain unhealthy, so fallback commands intentionally omit `--wait`. No production DB restore/reset is routine rollback.

## Approval decision

The application backup/restore preparation is PASS. **Request approval only for the five application recreations, the scoped HyperLine gateway reload, post-activation checks and guarded application recovery on critical failure.** Before execution, record real WG/VLESS client continuity baselines; verification here did not recreate services or prove new images under live configuration.

HTTPS certificate issuance and host Nginx remain a separate approval stage using [../deploy/https/OPERATIONS.md](../deploy/https/OPERATIONS.md). No new certificate is present. Existing-CDN authorization/credentials/CORS/nested-resource renewal remain unverified; enabling protected production playback is a separate NO-GO. No CDN/origin changes, unsigned fallback, new CDN hostname or Zentunnel shutdown are authorized.
