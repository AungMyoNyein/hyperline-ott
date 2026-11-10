#!/usr/bin/env python3
"""Read-only host/DNS/certificate audit; writes sanitized workspace evidence only."""
import concurrent.futures
import datetime
import hashlib
import json
import os
from pathlib import Path
import subprocess

ROOT = Path('/opt/hyperline-ott')
OUT = ROOT / 'deploy/reports/https-final-20261109'
OUT.mkdir(exist_ok=True)

def run(args):
    p = subprocess.run(args, capture_output=True, text=True, timeout=30)
    return {'code': p.returncode, 'output': p.stdout.strip()}

def index(base):
    result = {}
    for p in sorted(base.rglob('*')):
        if p.is_symlink():
            result[str(p.relative_to(base))] = {'link': os.readlink(p)}
        elif p.is_file():
            result[str(p.relative_to(base))] = {'sha256': hashlib.sha256(p.read_bytes()).hexdigest(),
                                               'mode': p.stat().st_mode & 0o777, 'uid': p.stat().st_uid}
    return result

checks = {}
evidence = {'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat()}
live = index(Path('/etc/nginx'))
baseline = index(Path('/var/backups/hyperline/pre-activation-20261109T143135Z/nginx'))
checks['complete_host_nginx_tree_matches_pre_activation_backup'] = bool(baseline) and live == baseline
evidence['nginx_tree'] = live
evidence['nginx_changed_paths'] = [p for p in sorted(set(live) | set(baseline)) if live.get(p) != baseline.get(p)]
checks['original_zentunnel_vhost_hash'] = hashlib.sha256(Path('/etc/nginx/sites-available/zentunnel').read_bytes()).hexdigest() == 'f4c3fd2256852e7438019b790c4993264a703b315e637264cb0ae983fb82ebe4'

hosts = ['ott.zentunnel.net', 'cms.zentunnel.net', 'api.zentunnel.net']
queries = [(resolver, host, kind) for resolver in ['1.1.1.1', '8.8.8.8']
           for host in hosts + ['zentunnel.net'] for kind in (['A', 'AAAA', 'CAA'] if host in hosts else ['CAA'])]
def dns(q):
    resolver, host, kind = q
    r = run(['dig', '@' + resolver, host, kind, '+time=3', '+tries=1', '+noall', '+comments', '+answer'])
    return {'resolver': resolver, 'host': host, 'type': kind, **r}
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
    evidence['dns'] = list(pool.map(dns, queries))
for r in evidence['dns']:
    label = 'dns_' + r['resolver'] + '_' + r['host'] + '_' + r['type']
    good = r['code'] == 0 and 'status: NOERROR' in r['output']
    answers = [s for s in r['output'].splitlines() if s and not s.startswith(';')]
    if r['type'] == 'A': good = good and bool(answers) and all(s.split()[-1] == '157.85.104.49' for s in answers)
    if r['type'] == 'AAAA': good = good and not answers
    if r['type'] == 'CAA': good = good and (not answers or all('letsencrypt.org' in s for s in answers))
    checks[label] = good

paths = ['/var/www/hyperline-acme', '/etc/letsencrypt/live/hyperline-ott', '/etc/letsencrypt/archive/hyperline-ott',
         '/etc/letsencrypt/renewal/hyperline-ott.conf', '/etc/nginx/conf.d/zz-hyperline-http-common.conf',
         '/etc/nginx/snippets/hyperline-edge-proxy.conf', '/etc/letsencrypt/renewal-hooks/deploy/zz-hyperline-reload']
for label in ['ott', 'cms', 'api']:
    paths += ['/etc/nginx/sites-enabled/zz-hyperline-' + label,
              '/etc/nginx/sites-available/zz-hyperline-' + label + '.bootstrap.conf',
              '/etc/nginx/sites-available/zz-hyperline-' + label + '.https.conf']
evidence['collisions'] = [p for p in paths if os.path.lexists(p)]
checks['all_planned_paths_unused'] = not evidence['collisions']

renewal = Path('/etc/letsencrypt/renewal/zentunnel.net.conf')
saved = Path('/var/backups/hyperline/pre-activation-20261109T143135Z/renewal/zentunnel.net.conf')
checks['zentunnel_renewal_unchanged'] = renewal.read_bytes() == saved.read_bytes()
evidence['renewal_hooks'] = index(Path('/etc/letsencrypt/renewal-hooks'))
checks['no_global_renewal_hooks'] = not evidence['renewal_hooks']
evidence['certbot_global_options'] = []
for p in [Path('/etc/letsencrypt/cli.ini'), renewal]:
    if p.exists():
        for line in p.read_text().splitlines():
            if line.strip() and not line.lstrip().startswith('#') and any(s in line.lower() for s in ['hook', 'authenticator', 'installer', 'server =']):
                evidence['certbot_global_options'].append({'path': str(p), 'option': line.strip()})
checks['no_configured_global_hooks'] = not any('hook' in v['option'].lower() for v in evidence['certbot_global_options'])
cert = Path('/etc/letsencrypt/live/zentunnel.net/fullchain.pem')
evidence['zentunnel_certificate'] = run(['openssl', 'x509', '-in', str(cert), '-noout', '-fingerprint', '-sha256', '-serial', '-dates', '-ext', 'subjectAltName'])
evidence['zentunnel_certificate'].update({'resolved': str(cert.resolve()), 'sha256': hashlib.sha256(cert.read_bytes()).hexdigest(),
    'modified_utc': datetime.datetime.fromtimestamp(cert.stat().st_mtime, datetime.timezone.utc).isoformat(), 'link': os.readlink(cert)})
key = Path('/etc/letsencrypt/live/zentunnel.net/privkey.pem')
evidence['zentunnel_key_metadata'] = {'uid': key.stat().st_uid, 'mode': oct(key.stat().st_mode & 0o777), 'resolved': str(key.resolve())}
checks['zentunnel_certificate_valid_now'] = run(['openssl', 'x509', '-in', str(cert), '-checkend', '0', '-noout'])['code'] == 0
checks['zentunnel_key_root_0600'] = key.stat().st_uid == 0 and key.stat().st_mode & 0o777 == 0o600

evidence['certbot_timer'] = run(['systemctl', 'is-active', 'certbot.timer'])
evidence['certbot_version'] = run(['certbot', '--version'])
evidence['certbot_plugins'] = run(['certbot', 'plugins'])
checks['webroot_plugin_available'] = '* webroot' in evidence['certbot_plugins']['output']
checks['renewal_timer_active'] = evidence['certbot_timer']['output'] == 'active'
evidence['listeners'] = run(['ss', '-H', '-ltn', '( sport = :80 or sport = :443 or sport = :18080 )'])
evidence['public_probes'] = {}
for host in hosts:
    evidence['public_probes'][host] = {scheme: run(['curl', '--noproxy', '*', '--max-time', '10', '-s', '-o', '/dev/null', '-w', '%{http_code}', scheme + '://' + host + '/']) for scheme in ['http', 'https']}

evidence['checks'] = checks
(OUT / 'host-dns-certificate.json').write_text(json.dumps(evidence, indent=2) + '\n')
for label, ok in checks.items(): print(('PASS ' if ok else 'FAIL ') + label, flush=True)
print('Evidence: ' + str(OUT / 'host-dns-certificate.json'))
raise SystemExit(0 if all(checks.values()) else 1)
