#!/usr/bin/env python3
"""Prepared CMS lifecycle. Default is plan-only. Requires separate execution approval.
Passwords/tokens stay in memory; never log response bodies or credential values.
"""
import argparse
import datetime
import getpass
import hashlib
import json
from pathlib import Path
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def is_owned_record(movie, own_id, slug, title, actor, baseline):
    """Never adopt a pre-existing ID, a foreign owner or a merely similar marker."""
    return (movie.get('id') == own_id and movie.get('slug') == slug
            and movie.get('title', '').startswith(title)
            and movie.get('created_by', {}).get('id') == actor
            and own_id not in baseline)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--execute', action='store_true')
    p.add_argument('--confirm', choices=['DISPOSABLE-CMS-LIFECYCLE'])
    p.add_argument('--approved-admin-email')
    p.add_argument('--hls-source', help='Approved credential-free HTTPS .m3u8 metadata; never fetched')
    p.add_argument('--genre', help='Existing genre slug only; no genre endpoint writes')
    p.add_argument('--password-stdin', action='store_true')
    p.add_argument('--api-url', default='https://api.zentunnel.net/api/v1')
    p.add_argument('--local-fixture', action='store_true')
    p.add_argument('--report', type=Path, default=Path('/tmp/hyperline-cms-acceptance-result.json'))
    args = p.parse_args()
    if not args.execute:
        print('PLAN ONLY: approved admin login; snapshot existing movies; create one marked draft; attach disabled HLS metadata; assign existing genre if available; edit; publish/public-check; unpublish; delete only acknowledged owned record; verify originals; logout/revocation. No network request made.')
        return
    if not args.confirm or not args.approved_admin_email or not args.hls_source:
        p.error('Execution requires confirmation, an explicitly approved test administrator and approved HLS source')
    base = args.api_url.rstrip('/')
    if args.local_fixture:
        if base != 'http://127.0.0.1:18081/api/v1': p.error('Local fixture must use dedicated API loopback port 18081')
    elif base != 'https://api.zentunnel.net/api/v1':
        p.error('Production requests must use the exact reviewed trusted HTTPS API')
    hls = urllib.parse.urlsplit(args.hls_source)
    if hls.scheme != 'https' or not hls.hostname or hls.username or hls.password or hls.query or hls.fragment or hls.port not in [None, 443] or not hls.path.endswith('.m3u8'):
        p.error('Use approved credential-free HTTPS HLS metadata without a query/fragment')
    if args.password_stdin:
        if sys.stdin.isatty(): p.error('Read the password securely in the operator shell and pipe it; do not echo it')
        password = sys.stdin.readline().rstrip('\r\n')
    else:
        password = getpass.getpass('Approved dedicated test administrator password: ')
    if not password: p.error('A credential is required; no bootstrap fallback is used')
    run_id = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-'+uuid.uuid4().hex[:12]
    slug = 'hyperline-acceptance-'+run_id.lower()
    title = '[ACCEPTANCE ONLY] '+run_id
    token = None; own_id = None; actor = None; baseline = None; creation_attempted = False
    result = {'run_id': run_id, 'status': 'RUNNING', 'checks': [], 'owned_movie_id': None, 'cleanup': 'not needed'}
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    def save():
        args.report.write_text(json.dumps(result, indent=2)+'\n')
        args.report.chmod(0o600)
    def check(ok, label):
        result['checks'].append({'check': label, 'pass': bool(ok)}); save()
        print(('PASS ' if ok else 'FAIL ')+label, flush=True)
        if not ok: raise RuntimeError(label)
    def request(path, method='GET', data=None, auth=True):
        headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
        if auth and token: headers['Authorization'] = 'Bearer '+token
        req = urllib.request.Request(base+path, data=json.dumps(data).encode() if data is not None else None, headers=headers, method=method)
        try: response = opener.open(req, timeout=15)
        except urllib.error.HTTPError as error: response = error
        with response:
            raw = response.read(); body = json.loads(raw) if raw else {}
            return response.status, body
    def movie_snapshot():
        found = {}; page = 1
        while True:
            code, data = request('/admin/movies?per_page=100&page='+str(page))
            check(code == 200, 'Read existing movie inventory page '+str(page))
            for movie in data['data']:
                found[movie['id']] = hashlib.sha256(json.dumps(movie, sort_keys=True).encode()).hexdigest()
            if page >= data['meta']['last_page']: return found
            page += 1
    def owned():
        code, data = request('/admin/movies/'+str(own_id)); movie = data.get('data', {})
        check(code == 200 and is_owned_record(movie, own_id, slug, title, actor, baseline), 'Mutation/cleanup target is this run acknowledged owned record')
        return movie
    try:
        code, data = request('/admin/login', 'POST', {'email': args.approved_admin_email, 'password': password, 'device_name': 'acceptance-'+run_id}, False)
        password = None
        check(code == 200 and bool(data.get('data', {}).get('token')), 'Approved administrator login')
        token = data['data']['token']; data = None
        code, data = request('/me'); me = data.get('data', {}); actor = me.get('id')
        check(code == 200 and me.get('email', '').lower() == args.approved_admin_email.lower() and 'admin' in me.get('roles', []), 'Authenticated identity is the explicitly approved administrator')
        baseline = movie_snapshot()
        code, data = request('/genres', auth=False); check(code == 200, 'Read supported genre collection')
        genres = {g['slug'] for g in data['data']}
        if args.genre: check(args.genre in genres, 'Requested genre already exists')
        genre = args.genre or next(iter(sorted(genres)), None)
        result['genre_assignment'] = 'TESTED existing genre' if genre else 'NOT TESTED: no genres; no unsupported creation attempted'
        source = {'label': 'acceptance-hls', 'kind': 'hls', 'url': args.hls_source, 'enabled': False, 'is_default': True}
        payload = {'title': title, 'slug': slug, 'synopsis': 'Disposable acceptance record; automatic cleanup required.', 'published': False, 'featured': False, 'genres': [genre] if genre else [], 'sources': [source]}
        creation_attempted = True
        code, data = request('/admin/movies', 'POST', payload)
        check(code == 201, 'Create one uniquely marked disposable draft')
        own_id = data['data']['id']; result['owned_movie_id'] = own_id; result['owned_slug'] = slug; save(); owned()
        movie = owned(); check(movie['sources'][0]['kind'] == 'hls' and movie['sources'][0]['url'] == args.hls_source and movie['sources'][0]['enabled'] is False, 'Approved HLS metadata attached disabled; no media fetched')
        if genre: check(genre in {g['slug'] for g in movie['genres']}, 'Existing genre assigned')
        owned(); code, data = request('/admin/movies/'+str(own_id), 'PATCH', {'synopsis': 'Edited disposable acceptance metadata.', 'sources': [{**source, 'quality': 'acceptance-test'}]})
        check(code == 200 and data['data']['synopsis'] == 'Edited disposable acceptance metadata.' and data['data']['sources'][0]['quality'] == 'acceptance-test', 'Edit only owned movie and HLS metadata')
        check(request('/movies/'+slug, auth=False)[0] == 404, 'Draft hidden from anonymous details')
        owned(); check(request('/admin/movies/'+str(own_id), 'PATCH', {'published': True})[0] == 200, 'Publish owned marked test movie')
        # Catalog searches titles/synopses, not slugs: use its unique title.
        code, data = request('/movies?q='+urllib.parse.quote(title), auth=False)
        check(code == 200 and any(m['id'] == own_id for m in data['data']), 'Published owned record visible in public catalog')
        code, data = request('/movies/'+slug, auth=False)
        check(code == 200 and data['data']['id'] == own_id and data['data']['playable'] is False and args.hls_source not in json.dumps(data) and all('url' not in source for source in data['data'].get('sources', [])), 'Public movie details work without source disclosure')
        if genre:
            code, data = request('/movies?genre='+urllib.parse.quote(genre)+'&q='+urllib.parse.quote(title), auth=False)
            check(code == 200 and any(m['id'] == own_id for m in data['data']), 'Published owned record matches existing genre filter')
        owned(); check(request('/admin/movies/'+str(own_id), 'PATCH', {'published': False})[0] == 200, 'Unpublish only owned record')
        check(request('/movies/'+slug, auth=False)[0] == 404, 'Unpublished owned detail hidden again')
        owned(); check(request('/admin/movies/'+str(own_id), 'DELETE')[0] in [200, 204], 'Delete only acknowledged owned record')
        deleted_id = own_id; own_id = None; result['cleanup'] = 'owned record deleted'
        check(request('/admin/movies/'+str(deleted_id))[0] == 404 and request('/movies/'+slug, auth=False)[0] == 404, 'Deleted owned record is absent from admin and public details')
        check(movie_snapshot() == baseline, 'All pre-existing movie IDs and metadata unchanged')
        check(request('/admin/logout', 'POST')[0] == 200, 'Administrator logout')
        check(request('/admin/dashboard')[0] == 401, 'Logged-out token rejected')
        token = None; result['status'] = 'PASS' if genre else 'PASS with genre assignment NOT TESTED'
    except Exception as error:
        result['status'] = 'FAIL'; result['failure'] = type(error).__name__+' (sensitive details suppressed)'
        if creation_attempted and own_id is None and result['cleanup'] == 'not needed': result['cleanup'] = 'Creation response uncertain: operator must inspect unique run marker; no unacknowledged record auto-deleted'
    finally:
        if own_id is not None:
            try:
                owned(); request('/admin/movies/'+str(own_id), 'PATCH', {'published': False})
                owned(); code, _ = request('/admin/movies/'+str(own_id), 'DELETE')
                if code not in [200, 204]: raise RuntimeError('Cleanup failed')
                result['cleanup'] = 'owned record unpublished/deleted after failure'
            except Exception: result['cleanup'] = 'FAILED: stop and inspect reported owned record only'
        if token:
            try: request('/admin/logout', 'POST')
            except Exception: result['logout_cleanup'] = 'NOT VERIFIED; revoke only this test session with approval'
        result['finished_at'] = datetime.datetime.now(datetime.timezone.utc).isoformat(); save()
    if result['status'] == 'FAIL': raise SystemExit(1)

if __name__ == '__main__': main()
