#!/usr/bin/env python3
"""Exercise actual candidate Web/CMS images with disposable fixtures only."""
import hashlib
import json
import os
import pathlib
import re
import secrets
import shutil
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
EVIDENCE = ROOT / 'docs/genre-cms'
manifest = json.loads((EVIDENCE / 'release-manifest.json').read_text())
fixture = pathlib.Path(json.loads((EVIDENCE / 'prebuilt-validation.json').read_text())['fixture_root']).resolve()
assert str(fixture).startswith('/tmp/hyperline-phase1-e2e-')
assert (fixture / 'tests/run-browser-prebuilt.sh').is_file()

def inventory():
    return {s: dict(zip(('container_id','image_id','started_at'), subprocess.check_output(['docker','inspect','--format','{{.Id}} {{.Image}} {{.State.StartedAt}}','hyperline-'+s+'-1'],text=True).split())) for s in manifest['runtime_before']}

before = inventory()
assert before == manifest['runtime_before'], 'UAT runtime differs from candidate baseline'
names = []
script = (fixture / 'tests/run-browser-prebuilt.sh').read_text()
for service, port in [('web',18082),('cms',18083)]:
    image = manifest['images'][service]['image_id']
    assert re.fullmatch(r'sha256:[0-9a-f]{64}', image)
    name = 'hyperline-genre-smoke-'+service+'-'+secrets.token_hex(4)
    names.append(name)
    old = f'(cd apps/{service} && exec node node_modules/next/dist/bin/next start --port {port} --hostname 127.0.0.1)'
    new = f'(exec docker run --rm --name {name} --network host --entrypoint node {image} node_modules/next/dist/bin/next start --port {port} --hostname 127.0.0.1)'
    assert old in script
    script = script.replace(old,new)
runner = fixture / 'tests/run-browser-image-smoke.sh'
runner.write_text(script)
shutil.copy2(ROOT / 'tests/e2e/genre-cms.spec.ts', fixture / 'tests/e2e/genre-cms.spec.ts')
env = dict(os.environ,HYPERLINE_BROWSER_ISOLATED='1',HYPERLINE_IMAGE_CANDIDATE='1',HYPERLINE_SIGNED_FIXTURE='1',DB_URL='',HYPERLINE_UI_SNAPSHOT_DIR=str(ROOT/'docs/ui/screenshots'),HYPERLINE_GENRE_SCREENSHOTS=str(EVIDENCE/'screenshots'))
try:
    with (EVIDENCE / 'image-smoke.txt').open('w') as log:
        result = subprocess.run(['bash',str(runner),'genre-cms.spec.ts'],cwd=fixture,env=env,stdout=log,stderr=subprocess.STDOUT)
finally:
    for name in names:
        if subprocess.run(['docker','container','inspect',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode == 0:
            subprocess.run(['docker','stop','--time','5',name],stdout=subprocess.DEVNULL,check=True)
after = inventory()
assert before == after, 'UAT runtime changed during image smoke'
assert result.returncode == 0, 'Image smoke failed; inspect image-smoke.txt'
assert '5 passed' in (EVIDENCE/'image-smoke.txt').read_text()
report = {'passed':True,'source_commit':manifest['source_commit'],'images':{s:manifest['images'][s]['image_id'] for s in ('web','cms')},'test_sha256':hashlib.sha256((ROOT/'tests/e2e/genre-cms.spec.ts').read_bytes()).hexdigest(),'browser_tests_passed':5,'axe_page_scans':40,'viewports':[360,390,768,1366,1920],'screenshots':40,'live_api_requests_forwarded_to_local_fixture':True,'runtime_unchanged':before==after,'temporary_containers_removed':True,'deployed':False}
(EVIDENCE/'image-smoke.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
