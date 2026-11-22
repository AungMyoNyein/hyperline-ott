#!/usr/bin/env python3
"""Refine only Web, reusing a verified candidate's identical locked dependencies.
No service activation, registry push, dependency installation or live data access.
"""
import datetime
import hashlib
import io
import json
import pathlib
import re
import subprocess
import tarfile
ROOT = pathlib.Path(__file__).resolve().parents[1]
EVIDENCE = ROOT/'docs/genre-cms'
def out(*args): return subprocess.check_output(args,cwd=ROOT,text=True).strip()
def inventory(base):
    return {s:dict(zip(('container_id','image_id','started_at'),out('docker','inspect','--format','{{.Id}} {{.Image}} {{.State.StartedAt}}','hyperline-'+s+'-1').split())) for s in base['runtime_before']}
def main():
    paths = ['apps/web','apps/cms','services/api']
    assert not out('git','diff','HEAD','--',*paths), 'Commit application changes first'
    assert not out('git','ls-files','--others','--exclude-standard',*paths)
    base=json.loads((EVIDENCE/'release-manifest.json').read_text())
    revision=out('git','rev-parse','HEAD')
    for service,path in [('api','services/api'),('cms','apps/cms')]:
        assert out('git','rev-parse',f'{revision}:{path}') == base['images'][service]['tree'], service+' source changed; full build required'
    for filename in ('package.json','package-lock.json'):
        old=subprocess.check_output(['git','show',base['source_commit']+':apps/web/'+filename],cwd=ROOT)
        assert old == (ROOT/'apps/web'/filename).read_bytes(), 'Dependencies changed; full build required'
    before=inventory(base)
    assert before == base['runtime_before'], 'UAT runtime changed since preparation'
    parent=base['images']['web']
    assert re.fullmatch(r'[A-Za-z0-9:._-]+',parent['tag'])
    assert out('docker','image','inspect','--format','{{.Id}}',parent['tag']) == parent['image_id']
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    release='genre-final-'+stamp+'-'+revision[:12]
    target=ROOT/'deploy/releases'/release
    target.mkdir(parents=True,mode=0o700)
    archive=subprocess.check_output(['git','archive',revision,*paths],cwd=ROOT)
    (target/'source.tar').write_bytes(archive)
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar: tar.extractall(target/'source',filter='data')
    dockerfile=target/'RefineWeb.Dockerfile'
    dockerfile.write_text('FROM '+parent['tag']+'\nWORKDIR /app\nCOPY . .\nARG NEXT_PUBLIC_API_URL\nRUN NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL npm run build\n')
    tag='hyperline-web:'+release
    with (target/'web-build.txt').open('w') as log:
        subprocess.run(['docker','build','-f',str(dockerfile),'--label','org.opencontainers.image.revision='+revision,'--build-arg','NEXT_PUBLIC_API_URL=https://api.zentunnel.net/api/v1','--tag',tag,str(target/'source/apps/web')],stdout=log,stderr=subprocess.STDOUT,check=True)
    assert out('docker','image','inspect','--format','{{.Id}}',parent['tag']) == parent['image_id']
    for entry in base['images'].values(): entry.setdefault('source_commit',base['source_commit'])
    base['images']['web']={'tag':tag,'image_id':out('docker','image','inspect','--format','{{.Id}}',tag),'tree':out('git','rev-parse',revision+':apps/web'),'source_commit':revision}
    assert inventory(base) == before
    base.update(source_commit=revision,source_archive_sha256=hashlib.sha256(archive).hexdigest(),web_build_strategy='Verified prior Web candidate dependency layer; unchanged package/package-lock; complete committed Web source overlay and production rebuild',web_dependency_base_image=parent['image_id'],release_directory=str(target),runtime_unchanged=True,deployed=False)
    candidate={'services':{s:{'image':v['image_id'],'pull_policy':'never'} for s,v in base['images'].items()}}
    rollback={'services':{s:{'image':before[s]['image_id'],'pull_policy':'never'} for s in base['images']}}
    for filename,value in [('release-manifest.json',base),('candidate.compose.json',candidate),('rollback.compose.json',rollback)]:
        content=json.dumps(value,indent=2)+'\n';(EVIDENCE/filename).write_text(content);(target/filename).write_text(content)
    (EVIDENCE/'source-files.sha256').write_text(''.join(hashlib.sha256((target/'source'/f).read_bytes()).hexdigest()+'  '+f+'\n' for f in out('git','ls-files',*paths).splitlines()))
    print(json.dumps({'source_commit':revision,'web':base['images']['web'],'release_directory':str(target),'deployed':False},indent=2))
if __name__=='__main__': main()
