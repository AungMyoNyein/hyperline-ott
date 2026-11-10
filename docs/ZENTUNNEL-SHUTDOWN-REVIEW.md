# Zentunnel temporary web/admin maintenance — approval packet

**Production approval gate: STOP HERE. No shutdown, stop/start script, live Nginx configuration change, reload, VPN restart or HyperLine deployment has been executed.** The proposal below is deliberately **partial**: website + CRM administration API maintenance. Full API shutdown cannot satisfy the requirement to preserve VPN provisioning/authentication.

## Verified host and installation

Read-only inspection verified `157.85.104.49` on `eth0`, hostname `ubuntu`. Sanitized evidence is in `deploy/reports/zentunnel-inventory.json`; it excludes environment secrets, command arguments, keys, client identifiers and cookie values. The inventory records original restart policies and enabled states.

- `/var/www/vpn-crm` is the built static website (`index.html`, assets), not the Laravel application.
- Laravel is `/root/VPN/backend` (`artisan`, routes, application code). `vpn-api.service` runs its PHP/Artisan server, working directory `/root/VPN/backend`, with its child listening on `127.0.0.1:8000`.
- Host Nginx's enabled site is `/etc/nginx/sites-enabled/zentunnel`, resolving to `/etc/nginx/sites-available/zentunnel`. The HTTPS server serves static files, proxies `/api/` and `/sanctum/` to port 8000, and preserves ACME. The HTTP server redirects to HTTPS.
- Laravel registers CRM administration under `/api/admin/v1` and VPN/device API under `/api/v1`. The latter contains device refresh, activation, account/subscription state, VPN locations/status/provision/revoke.
- CRM `.env` indicates SQLite, synchronous queues and array sessions; `/root/VPN/backend/database/database.sqlite` exists and no cached Laravel config was present. No standalone CRM queue/scheduler process, matching unit, user crontab or application cron entry was found. Its console routes have no registered schedule. Do not infer that future scheduled jobs are safe to stop.
- Only the HyperLine Compose project is running: eight containers. Older `docker-control-plane-1`, `docker-postgres-1`, `docker-redis-1`, `docker-mailpit-1` and one unlabelled container were already exited. Do not start/remove/prune these legacy containers or their volumes.

## Services and dependencies

| Actual service | Observed ownership / role | Proposed treatment |
| --- | --- | --- |
| `vpn-api.service` | Laravel CRM **and VPN device/provisioning API**; Restart=on-failure, enabled | KEEP RUNNING. No safe unit stop is identified. |
| `vpn-control-plane.service` | Local control-plane binary; `127.0.0.1:8081`; Laravel calls it | KEEP RUNNING |
| `vpn-node-agent.service` | Node/VPN manager; `0.0.0.0:8082`; manages WireGuard interface/rules and VLESS peer state | KEEP RUNNING |
| `xray-vless.service` | Xray, direct VLESS/TCP/TLS on 8443; 12 configured clients (not proof of active sessions) | KEEP RUNNING |
| `shadowsocks-libev.service` | TCP/UDP 8388 | KEEP RUNNING, although not part of the shutdown request |
| `wg-quick@wg0.service` | Inactive and disabled; `wg0` nevertheless exists, node-agent code manages interfaces | DO NOT START, STOP, ENABLE OR DISABLE. Preserve its original state. |
| `nginx.service` | Shared HTTP/TLS ingress, ports 80/443; enabled | KEEP RUNNING; only a reviewed graceful reload is proposed |
| `php8.3-fpm.service` | Shared host PHP pool; not the dedicated port-8000 server | KEEP RUNNING |
| `docker.service`, `containerd.service` | HyperLine and legacy container runtime | KEEP RUNNING |
| `postgresql@16-main.service`, `postgresql.service` | Host PostgreSQL on loopback 5432; shared infrastructure | KEEP RUNNING |
| `redis-server.service` | Host Redis on loopback 6379 | KEEP RUNNING |
| All eight `hyperline-*-1` containers | OTT Web/CMS/API/gateway/worker/scheduler/PostgreSQL/Redis | KEEP RUNNING; no shutdown action targets them |

`vpn-api.service` wants Docker but has no direct systemd reverse dependency from the VPN units. **That is not evidence it is disposable:** its application routes perform device authentication, provisioning and revocation, and it calls the separate control-plane. The node agent configures WireGuard and Xray. Stopping the Laravel API would remove required control functionality even if an already established tunnel might continue forwarding. No `systemctl stop vpn-api.service` command is authorized by this plan.

The current host vhost has **no observed WebSocket or gRPC transport location**. Xray's inspected inbound is TCP/TLS directly on 8443. This is a snapshot, not an assumption that future transports are absent: any change to the verified Zentunnel file invalidates the script hash and requires a new route review. Other vhosts are not edited.

## Ports and current baseline

| Port | Observed owner / use |
| --- | --- |
| TCP 80,443 (IPv4/IPv6) | Host Nginx |
| UDP 51820 (IPv4/IPv6) | WireGuard `wg0` |
| TCP 8443 | Xray VLESS/TLS |
| TCP/UDP 8388 | Shadowsocks |
| TCP 8082 | VPN node agent |
| TCP loopback 8081 | VPN control-plane |
| TCP loopback 8000 | Zentunnel Laravel server |
| TCP loopback 5432 / 6379 | Host PostgreSQL / Redis |
| TCP loopback 18080 | HyperLine Docker gateway only |
| TCP 22 | SSH; preserve access |

Snapshot: WireGuard peer count was zero; there were no recent handshakes. Aggregate established TCP counts on the VPN listener ports were zero at inspection. Therefore **existing-client continuity has not been demonstrated**. The owner must provide live known-good WireGuard/VLESS clients for before/during/after verification. Never publish their private keys, connection URIs or credentials.

IPv4 forwarding is enabled. IPv6 forwarding is disabled; this work does not alter either setting or claim IPv6 forwarding works. API, control-plane, node-agent and HyperLine liveness endpoints returned HTTP 200. Host Nginx syntax passed. Actual VPN traffic forwarding and VPN-client DNS remain NOT TESTED.

## Exactly what can be stopped safely

**No entire verified service/container is safe to stop under the provisioning-preservation requirement.** The proposal is HTTP maintenance only:

1. In the existing `zentunnel.net` HTTPS server, replace only `location /` static serving with 503 + Retry-After.
2. Add exact `/api/admin/v1` and prefix `/api/admin/v1/` locations returning 503.
3. Keep `/api/` proxying unchanged. Thus `/api/v1` VPN/device/authentication/provisioning remains operational.
4. Keep `/sanctum/`, ACME, HTTP redirect, TLS and all unrelated sites/routes unchanged.

Expected impact: CRM frontend and admin API unavailable to new requests; admin operations/renewals/payments cannot be performed through that frontend during maintenance. VPN clients retain their device/control API and all transport/control-plane processes. Existing in-flight requests may complete during a graceful Nginx reload. This is **not** a full Zentunnel API shutdown and does not prevent local direct backend access by trusted administrators.

Review `deploy/zentunnel-maintenance.candidate.conf`, `deploy/zentunnel-maintenance.review.diff` and `deploy/zentunnel-maintenance-policy.json`. Original reviewed vhost bytes are in `deploy/reports/zentunnel-original.review.conf`. The scripts verify the host IP, actual enabled-link target, exact config hashes, required units and original states; unknown ownership/config drift causes refusal. They contain no service/container stop command and no volume/database deletion operation.

## Commands — proposals only; DO NOT run before approval

From `/opt/hyperline-ott`, a read-only operator preview:

```sh
sudo deploy/zentunnel-app-stop.sh --dry-run
sudo deploy/zentunnel-app-start.sh --dry-run
```

Only after explicit approval of **web/admin-only** maintenance:

```sh
sudo deploy/zentunnel-app-stop.sh --execute --confirm STOP-ZENTUNNEL-WEB-ADMIN
```

The helper snapshots protected service states/restart/enabled settings, saves a root-only original vhost under `/var/lib/hyperline-zentunnel-maintenance`, atomically installs the hash-reviewed candidate, runs `nginx -t`, then `systemctl reload nginx.service`. It verifies protected states/PIDs have not changed. On validation/reload failure it restores the prior file and attempts a validated rollback reload. It never enables/disables or stops/starts a service and never changes a Docker restart policy. File mode/ownership/extended attributes are preserved.

Recovery after maintenance (also explicit confirmation):

```sh
sudo deploy/zentunnel-app-start.sh --execute --confirm RESTORE-ZENTUNNEL-WEB-ADMIN
```

Recovery restores **only** the exact saved original if the live file still matches the maintenance hash; it refuses to overwrite intervening operator edits. No service needs starting because none was stopped. The scripts are idempotent for their recognized original/maintenance states. A failed or missing backup requires manual operator review, not guessed replacement files.

The wrappers themselves have **not** been executed on production, including dry-run. Syntax and six mocked/offline tests cover confirmation refusal, config drift, no-op preview, stop/start idempotence, rollback and unchanged protected vhost bytes. The maintenance candidate plus separate HyperLine vhost templates passed an isolated Nginx syntax test using temporary test certificates, without installation/reload.

## Before / during / recovery checklist

Before approval/execution:

- Refresh sanitized inventory and compare candidate hash; confirm no new WS/gRPC/transport/auth callback routes were added.
- Confirm service states and enabled/restart settings, listener ownership, Docker projects/volumes and SQLite/host DB ownership remain unchanged.
- Establish real WG and VLESS client transfers and DNS resolution, capture aggregate handshake/traffic baselines privately, and keep transfers running during any approved maintenance.
- Verify `nginx -t`; Zentunnel site 200; Zentunnel `/api/v1/health` 200; admin unauthenticated endpoint retains its normal auth result; control-plane/node-agent health 200; HyperLine gateway health 200; PostgreSQL/Redis read connectivity.
- Take approved backups, arrange console/SSH recovery access and a brief monitored window. Do not proceed if client continuity or ownership is uncertain.

After an approved maintenance change:

- Only `https://zentunnel.net/` and CRM `/api/admin/v1/...` become 503 with Retry-After.
- `/api/v1/health`, required device/provisioning paths, control-plane/node-agent processes and VPN listeners stay available. Do not provision/revoke a real peer merely as a health test.
- WG handshakes and running client traffic/DNS continue; VLESS existing-client transfers stay connected. Zero clients at baseline cannot prove preservation.
- Shared Nginx remains active with successful syntax; Docker, host/container DB/Redis, HyperLine health and CDN metadata remain unchanged.
- If any required behavior regresses, perform the reviewed recovery and investigate; never restart VPN daemons as an attempted workaround.

After recovery:

- Original config hash restored; site and admin auth behavior return to baseline; all protected process/PID/restart/enabled states unchanged.
- Repeat client transfer/DNS, gateway, DB/Redis, Nginx and external-CDN checks. Record only sanitized results.

**Decision required:** approve this partial web/admin maintenance proposal, or keep everything live while a separate architecture isolates CRM from the VPN API. Full API-unit shutdown is blocked under the current preservation requirements.
