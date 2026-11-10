#!/usr/bin/env python3
"""Create the documented protected snapshot and test an isolated restore.

Explicit --create-backup is required to write the fresh snapshot. Production is
read-only: no migration, queue consumption, service stop/reload or recreation.
The only container this script creates/removes has a unique ID, no network or
ports, and tmpfs PostgreSQL storage. Sensitive output stays inside the 0700 backup.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tarfile
import time

ROOT = Path(__file__).resolve().parents[1]
RELEASE = (ROOT / 'deploy/releases/LATEST_RUNTIME_RELEASE').read_text().strip()
DIRECTORY = ROOT / 'deploy/releases' / RELEASE
BACKUP = Path('/var/backups/hyperline') / RELEASE
MANIFEST = json.loads((DIRECTORY / 'manifest.json').read_text())


def command(args, **kwargs):
    p = subprocess.run(args, cwd=ROOT, capture_output=True, **kwargs)
    if p.returncode:
        raise RuntimeError('Command failed; sensitive output suppressed')
    return p.stdout


def inspect(container):
    return json.loads(command(['docker', 'inspect', container]))[0]


def unchanged():
    for role, saved in {**MANIFEST['services'], **MANIFEST['protected_services']}.items():
        c = inspect(saved['container'])
        assert c['Id'] == saved['container_id'] and c['Image'] == saved['image_id']
        assert c['State']['StartedAt'] == saved['started_at']
        assert c['Config']['Labels']['com.docker.compose.project'] == 'hyperline'
        assert {(v['Type'], v['Source'], v['Destination']) for v in c['Mounts']} == {
            (v['type'], v['source'], v['destination']) for v in saved['mounts']}


def stream_backup(args, name):
    with (BACKUP / name).open('xb') as out:
        p = subprocess.run(args, cwd=ROOT, stdout=out, stderr=subprocess.PIPE)
    if p.returncode:
        (BACKUP / (name + '.private-error')).write_bytes(p.stderr)
        raise RuntimeError('Backup command failed; details retained privately')


def file_index(base):
    result = {}
    for p in sorted(base.rglob('*')):
        if p.is_symlink():
            result[str(p.relative_to(base))] = {'symlink': os.readlink(p)}
        elif p.is_file():
            digest = hashlib.sha256()
            with p.open('rb') as stream:
                for chunk in iter(lambda: stream.read(1024 * 1024), b''):
                    digest.update(chunk)
            result[str(p.relative_to(base))] = {'sha256': digest.hexdigest(), 'bytes': p.stat().st_size}
    return result


def create_backup():
    if BACKUP.exists() or BACKUP.is_symlink():
        raise RuntimeError('Refusing to overwrite an existing backup')
    BACKUP.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    BACKUP.mkdir(mode=0o700)
    files = {'hyperline.env': ROOT / '.env', 'compose.yaml': ROOT / 'compose.yaml',
             'runtime-release.compose.yaml': ROOT / 'deploy/runtime-release.compose.yaml',
             'gateway.conf': ROOT / 'infra/nginx/conf.d/00-app.conf'}
    for name, source in files.items():
        shutil.copy2(source, BACKUP / name)
        assert (BACKUP / name).read_bytes() == source.read_bytes()
    for name, source in {'release': DIRECTORY, 'nginx': Path('/etc/nginx'),
                         'renewal': Path('/etc/letsencrypt/renewal'),
                         'renewal-hooks': Path('/etc/letsencrypt/renewal-hooks'),
                         'https-staged': ROOT / 'deploy/https'}.items():
        before = file_index(source)
        shutil.copytree(source, BACKUP / name, symlinks=True)
        assert before == file_index(BACKUP / name) == file_index(source)
    stream_backup(['docker', 'exec', 'hyperline-postgres-1', 'sh', '-c',
                   'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc'], 'postgres.dump')
    for source, name in [('storage', 'api-storage.tar.gz'), ('public', 'api-public.tar.gz')]:
        stream_backup(['docker', 'exec', 'hyperline-api-1', 'tar', '-C',
                       '/var/www/html/' + source, '-czf', '-', '.'], name)
    (BACKUP / 'file-index.json').write_text(json.dumps(file_index(BACKUP), indent=2) + '\n')


def verify_backup():
    assert BACKUP.is_dir() and not BACKUP.is_symlink()
    assert BACKUP.stat().st_uid == 0 and BACKUP.stat().st_mode & 0o777 == 0o700
    index = json.loads((BACKUP / 'file-index.json').read_text())
    actual = file_index(BACKUP)
    assert all(actual.get(path) == record for path, record in index.items())
    for name in ['hyperline.env', 'compose.yaml', 'runtime-release.compose.yaml',
                 'postgres.dump', 'api-storage.tar.gz', 'api-public.tar.gz']:
        assert name in index and (BACKUP / name).stat().st_size > 0
    assert (BACKUP / 'hyperline.env').read_bytes() == (ROOT / '.env').read_bytes()
    with (BACKUP / 'postgres.dump').open('rb') as source:
        p = subprocess.run(['docker', 'exec', '-i', 'hyperline-postgres-1', 'pg_restore', '--list'],
                           stdin=source, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    assert p.returncode == 0 and b'TABLE DATA' in p.stdout
    (BACKUP / 'postgres.contents').write_bytes(p.stdout)
    for name in ['api-storage.tar.gz', 'api-public.tar.gz']:
        # Fully decompress and read every member; no extraction or path traversal.
        with tarfile.open(BACKUP / name, 'r|gz') as archive:
            members = 0
            for entry in archive:
                members += 1
                if entry.isfile():
                    with archive.extractfile(entry) as stream:
                        while stream.read(1024 * 1024):
                            pass
            assert members > 0
    print('PASS protected configuration copies/checksums, PostgreSQL archive TOC and full storage/public archive reads', flush=True)


def isolated_restore():
    image = MANIFEST['protected_services']['postgres']['image_id']
    nonce = secrets.token_hex(8)
    name = 'hyperline-restore-check-' + nonce
    cid = None
    try:
        cid = command(['docker', 'run', '-d', '--name', name, '--network', 'none',
                       '--memory', '768m', '--cpus', '0.5', '--pids-limit', '128',
                       '--label', 'hyperline.purpose=isolated-restore-check',
                       '--label', 'hyperline.restore-nonce=' + nonce,
                       '--mount', 'type=tmpfs,destination=/var/lib/postgresql/data,tmpfs-size=536870912',
                       '--env', 'POSTGRES_HOST_AUTH_METHOD=trust', image]).decode().strip()
        c = inspect(cid)
        assert c['Image'] == image and c['HostConfig']['NetworkMode'] == 'none'
        assert not c['HostConfig']['PortBindings']
        assert len(c['Mounts']) == 1 and c['Mounts'][0]['Type'] == 'tmpfs'
        assert c['Mounts'][0]['Destination'] == '/var/lib/postgresql/data'
        assert c['Config']['Labels']['hyperline.restore-nonce'] == nonce
        print('PASS restore target isolation: unique disposable container, no network/ports/production volumes, tmpfs data', flush=True)
        for attempt in range(120):
            logs = command(['docker', 'logs', cid])
            ready = subprocess.run(['docker', 'exec', cid, 'pg_isready', '-U', 'postgres'], capture_output=True)
            if b'init process complete' in logs and ready.returncode == 0:
                break
            if attempt == 119:
                raise RuntimeError('Disposable PostgreSQL readiness timed out')
            time.sleep(1)
        command(['docker', 'exec', cid, 'createdb', '-U', 'postgres', 'hyperline_restore_check'])
        with (BACKUP / 'postgres.dump').open('rb') as source:
            p = subprocess.run(['docker', 'exec', '-i', cid, 'pg_restore', '-U', 'postgres',
                                '-d', 'hyperline_restore_check', '--no-owner', '--no-privileges', '--exit-on-error'],
                               stdin=source, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if p.returncode:
            (BACKUP / 'restore.private-error').write_bytes(p.stderr)
            raise RuntimeError('Isolated restore failed; details retained privately')
        sql = "SELECT migration FROM migrations ORDER BY migration;"
        restored = command(['docker', 'exec', cid, 'psql', '-U', 'postgres', '-d', 'hyperline_restore_check',
                            '-v', 'ON_ERROR_STOP=1', '-Atc', sql]).decode().splitlines()
        expected = {f.stem for f in (ROOT / 'services/api/database/migrations').glob('*.php')}
        assert set(restored) == expected
        print('PASS full PostgreSQL restore with exit-on-error; all 9 expected migrations present', flush=True)
        return {'result': 'PASS', 'image_id': image, 'network': 'none', 'production_mounts': False,
                'database': 'hyperline_restore_check', 'migrations': len(restored), 'container_removed': True}
    finally:
        if cid:
            c = inspect(cid)
            assert c['Config']['Labels'].get('hyperline.restore-nonce') == nonce
            assert c['HostConfig']['NetworkMode'] == 'none'
            command(['docker', 'rm', '-f', cid])
            print('PASS removed only the owned disposable restore container; no Docker volumes removed', flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--create-backup', action='store_true')
    args = parser.parse_args()
    assert os.geteuid() == 0
    os.umask(0o077)
    unchanged()
    if args.create_backup:
        create_backup()
    verify_backup()
    restore = isolated_restore()
    unchanged()
    evidence = {'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                'release': RELEASE, 'backup_path': str(BACKUP), 'backup_verification': 'PASS',
                'isolated_restore': restore, 'production_containers_unchanged': True,
                'limits': ['Storage/public archives are live file snapshots, not an upload-quiesced transaction.',
                           'Off-host backup replication is not verified.', 'No production activation performed.']}
    (BACKUP / 'verification.json').write_text(json.dumps(evidence, indent=2) + '\n')
    (DIRECTORY / 'backup-recovery-verification.json').write_text(json.dumps(evidence, indent=2) + '\n')
    print('PASS backup and restore verification complete; production containers unchanged', flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('FAIL backup/restore verification; sensitive details suppressed; production activation prohibited', flush=True)
        raise SystemExit(1)
