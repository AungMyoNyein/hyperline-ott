#!/usr/bin/env python3
"""Post-deployment verification for the series release. Run ONLY after the
approved application rollout and migration.

Usage: python3 deploy/check-series-release-postdeploy.py --release <release-id>

Read-only. Asserts running image IDs against the manifest, container health,
protected-service immutability, mount preservation, worker/scheduler heartbeats,
series API shape (pagination, additive home/search keys, ordered-empty catalog),
unauthenticated 401 JSON + CORS contracts, fail-closed playback configuration,
migration state (10 ran / 0 pending), data preservation against the manifest
baseline, public HTTPS health, and VPN stopped-state. Never prints response
bodies (except health status), secrets or tokens. Authenticated episode
denial/renewal checks run only with operator-supplied approved identifiers.
"""
import argparse
import json
import os
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


def request(path, *, host='api.zentunnel.net', method='GET', headers=None, body=None):
    req = urllib.request.Request(BASE + path, data=body, method=method,
                                 headers={'Host': host, **(headers or {})})
    try:
        response = urllib.request.urlopen(req, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def psql(sql):
    return docker(['exec', 'hyperline-postgres-1', 'sh', '-c',
                   f'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "{sql}"'])


def no_leak(body: bytes) -> bool:
    text = body.decode(errors='replace').lower()
    return not any(marker in text for marker in ['m3u8', 'goldendragon', 'playlist', 'enc_1', '/var/www', 'stack trace'])


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--release', required=True)
    args = parser.parse_args()
    manifest = json.loads((ROOT / 'deploy/releases' / args.release / 'manifest.json').read_text())

    # --- running images / health -------------------------------------------
    for role in ['api', 'web', 'cms', 'worker', 'scheduler']:
        c = json.loads(docker(['inspect', 'hyperline-' + role + '-1']))[0]
        expected = manifest['built_images'][role if role in manifest['built_images'] else 'api']['image_id']
        check(c['Image'] == expected and c['Config']['Labels'].get('com.docker.compose.project') == 'hyperline',
              f'release image running: {role}')
        check(c['State']['Status'] == 'running' and c['State'].get('Health', {}).get('Status') == 'healthy',
              f'container health: {role}')
    for role, spec in manifest['deployed_baseline']['protected_services'].items():
        c = json.loads(docker(['inspect', spec['container']]))[0]
        check(c['Id'] == spec['container_id'] and c['State']['StartedAt'] == spec['started_at'],
              f'protected container untouched: {role}')
    for role, spec in manifest['deployed_baseline']['services'].items():
        c = json.loads(docker(['inspect', spec['container']]))[0]
        actual = {(m['Type'], m['Source'], m['Destination']) for m in c['Mounts']}
        expected = {(m['type'], m['source'], m['destination']) for m in spec['mounts']}
        check(actual == expected, f'persistent mounts preserved: {role}')
    for role in ['worker', 'scheduler']:
        docker(['exec', 'hyperline-' + role + '-1', 'php', 'docker/healthcheck.php', role])
        check(True, f'worker/scheduler heartbeat + PostgreSQL/Redis dependencies: {role}')

    # --- migration state ----------------------------------------------------
    status = docker(['exec', 'hyperline-api-1', 'php', 'artisan', 'migrate:status'])
    check(status.count('Ran') == 10 and 'Pending' not in status, 'migrate:status shows 10 ran / 0 pending')
    check(psql("SELECT to_regclass('public.series') IS NOT NULL AND to_regclass('public.series_episodes') IS NOT NULL") == 't',
          'series tables created')
    series_rows = psql('SELECT count(*) FROM series')
    episode_rows = psql('SELECT count(*) FROM series_episodes')
    print(f'INFO series rows={series_rows} episode rows={episode_rows} (import is a separately approved step; 0/0 expected pre-import)', flush=True)

    # --- data preservation ---------------------------------------------------
    for table, column in manifest['data_baseline'].items():
        check(psql(f'SELECT count(*) FROM {table}') == str(column), f'data preserved: {table}={column}')
    check(psql("SELECT count(*) FROM personal_access_tokens WHERE expires_at IS NULL") == '3',
          'legacy null-expiry token rows preserved')

    # --- public API contracts -------------------------------------------------
    status, body = request('/api/v1/health')
    check(status == 200 and json.loads(body).get('status') == 'ok', 'API health')
    status, _, body = request('/api/v1/series')
    ok = status == 200 and no_leak(body)
    if ok:
        payload = json.loads(body)
        ok = isinstance(payload.get('data'), list) and 'meta' in payload and 'links' in payload
    check(ok, 'GET /api/v1/series returns valid pagination with no stream leakage')
    status, _, body = request('/api/v1/series/nonexistent-series-slug')
    check(status == 404 and no_leak(body), 'unknown series slug returns clean 404')
    status, _, body = request('/api/v1/home')
    home = json.loads(body) if status == 200 else {}
    check(status == 200 and isinstance(home.get('data', {}).get('series'), list)
          and all(k in home.get('data', {}) for k in ['hero', 'featured', 'trending', 'latest', 'continue_watching']),
          '/home keeps movie rails and adds series rail')
    status, _, body = request('/api/v1/search?q=zzz-no-match')
    payload = json.loads(body) if status == 200 else {}
    check(status == 200 and all(k in payload.get('data', {}) for k in ['movies', 'series', 'genres']),
          '/search keeps movies/genres and adds series key')
    for accept in [None, 'application/json', 'text/html']:
        status, headers, body = request('/api/v1/me', headers={'Accept': accept} if accept else {})
        check(status == 401 and headers.get_content_type() == 'application/json'
              and json.loads(body).get('code') == 'unauthenticated', '401 JSON contract (Accept=' + str(accept) + ')')
    status, _, _ = request('/api/v1/playback/sessions', method='POST', headers={'Content-Type': 'application/json'}, body=b'{}')
    check(status == 401, 'unauthenticated playback POST returns 401')
    status, _, _ = request('/api/v1/auth/login', method='POST', headers={'Content-Type': 'application/json'}, body=b'{}')
    check(status == 422, 'login validation still 422')
    for origin in ['https://ott.zentunnel.net', 'https://cms.zentunnel.net']:
        status, headers, _ = request('/api/v1/playback/sessions', method='OPTIONS', headers={
            'Origin': origin, 'Access-Control-Request-Method': 'POST',
            'Access-Control-Request-Headers': 'Authorization,Content-Type'})
        check(status == 204 and headers.get('Access-Control-Allow-Origin') == origin
              and headers.get('Access-Control-Allow-Credentials') == 'true', 'credentialed preflight: ' + origin)
    _, headers, _ = request('/api/v1/me', method='OPTIONS', headers={
        'Origin': 'https://untrusted.example', 'Access-Control-Request-Method': 'GET'})
    check(not headers.get('Access-Control-Allow-Origin'), 'untrusted CORS origin rejected')

    # --- frontends via gateway + public HTTPS --------------------------------
    for host in ['ott.zentunnel.net', 'cms.zentunnel.net']:
        check(request('/', host=host)[0] == 200, f'host-routed page: {host}')
    check(request('/series', host='ott.zentunnel.net')[0] == 200, 'OTT /series page served')
    for url in ['https://api.zentunnel.net/api/v1/health', 'https://ott.zentunnel.net/', 'https://cms.zentunnel.net/']:
        try:
            with urllib.request.urlopen(url, timeout=15) as r:
                check(r.status == 200, 'public HTTPS (valid certificate): ' + url)
        except Exception:
            check(False, 'public HTTPS reachable: ' + url)

    # --- fail-closed playback configuration (regression guard) ----------------
    env = docker(['exec', 'hyperline-api-1', 'sh', '-c', 'env | grep -E "^(APP_ENV|APP_DEBUG|OTT_SIGNED_URLS|OTT_EXISTING_CDN_VERIFIED|OTT_NGINX_CDN_VERIFIED|OTT_PLAYBACK_SIGNER)=" | sort'])
    expected_env = ('APP_DEBUG=false\nAPP_ENV=production\nOTT_EXISTING_CDN_VERIFIED=false\n'
                    'OTT_NGINX_CDN_VERIFIED=false\nOTT_PLAYBACK_SIGNER=App\\Support\\Playback\\ExistingCdnPlaybackSigner\n'
                    'OTT_SIGNED_URLS=true')
    check(env == expected_env, 'fail-closed playback + production configuration intact')
    sanctum = docker(['exec', 'hyperline-api-1', 'php', '-r',
                      'require "/var/www/html/vendor/autoload.php";$a=require "/var/www/html/bootstrap/app.php";'
                      '$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
                      'echo config("sanctum.expiration"),":",json_encode(config("sanctum.guard"));'])
    check(sanctum == '1440:[]', 'auth-release regression: sanctum.expiration=1440, bearer-only guards')

    # --- VPN stopped state ----------------------------------------------------
    for unit in VPN_UNITS:
        active = sh(['systemctl', 'is-active', unit]).stdout.strip()
        check(active == 'inactive', f'VPN unit remains inactive: {unit}')
    wg = sh(['ip', '-o', 'link', 'show', 'dev', 'wg0'])
    check(wg.returncode != 0 or 'state UP' not in wg.stdout, 'wg0 remains down')

    # --- optional authenticated checks (operator-supplied, never generated) ----
    token = os.environ.get('HYPERLINE_CHECK_VIEWER_TOKEN')
    episode = os.environ.get('HYPERLINE_CHECK_EPISODE_ID')
    if token and episode:
        auth = {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'}
        status, _, body = request('/api/v1/playback/sessions', method='POST', headers=auth,
                                  body=json.dumps({'episode_id': int(episode)}).encode())
        check(status in (403, 404, 503) and no_leak(body), 'authenticated episode playback denied fail-closed without URL leakage')
        status, _, body = request('/api/v1/admin/dashboard', headers=auth)
        check(status == 403, 'ordinary viewer still denied admin access')
    else:
        print('NOT TESTED authenticated episode denial / RBAC: supply approved viewer token + episode ID (post-import)', flush=True)
    print('NOT TESTED browser CMS login and real-CDN playback: separately gated approvals', flush=True)

    failed = [label for ok, label in RESULTS if not ok]
    out = ROOT / 'deploy/releases' / args.release / 'postdeploy-series-result.json'
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
        print('FAIL postdeploy check aborted: ' + type(exc).__name__ + ' (details suppressed)')
        sys.exit(1)
