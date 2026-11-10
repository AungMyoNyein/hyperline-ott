"""Syntax-only staging test with a temporary certificate; never reloads Nginx."""
from pathlib import Path
import subprocess,tempfile,re
root=Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='hyperline-nginx-review-') as directory:
 p=Path(directory)
 subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(p/'key.pem'),'-out',str(p/'cert.pem'),'-days','1','-subj','/CN=syntax-test.local'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 content='\n'.join((root/name).read_text() for name in ['deploy/zentunnel-maintenance.candidate.conf','deploy/hyperline-host-nginx.conf.template'])
 content=re.sub(r'ssl_certificate\s+[^;]+;',f'ssl_certificate {p}/cert.pem;',content)
 content=re.sub(r'ssl_certificate_key\s+[^;]+;',f'ssl_certificate_key {p}/key.pem;',content)
 # Certificate/DH includes belong to live TLS; structural syntax uses test TLS only.
 content=re.sub(r'include /etc/letsencrypt/[^;]+;','',content)
 content=re.sub(r'ssl_dhparam [^;]+;','',content)
 config=f'pid {p}/nginx.pid;\nerror_log {p}/error.log warn;\nevents {{}}\nhttp {{ access_log off; client_body_temp_path {p}/body; proxy_temp_path {p}/proxy;\n'+content+'\n}\n'
 (p/'nginx.conf').write_text(config)
 subprocess.run(['nginx','-t','-p',directory+'/', '-c',str(p/'nginx.conf')],check=True)
 print('PASS isolated syntax: web/admin maintenance candidate + three separate HyperLine TLS vhosts. No live files or services changed.')
