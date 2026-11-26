#!/usr/bin/env python3
"""Build immutable frontend candidates without activating any UAT services."""
import datetime
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
BASELINE = 'ca37ed304459c565fabe25c90545152d8ec564d3'
ROLES = ['api', 'web', 'cms', 'worker', 'scheduler', 'postgres', 'redis', 'gateway']


def output(*arguments):
    return subprocess.check_output(arguments, cwd=ROOT, text=True).strip()


def inventory():
    result = {}
    for role in ROLES:
        container = json.loads(output('docker', 'inspect', 'hyperline-' + role + '-1'))[0]
        assert container['State']['Running']
        assert container['State'].get('Health', {}).get('Status', 'healthy') == 'healthy'
        result[role] = {
            'container_id': container['Id'], 'image_id': container['Image'],
            'started_at': container['State']['StartedAt'],
            'frontend_ip': container['NetworkSettings']['Networks'].get('hyperline_frontend', {}).get('IPAddress'),
        }
    return result


def main():
    assert '157.85.104.49' in output('hostname', '-I').split()
    revision = output('git', 'rev-parse', 'HEAD')
    output('git', 'merge-base', '--is-ancestor', BASELINE, revision)
    assert not output('git', 'diff', 'HEAD', '--', 'apps', 'services/api')
    assert not output('git', 'ls-files', '--others', '--exclude-standard', 'apps', 'services/api')
    assert output('git', 'rev-parse', revision + ':services/api') == output('git', 'rev-parse', BASELINE + ':services/api')
    before = inventory()
    assert before['api']['image_id'] == 'sha256:db69c7011b71b78cab7ccfe02a7ea4a6a7721ac99567632dd8895b05995fbaa6'
    assert all(before[role]['image_id'] == before['api']['image_id'] for role in ['worker', 'scheduler'])
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    release = 'portrait-compact-' + stamp + '-' + revision[:12]
    directory = ROOT / 'deploy/releases' / release
    directory.mkdir(mode=0o700)
    archive = subprocess.check_output(['git', 'archive', revision, 'apps/web', 'apps/cms', 'services/api'], cwd=ROOT)
    (directory / 'source.tar').write_bytes(archive)
    with tarfile.open(fileobj=io.BytesIO(archive)) as source:
        source.extractall(directory / 'source', filter='data')
    images = {}
    for role in ['web', 'cms']:
        base = before[role]['image_id']
        for filename in ['package.json', 'package-lock.json']:
            expected = hashlib.sha256((ROOT / 'apps' / role / filename).read_bytes()).hexdigest()
            actual = output('docker', 'run', '--rm', '--network', 'none', '--entrypoint', 'sha256sum', base, '/app/' + filename).split()[0]
            assert actual == expected, role + ' dependency lock mismatch; fresh dependency build required'
        dockerfile = directory / (role + '.Dockerfile')
        dockerfile.write_text('FROM ' + base + '\nWORKDIR /app\nCOPY . .\nARG NEXT_PUBLIC_API_URL\nRUN rm -rf .next && NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL npm run build\n')
        tag = 'hyperline-' + role + ':' + release
        with (directory / (role + '-build.txt')).open('w') as log:
            subprocess.run(['docker', 'build', '-f', str(dockerfile), '--label', 'org.opencontainers.image.revision=' + revision,
                            '--build-arg', 'NEXT_PUBLIC_API_URL=https://api.zentunnel.net/api/v1', '--tag', tag,
                            str(directory / 'source/apps' / role)], stdout=log, stderr=subprocess.STDOUT, check=True)
        images[role] = {'image_id': output('docker', 'image', 'inspect', '--format', '{{.Id}}', tag), 'tag': tag,
                        'tree': output('git', 'rev-parse', revision + ':apps/' + role), 'source_commit': revision,
                        'dependency_base_image': base}
    for role in ['api', 'worker', 'scheduler']:
        images[role] = {'image_id': before[role]['image_id'], 'reused_unchanged': True,
                        'tree': output('git', 'rev-parse', revision + ':services/api')}
    manifest = {'source_commit': revision, 'baseline_commit': BASELINE,
                'source_archive_sha256': hashlib.sha256(archive).hexdigest(), 'images': images,
                'runtime_before': before, 'runtime_unchanged': inventory() == before,
                'migration_required': False, 'deployed': False, 'activation_authorized': False,
                'release_directory': str(directory), 'build_strategy': 'Exact immutable deployed dependency bases; byte-identical committed package files; clean production rebuild of committed frontend source'}
    assert manifest['runtime_unchanged']
    candidate = {'services': {role: {'image': image['image_id'], 'pull_policy': 'never'} for role, image in images.items()}}
    rollback = {'services': {role: {'image': before[role]['image_id'], 'pull_policy': 'never'} for role in images}}
    for role in ['api', 'web', 'cms']:
        for override in [candidate, rollback]:
            override['services'][role]['networks'] = {'frontend': {'ipv4_address': before[role]['frontend_ip']}}
    evidence = ROOT / 'docs/ui/portrait-compact'
    for filename, value in [('release-manifest.json', manifest), ('candidate.compose.json', candidate), ('rollback.compose.json', rollback)]:
        contents = json.dumps(value, indent=2) + '\n'
        (directory / filename).write_text(contents)
        (evidence / filename).write_text(contents)
    Path('/tmp/hyperline-portrait-candidate-path').write_text(str(directory))
    print(json.dumps({'release_directory': str(directory), 'images': images, 'deployed': False}, indent=2))


if __name__ == '__main__':
    main()
