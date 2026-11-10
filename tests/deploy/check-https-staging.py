#!/usr/bin/env python3
"""Non-production protocol tests. Own loopback Nginx PID/config/test certificate.
Never modifies /etc, issues real certificates, reloads a service, or touches VPN.
"""
import base64,hashlib,http.client,http.server,json,re,socket,ssl,subprocess,tempfile,threading,time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];STAGE=ROOT/'deploy/https'
HTTP_PORT=18445;TLS_PORT=18446;ECHO_PORT=18447;INNER_PORT=18448
for port in [HTTP_PORT,TLS_PORT,ECHO_PORT,INNER_PORT]:
 with socket.socket() as s:
  s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1)
  try:s.bind(('127.0.0.1',port))
  except OSError:raise SystemExit(f'Isolated validation port {port} is busy')
class Echo(http.server.BaseHTTPRequestHandler):
 protocol_version='HTTP/1.1'
 def do_GET(self):
  if self.headers.get('Upgrade','').lower()=='websocket':
   self.send_response(101);self.send_header('Upgrade','websocket');self.send_header('Connection','upgrade')
   key=self.headers.get('Sec-WebSocket-Key','')+'258EAFA5-E914-47DA-95CA-C5AB0DC85B11'
   self.send_header('Sec-WebSocket-Accept',base64.b64encode(hashlib.sha1(key.encode()).digest()).decode());self.end_headers();return
  if self.path=='/__stream__':
   self.send_response(200);self.send_header('Transfer-Encoding','chunked');self.end_headers()
   self.wfile.write(b'6\r\nfirst\n\r\n');self.wfile.flush();time.sleep(2)
   self.wfile.write(b'7\r\nsecond\n\r\n0\r\n\r\n');self.wfile.flush();return
  body=json.dumps({'path':self.path,'headers':{key:self.headers.get(key) for key in ['Host','X-Forwarded-Host','X-Forwarded-Proto','X-Forwarded-Port','X-Forwarded-For','X-Real-IP','Connection','Upgrade']},'authorization_forwarded':bool(self.headers.get('Authorization'))}).encode()
  self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
 def log_message(self,*args):pass
hosts=['ott.zentunnel.net','cms.zentunnel.net','api.zentunnel.net']
with tempfile.TemporaryDirectory(prefix='hyperline-https-review-') as directory:
 work=Path(directory);acme=work/'acme/.well-known/acme-challenge';acme.mkdir(parents=True);(acme/'fixture').write_text('fixture-only-no-real-challenge')
 subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(work/'key.pem'),'-out',str(work/'cert.pem'),'-days','1','-subj','/CN=ott.zentunnel.net','-addext','subjectAltName='+','.join('DNS:'+h for h in hosts+['zentunnel.net'])],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 context=ssl.create_default_context(cafile=str(work/'cert.pem'))
 def render(mode):
  snippet=(STAGE/'snippets/hyperline-edge-proxy.conf').read_text()
  if mode=='fixture':snippet=snippet.replace('127.0.0.1:18080','127.0.0.1:'+str(INNER_PORT))
  (work/'edge-proxy.conf').write_text(snippet)
  common=(STAGE/'00-hyperline-http-common.conf').read_text()
  sentinel=f'''server {{ listen 127.0.0.1:{HTTP_PORT}; server_name zentunnel.net; return 200 "existing-zentunnel-default"; }}
server {{ listen 127.0.0.1:{TLS_PORT} ssl; server_name zentunnel.net; ssl_certificate {work}/cert.pem; ssl_certificate_key {work}/key.pem; return 200 "existing-zentunnel-default"; }}\n'''
  sites='\n'.join(p.read_text() for p in sorted((STAGE/('bootstrap' if mode=='bootstrap' else 'sites')).glob('*.conf')))
  sites=sites.replace('listen 80;',f'listen 127.0.0.1:{HTTP_PORT};').replace('listen [::]:80;','').replace('listen 443 ssl;',f'listen 127.0.0.1:{TLS_PORT} ssl;').replace('listen [::]:443 ssl;','')
  sites=sites.replace('/var/www/hyperline-acme',str(work/'acme')).replace('/etc/nginx/snippets/hyperline-edge-proxy.conf',str(work/'edge-proxy.conf'))
  sites=re.sub(r'ssl_certificate\s+[^;]+;',f'ssl_certificate {work}/cert.pem;',sites)
  sites=re.sub(r'ssl_certificate_key\s+[^;]+;',f'ssl_certificate_key {work}/key.pem;',sites)
  sites=sites.replace('/var/log/nginx/',str(work)+'/')
  inner=''
  if mode=='fixture':
   inner=(STAGE/'gateway.conf.staged').read_text().split('server {\n    listen 80;\n    server_name api.zentunnel.net;')[0]
   inner=inner.replace('listen 80;',f'listen 127.0.0.1:{INNER_PORT};').replace('http://web:3000',f'http://127.0.0.1:{ECHO_PORT}').replace('http://cms:3000',f'http://127.0.0.1:{ECHO_PORT}')
  config=f'pid {work}/nginx.pid;\nerror_log {work}/nginx.error warn;\nmaster_process off;\nevents {{}}\nhttp {{ access_log off; client_body_temp_path {work}/body; proxy_temp_path {work}/proxy;\n'+common+sentinel+sites+inner+'\n}\n'
  (work/'nginx.conf').write_text(config)
 def connection(host,tls=True):
  if tls:
   c=http.client.HTTPSConnection(host,TLS_PORT,context=context,timeout=8)
   c._create_connection=lambda address,timeout=8,source_address=None:socket.create_connection(('127.0.0.1',TLS_PORT),timeout)
  else:c=http.client.HTTPConnection('127.0.0.1',HTTP_PORT,timeout=8)
  return c
 def request(host,path='/',tls=True,headers=None,method='GET'):
  c=connection(host,tls);c.request(method,path,headers={'Host':host,**(headers or {})});r=c.getresponse();out=(r.status,dict(r.getheaders()),r.read());c.close();return out
 def start(mode):
  render(mode)
  subprocess.run(['nginx','-t','-p',directory+'/', '-c',str(work/'nginx.conf')],check=True)
  log=(work/(mode+'.log')).open('w');p=subprocess.Popen(['nginx','-p',directory+'/', '-c',str(work/'nginx.conf'),'-g','daemon off;'],stdout=log,stderr=log)
  for i in range(50):
   try:request('zentunnel.net','/',False);break
   except OSError:
    if p.poll() is not None:raise RuntimeError('Isolated Nginx did not start')
    time.sleep(.1)
  return p,log
 def stop(p,log):
  p.terminate()
  try:p.wait(timeout=5)
  except subprocess.TimeoutExpired:p.kill();p.wait()
  log.close()
 p,log=start('bootstrap')
 try:
  for host in hosts:
   token=request(host,'/.well-known/acme-challenge/fixture',False)
   assert token[0]==200 and token[2]==b'fixture-only-no-real-challenge'
   assert request(host,'/.well-known/acme-challenge/missing',False)[0]==404
   root=request(host,'/',False)
   assert root[0]==503 and root[1].get('Retry-After')=='300'
  assert request('unmatched.invalid','/',False)[2]==b'existing-zentunnel-default'
  print('PASS HTTP bootstrap: per-host webroot challenge, missing challenge 404, other paths 503, existing default preserved')
 finally:stop(p,log)
 p,log=start('live-gateway')
 try:
  for host in hosts:
   redirect=request(host,'/test/path?fixture=1',False,method='POST')
   assert redirect[0]==308 and redirect[1]['Location']==f'https://{host}/test/path?fixture=1'
   assert request(host,'/.well-known/acme-challenge/fixture',False)[0]==200
  for host in hosts[:2]:
   r=request(host);assert r[0]==200 and 'text/html' in r[1]['Content-Type']
   text=r[2].decode(errors='replace');match=re.search(r'(?:src|href)="(/_next/static/[^\"]+)"',text)
   assert match and request(host,match.group(1))[0]==200
  api=request(hosts[2],'/api/v1/health');assert api[0]==200 and json.loads(api[2])['service']=='hyperline-api'
  print('Current image /api/v1/me without Accept observed:',request(hosts[2],'/api/v1/me')[0])
  assert request(hosts[2],'/api/v1/me',headers={'Accept':'application/json'})[0]==401
  assert request(hosts[2],'/health')[0]==404
  catalog=request(hosts[2],'/api/v1/movies?per_page=1');assert catalog[0]==200 and json.loads(catalog[2])['meta']['per_page']==1
  cors=request(hosts[2],'/api/v1/playback/sessions',headers={'Origin':'https://ott.zentunnel.net','Access-Control-Request-Method':'POST','Access-Control-Request-Headers':'Authorization,Content-Type'},method='OPTIONS')
  print('API preflight observed:',cors[0],'allow-origin:',cors[1].get('Access-Control-Allow-Origin'),'allow-credentials:',cors[1].get('Access-Control-Allow-Credentials','[absent]'))
  print('PASS final TLS templates: verified test SAN, HTTP 308/path/query, ACME renewal path, real Host-routed Web/CMS/static assets/API health/catalog/401/404')
 finally:stop(p,log)
 fixture=http.server.ThreadingHTTPServer(('127.0.0.1',ECHO_PORT),Echo);threading.Thread(target=fixture.serve_forever,daemon=True).start()
 p,log=start('fixture')
 try:
  for host in hosts[:2]:
   result=request(host,'/fixture/path?keep=1',headers={'X-Forwarded-For':'203.0.113.77','Authorization':'Bearer fixture-only'})
   echo=json.loads(result[2]);h=echo['headers']
   assert echo['path']=='/fixture/path?keep=1' and h['Host']==host and h['X-Forwarded-Proto']=='https' and h['X-Forwarded-Port']=='443'
   assert '203.0.113.77' not in h['X-Forwarded-For'] and echo['authorization_forwarded']
   upgrade=request(host,'/__upgrade__',headers={'Upgrade':'websocket','Connection':'Upgrade','Sec-WebSocket-Key':base64.b64encode(b'fixture-16-bytes').decode(),'Sec-WebSocket-Version':'13'})
   assert upgrade[0]==101
  c=connection(hosts[0]);start_time=time.monotonic();c.request('GET','/__stream__',headers={'Host':hosts[0]});r=c.getresponse();assert r.read(6)==b'first\n' and time.monotonic()-start_time<1;assert b'second' in r.read();c.close()
  c=connection(hosts[2]);c.putrequest('POST','/api/v1/admin/movies',skip_host=True);c.putheader('Host',hosts[2]);c.putheader('Content-Length',str(1024*1024+1));c.putheader('Expect','100-continue');c.endheaders();assert c.getresponse().status==413;c.close()
  print('PASS mock two-hop headers/auth preservation, spoofed-XFF removal, WebSocket 101, incremental streaming and request-size limit')
 finally:stop(p,log);fixture.shutdown();fixture.server_close()
 # Full listen-option syntax compatibility with REAL existing sites (read only).
 # New SAN references use the test key; no production cert is issued or installed.
 full=(STAGE/'00-hyperline-http-common.conf').read_text()
 for site in sorted((STAGE/'sites').glob('*.conf')):
  text=site.read_text().replace('/etc/nginx/snippets/hyperline-edge-proxy.conf',str(work/'edge-proxy.conf')).replace('/var/log/nginx/',str(work)+'/')
  text=re.sub(r'ssl_certificate\s+[^;]+;',f'ssl_certificate {work}/cert.pem;',text)
  text=re.sub(r'ssl_certificate_key\s+[^;]+;',f'ssl_certificate_key {work}/key.pem;',text)
  full+=text
 (work/'staged-sites.conf').write_text(full)
 config=f'pid {work}/syntax-only.pid;\nerror_log {work}/syntax-only.error warn;\nevents {{}}\nhttp {{ access_log off; client_body_temp_path {work}/body; proxy_temp_path {work}/proxy; include /etc/nginx/mime.types; include /etc/nginx/conf.d/*.conf; include /etc/nginx/sites-enabled/*; include {work}/staged-sites.conf; }}\n'
 (work/'full-listen-syntax.conf').write_text(config)
 subprocess.run(['nginx','-t','-p',directory+'/', '-c',str(work/'full-listen-syntax.conf')],check=True)
 print('PASS syntax alongside actual read-only host sites, including existing IPv4/IPv6 listen options')
 print('PASS validation complete; own temporary processes stopped; no production configuration/reload/issuance performed')
