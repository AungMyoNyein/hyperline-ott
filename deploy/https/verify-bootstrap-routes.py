#!/usr/bin/env python3
"""Strict read-only Phase A HTTP checks, on fresh connections without redirects.
Worker handover must pass first. No retry, reload or status fallback is performed.
"""
import argparse
import http.client

HOSTS = ['ott.zentunnel.net', 'cms.zentunnel.net', 'api.zentunnel.net']
TOKEN_BODY = b'hyperline-acme-readiness\n'


def verify(connect_address=None, port=80, token='readiness-check', timeout=3):
    for host in HOSTS:
        def get(path):
            c = http.client.HTTPConnection(connect_address or host, port, timeout=timeout)
            try:
                c.request('GET', path, headers={'Host': host, 'Connection': 'close'})
                r = c.getresponse()
                return r.status, r.headers, r.read(65537)
            finally:
                c.close()

        for path in ['/', '/phase-a-route-check', '/api/v1/health']:
            code, headers, _ = get(path)
            if code != 503 or headers.get('Retry-After') != '300':
                raise RuntimeError(f'{host}{path}: expected 503 / Retry-After 300; got {code}')
        code, headers, body = get('/.well-known/acme-challenge/' + token)
        if code != 200 or body != TOKEN_BODY or headers.get_content_type() != 'text/plain':
            raise RuntimeError(f'{host}: token must be 200, exact bytes including newline, text/plain')
        code, _, _ = get('/.well-known/acme-challenge/phase-a-missing-token')
        if code != 404:
            raise RuntimeError(f'{host}: missing token expected 404; got {code}')
        print(f'PASS {host}: non-ACME 503/Retry-After 300; token exact 200; missing token 404', flush=True)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--connect-address', help='Override destination IP; Host stays the real application name')
    p.add_argument('--port', type=int, default=80)
    p.add_argument('--token', default='readiness-check')
    p.add_argument('--timeout', type=float, default=3)
    args = p.parse_args()
    if not 0 < args.timeout <= 10 or not 0 < args.port < 65536:
        p.error('Use timeout (0,10] seconds and a valid port')
    if not args.token or any(c not in 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_' for c in args.token):
        p.error('Token must be a single safe filename')
    verify(args.connect_address, args.port, args.token, args.timeout)


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, http.client.HTTPException) as error:
        raise SystemExit(f'FAIL bootstrap routes: {error}')
