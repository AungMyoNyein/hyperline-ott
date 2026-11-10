# HyperLine production HTTPS review — preparation only

**No production Nginx file, gateway configuration, certificate, renewal configuration, firewall or service was changed. No certificate was issued and no production reload was performed.** The new files are staging artifacts. Existing WireGuard, Xray/VLESS, Shadowsocks, VPN API/control-plane/node-agent, shared Nginx, PostgreSQL, Redis, Docker and HyperLine services remain untouched. There is no new CDN or origin.

## Existing routing and ownership

Host Nginx 1.24 has one enabled site, `zentunnel`, resolving to `/etc/nginx/sites-available/zentunnel`. It owns HTTP/HTTPS for zentunnel.net, serves `/var/www/vpn-crm`, proxies `/api/` and `/sanctum/` to the independent Laravel server on loopback 8000, and serves the existing ACME namespace. The original hash is `f4c3fd2256852e7438019b790c4993264a703b315e637264cb0ae983fb82ebe4`.

No WebSocket/gRPC transport location was observed in the enabled host site. Existing Xray is direct VLESS/TCP/TLS on 8443; WireGuard is UDP 51820; Shadowsocks uses TCP/UDP 8388. Preserve all of these, the shared Nginx listeners and the VPN API. Refresh this route inventory before any approved change; do not assume later transport additions are absent.

The existing root-domain certificate covers **zentunnel.net only** and uses Certbot's `nginx` authenticator and installer. Certbot is 2.9.0, certbot.timer is active, and pre/post/deploy hook directories were empty at inspection. The current root key is owned by UID 0 and mode 0600. No HyperLine certificate lineage currently exists.

The Docker gateway is bound only to `127.0.0.1:18080`. Its active configuration selects upstreams by Host:

| Incoming Host | Gateway destination | URL handling verified |
| --- | --- | --- |
| ott.zentunnel.net | Web at web:3000 | `/` and Next static assets preserved |
| cms.zentunnel.net | CMS at cms:3000 | `/` and its own static assets preserved |
| api.zentunnel.net | Laravel via FastCGI api:9000 | `/api/v1/health`, catalog query and auth paths preserved; do not strip/prepend `/api` |

Therefore all three edge vhosts may proxy to the same **address** with an unchanged URI **only because** they preserve Host and the gateway's Host matching selects the correct service. This was tested through isolated TLS edge sites against the real running gateway, not inferred from a bare IP HTTP 200.

## Prepared files

The directory `deploy/https/` contains:

- Three HTTP-only bootstrap files under `bootstrap/`, serving the new webroot challenge and returning 503 elsewhere until certificate activation.
- Three independent final files under `sites/`, one for each hostname, with HTTP challenge exemption, fixed-host 308 HTTPS redirect, and HTTPS proxying to loopback 18080.
- `00-hyperline-http-common.conf`: http-context conditional Upgrade map and sanitized log format. Its production name is `conf.d/zz-hyperline-http-common.conf`.
- `snippets/hyperline-edge-proxy.conf`: no URI suffix on proxy_pass, normalized original Host identity, authoritative HTTPS/port/real-client forwarding headers, HTTP/1.1 and bounded connection/body timeouts. Client-supplied forwarding chains are discarded at this verified direct public edge.
- `renewal/hyperline-reload.sh`: a future root-owned executable directory hook that skips all lineages except the new HyperLine SAN certificate and validates Nginx before graceful reload.
- `gateway.conf.staged`: **optional** two-hop Web/CMS Upgrade and incremental-response support. It is not installed; modifying the actual infra file would change a live Docker bind mount and requires separate approval.
- `OPERATIONS.md`: exact approval-only backup, install, issuance, activation, renewal-test and rollback commands.

The site filenames start with `zz-hyperline-` so the existing `zentunnel` site remains first/default when sites-enabled is included. None sets default_server, replaces the existing site, edits nginx.conf, alters VPN routes, binds Docker to 80/443 or publishes gateway 18080 externally. New file/link names are checked for collisions before installation. The older aggregate host-vhost sketch is superseded for installation.

The API uses REST/FastCGI and has no verified WebSocket endpoint: its Upgrade headers are suppressed. Web/CMS forward conditional Upgrade. The current gateway drops Upgrade and uses default response buffering; outer headers alone cannot overcome that. Both-hop support was tested with a mock receiver using the optional staged gateway. Actual production application WebSocket behavior is NOT TESTED because no endpoint exists in the inspected app. HLS video remains a direct browser request to cdn.goldendragon2000.com, not an Nginx/Laravel media proxy.

Request size is explicitly 1m, matching the current gateway default and comfortably exceeding validated metadata payloads. No video-upload operation exists. Connect timeout is 5s, body/send timeouts bounded, Web/CMS read timeout 300s and API read timeout 120s. Future large uploads, SSE or long-running jobs require a separate end-to-end review; current gateway FastCGI defaults may be shorter until the optional staged config is approved.

## Certificate and ACME strategy

Use a separate **SAN certificate named hyperline-ott**, containing exactly ott.zentunnel.net, cms.zentunnel.net and api.zentunnel.net. Final paths are `/etc/letsencrypt/live/hyperline-ott/fullchain.pem` and `privkey.pem`. Never expand or replace the zentunnel.net certificate.

Prefer `certbot certonly --webroot --webroot-path /var/www/hyperline-acme`. The new HTTP vhosts serve `/.well-known/acme-challenge/` directly from that new webroot; neither Laravel nor the Docker gateway participates in validation. Keep the namespace in the final HTTP sites so renewals do not depend on application health. Do not use standalone mode, which would compete for port 80; do not use the Nginx installer on the new certificate, which could rewrite configurations.

No CAA records were returned for zentunnel.net or the three application names in the read-only check. This is not proof of successful ACME validation; external HTTP reachability and actual issuance remain untested until approval.

The exact certificate-only command, after approved HTTP bootstrap and public challenge validation, is:

```bash
: "${HYPERLINE_ACME_EMAIL:?Set the approved ACME contact email first}"
certbot certonly --webroot --webroot-path /var/www/hyperline-acme \
  --preferred-challenges http --cert-name hyperline-ott \
  -d ott.zentunnel.net -d cms.zentunnel.net -d api.zentunnel.net \
  --email "$HYPERLINE_ACME_EMAIL" --agree-tos --non-interactive
```

This is a proposal, not a command run during preparation. A staging --dry-run is included in OPERATIONS.md and also requires approval. The approved contact email is not yet supplied. The separate root-owned renewal hook is installed only after final activation; first issuance therefore does not automatically reload Nginx. Future renewals use the existing timer and invoke the hook only for this lineage. Hook tests proved the existing zentunnel.net lineage causes no action and failed nginx -t prevents reload. Actual renewal compatibility remains subject to an approved `certbot renew --cert-name hyperline-ott --dry-run`, without --run-deploy-hooks unless a reload is approved.

The new private key does not exist yet, so its actual ownership/permissions cannot be certified. After issuance, verify restrictive root ownership/readability; never chmod it world-readable or grant key access to application containers. Root Nginx already reads the current 0600 key.

## Environment and application readiness findings

The current environment has production/debug=false, APP_URL=https://api.zentunnel.net, NEXT_PUBLIC_API_URL=https://api.zentunnel.net/api/v1, exact OTT/CMS CORS origins, private Docker DB/Redis hostnames, and CDN activation false. Secrets were not printed or changed. Default proxy trust covers the current Docker private addresses; preserve the inspected chain and review it if topology changes.

Two separate application-release issues remain:

1. The current API responds to the inspected preflight with exact allow-origin, but **no Access-Control-Allow-Credentials**. The new player uses credentialed API create/renew requests, so application environment/config/image rollout is needed. Do not mask this with wildcard CORS in Nginx.
2. The current running image returns 500 for an unauthenticated `/api/v1/me` request without JSON Accept. The source fix already exists but is not deployed. The normal client request with Accept: application/json returns 401, as tested through TLS staging.

Runtime follow-up (2026-11-09): API/Web/CMS release images have now been built and tested, but no running container or production .env was changed. See [RUNTIME-RELEASE-REVIEW.md](RUNTIME-RELEASE-REVIEW.md) for 178 tests/780 assertions, image probes, guarded rollback and remaining live failures. [ACTIVATION-SEQUENCE.md](ACTIVATION-SEQUENCE.md) is the current ordered rollout plan. HTTPS routing can be configured independently, but it does not make the old application images or protected playback production-ready. CDN authorization remains fail-closed; no signing secrets/algorithm are assumed and no video infrastructure changes are proposed.

## Risk to existing Zentunnel/VPN

The proposed changes are new explicit-name vhosts, one new map/snippet and one independently scoped certificate lineage. Existing listeners, root-domain certificate, static CRM/VPN routes, transport daemons, DB/Redis and Docker stay in place. The shared Nginx reload is still a production operation: test first, preserve defaults, use graceful reload only, and compare original file hashes. Never stop/restart Nginx or VPN units to resolve a failed certificate request.

UFW is inactive, but nftables has existing tables/chains/rules; no firewall was altered and no claim is made that UFW status proves external ACME reachability. Public 80/443 are listening; loopback 18080 remains private. Do not add firewall resets or broad rules to this plan. Before any approved reload, verify known live WG/VLESS traffic/DNS and preserve all current transport routes, including any added since inventory.

## Validation results

| Status | Check | Evidence / limit |
| --- | --- | --- |
| PASS | DNS | All three names resolve to 157.85.104.49 |
| PASS | Current host/gateway syntax | nginx -t passed for host and running Docker gateway; no reload |
| PASS | Local upstream identity | OTT/CMS pages, static assets; API health 200, catalog/query 200 and JSON auth 401 through Host-routed gateway |
| PASS | Isolated bootstrap | All names: challenge 200, missing token 404, other routes 503, existing default preserved |
| PASS | Isolated final HTTPS | Temporary test SAN trusted by validator; HTTP 308 preserves path/query; ACME exemption preserved; real gateway routes work through staged edge |
| PASS | Listen compatibility | Complete staged final sites syntax-tested alongside read-only actual host sites, including IPv4/IPv6 listen options, using a test SAN for new certificate paths |
| PASS | Mock two-hop proxy behavior | Host/path/query/auth forwarding, spoofed XFF removal, WebSocket 101, incremental response timing and 413 size limit |
| PASS | Renewal hook tests | Two local tests include existing-lineage no-op, correct-lineage validate/reload ordering and validation-failure refusal; no production command executed |
| FAIL | Current public HTTPS | All three names still fail curl TLS hostname verification (exit 60); existing certificate only covers zentunnel.net |
| FAIL / APP RELEASE | Current API behavior | Credentialed preflight lacks allow-credentials; bare unauthenticated request returns 500; fixes/configuration are not rolled out |
| NOT TESTED | Real new certificate | No ACME issuance, production SAN/chain validation or new key permissions yet |
| NOT TESTED | Real ACME/renewal | Bootstrap not installed; external challenge and real/staging issuance/renewal not performed |
| NOT TESTED | Production activation/rollback | No host/gateway reload, install, application rollout or rollback performed |
| NOT TESTED | Live app WebSockets / VPN continuity | Protocol mocked; actual WS endpoint absent; before/during/after live VPN traffic checks require the approved operation and operator clients |
| NOT TESTED | Full protected CDN playback | Signing protocol/credentials/rights-approved nested-resource verification still blocked; no video downloaded |

## Recommendation and approval boundary

**GO for the reviewed, scoped setup plan; NO-GO for live execution until explicit approval, and NO-GO for claiming complete application production readiness yet.**

Approve independently: A) new HTTP bootstrap files/webroot and validated graceful host reload; B) new HyperLine-only certificate issuance with an approved email; C) final new HTTPS files, scoped renewal hook and validated graceful host reload. D) optional gateway Upgrade changes and any application environment/image rollout require separate approval. Do not combine these with Zentunnel shutdown, VPN restart, CDN changes or DNS changes.

`deploy/https/OPERATIONS.md` contains exact commands and rollback that touches only the newly created HyperLine artifacts. No command to stop any production service is part of this proposal.
