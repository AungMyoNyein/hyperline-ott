#!/usr/bin/env python3
"""Isolated real Nginx secure_link/cache and HTTPS sibling-domain browser checks.
Never reads production secrets, configs or databases; never signals system Nginx.
"""
import atexit
import base64
import hashlib
import http.client
import http.server
import json
import os
from pathlib import Path
import secrets
import socket
import ssl
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
os.chdir(ROOT)
for port in [18081,18082,18084,18443]:
    with socket.socket() as s:
        s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1)
        try: s.bind(('127.0.0.1',port))
        except OSError: raise SystemExit(f'Temporary test port {port} is busy')
work = Path(tempfile.mkdtemp(prefix='hyperline-cdn-'))
atexit.register(lambda: [(work/name).unlink(missing_ok=True) for name in ['secret.conf','key.pem']])
media = work/'media'
media.mkdir()
secret = secrets.token_hex(32)
env = {**os.environ, 'APP_ENV':'testing','APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),
       'APP_URL':'https://api.hyperline.test:18443','DB_CONNECTION':'sqlite','DB_DATABASE':'/tmp/hyperline-cdn-browser.sqlite',
       'CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','TRUSTED_PROXIES':'127.0.0.1',
       'OTT_SIGNED_URLS':'true','OTT_PLAYBACK_SIGNER':r'App\Support\Playback\NginxSecureLinkSigner',
       'OTT_NGINX_CDN_VERIFIED':'true','OTT_NGINX_CDN_URL':'https://cdn.hyperline.test:18443',
       'OTT_NGINX_COOKIE_DOMAIN':'hyperline.test','OTT_NGINX_SIGNING_SECRET':secret,
       'OTT_NGINX_ASSETS_FILE':str(work/'assets.json'),'OTT_NGINX_COOKIE_TTL_SECONDS':'30',
       'CORS_ALLOWED_ORIGINS':'https://ott.hyperline.test:18443','CORS_SUPPORTS_CREDENTIALS':'true',
       'SANCTUM_STATEFUL_DOMAINS':'api.hyperline.test:18443',
       'NEXT_PUBLIC_API_URL':'https://api.hyperline.test:18443/api/v1',
       'PLAYWRIGHT_BROWSERS_PATH':'/tmp/hyperline-playwright','HYPERLINE_CDN_E2E':'1'}
Path(env['DB_DATABASE']).touch(exist_ok=True)
def run(args, cwd=ROOT, **kw):
    return subprocess.run(args,cwd=cwd,env=env,check=True,**kw)
run(['php','artisan','migrate','--force'],ROOT/'services/api')
run(['php','tests/cdn/seed.php'])
for variant,size in [('low','320x180'),('high','640x360')]:
    (media/variant).mkdir()
    run(['ffmpeg','-hide_banner','-loglevel','error','-f','lavfi','-i',f'testsrc2=size={size}:rate=24','-t','8','-c:v','libx264','-preset','ultrafast','-pix_fmt','yuv420p','-g','48','-sc_threshold','0','-f','hls','-hls_time','2','-hls_playlist_type','vod','-hls_segment_type','fmp4',str(media/variant/'index.m3u8')])
(media/'master.m3u8').write_text('#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=250000,RESOLUTION=320x180\nlow/index.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=900000,RESOLUTION=640x360\nhigh/index.m3u8\n')
run(['python3','deploy/cdn/audit-hls-tree.py',str(media),'master.m3u8'])
(media/'keys').mkdir()
(media/'keys'/'content.key').write_bytes(secrets.token_bytes(16))
run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(work/'key.pem'),'-out',str(work/'cert.pem'),'-days','1','-subj','/CN=hyperline.test'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
(work/'secret.conf').write_text(f'set $hl_signing_secret "{secret}";\n')
(work/'secret.conf').chmod(0o600)
(work/'gate.conf').write_text('set $hl_cdn_enabled 0;\n')
(work/'origin-access.conf').write_text('# Generated local test content needs no upstream credential.\n')
with (work/'assets.map').open('w') as output:
    run(['python3','deploy/cdn/render-assets.py',str(work/'assets.json')],stdout=output)
registry = json.loads((work/'assets.json').read_text())
source, asset = next(iter(registry.items()))
scope = f'/hls/{asset["movie_id"]}/{source}/'
other_source, other_asset = list(registry.items())[1]
other_scope = f'/hls/{other_asset["movie_id"]}/{other_source}/'
counts = {}
upstream_headers = []
class Origin(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        counts[self.path] = counts.get(self.path,0)+1
        upstream_headers.append(dict(self.headers))
        parts = self.path.split('/',3)
        path = media/(parts[3] if len(parts)==4 else '')
        if len(parts)!=4 or parts[1]!='vod' or parts[2] not in ['approved1','approved2'] or not path.is_file():
            self.send_error(404);return
        data=path.read_bytes()
        self.send_response(200)
        self.send_header('Content-Length',str(len(data)))
        self.send_header('Content-Type','application/vnd.apple.mpegurl' if path.suffix=='.m3u8' else 'application/octet-stream')
        self.send_header('Cache-Control','public, max-age=600')
        self.end_headers();self.wfile.write(data)
    def log_message(self,*args): pass
origin=http.server.ThreadingHTTPServer(('127.0.0.1',18084),Origin)
threading.Thread(target=origin.serve_forever,daemon=True).start()
template=(ROOT/'deploy/cdn/cdn.zentunnel.net.conf.template').read_text()
template=template.replace('/etc/nginx/hyperline-cdn/',str(work)+'/').replace('/var/cache/nginx/hyperline-cdn',str(work/'cache')).replace('/var/log/nginx/',str(work)+'/')
template=template.replace('/etc/letsencrypt/live/cdn.zentunnel.net/fullchain.pem',str(work/'cert.pem')).replace('/etc/letsencrypt/live/cdn.zentunnel.net/privkey.pem',str(work/'key.pem'))
template=template.replace('listen 443 ssl;', 'listen 127.0.0.1:18443 ssl;').replace('server_name cdn.zentunnel.net;', 'server_name cdn.hyperline.test;').replace('https://ott.zentunnel.net','https://ott.hyperline.test:18443')
# Nginx template is tested against OUR HTTP fixture origin, never the actual CDN.
template=template.replace('server cdn.goldendragon2000.com:443;', 'server 127.0.0.1:18084;').replace('proxy_pass https://hl_origin','proxy_pass http://hl_origin')
template=template.replace('add_header Vary Origin always;', 'add_header Vary Origin always;\n    add_header X-Test-Cache $upstream_cache_status always;')
proxies=''
for host,port in [('api',18081),('ott',18082)]:
    proxies+=f'''server {{ listen 127.0.0.1:18443 ssl; server_name {host}.hyperline.test;
    ssl_certificate {work}/cert.pem; ssl_certificate_key {work}/key.pem;
    location / {{ proxy_pass http://127.0.0.1:{port}; proxy_set_header Host $host; proxy_set_header X-Forwarded-Proto https; }} }}\n'''
(work/'nginx.conf').write_text(f'pid {work}/nginx.pid;\nerror_log {work}/error.log warn;\nmaster_process off;\nevents {{}}\nhttp {{ client_body_temp_path {work}/body; proxy_temp_path {work}/proxy;\n'+template+proxies+'}\n')
run(['nginx','-t','-p',str(work)+'/', '-c',str(work/'nginx.conf')])
processes=[]
def start(args,cwd=ROOT,name='process'):
    output=(work/(name+'.log')).open('w')
    p=subprocess.Popen(args,cwd=cwd,env=env,stdout=output,stderr=subprocess.STDOUT)
    processes.append((p,output))
    return p
def request(path,cookie=None,headers=None,method='GET'):
    conn=http.client.HTTPSConnection('127.0.0.1',18443,context=ssl._create_unverified_context(),timeout=5)
    hdr={'Host':'cdn.hyperline.test',**(headers or {})}
    if cookie: hdr['Cookie']='__Secure-hl_playback='+cookie
    conn.request(method,path,headers=hdr)
    response=conn.getresponse();result=(response.status,dict(response.getheaders()),response.read());conn.close();return result
session='12345678-1234-4234-8234-123456789abc'
def token(exp, directory=scope, sid=session):
    value=f'v1|{exp}|{directory}|{sid}|{secret}'.encode('ascii')
    signature=base64.urlsafe_b64encode(hashlib.md5(value).digest()).decode().rstrip('=')
    return f'v1.{exp}.{sid}.{signature}'
try:
    nginx_process=start(['nginx','-p',str(work)+'/', '-c',str(work/'nginx.conf'),'-g','daemon off;'],name='nginx')
    for i in range(50):
        try: request('/');break
        except OSError: time.sleep(.1)
    assert request(scope+'master.m3u8')[0]==503
    nginx_process.terminate();nginx_process.wait(timeout=5)
    (work/'gate.conf').write_text('set $hl_cdn_enabled 1;\n')
    start(['nginx','-p',str(work)+'/', '-c',str(work/'nginx.conf'),'-g','daemon off;'],name='nginx-enabled')
    for i in range(50):
        try: request('/');break
        except OSError: time.sleep(.1)
    print('PASS Nginx activation gate defaults closed')
    expires=int(time.time())+120
    valid=token(expires)
    for name, cookie, path, expected in [
        ('missing',None,scope+'master.m3u8',403),('valid',valid,scope+'master.m3u8',200),
        ('invalid',valid[:-22]+('A' if valid[-22]!='A' else 'B')+valid[-21:],scope+'master.m3u8',403),
        ('noncanonical signature',valid[:-1]+'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_'['ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_'.index(valid[-1])+1],scope+'master.m3u8',403),
        ('expired',token(int(time.time())-5),scope+'master.m3u8',410),
        ('cross-movie',valid,other_scope+'master.m3u8',403),
        ('traversal',valid,scope+'../master.m3u8',400),('encoded',valid,scope+'low/%69nit.mp4',400),
        ('query',valid,scope+'master.m3u8?url=https://example.org',400),
        ('unknown mapping',valid,'/hls/99999/99999/master.m3u8',404)]:
        actual=request(path,cookie)[0]
        assert actual==expected,(name,actual,expected)
        print('PASS Nginx',name)
    for resource in ['low/index.m3u8','low/index0.m4s','low/init.mp4','keys/content.key']:
        assert request(scope+resource)[0]==403
        assert request(scope+resource,valid)[0]==200,resource
    print('PASS Nginx playlists, segments, initialization segments and keys require auth')
    first=request(scope+'master.m3u8',valid)
    second=request(scope+'master.m3u8',token(expires,sid='22222222-2222-4222-8222-222222222222'))
    assert first[1].get('X-Test-Cache')=='HIT' and second[1].get('X-Test-Cache')=='HIT'
    assert counts[asset['origin_directory']+'master.m3u8']==1
    assert request(scope+'master.m3u8')[0]==403
    assert request(scope+'master.m3u8',token(int(time.time())-5))[0]==410
    assert request(scope+'high/index.m3u8',valid,{'Authorization':'Bearer test-only-do-not-forward'})[0]==200
    assert all(not h.get('Cookie') and not h.get('Authorization') for h in upstream_headers)
    ranged=request(scope+'low/init.mp4',valid,{'Range':'bytes=0-15'})
    assert ranged[0]==206 and len(ranged[2])==16
    print('PASS shared cache HIT across sessions; missing/expired cookies cannot read cache; no viewer credentials upstream; byte ranges')
    assert request(scope+'master.m3u8',valid,{'Origin':'https://evil.hyperline.test'})[0]==403
    cors=request(scope+'master.m3u8',None,{'Origin':'https://ott.hyperline.test:18443','Access-Control-Request-Method':'GET'},'OPTIONS')
    assert cors[0]==204 and cors[1].get('Access-Control-Allow-Credentials')=='true'
    print('PASS exact-origin credentialed CORS and preflight')
    run(['npm','run','build','--prefix','apps/web'])
    start(['php','-S','127.0.0.1:18081','../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],ROOT/'services/api/public','api')
    start(['node','node_modules/next/dist/bin/next','start','--port','18082','--hostname','127.0.0.1'],ROOT/'apps/web','web')
    for port in [18081,18082]:
        for attempt in range(60):
            try:
                with urllib.request.urlopen(f'http://127.0.0.1:{port}/',timeout=1):pass
                break
            except urllib.error.HTTPError: break
            except OSError:
                if attempt==59:raise
                time.sleep(.5)
    run(['npm','run','test:e2e','--prefix','apps/web','--','cdn.spec.ts'])
    print('PASS isolated CDN browser tests; temporary processes will stop')
finally:
    for p,out in reversed(processes):
        p.terminate()
        try:p.wait(timeout=5)
        except subprocess.TimeoutExpired:p.kill();p.wait()
        out.close()
    origin.shutdown()
    # Preserve failure logs, but remove ephemeral signing material after the run.
    (work/'secret.conf').unlink(missing_ok=True)
    (work/'key.pem').unlink(missing_ok=True)
