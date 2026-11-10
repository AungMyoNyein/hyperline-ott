#!/usr/bin/env python3
"""Public TLS/read-only HTTP contracts; no credentials or playback requests."""
import json
import socket
import ssl
import urllib.error
import urllib.request

HOSTS = ['ott.zentunnel.net', 'cms.zentunnel.net', 'api.zentunnel.net']

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

def require(ok, label):
    print(('PASS ' if ok else 'FAIL ') + label, flush=True)
    if not ok:
        raise SystemExit(1)

def request(url, method='GET', headers=None):
    req = urllib.request.Request(url, method=method, headers=headers or {})
    try:
        response = opener.open(req, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()

def main():
    context = ssl.create_default_context()
    for host in HOSTS:
        with socket.create_connection((host, 443), timeout=15) as raw:
            with context.wrap_socket(raw, server_hostname=host) as tls:
                cert = tls.getpeercert()
                require({v for k, v in cert.get('subjectAltName', []) if k == 'DNS'} == set(HOSTS),
                        'Trusted, hostname-validated TLS with exactly the HyperLine SANs: ' + host)
        status, headers, _ = request('http://' + host + '/test/path?preflight=1')
        require(status == 308 and headers.get('Location') == 'https://' + host + '/test/path?preflight=1',
                'HTTP 308 preserves path/query: ' + host)
        status, _, body = request('http://' + host + '/.well-known/acme-challenge/readiness-check')
        require(status == 200 and body == b'hyperline-acme-readiness\n', 'ACME exemption and token: ' + host)
        status, _, _ = request('http://' + host + '/.well-known/acme-challenge/hyperline-nonexistent-verification-token')
        require(status == 404, 'Missing ACME token 404: ' + host)
        if host != 'api.zentunnel.net':
            status, _, _ = request('https://' + host + '/')
            require(status == 200, 'HTTPS page: ' + host)
    base = 'https://api.zentunnel.net/api/v1'
    status, _, body = request(base + '/health')
    require(status == 200 and json.loads(body).get('status') == 'ok', 'Public API health')
    for accept in [None, 'application/json', 'text/html']:
        status, headers, body = request(base + '/me', headers={'Accept': accept} if accept else {})
        require(status == 401 and headers.get_content_type() == 'application/json' and
                json.loads(body).get('code') == 'unauthenticated', 'JSON unauthenticated response: ' + str(accept))
    for host in HOSTS[:2]:
        origin = 'https://' + host
        status, headers, _ = request(base + '/playback/sessions', 'OPTIONS', {
            'Origin': origin, 'Access-Control-Request-Method': 'POST',
            'Access-Control-Request-Headers': 'Authorization,Content-Type'})
        require(status == 204 and headers.get('Access-Control-Allow-Origin') == origin and
                headers.get('Access-Control-Allow-Credentials') == 'true' and
                all(h in headers.get('Access-Control-Allow-Headers', '').lower() for h in ['authorization', 'content-type']),
                'Public credentialed CORS preflight: ' + host)
        status, headers, _ = request(base + '/me', headers={'Origin': origin})
        require(status == 401 and headers.get('Access-Control-Allow-Origin') == origin and
                headers.get('Access-Control-Allow-Credentials') == 'true', 'Public actual CORS response: ' + host)
    _, headers, _ = request(base + '/me', 'OPTIONS', {
        'Origin': 'https://untrusted.example', 'Access-Control-Request-Method': 'GET'})
    require(not headers.get('Access-Control-Allow-Origin'), 'Untrusted CORS rejected')
    print('NOT TESTED authenticated browser sessions; run browser-smoke.cjs with approved existing accounts')

if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('FAIL public verification incomplete; network/response details suppressed')
        raise SystemExit(1)
