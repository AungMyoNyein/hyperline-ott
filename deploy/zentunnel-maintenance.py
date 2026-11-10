#!/usr/bin/env python3
"""Verified web/admin-only maintenance. NEVER stops a service or container.
Default is read-only dry-run. Execution requires the operator's explicit review.
"""
import argparse, datetime, fcntl, hashlib, json, os, shutil, subprocess, sys, tempfile
from pathlib import Path
HERE=Path(__file__).resolve().parent

def digest(data):return hashlib.sha256(data).hexdigest()
def run(args):
    p=subprocess.run(args,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if p.returncode:raise RuntimeError('Command failed: '+args[0]+' (details suppressed to protect configuration)')
    return p.stdout.strip()
def log(message):print(datetime.datetime.now(datetime.timezone.utc).isoformat(),message,flush=True)
def states(policy):
    answer={}
    for unit in policy['protected_units']+policy['protected_passive_units']:
        result=run(['systemctl','show',unit,'--property=LoadState,ActiveState,UnitFileState,Restart,MainPID'])
        item=dict(line.split('=',1) for line in result.splitlines() if '=' in line)
        if item.get('LoadState')!='loaded':raise RuntimeError('Missing verified unit: '+unit)
        if unit in policy['protected_units'] and item.get('ActiveState')!='active':raise RuntimeError('Required service is not active: '+unit)
        answer[unit]=item
    return answer
def atomic_write(path,data,metadata):
    fd,name=tempfile.mkstemp(prefix='.zentunnel-maintenance-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as stream:stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.chmod(name,metadata.st_mode & 0o7777);os.chown(name,metadata.st_uid,metadata.st_gid)
        for attribute in os.listxattr(path):os.setxattr(name,attribute,os.getxattr(path,attribute))
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)
def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action',choices=['stop','start'])
    group=parser.add_mutually_exclusive_group();group.add_argument('--dry-run',action='store_true');group.add_argument('--execute',action='store_true')
    parser.add_argument('--confirm',default='')
    args=parser.parse_args()
    policy=json.loads((HERE/'zentunnel-maintenance-policy.json').read_text())
    expected='STOP-ZENTUNNEL-WEB-ADMIN' if args.action=='stop' else 'RESTORE-ZENTUNNEL-WEB-ADMIN'
    if args.execute and args.confirm!=expected:raise RuntimeError('Execution requires --confirm '+expected+' after explicit production approval')
    if args.execute and os.geteuid()!=0:raise RuntimeError('Execution requires root')
    if policy['stop_units'] or policy['stop_containers']:raise RuntimeError('No service/container stop is verified safe')
    if policy['host_ip'] not in run(['ip','-brief','-4','address']).split():
        # ip prints addresses with a CIDR suffix.
        if not any(token.split('/')[0]==policy['host_ip'] for token in run(['ip','-brief','-4','address']).split()):raise RuntimeError('Wrong host')
    source=Path(policy['source']);link=Path(policy['enabled_link'])
    if not source.is_file() or not link.exists() or link.resolve()!=source:raise RuntimeError('Verified vhost or enabled link missing/changed')
    candidate=(HERE/'zentunnel-maintenance.candidate.conf').read_bytes()
    if digest(candidate)!=policy['candidate_sha256']:raise RuntimeError('Candidate changed; new review required')
    original=source.read_bytes();current_hash=digest(original)
    state_dir=Path(policy['state_dir']);state_file=state_dir/'state.json';backup=state_dir/'original.conf'
    # Dry-run creates nothing and cannot test a candidate against production includes.
    if not args.execute:
        if args.action=='stop' and current_hash not in [policy['original_sha256'],policy['candidate_sha256']]:raise RuntimeError('Live vhost drifted; new inventory/review required')
        if args.action=='start' and current_hash not in [policy['original_sha256'],policy['candidate_sha256']]:raise RuntimeError('Live vhost drifted; restoration must be reviewed')
        states(policy)
        log('DRY RUN ONLY: '+policy['scope'])
        log('No unit/container stop commands exist. Keep VPN/control-plane/API/DB/Redis/Docker/Nginx running.')
        log('Would '+('install the reviewed candidate' if args.action=='stop' else 'restore the exact saved original')+' at '+str(source))
        log('Then nginx -t; systemctl reload nginx.service; verify protected state/PIDs unchanged.')
        log('Explicit execution: --execute --confirm '+expected)
        return
    state_dir.mkdir(mode=0o700,parents=True,exist_ok=True)
    if state_dir.is_symlink() or state_dir.stat().st_uid!=0 or state_dir.stat().st_mode & 0o077:raise RuntimeError('State directory must be root-owned mode 0700')
    with (state_dir/'lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        before=states(policy)
        if args.action=='stop':
            if current_hash==policy['candidate_sha256']:
                if not state_file.exists() or not backup.exists() or digest(backup.read_bytes())!=policy['original_sha256']:raise RuntimeError('Maintenance exists without valid recovery state; refuse')
                log('Already in reviewed maintenance; no changes');return
            if current_hash!=policy['original_sha256']:raise RuntimeError('Live config drift; refusing maintenance')
            if state_file.exists():
                saved=json.loads(state_file.read_text())
                if saved.get('active'):raise RuntimeError('Previous active maintenance state needs review')
            shutil.copy2(source,backup);os.chmod(backup,0o600)
            saved={'original_sha256':current_hash,'candidate_sha256':policy['candidate_sha256'],'states':before,'active':True}
            state_file.write_text(json.dumps(saved,indent=2));os.chmod(state_file,0o600)
            replacement=candidate
        else:
            if current_hash==policy['original_sha256']:
                log('Original configuration is already present; no reload required');return
            if current_hash!=policy['candidate_sha256'] or not state_file.exists() or not backup.exists():raise RuntimeError('No matching recovery state or live configuration changed')
            saved=json.loads(state_file.read_text())
            replacement=backup.read_bytes()
            if digest(replacement)!=policy['original_sha256'] or saved['original_sha256']!=policy['original_sha256']:raise RuntimeError('Backup hash mismatch')
        metadata=source.stat()
        atomic_write(source,replacement,metadata)
        try:
            run(['nginx','-t'])
            run(['systemctl','reload','nginx.service'])
            after=states(policy)
            if after!=before:raise RuntimeError('Protected service/PID/restart/enabled state changed; manual investigation required')
        except Exception:
            # Restore the previous file; validate before any rollback reload.
            atomic_write(source,original,metadata)
            try:
                run(['nginx','-t']);run(['systemctl','reload','nginx.service'])
                saved['active']=current_hash==policy['candidate_sha256']
                state_file.write_text(json.dumps(saved,indent=2));os.chmod(state_file,0o600)
            except Exception:log('CRITICAL: prior file restored but reload failed; manual operator recovery required')
            raise
        saved['active']=args.action=='stop'
        state_file.write_text(json.dumps(saved,indent=2));os.chmod(state_file,0o600)
        log('Completed '+args.action+' web/admin maintenance; no protected service was stopped or enabled/disabled')
if __name__=='__main__':
    try:main()
    except Exception as error:log('REFUSED/FAILED: '+str(error));sys.exit(1)
