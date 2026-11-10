import importlib.util,json,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('maintenance',ROOT/'deploy/zentunnel-maintenance.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class MaintenanceTest(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name);self.source=self.root/'live.conf';self.source.write_bytes(b'original');self.link=self.root/'enabled';self.link.symlink_to(self.source)
  (self.root/'zentunnel-maintenance.candidate.conf').write_bytes(b'maintenance')
  self.policy={'host_ip':'157.85.104.49','source':str(self.source),'enabled_link':str(self.link),'original_sha256':m.digest(b'original'),'candidate_sha256':m.digest(b'maintenance'),'protected_units':['nginx.service'],'protected_passive_units':[],'stop_units':[],'stop_containers':[],'scope':'fixture','state_dir':str(self.root/'state')}
  (self.root/'zentunnel-maintenance-policy.json').write_text(json.dumps(self.policy));self.calls=[]
 def tearDown(self):self.tmp.cleanup()
 def invoke(self,action,*flags,fail_validation=False):
  failed=False
  def run(args):
   nonlocal failed
   self.calls.append(args)
   if args[0]=='ip':return 'eth0 UP 157.85.104.49/24'
   if fail_validation and args==['nginx','-t'] and not failed:failed=True;raise RuntimeError('test syntax failure')
   return ''
  with patch.object(m,'HERE',self.root),patch.object(m,'run',side_effect=run),patch.object(m,'states',return_value={'nginx.service':{'ActiveState':'active','MainPID':'1'}}),patch.object(m.os,'geteuid',return_value=0),patch.object(m.os,'chown'),patch('sys.argv',['maintenance',action,*flags]):m.main()
 def test_dry_run_creates_no_state_and_reloads_nothing(self):
  self.invoke('stop','--dry-run');self.assertFalse((self.root/'state').exists());self.assertEqual(self.source.read_bytes(),b'original');self.assertTrue(all(c[0]=='ip' for c in self.calls))
 def test_execute_without_confirmation_is_refused(self):
  with self.assertRaises(RuntimeError):self.invoke('stop','--execute')
  self.assertEqual(self.calls,[])
 def test_reversible_idempotent_maintenance_never_stops_services(self):
  self.invoke('stop','--execute','--confirm','STOP-ZENTUNNEL-WEB-ADMIN');self.assertEqual(self.source.read_bytes(),b'maintenance')
  self.invoke('stop','--execute','--confirm','STOP-ZENTUNNEL-WEB-ADMIN')
  self.invoke('start','--execute','--confirm','RESTORE-ZENTUNNEL-WEB-ADMIN');self.assertEqual(self.source.read_bytes(),b'original')
  self.invoke('start','--execute','--confirm','RESTORE-ZENTUNNEL-WEB-ADMIN')
  self.assertFalse(any('stop' in c or 'disable' in c or 'restart' in c for c in self.calls))
 def test_validation_failure_restores_original(self):
  with self.assertRaises(RuntimeError):self.invoke('stop','--execute','--confirm','STOP-ZENTUNNEL-WEB-ADMIN',fail_validation=True)
  self.assertEqual(self.source.read_bytes(),b'original');self.assertFalse(json.loads((self.root/'state'/'state.json').read_text())['active'])
 def test_config_drift_is_refused(self):
  self.source.write_bytes(b'unreviewed route')
  with self.assertRaises(RuntimeError):self.invoke('stop','--execute','--confirm','STOP-ZENTUNNEL-WEB-ADMIN')
  self.assertFalse((self.root/'state'/'original.conf').exists())
class ReviewedCandidateTest(unittest.TestCase):
 def test_reviewed_maintenance_keeps_existing_api_tls_and_acme_bytes(self):
  original=(ROOT/'deploy/reports/zentunnel-original.review.conf').read_text()
  candidate=(ROOT/'deploy/zentunnel-maintenance.candidate.conf').read_text()
  policy=json.loads((ROOT/'deploy/zentunnel-maintenance-policy.json').read_text())
  self.assertEqual(m.digest(original.encode()),policy['original_sha256'])
  self.assertEqual(m.digest(candidate.encode()),policy['candidate_sha256'])
  before=original.index('    location / {');after=original.index('    location /api/ {')
  self.assertEqual(candidate[:before],original[:before])
  self.assertEqual(candidate[candidate.index('    location /api/ {'):],original[after:])
  self.assertEqual(policy['stop_units'],[]);self.assertEqual(policy['stop_containers'],[])
  for unit in ['vpn-api.service','vpn-node-agent.service','vpn-control-plane.service','xray-vless.service','docker.service','nginx.service']:
   self.assertIn(unit,policy['protected_units'])
if __name__=='__main__':unittest.main()
