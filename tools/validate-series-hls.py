#!/usr/bin/env python3
"""Bounded read-only HLS validation for the King of Medical Martial Arts series.

For each episode: HTTPS status, content type, M3U8 manifest validity, one level
of nested playlist inspection, browser CORS compatibility and auth/signing
detection. Downloads only small text manifests (capped), never segments, except
two explicitly authorized 1-byte range probes on the first and last PASSing
episode to prove segment reachability and byte-range support.

Output: JSON evidence + markdown table. No credentials, no writes to production.
"""
import argparse
import concurrent.futures
import datetime
import ipaddress
import json
import socket
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

CDN_HOST = 'cdn.goldendragon2000.com'
PATTERN = ('https://cdn.goldendragon2000.com/Vibe/Vertical_Series/Chinese/'
           'King_of_Medical_Martial_Arts/{episode}/playlist.m3u8')
ORIGIN = 'https://ott.zentunnel.net'
MAX_MANIFEST_BYTES = 128 * 1024
TIMEOUT = 20


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


OPENER = urllib.request.build_opener(
    NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))


def assert_public_host(host):
    """SSRF hygiene: the CDN name must resolve to public addresses only."""
    for info in socket.getaddrinfo(host, 443, proto=socket.IPPROTO_TCP):
        ip = ipaddress.ip_address(info[4][0])
        if ip.is_private or ip.is_loopback or ip.is_link_local or ip.is_reserved:
            raise RuntimeError('CDN host resolves to a non-public address')


def fetch(url, *, headers=None, byte_range=None):
    hdrs = {'User-Agent': 'hyperline-series-validator/1.0', **(headers or {})}
    if byte_range:
        hdrs['Range'] = byte_range
    request = urllib.request.Request(url, headers=hdrs)
    try:
        response = OPENER.open(request, timeout=TIMEOUT)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        body = response.read(MAX_MANIFEST_BYTES + 1)
        return {
            'status': response.code,
            'headers': dict(response.headers.items()),
            'body': body[:MAX_MANIFEST_BYTES],
            'truncated': len(body) > MAX_MANIFEST_BYTES,
        }


def parse_manifest(text):
    """Return a manifest summary or None when not a valid M3U8."""
    lines = [ln.strip() for ln in text.splitlines() if ln.strip()]
    if not lines or lines[0] != '#EXTM3U':
        return None
    info = {
        'valid_m3u8_header': True,
        'is_master': any(ln.startswith('#EXT-X-STREAM-INF') for ln in lines),
        'version': next((ln.split(':')[1] for ln in lines if ln.startswith('#EXT-X-VERSION')), None),
        'segment_count': sum(1 for ln in lines if ln.startswith('#EXTINF')),
        'encrypted': any(ln.startswith('#EXT-X-KEY') for ln in lines),
        'endlist': any(ln == '#EXT-X-ENDLIST' for ln in lines),
        'variant_uris': [],
        'segment_uris': [],
        'key_uris': [],
    }
    for index, ln in enumerate(lines):
        if ln.startswith('#EXT-X-STREAM-INF') and index + 1 < len(lines):
            info['variant_uris'].append(lines[index + 1])
        elif ln.startswith('#EXT-X-KEY'):
            uri = ln.split('URI="', 1)[1].split('"', 1)[0] if 'URI="' in ln else None
            if uri:
                info['key_uris'].append(uri)
        elif not ln.startswith('#') and info['segment_count']:
            info['segment_uris'].append(ln)
    return info


def resolve(base, uri):
    return urllib.parse.urljoin(base, uri)


def episode_token(episode, pad_below):
    """CDN directories are zero-padded two digits below `pad_below` (01..09)."""
    return f'{episode:02d}' if episode < pad_below else str(episode)


def check_episode(episode, pad_below=10):
    url = PATTERN.format(episode=episode_token(episode, pad_below))
    row = {'episode': episode, 'url': url}
    try:
        first = fetch(url, headers={'Origin': ORIGIN})
    except Exception as error:
        row.update(result='UNVERIFIED', reason=type(error).__name__)
        return row

    hdrs = first['headers']
    row['status'] = first['status']
    row['content_type'] = hdrs.get('Content-Type') or hdrs.get('content-type')
    row['redirect_present'] = bool(hdrs.get('Location') or hdrs.get('location'))
    row['auth_challenge_present'] = bool(hdrs.get('WWW-Authenticate') or hdrs.get('WWW-authenticate'))
    row['set_cookie_present'] = any(k.lower() == 'set-cookie' for k in hdrs)
    row['cors_allow_origin'] = hdrs.get('Access-Control-Allow-Origin') or hdrs.get('access-control-allow-origin')
    row['cors_allow_credentials'] = hdrs.get('Access-Control-Allow-Credentials') or hdrs.get('access-control-allow-credentials')
    row['accept_ranges'] = hdrs.get('Accept-Ranges') or hdrs.get('accept-ranges')
    row['anonymous_access'] = first['status'] == 200 and not row['auth_challenge_present']

    if first['status'] != 200:
        row.update(result='FAIL', reason=f'http_{first["status"]}')
        return row

    try:
        text = first['body'].decode('utf-8', errors='replace')
    except Exception:
        row.update(result='FAIL', reason='undecodable_body')
        return row

    manifest = parse_manifest(text)
    if manifest is None:
        row.update(result='FAIL', reason='not_m3u8')
        return row

    row['manifest'] = {k: v for k, v in manifest.items()
                       if k not in ('variant_uris', 'segment_uris', 'key_uris')}
    row['manifest']['variant_count'] = len(manifest['variant_uris'])
    row['manifest']['first_segments'] = manifest['segment_uris'][:3]
    row['manifest']['key_uris'] = manifest['key_uris'][:3]

    if manifest['is_master'] and manifest['variant_uris']:
        variant_url = resolve(url, manifest['variant_uris'][0])
        try:
            nested = fetch(variant_url, headers={'Origin': ORIGIN})
            nested_manifest = parse_manifest(nested['body'].decode('utf-8', errors='replace')) \
                if nested['status'] == 200 else None
            row['nested'] = {
                'status': nested['status'],
                'content_type': nested['headers'].get('Content-Type') or nested['headers'].get('content-type'),
                'valid_media_playlist': bool(nested_manifest and not nested_manifest['is_master']),
                'segment_count': nested_manifest['segment_count'] if nested_manifest else None,
                'encrypted': nested_manifest['encrypted'] if nested_manifest else None,
                'endlist': nested_manifest['endlist'] if nested_manifest else None,
                'cors_allow_origin': nested['headers'].get('Access-Control-Allow-Origin')
                                     or nested['headers'].get('access-control-allow-origin'),
                'first_segments': (nested_manifest['segment_uris'][:2] if nested_manifest else []),
            }
        except Exception as error:
            row['nested'] = {'error': type(error).__name__}

    problems = []
    if not row['anonymous_access']:
        problems.append('auth_required')
    if row['cors_allow_origin'] not in ('*', ORIGIN):
        problems.append('cors_incompatible')
    if manifest['is_master'] and not row.get('nested', {}).get('valid_media_playlist'):
        problems.append('nested_playlist_invalid')
    if not manifest['is_master'] and manifest['segment_count'] == 0:
        problems.append('no_segments')

    row['result'] = 'PASS' if not problems else 'FAIL'
    if problems:
        row['reason'] = ','.join(problems)
    return row


def probe_segment(url):
    """One-byte range GET proving segment reachability; no bulk transfer."""
    try:
        result = fetch(url, headers={'Origin': ORIGIN}, byte_range='bytes=0-0')
        return {'status': result['status'],
                'content_type': result['headers'].get('Content-Type') or result['headers'].get('content-type'),
                'content_range': result['headers'].get('Content-Range') or result['headers'].get('content-range')}
    except Exception as error:
        return {'error': type(error).__name__}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--report-dir', required=True)
    parser.add_argument('--episodes', default='1-54')
    parser.add_argument('--workers', type=int, default=6)
    parser.add_argument('--pad-below', type=int, default=10,
                        help='zero-pad episode directory names below this number')
    args = parser.parse_args()

    start, end = (int(part) for part in args.episodes.split('-'))
    episodes = list(range(start, end + 1))
    assert_public_host(CDN_HOST)

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        rows = list(pool.map(lambda ep: check_episode(ep, args.pad_below), episodes))

    passing = [r for r in rows if r['result'] == 'PASS']
    segment_probes = {}
    for label, row in [('first_pass', passing[0] if passing else None),
                       ('last_pass', passing[-1] if len(passing) > 1 else None)]:
        if not row:
            continue
        segments = (row.get('nested') or {}).get('first_segments') or row.get('manifest', {}).get('first_segments')
        if segments:
            segment_probes[f"episode_{row['episode']}"] = probe_segment(resolve(row['url'], segments[0]))

    summary = {
        'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'series': 'King of Medical Martial Arts',
        'host': CDN_HOST,
        'episodes_checked': len(rows),
        'pass': sum(1 for r in rows if r['result'] == 'PASS'),
        'fail': sum(1 for r in rows if r['result'] == 'FAIL'),
        'unverified': sum(1 for r in rows if r['result'] == 'UNVERIFIED'),
        'scope': 'Bounded read-only: small text manifests only, one nested level, '
                 'two authorized 1-byte segment range probes, no redirects, no bulk transfer.',
        'segment_probes': segment_probes,
    }

    report_dir = Path(args.report_dir)
    report_dir.mkdir(parents=True, exist_ok=True)
    (report_dir / 'hls-validation.json').write_text(
        json.dumps({'summary': summary, 'episodes': rows}, indent=2) + '\n')

    lines = ['| Ep | Result | Status | Content-Type | Manifest | CORS | Reason |',
             '| --- | --- | --- | --- | --- | --- | --- |']
    for r in rows:
        m = r.get('manifest') or {}
        kind = ('master' if m.get('is_master') else 'media') if m else '-'
        lines.append(f"| {r['episode']} | {r['result']} | {r.get('status', '-')} | "
                     f"{r.get('content_type', '-')} | {kind} "
                     f"({m.get('segment_count', '-') if not m.get('is_master') else m.get('variant_count', '-')} "
                     f"{'segs' if not m.get('is_master') else 'variants'}) | "
                     f"{r.get('cors_allow_origin', '-')} | {r.get('reason', '')} |")
    (report_dir / 'hls-validation.md').write_text('\n'.join(lines) + '\n')

    print(json.dumps(summary, indent=2))
    return 0 if summary['unverified'] == 0 else 2


if __name__ == '__main__':
    sys.exit(main())
