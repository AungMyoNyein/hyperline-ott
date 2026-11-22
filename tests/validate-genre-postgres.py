#!/usr/bin/env python3
"""Read-only UAT snapshot -> isolated tmpfs PostgreSQL -> real Laravel migration.
Never connects Laravel to UAT. Snapshot stays private and is never committed.
"""
import hashlib
import json
import os
import pathlib
import secrets
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[1]
REPORT = ROOT / 'docs/genre-cms/postgres-restore.json'
name = 'hyperline-genre-restore-' + secrets.token_hex(4)
private = pathlib.Path(tempfile.mkdtemp(prefix='hyperline-genre-restore-'))
os.chmod(private, 0o700)

def run(args, **kwargs):
    return subprocess.check_output(args, **kwargs)

def sql(query):
    return run(['docker', 'exec', '-e', 'PGPASSWORD='+password, name, 'psql', '-h', '127.0.0.1', '-X', '-U', 'fixture', '-d', 'fixture', '-Atc', query]).decode().strip()

def fingerprint():
    result = {}
    tables = sql("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename NOT IN ('migrations','homepage_settings') ORDER BY tablename").splitlines()
    for table in tables:
        projection = 'id,name,slug,created_at,updated_at' if table == 'genres' else '*'
        value = sql(f"SELECT coalesce(jsonb_agg(row ORDER BY row::text)::text,'[]') FROM (SELECT to_jsonb(t) AS row FROM (SELECT {projection} FROM {table}) t) x")
        result[table] = hashlib.sha256(value.encode()).hexdigest()
    return result

created = False
try:
    snapshot = private / 'uat-readonly.dump'
    with snapshot.open('wb') as output:
        subprocess.run(['docker','exec','hyperline-postgres-1','sh','-c','pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc --no-owner --no-privileges'], stdout=output, check=True)
    os.chmod(snapshot, 0o600)
    password = secrets.token_urlsafe(32)
    env = dict(os.environ, POSTGRES_PASSWORD=password)
    run(['docker','run','-d','--name',name,'--network','none','--tmpfs','/var/lib/postgresql/data:rw,nosuid','-e','POSTGRES_PASSWORD','-e','POSTGRES_USER=fixture','-e','POSTGRES_DB=fixture','postgres:16-alpine'], env=env)
    created = True
    for attempt in range(100):
        if subprocess.run(['docker','exec',name,'pg_isready','-h','127.0.0.1','-U','fixture','-d','fixture'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
            break
        time.sleep(.2)
    else:
        raise RuntimeError('Disposable PostgreSQL did not become ready')
    with snapshot.open('rb') as source:
        subprocess.run(['docker','exec','-i','-e','PGPASSWORD='+password,name,'pg_restore','-h','127.0.0.1','--exit-on-error','--no-owner','--no-privileges','-U','fixture','-d','fixture'], stdin=source, check=True)
    before = fingerprint()
    applied_before = int(sql('SELECT count(*) FROM migrations'))
    image = run(['docker','inspect','--format','{{.Image}}','hyperline-api-1']).decode().strip()
    command = ['docker','run','--rm','--network','container:'+name,'--mount',f'type=bind,src={ROOT}/services/api,dst=/app,readonly','--workdir','/app','--entrypoint','php']
    variables = {'APP_ENV':'testing','DB_CONNECTION':'pgsql','DB_HOST':'127.0.0.1','DB_PORT':'5432','DB_DATABASE':'fixture','DB_USERNAME':'fixture','DB_PASSWORD':password,'DB_URL':'','APP_CONFIG_CACHE':'/tmp/genre-config.php','APP_SERVICES_CACHE':'/tmp/genre-services.php','APP_PACKAGES_CACHE':'/tmp/genre-packages.php','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','APP_KEY':'base64:'+__import__('base64').b64encode(secrets.token_bytes(32)).decode()}
    env = dict(os.environ, **variables)
    for key in variables:
        command += ['-e',key]
    log = run(command + [image,'artisan','migrate','--force','--no-ansi'], env=env).decode()
    after = fingerprint()
    assert before == after, 'Existing records changed during migration'
    assert int(sql('SELECT count(*) FROM migrations')) == applied_before + 1
    assert sql('SELECT count(*) FROM genres WHERE enabled IS DISTINCT FROM true OR sort_order <> 0 OR name_my IS NOT NULL') == '0'
    # Test the optional command twice only against this isolated restore.
    for _ in range(2):
        run(command + [image,'artisan','ott:seed-suggested-genres','--no-ansi'],env=env)
    first_count = sql('SELECT count(*) FROM genres')
    run(command + [image,'artisan','ott:seed-suggested-genres','--no-ansi'],env=env)
    assert sql('SELECT count(*) FROM genres') == first_count
    report = {'passed':True,'postgres':'16-alpine','isolation':'network none; tmpfs database; API shares only disposable network namespace','applied_before':applied_before,'applied_after':applied_before+1,'existing_records_preserved':before==after,'fingerprinted_tables':list(before),'optional_seed_idempotent':True,'snapshot_sha256':hashlib.sha256(snapshot.read_bytes()).hexdigest(),'private_snapshot':str(snapshot),'migration_output':log,'live_migrations_run':False}
    REPORT.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k not in ['private_snapshot','migration_output']}))
finally:
    if created:
        subprocess.run(['docker','rm','-f',name],check=True,stdout=subprocess.DEVNULL)
