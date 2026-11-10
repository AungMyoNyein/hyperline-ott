# Final HTTPS activation commands — prepared, not executed

2026-11-09. Companion to `OPERATIONS.md`; this packet uses the activated runtime and intentionally stopped VPN baseline. It supersedes the old instructions requiring active VPN services/client transfers and the obsolete API/CORS blockers. Do not run the fixture browser suite against production. Separate approval is required for Phase A, Phase B staging/production issuance, Phase C, and any rollback host reload. Optional gateway Phase D is excluded.

Run approved phases manually in one root Bash session. Stop on any failed command or unexpected file/state. No VPN service is started; no CDN/origin, database or volume change is included. Commands deliberately leave unused new files and certificates available for diagnosis.

## Phase A — only after bootstrap installation and host reload approval

```bash
set -euo pipefail
cd /opt/hyperline-ott
python3 deploy/check-activated-release.py
python3 deploy/https/final-preflight.py
nginx -t
hyperline_https_backup=/var/backups/hyperline-https/final-$(date -u +%Y%m%dT%H%M%SZ)
test ! -e "$hyperline_https_backup"
install -d -o root -g root -m 700 "$hyperline_https_backup"
cp -a /etc/nginx "$hyperline_https_backup/nginx"
cp -a /etc/letsencrypt/renewal/zentunnel.net.conf "$hyperline_https_backup/zentunnel.renewal.conf"
cp -a /etc/letsencrypt/renewal-hooks "$hyperline_https_backup/renewal-hooks"
sha256sum /etc/nginx/sites-available/zentunnel > "$hyperline_https_backup/zentunnel.sha256"
sha256sum /etc/letsencrypt/live/zentunnel.net/fullchain.pem > "$hyperline_https_backup/zentunnel-cert.sha256"
openssl x509 -in /etc/letsencrypt/live/zentunnel.net/fullchain.pem -noout \
  -fingerprint -sha256 -serial -dates -ext subjectAltName > "$hyperline_https_backup/zentunnel.public-cert.txt"
systemctl show nginx.service vpn-api.service vpn-control-plane.service vpn-node-agent.service \
  xray-vless.service shadowsocks-libev.service wg-quick@wg0.service \
  --property=Id,ActiveState,UnitFileState,MainPID > "$hyperline_https_backup/service-states.txt"
cp -a deploy/https "$hyperline_https_backup/staged"
printf 'Recovery directory: %s\n' "$hyperline_https_backup"
# Keep this exact path in the operator record; do not guess it in a later shell.
for label in ott cms api; do
  target=/etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf
  link=/etc/nginx/sites-enabled/zz-hyperline-$label
  test ! -e "$target" && test ! -L "$target"
  test ! -e "$link" && test ! -L "$link"
done
test ! -e /var/www/hyperline-acme && test ! -L /var/www/hyperline-acme
install -d -o root -g root -m 755 /var/www/hyperline-acme/.well-known/acme-challenge
for label in ott cms api; do
  install -o root -g root -m 644 deploy/https/bootstrap/zz-hyperline-$label.conf \
    /etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf
  ln -s /etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf \
    /etc/nginx/sites-enabled/zz-hyperline-$label
done
nginx -t
# Only if validation succeeds and Phase-A reload is approved:
python3 deploy/https/wait-nginx-generation.py snapshot > "$hyperline_https_backup/nginx-workers.before.json"
systemctl reload nginx.service
python3 deploy/https/wait-nginx-generation.py wait \
  --before "$hyperline_https_backup/nginx-workers.before.json" --timeout 5
sha256sum -c "$hyperline_https_backup/zentunnel.sha256"
sha256sum -c "$hyperline_https_backup/zentunnel-cert.sha256"
```

If validation fails, do not reload. Use the scoped bootstrap rollback below. The backup contains the complete original host configuration; never restore it wholesale over unrelated changes.

## Challenge reachability gate — before any Certbot invocation

The filesystem mapping for all three names is exactly:

`http://<host>/.well-known/acme-challenge/<token>` → `/var/www/hyperline-acme/.well-known/acme-challenge/<token>`.

```bash
test ! -e /var/www/hyperline-acme/.well-known/acme-challenge/readiness-check
test ! -L /var/www/hyperline-acme/.well-known/acme-challenge/readiness-check
printf 'hyperline-acme-readiness\n' > /var/www/hyperline-acme/.well-known/acme-challenge/readiness-check
chmod 644 /var/www/hyperline-acme/.well-known/acme-challenge/readiness-check
for host in ott.zentunnel.net cms.zentunnel.net api.zentunnel.net; do
  body=$(curl --noproxy '*' --fail --silent --show-error --max-time 10 \
    "http://$host/.well-known/acme-challenge/readiness-check")
  test "$body" = hyperline-acme-readiness
  test "$(curl --noproxy '*' --silent --show-error --max-time 10 -o /dev/null -w '%{http_code}' "http://$host/")" = 503
done
```

Repeat the exact loop from an independent external network. Require all three token bodies to match; a same-server request is insufficient. Missing tokens must return 404. Do not issue until external reachability is evidenced. The token is non-secret and can remain for diagnosis. No firewall change is included.


Phase A reload verification must observe a new active worker generation before strict HTTP checks. A successful reload command acknowledges signaling, not completed configuration handover. The read-only `wait-nginx-generation.py` gate times out after five seconds, rejects a changed master PID/start time, and never accepts HTTP 404 as success. On timeout, stop and use the approved scoped rollback; do not signal repeatedly. Use fresh HTTP connections for routing tests. See `deploy/reports/https-final-20261109/PHASE-A-REVISED-PLAN.md` for the retained-artifact retry plan; the original unused-path installation block must not be rerun after rollback.

## Phase B — separately approved staging and production issuance

The approved proposed ACME contact email is `aungmyonyein321@gmail.com`; it is set only in the staged commands below, not in production account settings. Verify global renewal hook directories and cli.ini have not acquired any service-affecting hook since preflight. Current directories are empty. No Nginx installer, standalone authenticator or expansion of the existing lineage is allowed.

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
openssl x509 -in /etc/letsencrypt/live/hyperline-ott/cert.pem -checkend 86400 -noout
openssl verify -CApath /etc/ssl/certs -untrusted /etc/letsencrypt/live/hyperline-ott/chain.pem \
  /etc/letsencrypt/live/hyperline-ott/cert.pem
for host in ott.zentunnel.net cms.zentunnel.net api.zentunnel.net; do
  openssl x509 -in /etc/letsencrypt/live/hyperline-ott/cert.pem -checkhost "$host" -noout
done
test "$(stat -Lc '%u %a' /etc/letsencrypt/live/hyperline-ott/privkey.pem)" = '0 600'
cert_public=$(openssl x509 -in /etc/letsencrypt/live/hyperline-ott/cert.pem -pubkey -noout | openssl pkey -pubin -outform DER | sha256sum)
key_public=$(openssl pkey -in /etc/letsencrypt/live/hyperline-ott/privkey.pem -pubout -outform DER | sha256sum)
test "$cert_public" = "$key_public"
sha256sum -c "$hyperline_https_backup/zentunnel-cert.sha256"
cmp /etc/letsencrypt/renewal/zentunnel.net.conf "$hyperline_https_backup/zentunnel.renewal.conf"
```

Certbot 2.9 dry-run may register a staging account without email even with --email supplied. Production certonly normally reuses the existing production ACME account and does not update its contact from --email. The current existing account has no contact recorded locally. Do not silently change that shared account: the optional proposed account-contact update is separated in PHASE-B-PREPARATION.md and requires inclusion in a later approval. This does not prevent staging HTTP-01 validation.


Require SANs to contain exactly the three application names; inspect the new renewal file for `authenticator = webroot`, correct `/var/www/hyperline-acme` webroot, no installer and no unexpected hooks. Stop on mismatch. No certificate key bytes are printed by the public-key comparison. New key/chain checks cannot be completed before issuance.

## Phase C — only after final site installation and host reload approval

```bash
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
  cmp deploy/https/sites/zz-hyperline-$label.conf "$hyperline_https_backup/staged/sites/zz-hyperline-$label.conf"
done
sha256sum -c "$hyperline_https_backup/zentunnel.sha256"
sha256sum -c "$hyperline_https_backup/zentunnel-cert.sha256"
cmp deploy/https/00-hyperline-http-common.conf "$hyperline_https_backup/staged/00-hyperline-http-common.conf"
cmp deploy/https/snippets/hyperline-edge-proxy.conf "$hyperline_https_backup/staged/snippets/hyperline-edge-proxy.conf"
cmp deploy/https/renewal/hyperline-reload.sh "$hyperline_https_backup/staged/renewal/hyperline-reload.sh"
install -o root -g root -m 644 deploy/https/00-hyperline-http-common.conf \
  /etc/nginx/conf.d/zz-hyperline-http-common.conf
install -o root -g root -m 644 deploy/https/snippets/hyperline-edge-proxy.conf \
  /etc/nginx/snippets/hyperline-edge-proxy.conf
for label in ott cms api; do
  install -o root -g root -m 644 deploy/https/sites/zz-hyperline-$label.conf \
    /etc/nginx/sites-available/zz-hyperline-$label.https.conf
  ln -sfn /etc/nginx/sites-available/zz-hyperline-$label.https.conf \
    /etc/nginx/sites-enabled/zz-hyperline-$label
done
nginx -t
# Only if validation succeeds and Phase-C reload is approved:
systemctl reload nginx.service
sha256sum -c "$hyperline_https_backup/zentunnel.sha256"
sha256sum -c "$hyperline_https_backup/zentunnel-cert.sha256"
install -o root -g root -m 755 deploy/https/renewal/hyperline-reload.sh \
  /etc/letsencrypt/renewal-hooks/deploy/zz-hyperline-reload
```

The future hook skips every lineage except `hyperline-ott`; it validates syntax before graceful reload. Do not invoke it now. A later `certbot renew --cert-name hyperline-ott --dry-run` requires its own approval; do not add `--run-deploy-hooks` without reload approval.

## Verification after approved activation

```bash
python3 deploy/https/verify-public.py
python3 deploy/check-activated-release.py
nginx -t
systemctl is-active nginx.service docker.service php8.3-fpm.service postgresql.service redis-server.service ssh.service
systemctl show vpn-api.service vpn-control-plane.service vpn-node-agent.service \
  xray-vless.service shadowsocks-libev.service wg-quick@wg0.service --property=Id,ActiveState,UnitFileState,MainPID
ip -brief link show wg0
sha256sum -c "$hyperline_https_backup/zentunnel.sha256"
sha256sum -c "$hyperline_https_backup/zentunnel-cert.sha256"
```

Require all six VPN units inactive/disabled with PID 0, wg0 DOWN, shared services active. Do not use the obsolete OPERATIONS.md `is-active` command that expects VPN services to run. Repeat `verify-public.py` externally. No `curl -k`, ignored browser certificate errors or media-download checks are accepted as TLS/playback evidence.

Authenticated CMS and OTT tests are technically runnable: Playwright and Chromium launch were verified. Existing authorized admin and viewer credentials remain required. These tests only sign in, read dashboard/library/catalog, and sign out; login/logout creates/revokes normal session tokens. No content is created/edited/deleted, no seeding occurs, and no playback/CDN requests are made. Do not use fixture passwords or existing destructive E2E tests. Enter inputs privately; do not enable shell tracing.

```bash
read -r -s -p 'Existing CMS email: ' HYPERLINE_CMS_EMAIL; printf '\n'
read -r -s -p 'Existing CMS password: ' HYPERLINE_CMS_PASSWORD; printf '\n'
read -r -s -p 'Existing viewer email: ' HYPERLINE_VIEWER_EMAIL; printf '\n'
read -r -s -p 'Existing viewer password: ' HYPERLINE_VIEWER_PASSWORD; printf '\n'
export HYPERLINE_CMS_EMAIL HYPERLINE_CMS_PASSWORD HYPERLINE_VIEWER_EMAIL HYPERLINE_VIEWER_PASSWORD
PLAYWRIGHT_BROWSERS_PATH=/tmp/hyperline-playwright node deploy/https/browser-smoke.cjs
unset HYPERLINE_CMS_EMAIL HYPERLINE_CMS_PASSWORD HYPERLINE_VIEWER_EMAIL HYPERLINE_VIEWER_PASSWORD
```

Authenticated playback/renewal denial needs an approved published movie/existing session if later requested; empty production catalog currently prevents that scenario. Keep CDN verification flags false and signing required throughout activation.

## Scoped rollback — prepare now, execute only with reload approval

TLS failure: switch only the three new links back to verified bootstrap files. No application image, certificate, source, database or VPN recovery command is involved.

```bash
for label in ott cms api; do
  current=$(readlink /etc/nginx/sites-enabled/zz-hyperline-$label)
  case "$current" in
    /etc/nginx/sites-available/zz-hyperline-$label.https.conf|/etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf) ;;
    *) echo 'Unexpected link; stop for review' >&2; exit 1 ;;
  esac
  cmp /etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf \
    "$hyperline_https_backup/staged/bootstrap/zz-hyperline-$label.conf"
done
for label in ott cms api; do
  ln -sfn /etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf \
    /etc/nginx/sites-enabled/zz-hyperline-$label
done
nginx -t
# Only with rollback reload approval; skip reload if Phase C never loaded:
systemctl reload nginx.service
```

Abandon bootstrap: validate all three links first, then unlink only those links:

```bash
for label in ott cms api; do
  test "$(readlink /etc/nginx/sites-enabled/zz-hyperline-$label)" = \
    "/etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf"
done
for label in ott cms api; do
  unlink /etc/nginx/sites-enabled/zz-hyperline-$label
done
nginx -t
# Only with rollback reload approval; skip if bootstrap never loaded:
systemctl reload nginx.service
```

If abandoning after Phase C installed its hook, disable only the verified new executable hook, preserving its bytes:

```bash
if test -e /etc/letsencrypt/renewal-hooks/deploy/zz-hyperline-reload; then
  test ! -L /etc/letsencrypt/renewal-hooks/deploy/zz-hyperline-reload
  cmp /etc/letsencrypt/renewal-hooks/deploy/zz-hyperline-reload \
    "$hyperline_https_backup/staged/renewal/hyperline-reload.sh"
  chmod 644 /etc/letsencrypt/renewal-hooks/deploy/zz-hyperline-reload
fi
sha256sum -c "$hyperline_https_backup/zentunnel.sha256"
sha256sum -c "$hyperline_https_backup/zentunnel-cert.sha256"
cmp /etc/letsencrypt/renewal/zentunnel.net.conf "$hyperline_https_backup/zentunnel.renewal.conf"
python3 deploy/check-activated-release.py
```

New unused maps/snippets/sites/certificate lineage stay in place. Do not restore all of `/etc/nginx`, revoke certificates, start Zentunnel, reset databases or remove Docker volumes. If Phase A/C failed before reload, the old Nginx workers continue serving the previous configuration; validate the recovered disk configuration before any separately approved reload.
