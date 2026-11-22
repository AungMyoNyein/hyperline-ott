#!/usr/bin/env python3
"""Build committed immutable application images; never activate services."""
import datetime
import hashlib
import io
import json
import pathlib
import subprocess
import tarfile
ROOT = pathlib.Path(__file__).resolve().parents[1]
SERVICES = ('api','worker','scheduler','web','cms','gateway','postgres','redis')
def out(*args):
    return subprocess.check_output(args,cwd=ROOT,text=True).strip()
def inventory():
    return {s:dict(zip(('container_id','image_id','started_at'),out('docker','inspect','--format','{{.Id}} {{.Image}} {{.State.StartedAt}}',f'hyperline-{s}-1').split())) for s in SERVICES}
def main():
    paths = ['apps/web','apps/cms','services/api']
    if out('git','diff','HEAD','--',*paths) or out('git','ls-files','--others','--exclude-standard',*paths):
        raise SystemExit('Commit application changes first.')
    revision = out('git','rev-parse','HEAD')
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    name = f'genre-cms-{stamp}-{revision[:12]}'
    target = ROOT/'deploy/releases'/name
    target.mkdir(parents=True,mode=0o700)
    before = inventory()
    archive = subprocess.check_output(['git','archive',revision,*paths],cwd=ROOT)
    (target/'source.tar').write_bytes(archive)
    with tarfile.open(fileobj=io.BytesIO(archive)) as source:
        source.extractall(target/'source',filter='data')
    candidates = {}
    for service,path in [('api','services/api'),('web','apps/web'),('cms','apps/cms')]:
        tag = f'hyperline-{service}:{name}'
        if subprocess.run(['docker','image','inspect',tag],capture_output=True).returncode == 0:
            raise SystemExit('Refuse to overwrite an existing image tag.')
        command = ['docker','build','--label',f'org.opencontainers.image.revision={revision}','--tag',tag]
        if service != 'api': command += ['--build-arg','NEXT_PUBLIC_API_URL=https://api.zentunnel.net/api/v1']
        with (target/f'{service}-build.txt').open('w') as log:
            subprocess.run(command+[str(target/'source'/path)],stdout=log,stderr=subprocess.STDOUT,check=True)
        candidates[service] = {'tag':tag,'image_id':out('docker','image','inspect','--format','{{.Id}}',tag),'tree':out('git','rev-parse',f'{revision}:{path}')}
    candidates['worker'] = dict(candidates['api'])
    candidates['scheduler'] = dict(candidates['api'])
    after = inventory()
    if before != after: raise SystemExit('Runtime changed during preparation; investigate.')
    manifest = {'source_commit':revision,'source_archive_sha256':hashlib.sha256(archive).hexdigest(),'images':candidates,'runtime_before':before,'runtime_unchanged':before==after,'deployed':False,'migration':'2026_11_22_120000_add_genre_management_fields; additive, application rollback retains new metadata'}
    candidate = {'services':{s:{'image':v['image_id'],'pull_policy':'never'} for s,v in candidates.items()}}
    rollback = {'services':{s:{'image':before[s]['image_id'],'pull_policy':'never'} for s in candidates}}
    evidence = ROOT/'docs/genre-cms'
    for filename,value in [('release-manifest.json',manifest),('candidate.compose.json',candidate),('rollback.compose.json',rollback)]:
        content=json.dumps(value,indent=2)+'\n'
        (target/filename).write_text(content)
        (evidence/filename).write_text(content)
    (evidence/'source-files.sha256').write_text(''.join(f'{hashlib.sha256((target/"source"/f).read_bytes()).hexdigest()}  {f}\n' for f in out('git','ls-files',*paths).splitlines()))
    print(json.dumps({'release_directory':str(target),'source_commit':revision,'images':candidates,'deployed':False},indent=2))
if __name__ == '__main__': main()
