#!/usr/bin/env python3
"""Capture immutable rollback references before BUILD ONLY. No restart/create.
Docker tag changes image metadata only; no production container is touched.
"""
import datetime,json,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def docker(args):return subprocess.run(['docker',*args],cwd=ROOT,check=True,capture_output=True,text=True).stdout.strip()
release='runtime-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
target=ROOT/'deploy/releases'/release
target.mkdir(mode=0o700)
manifest={'release':release,'services':{},'protected_services':{},'environment':'Existing .env and APP_KEY unchanged; do not print env values.'}
rollback={'services':{}}
def compose_literal(value):
 if isinstance(value,str):return value.replace('$','$$')
 if isinstance(value,list):return [compose_literal(v) for v in value]
 if isinstance(value,dict):return {k:compose_literal(v) for k,v in value.items()}
 return value
for service in ['api','worker','scheduler','web','cms','gateway','postgres','redis']:
 ids=docker(['compose','ps','-q',service]).splitlines()
 if len(ids)!=1:raise SystemExit('Expected exactly one running service: '+service)
 c=json.loads(docker(['inspect',ids[0]]))[0]
 labels=c['Config'].get('Labels',{})
 if labels.get('com.docker.compose.project')!='hyperline' or labels.get('com.docker.compose.service')!=service:raise SystemExit('Container ownership mismatch')
 record={'container':c['Name'].lstrip('/'),'container_id':c['Id'],'image_id':c['Image'],'started_at':c['State']['StartedAt'],'restart_policy':c['HostConfig']['RestartPolicy'],
  'mounts':[{'type':m['Type'],'source':m['Source'],'destination':m['Destination']} for m in c['Mounts']]}
 if service in ['gateway','postgres','redis']:manifest['protected_services'][service]=record;continue
 tag='hyperline-'+service+':rollback-'+release
 if subprocess.run(['docker','image','inspect',tag],capture_output=True).returncode==0:raise SystemExit('Rollback tag already exists; refuse overwrite')
 docker(['image','tag',c['Image'],tag]);record['rollback_tag']=tag
 record['command']=c['Config'].get('Cmd')
 record['stop_timeout']=c['Config'].get('StopTimeout') or 10
 manifest['services'][service]=record
 entry={'image':tag,'command':c['Config'].get('Cmd'),'stop_grace_period':str({'worker':120,'scheduler':75}.get(service,c['Config'].get('StopTimeout') or 10))+'s'}
 health=c['Config'].get('Healthcheck')
 if health:
  entry['healthcheck']={'test':health['Test']}
  for source,key in [('Interval','interval'),('Timeout','timeout'),('StartPeriod','start_period'),('StartInterval','start_interval')]:
   if health.get(source):entry['healthcheck'][key]=str(health[source])+'ns'
  if 'Retries' in health:entry['healthcheck']['retries']=health['Retries']
 else:entry['healthcheck']={'disable':True}
 if service=='api':
  record['raw_rollback_allowed']=False
  entry['image']='hyperline-api:guarded-rollback-required-'+release
 rollback['services'][service]=compose_literal(entry)
(target/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
(target/'rollback.compose.json').write_text(json.dumps(rollback,indent=2)+'\n')
(target/'release.env').write_text('HYPERLINE_RELEASE='+release+'\n')
(ROOT/'deploy/releases/LATEST_RUNTIME_RELEASE').write_text(release+'\n')
print('Captured rollback references:',target.relative_to(ROOT))
for service,record in manifest['services'].items():print(service,record['image_id'],record['rollback_tag'])
