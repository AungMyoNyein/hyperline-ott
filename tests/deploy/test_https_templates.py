import json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];STAGE=ROOT/'deploy/https'
class HttpsTemplatesTest(unittest.TestCase):
 def test_all_sites_share_only_new_san_and_preserve_uri_host_boundary(self):
  for label in ['ott','cms','api']:
   text=(STAGE/'sites'/f'zz-hyperline-{label}.conf').read_text()
   self.assertIn('server_name '+label+'.zentunnel.net;',text)
   self.assertIn('/etc/letsencrypt/live/hyperline-ott/fullchain.pem',text)
   self.assertNotIn('/etc/letsencrypt/live/zentunnel.net/',text)
   self.assertNotIn('default_server',text.replace('# Do not install until explicit approval. Never use default_server.',''))
   self.assertIn('location ^~ /.well-known/acme-challenge/',text)
  proxy=(STAGE/'snippets/hyperline-edge-proxy.conf').read_text()
  self.assertIn('proxy_pass http://127.0.0.1:18080;',proxy)
  self.assertIn('proxy_set_header Host $host;',proxy)
  self.assertNotIn('proxy_pass http://127.0.0.1:18080/;',proxy)
 def test_renewal_hook_skips_existing_lineage_and_validates_before_reload(self):
  with tempfile.TemporaryDirectory() as directory:
   root=Path(directory);log=root/'calls';nginx=root/'nginx';systemctl=root/'systemctl'
   nginx.write_text(f'#!/bin/sh\necho nginx-test >> "{log}"\nexit "${{NGINX_TEST_RESULT:-0}}"\n');nginx.chmod(0o755)
   systemctl.write_text(f'#!/bin/sh\necho "$*" >> "{log}"\n');systemctl.chmod(0o755)
   script=(STAGE/'renewal/hyperline-reload.sh').read_text().replace('/usr/sbin/nginx',str(nginx)).replace('/usr/bin/systemctl',str(systemctl))
   hook=root/'hook.sh';hook.write_text(script)
   env={**os.environ,'RENEWED_LINEAGE':'/etc/letsencrypt/live/zentunnel.net'}
   subprocess.run(['sh',str(hook)],env=env,check=True);self.assertFalse(log.exists())
   env['RENEWED_LINEAGE']='/etc/letsencrypt/live/hyperline-ott'
   subprocess.run(['sh',str(hook)],env=env,check=True);self.assertEqual(log.read_text().splitlines(),['nginx-test','reload nginx.service'])
   log.unlink();env['NGINX_TEST_RESULT']='1'
   self.assertNotEqual(subprocess.run(['sh',str(hook)],env=env).returncode,0);self.assertEqual(log.read_text().splitlines(),['nginx-test'])
if __name__=='__main__':unittest.main()
