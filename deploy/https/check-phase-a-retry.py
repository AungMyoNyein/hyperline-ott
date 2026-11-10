#!/usr/bin/env python3
"""Read-only precheck for the retained-artifact Phase A retry; never enables sites.
Writes an optional sanitized report and an isolated temporary restore copy only.
"""
import argparse
import concurrent.futures
import datetime
import hashlib
import http.client
import json
import os
from pathlib import Path
import shutil
import ssl
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
BACKUP = Path('/var/backups/hyperline-https/final-20261109T154849Z')
HOSTS = ['ott.zentunnel.net', 'cms.zentunnel.net', 'api.zentunnel.net']


def run(args):
    p = subprocess.run(args, capture_output=True, text=True, timeout=30)
    if p.returncode:
        raise RuntimeError('Command failed: ' + ' '.join(args[:3]))
    return p.stdout


def index(base):
    result = {}
    for p in base.rglob('*'):
        st = p.lstat()
        value = {'uid': st.st_uid, 'gid': st.st_gid, 'mode': st.st_mode & 0o777}
        if p.is_symlink():
            value['link'] = os.readlink(p)
        elif p.is_file():
            value['sha256'] = hashlib.sha256(p.read_bytes()).hexdigest()
        elif p.is_dir():
            value['directory'] = True
        else:
            raise RuntimeError('Unexpected filesystem entry type')
        result[str(p.relative_to(base))] = value
    return result


def get(address, host, path='/', tls=False):
    c = http.client.HTTPSConnection(address, timeout=3, context=ssl.create_default_context()) if tls else http.client.HTTPConnection(address, timeout=3)
    try:
        c.request('GET', path, headers={'Host': host, 'Connection': 'close'})
        r = c.getresponse()
        return r.status, r.headers, r.read()
    finally:
        c.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--expect-bootstrap', action='store_true', help='Post-activation mode: require exact three links and strict HTTP/ACME routing')
    args = parser.parse_args()
    evidence = {'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'checks': [], 'status': 'running read-only checks'}
    def check(ok, label):
        evidence['checks'].append({'label': label, 'pass': bool(ok)})
        print(('PASS ' if ok else 'FAIL ') + label, flush=True)
        if args.output:
            args.output.write_text(json.dumps(evidence, indent=2)+'\n')
        if not ok:
            raise RuntimeError(label)
    baseline = index(BACKUP/'nginx')
    check(bool(baseline), 'Original activation backup readable')
    expected = dict(baseline)
    for label in ['ott', 'cms', 'api']:
        relative = 'sites-available/zz-hyperline-'+label+'.bootstrap.conf'
        p = Path('/etc/nginx')/relative
        stage = ROOT/'deploy/https/bootstrap'/('zz-hyperline-'+label+'.conf')
        check(not p.is_symlink() and p.is_file() and p.read_bytes() == stage.read_bytes() == (BACKUP/'staged/bootstrap'/stage.name).read_bytes(), 'Retained bootstrap bytes match approved template: '+label)
        expected[relative] = {'uid': 0, 'gid': 0, 'mode': 0o644, 'sha256': hashlib.sha256(stage.read_bytes()).hexdigest()}
        link = '/etc/nginx/sites-enabled/zz-hyperline-'+label
        if args.expect_bootstrap:
            expected['sites-enabled/zz-hyperline-'+label] = {'uid': 0, 'gid': 0, 'mode': 0o777, 'link': str(p)}
            check(Path(link).is_symlink() and os.readlink(link) == str(p), 'Exact bootstrap link enabled: '+label)
        else:
            check(not os.path.lexists(link), 'Bootstrap disabled: '+label)
    check(index(Path('/etc/nginx')) == expected, 'Full Nginx content/ownership/mode/link/directory baseline unchanged')
    run(['nginx', '-t']); check(True, 'Production nginx -t')
    check(run(['systemctl', 'is-active', 'nginx']).strip() == 'active', 'Production Nginx active')
    token = Path('/var/www/hyperline-acme/.well-known/acme-challenge/readiness-check')
    check(not token.is_symlink() and token.is_file() and token.read_bytes() == b'hyperline-acme-readiness\n' and token.stat().st_uid == 0 and token.stat().st_mode & 0o777 == 0o644, 'Retained ACME token exact bytes/owner/mode')
    for parent in list(token.parents)[:3]:
        check(not parent.is_symlink() and parent.is_dir() and parent.stat().st_uid == 0 and parent.stat().st_mode & 0o777 == 0o755, 'Retained ACME directory preserved: '+str(parent))
    check(not any(os.path.lexists(p) for p in ['/etc/letsencrypt/live/hyperline-ott', '/etc/letsencrypt/archive/hyperline-ott', '/etc/letsencrypt/renewal/hyperline-ott.conf', '/etc/letsencrypt/renewal-hooks/deploy/zz-hyperline-reload', '/etc/nginx/conf.d/zz-hyperline-http-common.conf', '/etc/nginx/snippets/hyperline-edge-proxy.conf']), 'No HyperLine certificate/HTTPS artifacts or hook')
    for name in ['zentunnel.sha256', 'zentunnel-cert.sha256']:
        run(['sha256sum', '-c', str(BACKUP/name)])
    check(Path('/etc/letsencrypt/renewal/zentunnel.net.conf').read_bytes() == (BACKUP/'zentunnel.renewal.conf').read_bytes() and index(Path('/etc/letsencrypt/renewal-hooks')) == index(BACKUP/'renewal-hooks'), 'Zentunnel certificate/vhost hashes, renewal and hooks preserved')
    v = os.statvfs('/var/backups'); evidence['free_bytes'] = v.f_bavail*v.f_frsize; evidence['free_inodes'] = v.f_favail
    check(evidence['free_bytes'] > 100*1024*1024 and v.f_favail > 1000, 'Sufficient backup bytes and inodes')
    with tempfile.TemporaryDirectory(prefix='hyperline-retry-restore-') as tmp:
        restored = Path(tmp)/'nginx'
        shutil.copytree(BACKUP/'nginx', restored, symlinks=True)
        check(index(restored) == baseline, 'Backup restore to isolated directory preserves indexed metadata/bytes')
    check(Path('/var/backups').is_dir() and os.access('/var/backups', os.W_OK), 'Backup parent writable by operator')
    def dns(q):
        resolver, host, kind = q
        output = run(['dig', '@'+resolver, host, kind, '+time=3', '+tries=1', '+noall', '+comments', '+answer'])
        answers = [line for line in output.splitlines() if line and not line.startswith(';')]
        ok = 'status: NOERROR' in output
        if kind == 'A': ok = ok and bool(answers) and all(line.split()[-1] == '157.85.104.49' for line in answers)
        if kind == 'AAAA': ok = ok and not answers
        if kind == 'CAA': ok = ok and (not answers or all('letsencrypt.org' in line for line in answers))
        return ok, 'DNS '+' '.join(q)
    queries = [(r, h, t) for r in ['1.1.1.1', '8.8.8.8'] for h in HOSTS+['zentunnel.net'] for t in (['A', 'AAAA', 'CAA'] if h in HOSTS else ['CAA'])]
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(dns, queries))
    for ok, label in results: check(ok, label)
    manifest = json.loads((ROOT/'deploy/releases/runtime-20261109T003915Z/manifest.json').read_text())
    containers = json.loads(run(['docker', 'inspect']+['hyperline-'+r+'-1' for r in ['api', 'web', 'cms', 'worker', 'scheduler', 'gateway', 'postgres', 'redis']]))
    for c in containers:
        role = c['Config']['Labels']['com.docker.compose.service']
        ok = c['State']['Running'] and c['Config']['Labels']['com.docker.compose.project'] == 'hyperline'
        if role in ['api', 'web', 'cms', 'worker', 'scheduler']:
            ok = ok and c['State']['Health']['Status'] == 'healthy' and c['Image'] == manifest['built_images'][role if role in ['web', 'cms'] else 'api']['image_id']
        else:
            ok = ok and c['Id'] == manifest['protected_services'][role]['container_id']
        if role in ['postgres', 'redis']: ok = ok and c['State']['Health']['Status'] == 'healthy'
        old = {**manifest['services'], **manifest['protected_services']}[role]
        ok = ok and {(v['Type'], v['Source'], v['Destination']) for v in c['Mounts']} == {(v['type'], v['source'], v['destination']) for v in old['mounts']}
        if role == 'gateway': ok = ok and c['HostConfig']['PortBindings'] == {'80/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '18080'}]}
        check(ok, 'Container identity/image/health/mounts: '+role)
    for role in ['worker', 'scheduler']:
        run(['docker', 'exec', 'hyperline-'+role+'-1', 'php', 'docker/healthcheck.php', role]); check(True, role+' dependencies/heartbeat')
    for host, path in [(HOSTS[0], '/'), (HOSTS[1], '/'), (HOSTS[2], '/api/v1/health')]:
        code, _, body = get('127.0.0.1:18080', host, path)
        check(code == 200 and ('health' not in path or json.loads(body)['status'] == 'ok'), 'Gateway route healthy: '+host+path)
    code, headers, _ = get('zentunnel.net', 'zentunnel.net')
    check(code == 301 and headers.get('Location') == 'https://zentunnel.net/', 'Zentunnel HTTP redirect unchanged')
    code, _, body = get('zentunnel.net', 'zentunnel.net', tls=True)
    expected_body = json.loads((ROOT/'deploy/reports/https-final-20261109/PHASE-A-EXECUTION.json').read_text())['zentunnel_before']['frontend_sha256']
    evidence['zentunnel_frontend_sha256'] = hashlib.sha256(body).hexdigest()
    check(code == 200 and evidence['zentunnel_frontend_sha256'] == expected_body, 'Trusted Zentunnel HTTPS frontend unchanged')
    check(get('zentunnel.net', 'zentunnel.net', '/api/v1/health', tls=True)[0] == 502, 'Intentionally stopped Zentunnel API still 502')
    for unit in ['vpn-api', 'vpn-control-plane', 'vpn-node-agent', 'xray-vless', 'shadowsocks-libev', 'wg-quick@wg0']:
        state = dict(line.split('=', 1) for line in run(['systemctl', 'show', unit, '--property=ActiveState,UnitFileState,MainPID']).splitlines())
        check(state == {'MainPID': '0', 'ActiveState': 'inactive', 'UnitFileState': 'disabled'}, 'VPN remains stopped/disabled: '+unit)
    if args.expect_bootstrap:
        run(['python3', str(ROOT/'deploy/https/verify-bootstrap-routes.py'), '--connect-address', '127.0.0.1'])
        check(True, 'Strict loopback/Host HTTP statuses, Retry-After and exact ACME bytes')
        run(['python3', str(ROOT/'deploy/https/verify-bootstrap-routes.py')])
        check(True, 'Strict public-DNS-path HTTP statuses, Retry-After and exact ACME bytes')
    else:
        for host in HOSTS:
            check(get('127.0.0.1', host)[0] == 404, 'Current disabled-bootstrap public host response 404: '+host)
    evidence['nginx_service'] = run(['systemctl', 'show', 'nginx', '--property=MainPID,ExecReload,ActiveState'])
    evidence['status'] = 'PASS post-activation read-only checks' if args.expect_bootstrap else 'PASS; preparation only; production retry not executed'
    if args.output: args.output.write_text(json.dumps(evidence, indent=2)+'\n')
    print('PASS all read-only retained-artifact checks; bootstrap expected=' + str(args.expect_bootstrap), flush=True)


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, http.client.HTTPException) as error:
        raise SystemExit(f'FAIL retry precheck: {error}')
