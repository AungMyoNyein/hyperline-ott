#!/usr/bin/env python3
"""Verify candidate preparation and unchanged live UAT state; never activate."""
import datetime
import hashlib
import json
from pathlib import Path
import re
import shlex
import subprocess
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
EVIDENCE = ROOT / 'docs/ui/portrait-compact'


def output(arguments):
    return subprocess.check_output(arguments, cwd=ROOT, text=True).strip()


def main():
    manifest = json.loads((EVIDENCE / 'release-manifest.json').read_text())
    assert not manifest['deployed'] and not manifest['activation_authorized']
    assert '157.85.104.49' in output(['hostname', '-I']).split()
    assert not output(['git', 'diff', 'HEAD', '--', 'apps', 'services/api'])
    archive = Path(manifest['release_directory']) / 'source.tar'
    assert hashlib.sha256(archive.read_bytes()).hexdigest() == manifest['source_archive_sha256']
    initial = json.loads(Path('/tmp/hyperline-portrait-runtime-before.json').read_text())
    assert all(all(manifest['runtime_before'][role][key] == value for key, value in values.items()) for role, values in initial.items())
    compose = json.loads((EVIDENCE / 'compose-validation.json').read_text())
    assert compose['passed']
    for filename, digest in compose['runtime_inputs'].items():
        assert hashlib.sha256(Path(filename).read_bytes()).hexdigest() == digest
    for filename, digest in compose['overrides'].items():
        assert hashlib.sha256((EVIDENCE / filename).read_bytes()).hexdigest() == digest
    for role, expected in manifest['runtime_before'].items():
        actual = json.loads(output(['docker', 'inspect', 'hyperline-' + role + '-1']))[0]
        assert actual['Id'] == expected['container_id'] and actual['Image'] == expected['image_id']
        assert actual['State']['StartedAt'] == expected['started_at'] and actual['State']['Running']
        assert actual['State'].get('Health', {}).get('Status', 'healthy') == 'healthy'
        assert output(['docker', 'image', 'inspect', '--format', '{{.Id}}', expected['image_id']]) == expected['image_id']
    for role, image in manifest['images'].items():
        assert output(['docker', 'image', 'inspect', '--format', '{{.Id}}', image['image_id']]) == image['image_id']
        source = 'apps/' + role if role in ['web', 'cms'] else 'services/api'
        assert output(['git', 'rev-parse', 'HEAD:' + source]) == image['tree']
    before = json.loads((EVIDENCE / 'catalog-before.json').read_text())
    tables = {}
    for table, expected in before['tables'].items():
        assert re.fullmatch('[a-z_]+', table)
        query = f"SELECT coalesce(jsonb_agg(row ORDER BY row::text)::text,'[]') FROM (SELECT to_jsonb(t) AS row FROM {table} t) x"
        rows = output(['docker', 'exec', 'hyperline-postgres-1', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -XqAt -c ' + shlex.quote(query)])
        actual = {'sha256': hashlib.sha256(rows.encode()).hexdigest(), 'rows': len(json.loads(rows))}
        assert actual == expected, 'Catalog drift: ' + table
        tables[table] = actual
    (EVIDENCE / 'catalog-after.json').write_text(json.dumps({'tables': tables}, indent=2) + '\n')
    migrations = int(output(['docker', 'exec', 'hyperline-postgres-1', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -XqAt -c "SELECT count(*) FROM migrations"']))
    assert migrations == 14
    https = {}
    for url in ['https://api.zentunnel.net/api/v1/health', 'https://ott.zentunnel.net/', 'https://cms.zentunnel.net/']:
        with urllib.request.urlopen(url, timeout=20) as response:
            assert response.status == 200
            https[url] = response.status
    smoke = json.loads((EVIDENCE / 'image-smoke.json').read_text())
    assert smoke['passed'] and smoke['runtime_unchanged']
    assert all(smoke['images'][role] == manifest['images'][role]['image_id'] for role in ['web', 'cms'])
    log = (EVIDENCE / 'browser-regression.txt').read_text()
    assert not re.search(r'^\s+\d+ failed', log, re.MULTILINE)
    passed = int(re.findall(r'^\s+(\d+) passed', log, re.MULTILINE)[-1])
    skipped = int(re.findall(r'^\s+(\d+) skipped', log, re.MULTILINE)[-1])
    report = {'verified_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'ready_for_review': True,
              'deployed': False, 'activation_authorized': False, 'runtime_unchanged': True,
              'catalog_unchanged': True, 'live_migrations_unchanged': migrations, 'https': https,
              'browser_tests_passed': passed, 'browser_tests_skipped': skipped,
              'candidate_image_smoke_passed': True, 'rollback_images_available': True,
              'frontend_git_trees_match_current_head': True,
              'source_commit': manifest['source_commit']}
    (EVIDENCE / 'preservation.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
