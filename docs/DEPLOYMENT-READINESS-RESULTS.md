# Readiness results and production approval boundary

Read-only inventory confirmed this is `157.85.104.49`. No Zentunnel or HyperLine production service was stopped, restarted, recreated, enabled/disabled or deployed. Host Nginx was syntax-tested but not edited/reloaded. No VPN key/config, database, volume, Redis data or video infrastructure was changed. The shutdown/start wrappers were not executed against production. Mocked helper tests changed temporary fixture files only.

| Result | Check | Evidence / limitation |
| --- | --- | --- |
| PASS | Target/install inventory | Static CRM `/var/www/vpn-crm`; Laravel `/root/VPN/backend`; verified vpn-api.service; sanitized unit/container/route snapshot recorded |
| PASS | Shared/VPN ownership inventory | Verified direct Xray TLS 8443, WG interface UDP 51820, Shadowsocks 8388, separate control-plane/node-agent, host Nginx/PHP/DB/Redis and HyperLine containers |
| PASS | Laravel automated tests | 162 tests / 654 assertions, including heartbeat freshness/staleness/malformed input and existing authorization/renewal tests |
| PASS | Frontend production builds | Web and CMS builds passed with local fixture API; production release must rebuild with real API URL |
| PASS | TypeScript checks | Both apps checked after builds completed; concurrent checks during Next regeneration are not used as final evidence |
| PASS | Browser integration | 5 passed: CMS CRUD/validation/logout, catalog/mobile, generated HLS/fullscreen, negative CORS, signed-URL renewal/position preservation. Superseded same-site-cookie test intentionally skipped. |
| PASS | Docker Compose validation | Quiet validation passed; gateway remains loopback 18080; no host 80/443/DB/Redis publication |
| PASS | New worker/scheduler health source | Actual local PHP worker + scheduler against isolated Redis 16381 and disposable SQLite became healthy; stopped worker and Redis failure became unhealthy. Own fixture processes stopped afterward. |
| PASS | Shutdown safety tests | 6 offline/mocked tests: default dry-run, explicit confirmation, no-op/idempotence, rollback, config drift and protected route-byte preservation |
| PASS | Shell/PHP formatting checks | Wrapper/scheduler shell syntax, targeted Pint, whitespace checks passed |
| PASS | Current host Nginx | `nginx -t` passed without reload or config change |
| PASS | Proposed maintenance + app vhost syntax | Isolated Nginx syntax test passed with temporary certificates; no live config installed |
| PASS | Live application health | Loopback HyperLine `/api/v1/health`, Zentunnel API health, control-plane and node-agent health returned 200 |
| PASS | Live PostgreSQL/Redis connectivity | Read-only HyperLine DB SELECT 1 and Redis PING succeeded; migrations all applied |
| PASS | Environment structure | Root env mode 0600, production/debug=false, structurally valid existing APP_KEY, matching DB credentials, Redis password present. No secret values printed or rotated. CDN activation remains false. |
| FAIL | Existing worker/scheduler container health | Running images still use old FPM checks. Source fixes are not deployed; unhealthy status remains until approved rollout. |
| FAIL | Public HyperLine DNS/TLS/vhost readiness | EAI_NONAME from host resolver for ott/cms/api; corresponding local certificate files absent; no existing app host vhosts. Requires approved DNS/TLS/new-file routing work. |
| BLOCKED | Full Zentunnel API shutdown | vpn-api.service provides required VPN device/control/provisioning endpoints. No complete app unit/container is verified safe to stop. |
| NOT TESTED | Approved maintenance/recovery execution | Neither script was executed on production; explicit later confirmation required. Candidate limits maintenance to website and admin API. |
| NOT TESTED | Established VPN continuity | Snapshot had zero WireGuard peers/recent handshakes and zero established VPN TCP counts; real WG/VLESS before/during/after client tests require operator-provided clients. No shutdown occurred. |
| PASS / NOT TESTED | Forwarding | Host IPv4 forwarding is enabled; IPv6 forwarding disabled and unchanged. Actual tunnel forwarding/NAT/client DNS not tested. |
| NOT TESTED | Actual production API image rollout | New pcntl build and image-level CLI probes require build/rollout validation; local process health tests are not a production deployment. |
| NOT TESTED | Live protected HLS/credentials | Real existing-CDN protocol, credentials and nested-resource coverage remain unknown; no unsigned fallback enabled. Metadata results are documented separately. |
| NOT TESTED | Native devices / production load | Safari/iOS and load/soak tests pending |

Approval packets: [Zentunnel shutdown review](ZENTUNNEL-SHUTDOWN-REVIEW.md), [HyperLine deployment plan](HYPERLINE-DEPLOYMENT-PLAN.md), [CDN requirements](CDN-PLAYBACK.md). These are separate approvals. No current approval authorizes a full API/VPN/control-plane shutdown.
