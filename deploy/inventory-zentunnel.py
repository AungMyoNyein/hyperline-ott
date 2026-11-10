#!/usr/bin/env python3
"""Read-only, allowlisted metadata inventory. Never prints env/cmdlines/keys."""
import datetime, hashlib, json, os, re, subprocess
from pathlib import Path

def run(args):
    r=subprocess.run(args,text=True,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
    return r.stdout.strip() if r.returncode==0 else ''
def proc(pid):
    p=Path('/proc')/str(pid)
    try:
        words=(p/'cmdline').read_bytes().split(b'\0')
        kinds=[name for name in ['artisan','queue:work','queue:listen','schedule:run','schedule:work','php-fpm','xray','vpn-control-plane','vpn-node-agent'] if any(name.encode() in w for w in words)]
        return {'pid':pid,'executable':os.readlink(p/'exe'),'cwd':os.readlink(p/'cwd'),'kinds':kinds}
    except (OSError,ValueError):return {'pid':pid,'unavailable':True}
units=run(['systemctl','list-unit-files','--type=service','--no-legend','--no-pager']).splitlines()
selected=[line.split()[0] for line in units if re.search(r'vpn|crm|zentunnel|wireguard|wg-quick|xray|vless|shadow|php.*fpm|nginx|docker|containerd|postgre|maria|mysql|redis|supervisor|queue|scheduler',line,re.I)]
selected+=['wg-quick@'+name+'.service' for name in run(['wg','show','interfaces']).split()]
selected+=['postgresql@16-main.service']
selected=sorted(set(selected))
properties=['LoadState','Id','ActiveState','SubState','UnitFileState','Restart','FragmentPath','WorkingDirectory','MainPID','Requires','Wants','After','Before','BindsTo','PartOf','TriggeredBy','ControlGroup']
services=[]
for unit in selected:
    text=run(['systemctl','show',unit,*['--property='+p for p in properties]])
    item=dict(line.split('=',1) for line in text.splitlines() if '=' in line)
    pid=int(item.get('MainPID','0') or 0)
    if pid:item['process']=proc(pid)
    services.append(item)
containers=[]
ids=run(['docker','ps','-aq']).splitlines()
if ids:
 for c in json.loads(run(['docker','inspect',*ids]) or '[]'):
    containers.append({'id':c['Id'],'name':c['Name'].lstrip('/'),'image':c['Config']['Image'],'status':c['State']['Status'],'restart':c['HostConfig']['RestartPolicy'],
     'compose_project':c['Config'].get('Labels',{}).get('com.docker.compose.project'),'compose_service':c['Config'].get('Labels',{}).get('com.docker.compose.service'),
     'mounts':[{'source':m['Source'],'destination':m['Destination'],'type':m['Type']} for m in c['Mounts']],
     'ports':c['NetworkSettings'].get('Ports'),'networks':list(c['NetworkSettings']['Networks'])})
nginx=[]
for path in sorted(Path('/etc/nginx').rglob('*')):
 if not path.is_file() or path.suffix in ['.key','.pem','.crt'] or not (str(path).startswith('/etc/nginx/sites-enabled/') or str(path).startswith('/etc/nginx/conf.d/')):continue
 text=path.read_text(errors='replace'); directives=[]
 for number,line in enumerate(text.splitlines(),1):
    stripped=line.strip()
    if re.match(r'^(server_name|listen|root|alias|location|proxy_pass|fastcgi_pass|try_files|include|auth_request|return|ssl_certificate)\b',stripped):
        safe=re.sub(r'(https?://)([^/@\s]+:[^/@\s]+@)',r'\1[redacted]@',stripped)
        safe=re.sub(r'\?[^\s;]+','?[redacted]',safe)
        directives.append({'line':number,'directive':safe})
 nginx.append({'path':str(path),'resolved':str(path.resolve()),'sha256':hashlib.sha256(text.encode()).hexdigest(),'directives':directives})
processes=[]
for path in Path('/proc').iterdir():
 if not path.name.isdigit():continue
 try:
    name=(path/'comm').read_text().strip()
    if re.search(r'php|xray|vpn-|ss-server|supervisor',name):processes.append(proc(int(path.name)))
 except OSError:pass
wg=[]
for interface in run(['wg','show','interfaces']).split():
 lines=run(['wg','show',interface,'latest-handshakes']).splitlines()
 timestamps=[int(line.split()[-1]) for line in lines if line.split()]
 now=int(datetime.datetime.now().timestamp())
 wg.append({'interface':interface,'listen_port':run(['wg','show',interface,'listen-port']),'peer_count':len(timestamps),'handshakes_within_180_seconds':sum(t>0 and now-t<180 for t in timestamps)})
report={'captured_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'hostname':run(['hostname']),'ip_addresses':run(['ip','-brief','address']),
 'running_systemd_units':[line.split()[0] for line in run(['systemctl','list-units','--type=service','--state=running','--plain','--no-legend','--no-pager']).splitlines() if line.split()],
 'compose_projects':json.loads(run(['docker','compose','ls','--format','json']) or '[]'),'containers':containers,'services':services,'nginx':nginx,'processes':processes,'wireguard':wg}
print(json.dumps(report,indent=2))
