#!/usr/bin/env python3
"""Read-only Docker/configuration checks. Never print environment values.

Writes a sanitized evidence file in the workspace. Does not create, stop, reload,
restart or recreate containers/services and does not read application data.
"""
import base64
import datetime
import json
import os
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
RELEASE = (ROOT / 'deploy/releases/LATEST_RUNTIME_RELEASE').read_text().strip()
DIRECTORY = ROOT / 'deploy/releases' / RELEASE
CHECKS = []


def run(args):
    result = subprocess.run(args, cwd=ROOT, env={**os.environ, 'HYPERLINE_RELEASE': RELEASE},
                            capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError('Read-only command failed; output suppressed')
    return result.stdout


def check(condition, label):
    CHECKS.append({'result': 'PASS' if condition else 'FAIL', 'check': label})
    print(CHECKS[-1]['result'], label)


def env_of(container):
    return dict(item.split('=', 1) for item in container['Config']['Env'] if '=' in item)


def main():
    manifest = json.loads((DIRECTORY / 'manifest.json').read_text())
    model = json.loads(run(['docker', 'compose', '-f', 'compose.yaml', '-f',
                          'deploy/runtime-release.compose.yaml', 'config', '--format', 'json']))
    rollback = json.loads(run(['docker', 'compose', '-f', 'compose.yaml', '-f',
                             str(DIRECTORY / 'rollback.compose.json'), 'config', '--format', 'json']))
    check(model['name'] == rollback['name'] == 'hyperline', 'Compose project remains hyperline')
    records = {**manifest['services'], **manifest['protected_services']}
    containers = {}
    for name, record in records.items():
        c = json.loads(run(['docker', 'inspect', record['container']]))[0]
        containers[name] = c
        labels = c['Config']['Labels']
        check(labels['com.docker.compose.project'] == 'hyperline' and
              labels['com.docker.compose.service'] == name and
              c['Id'] == record['container_id'] and c['Image'] == record['image_id'] and
              c['State']['StartedAt'] == record['started_at'] and
              c['HostConfig']['RestartPolicy'] == record['restart_policy'],
              'Verified unchanged container ownership/image/start/restart policy: ' + name)
        actual_mounts = {(m['Type'], m['Source'], m['Destination']) for m in c['Mounts']}
        expected_mounts = {(m['type'], m['source'], m['destination']) for m in record['mounts']}
        check(actual_mounts == expected_mounts, 'Original persistent mounts unchanged: ' + name)
        expected_volumes = {(model['volumes'][v['source']]['name'], v['target'])
                            for v in model['services'][name].get('volumes', []) if v['type'] == 'volume'}
        check(expected_volumes == {(v['Name'], v['Destination']) for v in c['Mounts'] if v['Type'] == 'volume'},
              'Release resolves the same named volumes: ' + name)
    for name, record in {**manifest['built_images'], 'guarded_api': manifest['guarded_api_rollback']}.items():
        check(run(['docker', 'image', 'inspect', record['reference'], '--format', '{{.Id}}']).strip()
              == record['image_id'], 'Immutable release image verified: ' + name)
    for name in ['worker', 'scheduler', 'web', 'cms']:
        record = manifest['services'][name]
        check(run(['docker', 'image', 'inspect', record['rollback_tag'], '--format', '{{.Id}}']).strip()
              == record['image_id'], 'Captured rollback image exists: ' + name)
    check(rollback['services']['api']['image'] == manifest['guarded_api_rollback']['reference'] and
          manifest['services']['api'].get('raw_rollback_allowed') is False,
          'Rollback API uses the guarded image; raw legacy image forbidden')
    check(containers['gateway']['HostConfig']['PortBindings'] ==
          {'80/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '18080'}]} and
          len(model['services']['gateway']['ports']) == 1 and
          model['services']['gateway']['ports'][0]['host_ip'] == '127.0.0.1' and
          model['services']['gateway']['ports'][0]['published'] == '18080',
          'Gateway remains bound only to 127.0.0.1:18080')
    check(all(not c['HostConfig']['PortBindings'] for n, c in containers.items() if n != 'gateway'),
          'No other HyperLine service publishes a host port')
    check((ROOT / '.env').stat().st_mode & 0o777 == 0o600, 'Environment file permissions are 0600')
    pg = env_of(containers['postgres'])
    redis = env_of(containers['redis'])
    for name in ['api', 'worker', 'scheduler']:
        old, new = env_of(containers[name]), model['services'][name]['environment']
        secret_keys = ['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD']
        check(all(new.get(k) and new[k] == old.get(k) for k in secret_keys),
              'Existing APP_KEY/database/cache secrets preserved: ' + name)
        try:
            valid_key = new['APP_KEY'].startswith('base64:') and len(base64.b64decode(new['APP_KEY'][7:], validate=True)) == 32
        except (ValueError, KeyError):
            valid_key = False
        check(valid_key and new.get('APP_ENV') == 'production' and str(new.get('APP_DEBUG')).lower() in ['false', '0'],
              'Valid APP_KEY structure, production environment, debug disabled: ' + name)
        check(new.get('DB_CONNECTION') == 'pgsql' and new.get('DB_HOST') == 'postgres' and
              new.get('DB_DATABASE') == pg.get('POSTGRES_DB') and new.get('DB_USERNAME') == pg.get('POSTGRES_USER') and
              new.get('DB_PASSWORD') == pg.get('POSTGRES_PASSWORD') and not new.get('DB_URL') and
              new.get('REDIS_HOST') == 'redis' and new.get('REDIS_PASSWORD') == redis.get('REDIS_PASSWORD'),
              'Credentials and private DB/Redis destinations compatible: ' + name)
    api = model['services']['api']['environment']
    check(api.get('OTT_SIGNED_URLS') == 'true' and api.get('OTT_EXISTING_CDN_VERIFIED') == 'false' and
          api.get('OTT_NGINX_CDN_VERIFIED') == 'false' and
          api.get('OTT_PLAYBACK_SIGNER') == 'App\\Support\\Playback\\ExistingCdnPlaybackSigner',
          'Production playback remains explicitly fail-closed')
    check(api.get('CORS_ALLOWED_ORIGINS') == 'https://ott.zentunnel.net,https://cms.zentunnel.net' and
          api.get('CORS_SUPPORTS_CREDENTIALS') == 'true', 'Explicit credentialed API CORS configured')
    check(set(model['services']['api']['depends_on']) == {'postgres', 'redis'} and
          all(set(model['services'][n]['depends_on']) == {'api', 'postgres', 'redis'} for n in ['worker', 'scheduler']),
          'Application dependencies limited to existing HyperLine API/PostgreSQL/Redis')
    check(all(model['services'][n]['image'] == manifest['built_images']['api']['reference'] for n in ['api', 'worker', 'scheduler']),
          'API, worker and scheduler share the verified runtime image')
    check(set(manifest['services']) == {'api', 'worker', 'scheduler', 'web', 'cms'},
          'Recreation allowlist excludes gateway, DB/cache and every Zentunnel/VPN service')
    for name in ['pgdata', 'redisdata', 'api_storage', 'api_public']:
        volume = json.loads(run(['docker', 'volume', 'inspect', model['volumes'][name]['name']]))[0]
        check(volume.get('Labels', {}).get('com.docker.compose.project') == 'hyperline',
              'Existing volume ownership verified: ' + name)
    restore_record = DIRECTORY / 'backup-recovery-verification.json'
    restore = json.loads(restore_record.read_text()) if restore_record.is_file() else {}
    restore_ok = (restore.get('release') == RELEASE and restore.get('backup_verification') == 'PASS'
                  and restore.get('isolated_restore', {}).get('result') == 'PASS'
                  and restore.get('isolated_restore', {}).get('network') == 'none'
                  and restore.get('isolated_restore', {}).get('production_mounts') is False
                  and restore.get('isolated_restore', {}).get('container_removed') is True)
    evidence = {'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'release': RELEASE,
                'images': {**manifest['built_images'], 'guarded_api': manifest['guarded_api_rollback']},
                'checks': CHECKS, 'mutations': 'Workspace evidence only; no production mutations.',
                'activation_gates': {
                    'planned_postgres_backup_exists': Path('/var/backups/hyperline', RELEASE, 'postgres.dump').is_file(),
                    'fresh_backup_restore_verification': 'PASS recorded isolated restore; see backup-recovery-verification.json' if restore_ok else 'Requires separate operator evidence; not performed by this checker.',
                    'production_execution': 'NO-GO until explicit approval and all backup/operator gates pass.',
                }}
    (DIRECTORY / 'activation-preflight.json').write_text(json.dumps(evidence, indent=2) + '\n')
    return 1 if any(c['result'] == 'FAIL' for c in CHECKS) else 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception:
        print('FAIL preflight incomplete; sensitive command/error details suppressed')
        raise SystemExit(1)
