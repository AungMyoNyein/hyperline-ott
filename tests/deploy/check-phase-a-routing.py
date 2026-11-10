#!/usr/bin/env python3
"""Isolated Phase A/default-vhost/handover regression. Signals ONLY its own master.
No production configuration/reload, certificate generation, DB or CDN access.
"""
import http.client
import importlib.util
import json
import os
from pathlib import Path
import re
import signal
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('generation', ROOT/'deploy/https/wait-nginx-generation.py')
generation = importlib.util.module_from_spec(spec)
spec.loader.exec_module(generation)
hosts = ['ott.zentunnel.net', 'cms.zentunnel.net', 'api.zentunnel.net']

def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]

def request(port, host, path='/', method='GET'):
    c = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    try:
        c.request(method, path, headers={'Host': host, 'Connection': 'close'})
        r = c.getresponse()
        return r.status, r.headers, r.read()
    finally:
        c.close()

def gateway():
    for host, path in [(hosts[0], '/'), (hosts[1], '/'), (hosts[2], '/'), (hosts[2], '/api/v1/health')]:
        code, headers, body = request(18080, host, path)
        assert code == 200, (host, path, code)
        if path.endswith('health'):
            assert json.loads(body)['status'] == 'ok'
        print('PASS live gateway read-only GET', host, path, code, flush=True)

with tempfile.TemporaryDirectory(prefix='hyperline-phase-a-routing-') as directory:
    work = Path(directory)
    work.chmod(0o755)
    webroot = work/'acme'
    token = webroot/'.well-known/acme-challenge/test-token'
    token.parent.mkdir(parents=True)
    token.write_text('hyperline-acme-readiness\n')
    public = work/'zentunnel'
    public.mkdir()
    (public/'index.html').write_text('existing-zentunnel-frontend\n')
    http_port, frontend_port = port(), port()
    actual = Path('/var/backups/hyperline-https/final-20261109T154849Z/nginx/sites-available/zentunnel').read_text()
    # Existing vhost routing is copied verbatim except loopback listeners, temporary
    # static root, and removal of TLS-only directives. No production keys are read.
    existing = re.sub(r'^\s*(?:ssl_\w+|include /etc/letsencrypt/)\s+[^;]+;[^\n]*', '', actual, flags=re.M)
    existing = re.sub(r'^\s*listen \[::\]:443[^;]*;[^\n]*', '', existing, flags=re.M)
    existing = re.sub(r'listen 443 ssl;', f'listen 127.0.0.1:{frontend_port};', existing)
    existing = existing.replace('listen 80;', f'listen 127.0.0.1:{http_port};').replace('listen [::]:80;', '')
    existing = existing.replace('/var/www/vpn-crm', str(public))
    conf = work/'nginx.conf'
    def render(enabled):
        sites = ''
        if enabled:
            for label in ['api', 'cms', 'ott']:
                text = (ROOT/f'deploy/https/bootstrap/zz-hyperline-{label}.conf').read_text()
                assert 'proxy_pass' not in text and 'rewrite ' not in text
                text = text.replace('listen 80;', f'listen 127.0.0.1:{http_port};').replace('listen [::]:80;', '')
                sites += text.replace('/var/www/hyperline-acme', str(webroot))
        # $server_name/$upstream_addr/$pid prove handler and worker identity.
        conf.write_text(f'''worker_processes 1;
pid {work}/nginx.pid;
error_log {work}/error.log notice;
events {{ worker_connections 128; }}
http {{
 log_format evidence '$pid $host $server_name $status $upstream_addr';
 access_log {work}/access.log evidence;
 client_body_temp_path {work}/body;
 proxy_temp_path {work}/proxy;
 {existing}
 {sites}
}}
''')
        subprocess.run(['nginx', '-t', '-p', str(work)+'/', '-c', str(conf)], check=True, capture_output=True)
    render(False)
    proc = subprocess.Popen(['nginx', '-p', str(work)+'/', '-c', str(conf), '-g', 'daemon off;'], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    try:
        for _ in range(100):
            try:
                assert request(http_port, hosts[0])[0] == 404
                break
            except OSError:
                if proc.poll() is not None:
                    raise RuntimeError('Isolated Nginx failed to start')
                time.sleep(.02)
        else:
            raise RuntimeError('Isolated Nginx not ready')
        master = int((work/'nginx.pid').read_text())
        assert master == proc.pid, 'Only signal the process created by this test'
        before = generation.snapshot(master)
        (work/'before.json').write_text(json.dumps(before))
        assert request(http_port, 'zentunnel.net')[0:1] == (301,)
        assert request(http_port, 'unknown.invalid')[0] == 404
        assert request(frontend_port, 'zentunnel.net')[2] == b'existing-zentunnel-frontend\n'
        render(True)
        # Deterministically widen the real signal-to-configuration-commit window.
        # Workers keep serving the old configuration while OUR master is stopped.
        os.kill(master, signal.SIGSTOP)
        try:
            subprocess.run(['nginx', '-p', str(work)+'/', '-c', str(conf), '-s', 'reload'], check=True, capture_output=True)
            assert request(http_port, hosts[0])[0] == 404
            assert request(http_port, hosts[0], '/.well-known/acme-challenge/test-token')[0] == 404
            rejected = subprocess.run(['python3', str(ROOT/'deploy/https/verify-bootstrap-routes.py'), '--connect-address', '127.0.0.1', '--port', str(http_port), '--token', 'test-token'], capture_output=True, text=True)
            assert rejected.returncode != 0 and 'got 404' in rejected.stderr
            race_log = (work/'access.log').read_text()
            assert f'{hosts[0]} zentunnel.net 404 -' in race_log
            try:
                generation.wait_generation(master, before, .15)
            except RuntimeError as error:
                assert 'timed out' in str(error)
            else:
                raise AssertionError('Readiness gate accepted an uncommitted reload')
            print('PASS reproduced signal-success/old-worker 404, host default selected, no upstream; readiness gate rejects it', flush=True)
        finally:
            os.kill(master, signal.SIGCONT)
        observed = subprocess.run(['python3', str(ROOT/'deploy/https/wait-nginx-generation.py'), 'wait', '--pid-file', str(work/'nginx.pid'), '--before', str(work/'before.json')], check=True, capture_output=True, text=True)
        assert json.loads(observed.stdout)['master'] == before['master']
        print('PASS new worker generation observed before strict routing assertions', flush=True)
        subprocess.run(['python3', str(ROOT/'deploy/https/verify-bootstrap-routes.py'), '--connect-address', '127.0.0.1', '--port', str(http_port), '--token', 'test-token'], check=True)
        for h in hosts:
            for path in ['/', '/api/v1/health', '/login', '/.well-known/acme-challenge']:
                code, headers, body = request(http_port, h, path)
                assert code == 503 and headers.get('Retry-After') == '300', (h, path, code)
            code, headers, body = request(http_port, h, '/.well-known/acme-challenge/test-token?keep=1')
            assert code == 200 and body == b'hyperline-acme-readiness\n'
            assert headers.get_content_type() == 'text/plain'
            assert request(http_port, h, '/.well-known/acme-challenge/missing')[0] == 404
            assert request(http_port, h.upper()+':'+str(http_port))[0] == 503
            assert request(http_port, h, '/', method='HEAD')[0] == 503
            print('PASS bootstrap root/non-ACME 503, exact token 200, missing token 404, case/port Host and HEAD:', h, flush=True)
        token.write_bytes(b'hyperline-acme-readiness')
        rejected = subprocess.run(['python3', str(ROOT/'deploy/https/verify-bootstrap-routes.py'), '--connect-address', '127.0.0.1', '--port', str(http_port), '--token', 'test-token'], capture_output=True, text=True)
        assert rejected.returncode != 0 and 'exact bytes including newline' in rejected.stderr
        token.write_bytes(b'hyperline-acme-readiness\n')
        print('PASS shared route verifier rejects old-worker 404 and wrong token bytes', flush=True)
        assert request(http_port, '157.85.104.49')[0] == 404
        assert request(http_port, 'unknown.invalid')[0] == 404
        code, headers, _ = request(http_port, 'zentunnel.net', '/original?keep=1')
        assert code == 301 and headers.get('Location') == 'https://zentunnel.net/original?keep=1'
        assert request(frontend_port, 'zentunnel.net')[2] == b'existing-zentunnel-frontend\n'
        assert request(frontend_port, 'zentunnel.net', '/api/v1/health')[0] == 502
        print('PASS existing Zentunnel redirect/path/query, static frontend and stopped API 502; unknown Host default 404 preserved', flush=True)
        evidence = (work/'access.log').read_text()
        assert f'{hosts[0]} {hosts[0]} 503 -' in evidence
        assert f'{hosts[0]} {hosts[0]} 200 -' in evidence
        # Observe ordinary (unstopped) asynchronous reloads too. The result of
        # the immediate probe is evidence only; post-gate assertions stay strict.
        immediate = {404: 0, 503: 0}
        for _ in range(10):
            before = generation.snapshot(master)
            render(False)
            subprocess.run(['nginx', '-p', str(work)+'/', '-c', str(conf), '-s', 'reload'], check=True, capture_output=True)
            generation.wait_generation(master, before, 5)
            before = generation.snapshot(master)
            render(True)
            subprocess.run(['nginx', '-p', str(work)+'/', '-c', str(conf), '-s', 'reload'], check=True, capture_output=True)
            code = request(http_port, hosts[0])[0]
            assert code in immediate, code
            immediate[code] += 1
            generation.wait_generation(master, before, 5)
            assert request(http_port, hosts[0])[0] == 503
        print('PASS natural handover trials (immediate responses):', json.dumps(immediate), '; all 10 post-gate checks returned 503', flush=True)
        gateway()
        print('PASS unchanged templates are correct; reload/verification sequencing caused reproduced 404', flush=True)
    finally:
        if proc.poll() is None:
            os.kill(proc.pid, signal.SIGCONT)
            proc.terminate()
            try:
                proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait(timeout=5)
        if proc.stderr:
            proc.stderr.close()
