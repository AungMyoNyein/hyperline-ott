#!/usr/bin/env python3
"""Run the portrait candidate images against disposable loopback fixtures only."""
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
EVIDENCE = ROOT / 'docs/ui/portrait-compact'
manifest = json.loads((EVIDENCE / 'release-manifest.json').read_text())
fixture = Path(sys.argv[1]).resolve()
assert str(fixture).startswith('/tmp/hyperline-phase1-e2e-')
runner_source = fixture / 'tests/run-browser-tests.sh'
assert runner_source.is_file()


def inventory():
    result = {}
    for role in manifest['runtime_before']:
        fields = subprocess.check_output(['docker', 'inspect', '--format', '{{.Id}} {{.Image}} {{.State.StartedAt}}', 'hyperline-' + role + '-1'], text=True).split()
        result[role] = dict(zip(['container_id', 'image_id', 'started_at'], fields))
    return result


before = inventory()
assert all(all(before[role][key] == expected[key] for key in before[role]) for role, expected in manifest['runtime_before'].items())
script = runner_source.read_text()
for role in ['web', 'cms']:
    script = script.replace('npm run build --prefix apps/' + role + '\n', '')
names = []
for role, port in [('web', 18082), ('cms', 18083)]:
    image = manifest['images'][role]['image_id']
    assert subprocess.check_output(['docker', 'image', 'inspect', '--format', '{{.Id}}', image], text=True).strip() == image
    name = 'hyperline-portrait-smoke-' + role + '-' + secrets.token_hex(4)
    names.append(name)
    original = f'(cd apps/{role} && exec node node_modules/next/dist/bin/next start --port {port} --hostname 127.0.0.1)'
    replacement = f'(exec docker run --rm --name {name} --network host --entrypoint node {image} node_modules/next/dist/bin/next start --port {port} --hostname 127.0.0.1)'
    assert original in script
    script = script.replace(original, replacement)
for source in (ROOT / 'tests/e2e').glob('*.spec.ts'):
    shutil.copyfile(source, fixture / 'tests/e2e' / source.name)
runner = fixture / 'tests/run-portrait-images.sh'
runner.write_text(script)
environment = dict(os.environ, HYPERLINE_BROWSER_ISOLATED='1', HYPERLINE_SIGNED_FIXTURE='1', HYPERLINE_IMAGE_CANDIDATE='1', DB_URL='',
                   HYPERLINE_GENRE_SCREENSHOTS=str(EVIDENCE / 'cms-screenshots'),
                   HYPERLINE_PORTRAIT_SCREENSHOTS=str(EVIDENCE / 'screenshots'), HYPERLINE_UPDATE_UI_SNAPSHOTS='0')
try:
    with (EVIDENCE / 'image-smoke.txt').open('w') as log:
        result = subprocess.run(['bash', str(runner), 'compact-ui.spec.ts', 'portrait-surfaces.spec.ts', 'portrait-cms.spec.ts', 'genre-cms.spec.ts'], cwd=fixture, env=environment, stdout=log, stderr=subprocess.STDOUT)
finally:
    for name in names:
        if subprocess.run(['docker', 'container', 'inspect', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
            subprocess.run(['docker', 'stop', '--time', '10', name], check=True, stdout=subprocess.DEVNULL)
after = inventory()
assert before == after, 'UAT runtime changed'
report = {'passed': result.returncode == 0, 'source_commit': manifest['source_commit'], 'images': {role: manifest['images'][role]['image_id'] for role in ['web', 'cms']}, 'runtime_unchanged': before == after, 'uat_writes': False, 'disposable_fixture_only': True}
(EVIDENCE / 'image-smoke.json').write_text(json.dumps(report, indent=2) + '\n')
assert report['passed'], 'Candidate image smoke failed; inspect image-smoke.txt'
print(json.dumps(report, indent=2))
