# Approval-only host HTTPS operations

**These commands have NOT been run.** First complete the backup/restore gates and separately approved service-specific rollout/checks in `docs/RELEASE-ACTIVATION-PREFLIGHT.md` (which refines `docs/ACTIVATION-SEQUENCE.md`); built images alone do not correct the running API. The raw legacy API rollback is forbidden because it predates signer enforcement; use the tested guarded fallback described there. Obtain explicit approval for each selected phase. Nothing here authorizes Zentunnel maintenance, API shutdown, application rollout, VPN restarts, firewall changes or CDN changes. Run in an operator-controlled root Bash shell. Preserve the backup directory path for recovery. Do not paste private keys, auth tokens or expanded environment files into reports.

Application backup/isolated restore now passed; see `docs/BACKUP-RECOVERY-VERIFICATION.md`. This does not approve or execute any of the host HTTPS operations below.

## Phase A: backup + HTTP-only bootstrap + graceful reload

Preconditions: DNS A records resolve to 157.85.104.49; no unexpected AAAA; current nginx -t succeeds; existing Zentunnel/VPN/HyperLine baselines are recorded. Verify no existing file/link collides with the proposed names and no other administrator is editing these sites. The zz- names sort after the existing zentunnel site to preserve its default-vhost role.

```bash
set -euo pipefail
cd /opt/hyperline-ott
hyperline_https_backup=/var/backups/hyperline-https/$(date -u +%Y%m%dT%H%M%SZ)
install -d -m 700 "$hyperline_https_backup"
cp -a /etc/nginx "$hyperline_https_backup/nginx"
cp -a /etc/letsencrypt/renewal/zentunnel.net.conf "$hyperline_https_backup/zentunnel.renewal.conf"
cp -a /etc/letsencrypt/renewal-hooks "$hyperline_https_backup/renewal-hooks"
sha256sum /etc/nginx/sites-available/zentunnel > "$hyperline_https_backup/zentunnel.sha256"
openssl x509 -in /etc/letsencrypt/live/zentunnel.net/fullchain.pem \
  -noout -fingerprint -sha256 -dates -ext subjectAltName \
  > "$hyperline_https_backup/zentunnel.public-cert.txt"
# Record this path privately; backup contains existing configuration.
printf 'Backup directory: %s\n' "$hyperline_https_backup"

for label in ott cms api; do
  target=/etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf
  link=/etc/nginx/sites-enabled/zz-hyperline-$label
  test ! -e "$target" && test ! -L "$target"
  test ! -e "$link" && test ! -L "$link"
done
# This is a NEW webroot, separate from /var/www/vpn-crm.
test ! -e /var/www/hyperline-acme
install -d -m 755 /var/www/hyperline-acme/.well-known/acme-challenge
for label in ott cms api; do
  install -o root -g root -m 644 \
    deploy/https/bootstrap/zz-hyperline-$label.conf \
    /etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf
  ln -s /etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf \
    /etc/nginx/sites-enabled/zz-hyperline-$label
done
# If validation fails: DO NOT reload; use the Phase-A rollback below.
nginx -t
python3 deploy/https/wait-nginx-generation.py snapshot > "$hyperline_https_backup/nginx-workers.before.json"
systemctl reload nginx.service
python3 deploy/https/wait-nginx-generation.py wait \
  --before "$hyperline_https_backup/nginx-workers.before.json" --timeout 5
sha256sum -c "$hyperline_https_backup/zentunnel.sha256"
```

Verify all three hosts serve ONLY the new ACME namespace and return 503 elsewhere:

```bash
test ! -e /var/www/hyperline-acme/.well-known/acme-challenge/readiness-check
printf 'hyperline-acme-readiness\n' > /var/www/hyperline-acme/.well-known/acme-challenge/readiness-check
chmod 644 /var/www/hyperline-acme/.well-known/acme-challenge/readiness-check
for host in ott.zentunnel.net cms.zentunnel.net api.zentunnel.net; do
  curl --fail --max-time 10 "http://$host/.well-known/acme-challenge/readiness-check"
  curl -I --max-time 10 "http://$host/" # expected 503 until TLS activation
 done
```

Also perform the challenge GET from an external network; a same-host request cannot prove upstream provider firewall reachability. Do not change any firewall rule as part of this plan. UFW is inactive but existing nftables/VPN rules remain present and must be preserved.


Phase A reload verification must observe a new active worker generation before strict HTTP checks. A successful reload command acknowledges signaling, not completed configuration handover. The read-only `wait-nginx-generation.py` gate times out after five seconds, rejects a changed master PID/start time, and never accepts HTTP 404 as success. On timeout, stop and use the approved scoped rollback; do not signal repeatedly. Use fresh HTTP connections for routing tests. See `deploy/reports/https-final-20261109/PHASE-A-REVISED-PLAN.md` for the retained-artifact retry plan; the original unused-path installation block must not be rerun after rollback.

## Phase B: certificate-only issuance; no Nginx installer

Use a **new** SAN lineage named hyperline-ott containing only the three HyperLine names. Do not use --nginx, --standalone, --expand, --force-renewal or --cert-name zentunnel.net. Do not stop Nginx to free port 80. Existing renewal remains Nginx-plugin based; this new lineage uses webroot.

The current renewal hook directories are empty. Before first issuance, verify no newly added global pre/post/deploy hook would restart services; do not disable any existing renewal job without approval. Do not install the new deploy hook until Phase C, so first certonly issuance does not trigger a reload.

Run the refreshed read-only/hook guards in `deploy/reports/https-final-20261109/PHASE-B-PREPARATION.md` immediately before either approved operation. Both commands below are prepared only; no operation was executed.

Staging dry-run — requires explicit staging-only approval:

```bash
test ! -e /etc/letsencrypt/live/hyperline-ott
test ! -L /etc/letsencrypt/live/hyperline-ott
test ! -e /etc/letsencrypt/archive/hyperline-ott
test ! -L /etc/letsencrypt/archive/hyperline-ott
test ! -e /etc/letsencrypt/renewal/hyperline-ott.conf
test ! -L /etc/letsencrypt/renewal/hyperline-ott.conf
set -euo pipefail
cd /opt/hyperline-ott
HYPERLINE_ACME_EMAIL='aungmyonyein321@gmail.com'
certbot certonly --dry-run --webroot --webroot-path /var/www/hyperline-acme \
  --server https://acme-staging-v02.api.letsencrypt.org/directory \
  --preferred-challenges http --cert-name hyperline-ott \
  -d ott.zentunnel.net -d cms.zentunnel.net -d api.zentunnel.net \
  --email "$HYPERLINE_ACME_EMAIL" --agree-tos --non-interactive
```

Stop after staging and review the result. Trusted production issuance requires its own later explicit approval:

```bash
test ! -e /etc/letsencrypt/live/hyperline-ott
test ! -L /etc/letsencrypt/live/hyperline-ott
test ! -e /etc/letsencrypt/archive/hyperline-ott
test ! -L /etc/letsencrypt/archive/hyperline-ott
test ! -e /etc/letsencrypt/renewal/hyperline-ott.conf
test ! -L /etc/letsencrypt/renewal/hyperline-ott.conf
set -euo pipefail
cd /opt/hyperline-ott
HYPERLINE_ACME_EMAIL='aungmyonyein321@gmail.com'
certbot certonly --webroot --webroot-path /var/www/hyperline-acme \
  --server https://acme-v02.api.letsencrypt.org/directory \
  --preferred-challenges http --cert-name hyperline-ott \
  -d ott.zentunnel.net -d cms.zentunnel.net -d api.zentunnel.net \
  --email "$HYPERLINE_ACME_EMAIL" --agree-tos --non-interactive
```

After approved production issuance only, inspect the new public certificate:

```bash
openssl x509 -in /etc/letsencrypt/live/hyperline-ott/fullchain.pem \
  -noout -dates -ext subjectAltName
stat -Lc '%U %a %n' /etc/letsencrypt/live/hyperline-ott/privkey.pem
```

Certbot 2.9 dry-run may register a staging account without email even with --email supplied. Production certonly normally reuses the existing production ACME account and does not update its contact from --email. The current existing account has no contact recorded locally. Do not silently change that shared account: the optional proposed account-contact update is separated in PHASE-B-PREPARATION.md and requires inclusion in a later approval. This does not prevent staging HTTP-01 validation.


Expected: SAN covers exactly the three application names, a valid Let's Encrypt chain, root-readable key with restrictive permissions. Do not chmod the private key world-readable or change the existing Zentunnel key. The root Nginx master reads keys; application/container users do not need them. Inspect the new renewal file's non-secret fields to confirm authenticator=webroot and the recorded webroot, not an Nginx installer. Do not edit the existing zentunnel.net renewal file.

## Phase C: activate new HTTPS sites + scoped renewal hook

Preconditions: issued certificate paths verified, HTTP challenge reachability proven, candidate diff reviewed, originals unchanged. No new CDN hostname is involved. This phase changes only files created for HyperLine, never the existing Zentunnel file. Final files use the same NEW SAN lineage.

```bash
cd /opt/hyperline-ott
for file in /etc/nginx/conf.d/zz-hyperline-http-common.conf \
            /etc/nginx/snippets/hyperline-edge-proxy.conf \
            /etc/letsencrypt/renewal-hooks/deploy/zz-hyperline-reload; do
  test ! -e "$file" && test ! -L "$file"
done
for label in ott cms api; do
  test "$(readlink /etc/nginx/sites-enabled/zz-hyperline-$label)" = \
    "/etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf"
  test ! -e /etc/nginx/sites-available/zz-hyperline-$label.https.conf
  test ! -L /etc/nginx/sites-available/zz-hyperline-$label.https.conf
done
install -o root -g root -m 644 deploy/https/00-hyperline-http-common.conf \
  /etc/nginx/conf.d/zz-hyperline-http-common.conf
install -o root -g root -m 644 deploy/https/snippets/hyperline-edge-proxy.conf \
  /etc/nginx/snippets/hyperline-edge-proxy.conf
for label in ott cms api; do
  install -o root -g root -m 644 deploy/https/sites/zz-hyperline-$label.conf \
    /etc/nginx/sites-available/zz-hyperline-$label.https.conf
  # These are only the NEW links created in Phase A, validated above.
  ln -sfn /etc/nginx/sites-available/zz-hyperline-$label.https.conf \
    /etc/nginx/sites-enabled/zz-hyperline-$label
done
# If this fails: restore only the three new bootstrap links; do not reload.
nginx -t
systemctl reload nginx.service
sha256sum -c "$hyperline_https_backup/zentunnel.sha256"
# Install only AFTER activation. It skips every other certificate lineage.
install -o root -g root -m 755 deploy/https/renewal/hyperline-reload.sh \
  /etc/letsencrypt/renewal-hooks/deploy/zz-hyperline-reload
```

Future Certbot renewal uses the existing active certbot.timer. The new executable directory hook validates Nginx before graceful reload and returns immediately unless RENEWED_LINEAGE is exactly /etc/letsencrypt/live/hyperline-ott. Do not put a service-stop command into any hook.

Approval-dependent renewal validation:

```bash
certbot renew --cert-name hyperline-ott --dry-run
```

Do not add --run-deploy-hooks unless a corresponding production reload has separately been approved. No actual renewal/dry-run was performed during preparation. Keep the HTTP challenge location in the final sites so renewals do not depend on application login, Laravel, or Docker.

## Optional Phase D: gateway Upgrade/incremental-stream support

Current production Next.js has no verified WebSocket endpoint. Basic HTTPS, ordinary Web/CMS and Laravel API routing work through the unchanged gateway. Its current web/CMS proxy does not forward Upgrade headers and buffers responses by default. The outer site alone cannot provide two-hop Upgrade/streaming support.

`deploy/https/gateway.conf.staged` adds HTTP/1.1, conditional Upgrade and response buffering off for Web/CMS only, preserving REST/FastCGI routing. The live `infra/nginx/conf.d/00-app.conf` is mounted directly into the gateway: writing it IS a production configuration change. Do not copy it automatically or bundle it silently with host TLS installation.

Only after separate gateway-change approval:

```bash
cp -a infra/nginx/conf.d/00-app.conf "$hyperline_https_backup/gateway.original.conf"
install -m 644 deploy/https/gateway.conf.staged infra/nginx/conf.d/00-app.conf
docker compose exec -T gateway nginx -t
# If test fails, restore the exact backup before proceeding; no reload.
docker compose exec -T gateway nginx -s reload
```

If rollback is needed, restore only that original gateway file, validate inside the gateway, then gracefully reload it. Do not recreate or stop Docker, DB, Redis, VPN or host Nginx for this optional change. Mock two-hop Upgrade and incremental streaming passed locally; real production WebSocket functionality is not claimed without an actual application endpoint.

## Verification after approved activation

```bash
for host in ott.zentunnel.net cms.zentunnel.net api.zentunnel.net; do
  curl -I --max-time 10 "https://$host/"
  curl -I --max-time 10 "http://$host/" # 308 except ACME path
 done
curl --fail --max-time 10 -H 'Accept: application/json' https://api.zentunnel.net/api/v1/health
curl -I --max-time 5 -H 'Host: ott.zentunnel.net' http://127.0.0.1:18080/
curl -I --max-time 5 -H 'Host: cms.zentunnel.net' http://127.0.0.1:18080/
nginx -t
systemctl is-active nginx.service vpn-api.service vpn-control-plane.service \
  vpn-node-agent.service xray-vless.service shadowsocks-libev.service
```

API `/` is not the health URL and may return the Laravel landing response; `/health` is not the API health route. Use `/api/v1/health`. Verify protected endpoints with Accept: application/json. Do not use curl -k as proof of successful production TLS. Check SAN/chain/expiry and request routing with normal validation.

Verify exact-origin API CORS and actual Web/CMS behavior. The current image responds to preflight without Access-Control-Allow-Credentials and returns 500 for a bare unauthenticated `/api/v1/me` request without JSON Accept. Source fixes/configuration exist but are not rolled out. These are separate application-release blockers, not reasons to modify VPN routes or bypass playback authorization.

Keep known WG/VLESS client transfers running while observing their existing handshakes, traffic and DNS before/during/after any approved reload. Do not print keys or connection URIs. Current Xray uses direct TCP/TLS 8443; no host WS/gRPC route was observed. Do not assume future routes are absent: compare the original vhost hash and refresh routing inventory immediately before approval/execution.

## Rollback: new HyperLine files only

If issuance fails: retain bootstrap 503 for retry, or remove ONLY the three newly created bootstrap links after checking their targets; validate and reload Nginx. Do not restore the full Nginx backup over unrelated operator changes.

If TLS activation fails: return ONLY the new links to the Phase-A bootstrap targets:

```bash
for label in ott cms api; do
  current=$(readlink /etc/nginx/sites-enabled/zz-hyperline-$label)
  case "$current" in
    /etc/nginx/sites-available/zz-hyperline-$label.https.conf|/etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf) ;;
    *) echo 'Unexpected link: stop for operator review' >&2; exit 1 ;;
  esac
  ln -sfn /etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf \
    /etc/nginx/sites-enabled/zz-hyperline-$label
done
nginx -t
systemctl reload nginx.service
```

To fully undo Phase A after approval:

```bash
for label in ott cms api; do
  test "$(readlink /etc/nginx/sites-enabled/zz-hyperline-$label)" = \
    "/etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf"
  unlink /etc/nginx/sites-enabled/zz-hyperline-$label
done
nginx -t
systemctl reload nginx.service
```

Leave newly created unused site files/certificate lineage available for investigation; do not delete/revoke any existing certificate or application files. Disable only the newly installed HyperLine hook if abandoning this rollout, after verifying it matches the reviewed script. A leftover unreferenced map/snippet is inert; remove only verified new artifacts if approved. Restore an edited optional gateway from its own backup, never from guessed content. Recheck the original Zentunnel file, service states, VPN connectivity and local gateway after rollback.

**No command above was executed in production during preparation.** Separate explicit approval remains required for installation, issuance, every production reload, optional gateway changes, and application rollout.
