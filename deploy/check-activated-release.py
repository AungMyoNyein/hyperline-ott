#!/usr/bin/env python3
"""Run ONLY after approved rollout. Read-only checks, except optional playback
issuance checks using explicitly supplied existing-account/movie identifiers.
Never prints response bodies, tokens, credentials or exception messages.
"""
import json
import os
from pathlib import Path
import subprocess
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
RELEASE = (ROOT / 'deploy/releases/LATEST_RUNTIME_RELEASE').read_text().strip()
BASE = 'http://127.0.0.1:18080'


def require(condition, label):
    print(('PASS ' if condition else 'FAIL ') + label, flush=True)
    if not condition:
        raise SystemExit(1)


def docker(args):
    p = subprocess.run(['docker', *args], cwd=ROOT, capture_output=True, text=True)
    require(p.returncode == 0, 'Docker check completed (output suppressed)')
    return p.stdout


def request(path, *, host='api.zentunnel.net', method='GET', headers=None, body=None):
    req = urllib.request.Request(BASE + path, data=body, method=method,
                                 headers={'Host': host, **(headers or {})})
    try:
        response = urllib.request.urlopen(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def main():
    manifest = json.loads((ROOT / 'deploy/releases' / RELEASE / 'manifest.json').read_text())
    for role in ['api', 'web', 'cms', 'worker', 'scheduler']:
        c = json.loads(docker(['inspect', 'hyperline-' + role + '-1']))[0]
        expected = manifest['built_images'][role if role in ['web', 'cms'] else 'api']['image_id']
        require(c['Config']['Labels'].get('com.docker.compose.project') == 'hyperline' and c['Image'] == expected,
                'Approved image is running: ' + role)
        require(c['State']['Status'] == 'running' and c['State'].get('Health', {}).get('Status') == 'healthy',
                'Container health: ' + role)
    for role, baseline in manifest['protected_services'].items():
        c = json.loads(docker(['inspect', baseline['container']]))[0]
        require(c['Id'] == baseline['container_id'] and c['State']['StartedAt'] == baseline['started_at'],
                'Protected container remains unchanged: ' + role)
        if role == 'gateway':
            require(c['HostConfig']['PortBindings'] == {'80/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '18080'}]},
                    'Gateway remains loopback-only')
    for role, baseline in {**manifest['services'], **manifest['protected_services']}.items():
        c = json.loads(docker(['inspect', baseline['container']]))[0]
        actual = {(m['Type'], m['Source'], m['Destination']) for m in c['Mounts']}
        expected = {(m['type'], m['source'], m['destination']) for m in baseline['mounts']}
        require(actual == expected, 'Persistent mounts preserved: ' + role)
    for role in ['worker', 'scheduler']:
        docker(['exec', 'hyperline-' + role + '-1', 'php', 'docker/healthcheck.php', role])
        print('PASS heartbeat/process/DB/Redis dependencies: ' + role)
    for host in ['ott.zentunnel.net', 'cms.zentunnel.net']:
        require(request('/', host=host)[0] == 200, 'Host-routed page: ' + host)
    status, _, body = request('/api/v1/health')
    require(status == 200 and json.loads(body).get('status') == 'ok', 'API health')
    for accept in [None, 'application/json', 'text/html']:
        status, headers, body = request('/api/v1/me', headers={'Accept': accept} if accept else {})
        require(status == 401 and headers.get_content_type() == 'application/json' and
                json.loads(body).get('code') == 'unauthenticated', '401 JSON with Accept=' + str(accept))
    status, _, _ = request('/api/v1/auth/login', method='POST',
                           headers={'Content-Type': 'application/json'}, body=b'{}')
    require(status == 422, 'Validation returns 422')
    for origin in ['https://ott.zentunnel.net', 'https://cms.zentunnel.net']:
        status, headers, _ = request('/api/v1/playback/sessions', method='OPTIONS', headers={
            'Origin': origin, 'Access-Control-Request-Method': 'POST',
            'Access-Control-Request-Headers': 'Authorization,Content-Type'})
        allowed = headers.get('Access-Control-Allow-Headers', '').lower()
        require(status == 204 and headers.get('Access-Control-Allow-Origin') == origin and
                headers.get('Access-Control-Allow-Credentials') == 'true' and
                'authorization' in allowed and 'content-type' in allowed, 'Credentialed preflight: ' + origin)
        status, headers, _ = request('/api/v1/me', headers={'Origin': origin})
        require(status == 401 and headers.get('Access-Control-Allow-Origin') == origin and
                headers.get('Access-Control-Allow-Credentials') == 'true', 'Credentialed actual response: ' + origin)
    _, headers, _ = request('/api/v1/me', method='OPTIONS', headers={
        'Origin': 'https://untrusted.example', 'Access-Control-Request-Method': 'GET'})
    require(not headers.get('Access-Control-Allow-Origin'), 'Untrusted CORS origin rejected')
    token = os.environ.get('HYPERLINE_CHECK_VIEWER_TOKEN')
    movie = os.environ.get('HYPERLINE_CHECK_MOVIE_ID')
    session = os.environ.get('HYPERLINE_CHECK_SESSION_ID')
    if token:
        auth = {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'}
        require(request('/api/v1/admin/dashboard', headers=auth)[0] == 403, 'Existing ordinary viewer cannot access admin')
        if movie:
            status, _, body = request('/api/v1/playback/sessions', method='POST', headers=auth,
                                       body=json.dumps({'movie_id': int(movie)}).encode())
            data = json.loads(body)
            require(status == 503 and data.get('code') == 'service_unavailable' and
                    'goldendragon' not in body.decode().lower() and not data.get('data', {}).get('url'),
                    'Unverified CDN playback fails closed; no source URL disclosed')
        else:
            print('NOT TESTED authenticated playback: supply approved existing published playable movie ID')
        if session:
            status, _, body = request('/api/v1/playback/sessions/' + str(uuid.UUID(session)) + '/renew',
                                       method='POST', headers=auth, body=b'{}')
            require(status in [403, 404, 503] and 'goldendragon' not in body.decode().lower(),
                    'Renewal is denied/unavailable without source disclosure')
        else:
            print('NOT TESTED existing-session renewal: supply approved existing session ID')
    else:
        print('NOT TESTED authenticated admin/playback/renewal: no approved ordinary-viewer token supplied')
    print('NOT TESTED browser CMS login/logout, real HTTPS and VPN traffic: operator checks required')


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('FAIL post-activation check incomplete; sensitive details suppressed')
        raise SystemExit(1)
