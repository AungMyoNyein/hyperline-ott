#!/usr/bin/env bash
# Prepared only. Requires separate explicit approval before invocation with --execute.
# Phase A only: no certbot, HTTPS installs, application rollout or VPN starts.
set -Eeuo pipefail
if [[ "${1:-}" != --execute || $# != 1 ]]; then
  echo 'Preparation artifact only. After separate Phase A approval: bash deploy/https/phase-a-retry.sh --execute'
  exit 2
fi
cd /opt/hyperline-ott
hyperline_https_backup=''
reload_attempted=0
created_links=()
rollback() {
  trap - ERR INT TERM
  local original_status=${1:-1}
  [[ "$original_status" -ne 0 ]] || original_status=1
  if [[ ${#created_links[@]} -eq 0 ]]; then
    echo 'STOP: no bootstrap links created; no Nginx reload/rollback performed.'
    exit "$original_status"
  fi
  echo 'Critical failure: performing approved scoped Phase A rollback.'
  local link label
  for link in "${created_links[@]}"; do
    label=${link##*/zz-hyperline-}
    if [[ ! -L "$link" || "$(readlink "$link")" != "/etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf" ]]; then
      echo 'STOP: unexpected rollback target; no further mutation or reload.'
      exit 1
    fi
  done
  for link in "${created_links[@]}"; do
    unlink "$link" || exit 1
  done
  nginx -t || exit 1
  if [[ "$reload_attempted" -eq 1 ]]; then
    python3 deploy/https/wait-nginx-generation.py snapshot > "$hyperline_https_backup/nginx-workers.rollback-before.json" || exit 1
    systemctl reload nginx.service || exit 1
    python3 deploy/https/wait-nginx-generation.py wait \
      --before "$hyperline_https_backup/nginx-workers.rollback-before.json" --timeout 5 || exit 1
  fi
  python3 deploy/https/check-phase-a-retry.py --output "$hyperline_https_backup/rollback-checks.json" || exit 1
  echo "ROLLBACK COMPLETE. Retained site files/token and backup preserved: $hyperline_https_backup"
  exit "$original_status"
}
trap 'rollback $?' ERR
trap 'rollback 130' INT
trap 'rollback 143' TERM
# These read-only checks include retained artifacts, DNS, disk, backup restore,
# original vhosts/certificates, expected Zentunnel responses, gateway and VPN state.
python3 deploy/https/check-phase-a-retry.py
hyperline_https_backup=/var/backups/hyperline-https/final-$(date -u +%Y%m%dT%H%M%SZ)
test ! -e "$hyperline_https_backup"
test ! -L "$hyperline_https_backup"
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
diff -qr --no-dereference /etc/nginx "$hyperline_https_backup/nginx"
cmp /etc/letsencrypt/renewal/zentunnel.net.conf "$hyperline_https_backup/zentunnel.renewal.conf"
diff -qr --no-dereference /etc/letsencrypt/renewal-hooks "$hyperline_https_backup/renewal-hooks"
diff -qr --no-dereference deploy/https "$hyperline_https_backup/staged"
sha256sum -c "$hyperline_https_backup/zentunnel.sha256"
sha256sum -c "$hyperline_https_backup/zentunnel-cert.sha256"
printf 'Recovery directory: %s\n' "$hyperline_https_backup"
# Revalidate directly before enabling. Reuse exact retained files/token; overwrite nothing.
python3 deploy/https/check-phase-a-retry.py --output "$hyperline_https_backup/pre-enable-checks.json"
for label in ott cms api; do
  link=/etc/nginx/sites-enabled/zz-hyperline-$label
  ln -s /etc/nginx/sites-available/zz-hyperline-$label.bootstrap.conf "$link"
  created_links+=("$link")
done
# Exact Nginx diff gate: baseline files/metadata/directories plus only three symlinks.
python3 - "$hyperline_https_backup" <<'VERIFY'
import importlib.util
from pathlib import Path
import sys
spec = importlib.util.spec_from_file_location('precheck', 'deploy/https/check-phase-a-retry.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
backup = Path(sys.argv[1])
import shutil
import tempfile
with tempfile.TemporaryDirectory(prefix='hyperline-new-backup-restore-') as tmp:
    restored = Path(tmp)/'nginx'
    shutil.copytree(backup/'nginx', restored, symlinks=True)
    if m.index(restored) != m.index(backup/'nginx'):
        raise SystemExit('FAIL new activation backup isolated restore verification')
print('PASS new activation backup isolated restore verification')
expected = m.index(backup/'nginx')
for label in ['ott', 'cms', 'api']:
    expected['sites-enabled/zz-hyperline-'+label] = {'uid': 0, 'gid': 0, 'mode': 0o777, 'link': '/etc/nginx/sites-available/zz-hyperline-'+label+'.bootstrap.conf'}
if m.index(Path('/etc/nginx')) != expected:
    raise SystemExit('FAIL: unexpected Nginx changes before reload')
print('PASS only the three approved symlinks added; original entries unchanged')
VERIFY
nginx -t
python3 deploy/https/wait-nginx-generation.py snapshot > "$hyperline_https_backup/nginx-workers.before.json"
reload_attempted=1
systemctl reload nginx.service
# A new worker generation is necessary, but never sufficient for success.
python3 deploy/https/wait-nginx-generation.py wait \
  --before "$hyperline_https_backup/nginx-workers.before.json" --timeout 5
# Strict routes first, with no retry, redirect following or 404 fallback.
python3 deploy/https/verify-bootstrap-routes.py --connect-address 127.0.0.1
python3 deploy/https/verify-bootstrap-routes.py
# Then confirm exact Nginx diff, Zentunnel bytes/routes, gateway/application health,
# unchanged cert/renewal, DNS and stopped VPN units. Routes are asserted again.
python3 deploy/https/check-phase-a-retry.py --expect-bootstrap --output "$hyperline_https_backup/post-enable-checks.json"
sha256sum -c "$hyperline_https_backup/zentunnel.sha256"
sha256sum -c "$hyperline_https_backup/zentunnel-cert.sha256"
trap - ERR INT TERM
printf 'Phase A local/public-DNS-path verification complete. Backup: %s\n' "$hyperline_https_backup"
echo 'External-network reachability remains to be proven. STOP: no Phase B authorization.'
