#!/usr/bin/env python3
"""Read-only production preflight for the series release candidate.

Usage: python3 deploy/check-series-release-preflight.py --release <release-id>
       [--require-activation-gates]

Verifies the candidate packet (manifest, images, compose overrides, migration
bytes) AND that production is unchanged since preparation (running images,
protected containers, mounts, data counts, no series tables/records, old API
still 404s on /series, VPN stopped, gateway loopback-only). Never prints
response bodies, secrets or token material. Safe to run repeatedly.

With --require-activation-gates (use inside the approved rollout window),
additionally requires the fresh protected backup and its isolated restore
verification for THIS release to exist and be PASS before any mutation.
"""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
BASE = 'http://127.0.0.1:18080'
VPN_UNITS = ['vpn-api.service', 'vpn-control-plane.service', 'vpn-node-agent.service',
             'xray-vless.service', 'shadowsocks-libev.service', 'wg-quick@wg0.service']
RESULTS = []


def check(condition, label):
    RESULTS.append((bool(condition), label))
    print(('PASS ' if condition else 'FAIL ') + label, flush=True)
    return bool(condition)


def sh(args, cwd=ROOT):
    return subprocess.run(args, cwd=cwd, capture_output=True, text=True)


def docker(args):
    p = sh(['docker', *args])
    if p.returncode != 0:
        raise RuntimeError('docker ' + ' '.join(args[:2]) + ' failed (output suppressed)')
    return p.stdout.strip()


def image_id(reference):
    return docker(['image', 'inspect', reference, '--format', '{{.Id}}'])


def sha256_file(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def request(path, *, host='api.zentunnel.net', method='GET', headers=None):
    req = urllib.request.Request(BASE + path, method=method, headers={'Host': host, **(headers or {})})
    try:
        response = urllib.request.urlopen(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read()


def psql(sql):
    return docker(['exec', 'hyperline-postgres-1', 'sh', '-c',
                   f'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "{sql}"'])


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--release', required=True)
    parser.add_argument('--require-activation-gates', action='store_true')
    args = parser.parse_args()

    release_dir = ROOT / 'deploy/releases' / args.release
    manifest_path = release_dir / 'manifest.json'
    if not check(manifest_path.is_file(), f'release manifest exists: {args.release}'):
        return finish(args)
    manifest = json.loads(manifest_path.read_text())
    check(manifest.get('release_id') == args.release, 'manifest release_id matches directory')

    # --- candidate images -------------------------------------------------
    for role, spec in manifest['built_images'].items():
        try:
            check(image_id(spec['reference']) == spec['image_id'], f'candidate image ID matches manifest: {role}')
        except RuntimeError:
            check(False, f'candidate image present locally: {role}')

    # --- guarded rollback -------------------------------------------------
    guarded = manifest['guarded_api_rollback']
    try:
        check(image_id(guarded['reference']) == guarded['image_id'], 'guarded API rollback image ID matches manifest')
        baseline_api = manifest['deployed_baseline']['services']['api']['image_id']
        check(guarded['image_id'] == baseline_api, 'guarded API rollback equals the currently deployed (fail-closed) API image')
    except RuntimeError:
        check(False, 'guarded API rollback image present locally')
    rollback_text = (release_dir / 'rollback.compose.yaml').read_text()
    check(guarded['reference'] in rollback_text, 'rollback override references the guarded API image')
    check('runtime-20261109T183604Z' not in rollback_text, 'rollback override carries no stale release tags')

    # --- compose overrides ------------------------------------------------
    for name in ['release.compose.yaml', 'rollback.compose.yaml']:
        p = sh(['docker', 'compose', '-f', str(ROOT / 'compose.yaml'), '-f', str(release_dir / name), 'config', '--quiet'])
        check(p.returncode == 0, f'compose override validates: {name}')
    check(sha256_file(ROOT / 'compose.yaml') == manifest['config_checksums']['compose.yaml'], 'compose.yaml checksum matches manifest')
    check(sha256_file(release_dir / 'release.compose.yaml') == manifest['config_checksums']['release.compose.yaml'], 'release.compose.yaml checksum matches manifest')
    check(sha256_file(release_dir / 'rollback.compose.yaml') == manifest['config_checksums']['rollback.compose.yaml'], 'rollback.compose.yaml checksum matches manifest')

    # --- migration integrity ----------------------------------------------
    for filename, digest in manifest['migrations']['checksums'].items():
        repo_file = ROOT / 'services/api/database/migrations' / filename
        check(repo_file.is_file() and sha256_file(repo_file) == digest, f'repo migration bytes match manifest: {filename}')
    listing = docker(['run', '--rm', '--network', 'none', '--entrypoint', 'sh',
                      manifest['built_images']['api']['reference'], '-c',
                      'cd /var/www/html && sha256sum database/migrations/*.php'])
    in_image = {line.split()[1].split('/')[-1]: line.split()[0] for line in listing.splitlines() if line.strip()}
    check(in_image == manifest['migrations']['checksums'], 'candidate image migration bytes match manifest exactly')
    check(manifest['migrations']['new'] == ['2026_11_10_000100_create_series_tables.php'], 'exactly one new migration declared')

    # --- production unchanged since preparation ----------------------------
    for role, spec in manifest['deployed_baseline']['services'].items():
        try:
            c = json.loads(docker(['inspect', spec['container']]))[0]
            ok = (c['Image'] == spec['image_id'] and c['State']['Status'] == 'running'
                  and c['Config']['Labels'].get('com.docker.compose.project') == 'hyperline')
            check(ok, f'running container still on prepared baseline image: {role}')
            actual_mounts = {(m['Type'], m['Source'], m['Destination']) for m in c['Mounts']}
            expected_mounts = {(m['type'], m['source'], m['destination']) for m in spec['mounts']}
            check(actual_mounts == expected_mounts, f'persistent mounts unchanged: {role}')
        except (RuntimeError, KeyError, IndexError):
            check(False, f'baseline container inspectable: {role}')
    for role, spec in manifest['deployed_baseline']['protected_services'].items():
        try:
            c = json.loads(docker(['inspect', spec['container']]))[0]
            check(c['Id'] == spec['container_id'] and c['State']['StartedAt'] == spec['started_at'],
                  f'protected container untouched: {role}')
        except (RuntimeError, KeyError, IndexError):
            check(False, f'protected container inspectable: {role}')
    gw = json.loads(docker(['inspect', manifest['deployed_baseline']['protected_services']['gateway']['container']]))[0]
    check(gw['HostConfig']['PortBindings'] == {'80/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '18080'}]},
          'gateway remains loopback-only (127.0.0.1:18080)')
    net = json.loads(docker(['network', 'inspect', 'hyperline_backend']))[0]
    check(net[0].get('Internal') is True, 'backend network remains internal')

    # --- production data unchanged / no series imported --------------------
    check(psql("SELECT to_regclass('public.series') IS NULL AND to_regclass('public.series_episodes') IS NULL") == 't',
          'no series tables exist in production (nothing imported/deployed)')
    for table, column in manifest['data_baseline'].items():
        check(psql(f'SELECT count(*) FROM {table}') == str(column), f'production row count unchanged: {table}={column}')
    status, _ = request('/api/v1/series')
    check(status == 404, 'running production API still 404s on /api/v1/series (old code)')
    status, body = request('/api/v1/health')
    check(status == 200 and json.loads(body).get('status') == 'ok', 'production API healthy')

    # --- configuration hygiene ---------------------------------------------
    env_path = ROOT / '.env'
    check(env_path.exists() and oct(env_path.stat().st_mode)[-3:] == '600', '.env permissions remain 0600')
    app_key_line = [l for l in env_path.read_text().splitlines() if l.startswith('APP_KEY=')]
    check(bool(app_key_line) and app_key_line[0].split('=', 1)[1].startswith('base64:') and len(app_key_line[0]) > 20,
          'APP_KEY present and base64-prefixed (value never printed)')

    # --- VPN remains stopped -----------------------------------------------
    for unit in VPN_UNITS:
        active = sh(['systemctl', 'is-active', unit]).stdout.strip()
        enabled = sh(['systemctl', 'is-enabled', unit]).stdout.strip()
        check(active == 'inactive' and enabled == 'disabled', f'VPN unit disabled+inactive: {unit}')
    wg = sh(['ip', '-o', 'link', 'show', 'dev', 'wg0'])
    check(wg.returncode != 0 or 'state UP' not in wg.stdout, 'wg0 remains down')

    # --- activation gates (fresh backup for THIS release) -------------------
    backup = Path('/var/backups/hyperline') / args.release / 'postgres.dump'
    recovery = release_dir / 'backup-recovery-verification.json'
    gates = [backup.is_file() and oct(backup.stat().st_mode)[-3:] == '600',
             recovery.is_file() and json.loads(recovery.read_text()).get('result') == 'PASS']
    for ok, label in zip(gates, ['fresh protected backup exists for this release (0600)',
                                 'isolated restore verification recorded PASS for this release']):
        if args.require_activation_gates:
            check(ok, label)
        else:
            print(('GATE-READY ' if ok else 'GATE-PENDING ') + label, flush=True)

    return finish(args)


def finish(args):
    failed = [label for ok, label in RESULTS if not ok]
    out = ROOT / 'deploy/releases' / args.release / 'preflight-series-result.json'
    if out.parent.is_dir():
        out.write_text(json.dumps({'release': args.release, 'failed': failed,
                                   'checks': [{'result': 'PASS' if ok else 'FAIL', 'label': l} for ok, l in RESULTS]}, indent=2) + '\n')
    print(f'summary: {len(RESULTS) - len(failed)}/{len(RESULTS)} PASS')
    return 1 if failed else 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except SystemExit:
        raise
    except Exception as exc:
        print('FAIL preflight aborted: ' + type(exc).__name__ + ' (details suppressed)')
        sys.exit(1)
