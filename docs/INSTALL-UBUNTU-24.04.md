# HyperLine Phase 2 — GitHub installation runbook for Ubuntu 24.04

This is a reviewed procedure, **not an executed installation or deployment approval**. The source repository is the existing public monorepo **https://github.com/AungMyoNyein/hyperline-ott**. API, OTT Web and CMS are directories in that repository; there are no separate component repositories. The exact release tag is **`v2.0.0-phase2`**. Its full commit SHA is recorded in the GitHub release notes and the publication receipt produced after pushing.

The development/release host is **157.85.104.49**. It already runs HyperLine: never run the fresh-install steps against its existing project. No DNS, VPN, CDN/origin, production account or content changes are included. Protected non-YouTube playback remains unavailable with HTTP 503 until separate CDN authorization validation succeeds.

## 1. Preconditions and clone

Use an explicitly approved installation window and Ubuntu 24.04 amd64 host. Inventory interfaces, disk/RAM/CPU, existing containers/volumes, Nginx vhosts, listeners and certificates before choosing a path. The preparation host has two CPUs and 3.8 GiB RAM; this is a tested build environment, not a production capacity guarantee. Reserve adequate disk for images, backups and build intermediates; do not prune rollback images to make space.

For a **new host with no existing Docker installation**, follow [Docker's Ubuntu apt-repository instructions](https://docs.docker.com/engine/install/ubuntu/) and install Docker Engine, CLI, containerd, Buildx and the Compose plugin. Ubuntu 24.04 is supported. Verify:

```bash
cat /etc/os-release
docker version
docker compose version
docker compose up --help | rg -- '--wait|--wait-timeout'
df -h
free -h
nproc
```

Do not remove or upgrade existing Docker/containerd packages on a shared production host as part of this runbook. Host tools used below are Git, Python 3, curl, OpenSSL, Nginx and Certbot. Install missing tools only in the approved host setup. Restrict Docker daemon access to trusted operators. Docker port publication needs its own firewall review; PostgreSQL/Redis must have no public port mappings.

Clone into a new directory; do not reset or overwrite an existing checkout:

```bash
set -euo pipefail
umask 077
RELEASE_TAG=v2.0.0-phase2
RELEASE_DIR=/srv/hyperline-ott/releases/v2.0.0-phase2
test ! -e "$RELEASE_DIR"
git clone --branch "$RELEASE_TAG" --single-branch \
  https://github.com/AungMyoNyein/hyperline-ott.git "$RELEASE_DIR"
cd "$RELEASE_DIR"
git checkout --detach "$RELEASE_TAG^{commit}"
RELEASE_SHA=$(git rev-parse HEAD)
git remote get-url origin
git show --no-patch --format=fuller HEAD
git status --short
```

Compare the full `RELEASE_SHA` with the GitHub release notes/publication receipt before continuing. The tag is annotated, not a claim of cryptographic signing. HTTPS clone is the verified transport. `git@github.com:AungMyoNyein/hyperline-ott.git` is an alternate only after the operator separately provisions and verifies SSH access. Do not copy the development host's private keys.

The committed public manifest is `docs/releases/phase2-source-manifest.json`. It records source checksums, candidate image IDs, migration checksums and validation results. Local private release packets and backups are intentionally absent from GitHub.

## 2. Secure configuration and immutable builds

For an existing installation, securely preserve its `.env`, APP_KEY, database/Redis credentials, database destination, CORS settings and storage. **Never regenerate APP_KEY or authentication tables during an upgrade/restoration.** For a fresh empty installation only:

```bash
test ! -e .env
cp .env.example .env
chmod 600 .env
python3 - <<'PY'
import base64, pathlib, re, secrets
p=pathlib.Path('.env'); text=p.read_text()
db=secrets.token_hex(32); redis=secrets.token_hex(32)
values={'APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),
        'DB_PASSWORD':db,'POSTGRES_PASSWORD':db,'REDIS_PASSWORD':redis}
for name,value in values.items():
    text=re.sub(r'^'+name+r'=.*$',name+'='+value,text,flags=re.M)
p.write_text(text);p.chmod(0o600)
PY
```

Review configuration locally without printing secrets. Required agreement: DB_USERNAME/POSTGRES_USER, DB_DATABASE/POSTGRES_DB for a fresh database, and DB_PASSWORD/POSTGRES_PASSWORD. DB_HOST=`postgres`, REDIS_HOST=`redis`; use the private Compose services. Set production/debug=false, APP_URL=`https://api.zentunnel.net`, NEXT_PUBLIC_API_URL=`https://api.zentunnel.net/api/v1`, exact OTT/CMS CORS origins and credentials=true. Keep token expiration 1440 minutes unless an approved policy chooses another value in 1–10080. Preserve the existing CDN host/paths. Keep signing and playback authentication enabled, both CDN verification flags false and the authorizer unconfigured. Do not populate administrator passwords or run account/content import commands.

Build sequentially from the detached release source, tagging uniquely with its SHA:

```bash
ARTIFACT_DIR="$RELEASE_DIR/deploy/releases/install-$RELEASE_SHA"
mkdir -m 700 -p "$ARTIFACT_DIR"
for role in api web cms; do
  test -z "$(docker image ls -q "hyperline-$role:git-$RELEASE_SHA")"
done
docker build --label "org.opencontainers.image.revision=$RELEASE_SHA" \
  -t "hyperline-api:git-$RELEASE_SHA" services/api
docker build --label "org.opencontainers.image.revision=$RELEASE_SHA" \
  --build-arg NEXT_PUBLIC_API_URL=https://api.zentunnel.net/api/v1 \
  -t "hyperline-web:git-$RELEASE_SHA" apps/web
docker build --label "org.opencontainers.image.revision=$RELEASE_SHA" \
  --build-arg NEXT_PUBLIC_API_URL=https://api.zentunnel.net/api/v1 \
  -t "hyperline-cms:git-$RELEASE_SHA" apps/cms
docker pull postgres:16-alpine
docker pull redis:7-alpine
docker pull nginx:1.27-alpine
python3 - "$RELEASE_SHA" "$ARTIFACT_DIR" <<'PY'
import json,pathlib,subprocess,sys
sha,directory=sys.argv[1:]; images={}
for role,tag in {'api':f'hyperline-api:git-{sha}',
                 'web':f'hyperline-web:git-{sha}',
                 'cms':f'hyperline-cms:git-{sha}',
                 'postgres':'postgres:16-alpine','redis':'redis:7-alpine',
                 'gateway':'nginx:1.27-alpine'}.items():
    images[role]=subprocess.check_output(['docker','image','inspect',tag,'--format','{{.Id}}'],text=True).strip()
images['worker']=images['scheduler']=images['api']
services={r:{'image':i} for r,i in images.items()}
for role in ['api','worker','scheduler']:
    services[role]['environment']={'OTT_SIGNED_URLS':'true','OTT_PLAYBACK_REQUIRES_AUTH':'true',
        'OTT_EXISTING_CDN_VERIFIED':'false','OTT_NGINX_CDN_VERIFIED':'false',
        'OTT_PLAYBACK_SIGNER':r'App\Support\Playback\ExistingCdnPlaybackSigner',
        'CORS_ALLOWED_ORIGINS':'https://ott.zentunnel.net,https://cms.zentunnel.net',
        'CORS_SUPPORTS_CREDENTIALS':'true'}
p=pathlib.Path(directory)
(p/'images.json').write_text(json.dumps(images,indent=2)+'\n')
(p/'release.compose.json').write_text(json.dumps({'services':services},indent=2)+'\n')
PY
dc() {
  docker compose --project-directory "$RELEASE_DIR" --env-file "$RELEASE_DIR/.env" \
    -p hyperline -f "$RELEASE_DIR/compose.yaml" \
    -f "$ARTIFACT_DIR/release.compose.json" "$@"
}
dc config --quiet
sha256sum compose.yaml services/api/Dockerfile apps/web/Dockerfile apps/cms/Dockerfile
sha256sum services/api/database/migrations/*.php
```

Compare migrations and Dockerfiles with the public manifest. The Dockerfiles use floating base tags: a fresh build may have different IDs from the preparation host. Record its exact IDs and repeat audits, isolated tests and image smoke checks before approving those rebuilt bytes. Do not mistake a matching Git SHA for identical image bytes. Worker and scheduler must use the same API ID. The current prepared local images are not published in an image registry.

## 3. Choose fresh installation or existing application upgrade

**Fresh-host gate:** all of these must be empty before starting database services:

```bash
test -z "$(docker ps -aq --filter label=com.docker.compose.project=hyperline)"
test -z "$(docker volume ls -q --filter name='^hyperline_')"
test -z "$(ss -ltnH '( sport = :18080 )')"
```

If any gate fails, stop the fresh-install path. Existing HyperLine on 157.85.104.49 requires a separately approved scoped upgrade: capture actual image IDs and full runtime environment privately, pin rollback overrides, retain all volume mounts and APP_KEY, quiesce application writers/jobs, take and restore-test a new backup, then migrate and replace only API/Web/CMS/worker/scheduler. Do not recreate PostgreSQL, Redis or the gateway to apply an application release. Do not follow old VPN shutdown or CDN activation scripts. Review the internal gateway's static upstream IP resolution after application replacement; reload it only if needed and separately approved. Host Nginx/TLS need no routine change during an application upgrade.

For a verified fresh host, start only the two state services:

```bash
dc up -d --no-deps --no-build --pull never --wait --wait-timeout 120 postgres redis
dc exec -T postgres sh -c 'exec pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
dc exec -T redis sh -c 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli --no-auth-warning ping'
```

PostgreSQL 16 and Redis 7 use named persistent volumes. Do not publish 5432/6379, reuse unrelated host databases, seed production accounts, or use database refresh/reset. Inspect the Compose project/mount inventory before every stateful operation.

## 4. Backup, restoration and migration

Before an approved upgrade, stop new API writes and drain scheduled/queued work without killing unfinished jobs. Keep PostgreSQL/Redis and host infrastructure running. Use a new private backup directory; preserve earlier backups and exact rollback image references:

```bash
BACKUP_DIR="$ARTIFACT_DIR/backup-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 700 "$BACKUP_DIR"
cp .env "$BACKUP_DIR/environment.env"
cp compose.yaml "$BACKUP_DIR/compose.yaml"
cp "$ARTIFACT_DIR/release.compose.json" "$BACKUP_DIR/release.compose.json"
: "${APPLICATION_DATABASE:?Reviewed DB_DATABASE used by the application}"
dc exec -T postgres sh -c 'exec pg_dump -U "$POSTGRES_USER" -d "$1" -Fc' \
  sh "$APPLICATION_DATABASE" > "$BACKUP_DIR/postgres.dump"
dc exec -T postgres sh -c 'exec pg_dumpall -U "$POSTGRES_USER" --roles-only' > "$BACKUP_DIR/roles.sql"
chmod 600 "$BACKUP_DIR/"*
sha256sum "$BACKUP_DIR/"* > "$BACKUP_DIR/SHA256SUMS"
dc exec -T postgres pg_restore --list < "$BACKUP_DIR/postgres.dump" > "$BACKUP_DIR/contents.txt"
```

The application database can differ from POSTGRES_DB after a recovery switch; explicitly provide the reviewed DB_DATABASE above. Also archive API storage/public volumes using read-only mounts, capture Redis persistence/queue requirements, and preserve existing Nginx configuration privately. Quiesce upload/file writes for a consistent storage snapshot. Logical dumps alone cannot recover post-snapshot writes; establish WAL/PITR or a reconciliation procedure where required.

Before migrations, restore the dump with `pg_restore --exit-on-error --no-owner --no-privileges` into a disposable isolated PG16 container using tmpfs, no published ports and no production mounts. Verify migration names, row fingerprints, foreign keys, user/token/movie/source/progress preservation, application API behavior and recovery. The preparation already proved these paths, but a fresh deployment snapshot needs its own restore verification. Role/owner/grant restoration requires separate review.

For an approved **fresh-host data restore**, create a new database and preserve the old one; never restore over an existing database:

```bash
: "${SOURCE_DUMP:?Operator-approved verified backup path}"
RESTORE_DB="hyperline_restored_$(date -u +%Y%m%d%H%M%S)"
dc exec -T postgres sh -c 'exec createdb -U "$POSTGRES_USER" "$1"' sh "$RESTORE_DB"
dc exec -T postgres sh -c \
  'exec pg_restore -U "$POSTGRES_USER" -d "$1" --no-owner --no-privileges --exit-on-error' \
  sh "$RESTORE_DB" < "$SOURCE_DUMP"
```

Verify the new database and original APP_KEY before changing `.env` DB_DATABASE to `RESTORE_DB`. No automatic configuration switch is provided. Preserve the originating users, password hashes, token rows and storage. Do not replay a roles dump blindly. For an empty install, skip restoration and use the newly initialized empty application database.

Only after the approved backup/restore gate:

```bash
dc run --rm --no-deps --pull never --entrypoint php api artisan migrate:status
dc run --rm --no-deps --pull never \
  -e 'PGOPTIONS=-c lock_timeout=10s -c statement_timeout=300s' \
  --entrypoint php api artisan migrate --force
dc run --rm --no-deps --pull never --entrypoint php api artisan migrate:status
dc run --rm --no-deps --pull never --entrypoint php api artisan hyperline:check-playable-ownership --json
```

The four Phase 2 migrations add series/seasons, extend existing progress and install ownership checks. Migration files commit separately; a failure can leave earlier files applied. Stop and inspect; do not repeat blindly or run destructive downs. All 13 migration checksums must match the release manifest.

## 5. Gateway and HTTPS

On a fresh host only, after migration readiness, start API/Web/CMS and then worker/scheduler and gateway:

```bash
dc up -d --no-deps --no-build --pull never --wait --wait-timeout 180 api web cms
dc up -d --no-deps --no-build --pull never --wait --wait-timeout 240 worker scheduler
dc up -d --no-deps --no-build --pull never gateway
dc exec -T gateway nginx -t
curl --fail -H 'Host: api.zentunnel.net' http://127.0.0.1:18080/api/v1/health
```

The Docker gateway publishes **127.0.0.1:18080 only**. Its existing routing is `ott` → web:3000, `cms` → cms:3000 and API → api:9000 FastCGI. The external edge preserves Host and forwards to this loopback listener. The application never proxies media or keys through this gateway. Review trusted-proxy CIDRs against the actual Docker network and existing edge; do not trust arbitrary client forwarding headers.

**Existing host:** inspect and preserve its working vhosts, SAN certificate, renewal timer and snippets; no issuance or reload is required by a source release. **Fresh host:** only with separate host/TLS approval and DNS already routed correctly, review the repository's HyperLine-only templates under `deploy/https/`. Do not replace the global Nginx config, default vhost or unrelated VPN vhosts. Install only nonconflicting HyperLine includes/vhosts. The common include defines the safe log format/upgrade map; the location snippet preserves Host, scheme and client IP and proxies to loopback.

For HTTP-01, first enable reviewed HTTP bootstrap vhosts serving `/var/www/hyperline-acme/.well-known/acme-challenge/` for exactly the three names. Test their challenge routes before issuance. [Certbot webroot](https://eff-certbot.readthedocs.io/en/stable/using.html#webroot) obtains a certificate using the existing webserver; it does not install the TLS vhost itself:

```bash
# Approved fresh host only; fail if a HyperLine config already exists.
sudo install -d -m 755 /var/www/hyperline-acme /etc/nginx/snippets
test ! -e /etc/nginx/conf.d/00-hyperline-http-common.conf
test ! -e /etc/nginx/snippets/hyperline-edge-proxy.conf
sudo install -m 644 deploy/https/00-hyperline-http-common.conf /etc/nginx/conf.d/
sudo install -m 644 deploy/https/snippets/hyperline-edge-proxy.conf /etc/nginx/snippets/
for role in ott cms api; do
  test ! -e "/etc/nginx/sites-available/zz-hyperline-$role.conf"
  test ! -e "/etc/nginx/sites-enabled/zz-hyperline-$role.conf"
  sudo install -m 644 "deploy/https/bootstrap/zz-hyperline-$role.conf" /etc/nginx/sites-available/
  sudo ln -s "/etc/nginx/sites-available/zz-hyperline-$role.conf" \
    "/etc/nginx/sites-enabled/zz-hyperline-$role.conf"
done
sudo nginx -t
sudo systemctl reload nginx
# Verify a disposable HTTP challenge file is reachable for each name, then remove it.
# Future approved fresh-host TLS step only; not run for this release preparation.
sudo certbot certonly --webroot -w /var/www/hyperline-acme \
  --cert-name hyperline-ott \
  -d ott.zentunnel.net -d cms.zentunnel.net -d api.zentunnel.net
# Retain bootstrap configs before the approved transition to reviewed TLS vhosts.
for role in ott cms api; do
  sudo cp -n "/etc/nginx/sites-available/zz-hyperline-$role.conf" "$ARTIFACT_DIR/bootstrap-$role.conf"
  sudo install -m 644 "deploy/https/sites/zz-hyperline-$role.conf" /etc/nginx/sites-available/
done
sudo nginx -t
# Only after approving fresh-host activation:
sudo systemctl reload nginx
sudo certbot renew --dry-run
```

Use the actual certificate lineage paths and confirm SANs/expiry. Review a renewal deploy hook for a scoped graceful Nginx reload after config validation. Never use standalone Certbot by stopping a shared production Nginx, and never alter DNS as an implicit installation step.

## 6. Acceptance and rollback

```bash
dc ps
dc exec -T worker php docker/healthcheck.php worker
dc exec -T scheduler php docker/healthcheck.php scheduler
curl --fail --show-error https://api.zentunnel.net/api/v1/health
curl --fail --show-error -o /dev/null https://ott.zentunnel.net/
curl --fail --show-error -o /dev/null https://cms.zentunnel.net/
curl --silent --show-error -o /dev/null -w '%{http_code}\n' https://api.zentunnel.net/api/v1/me
curl --fail --show-error https://api.zentunnel.net/api/v1/series
curl --silent --show-error -D - -o /dev/null -X OPTIONS \
  -H 'Origin: https://ott.zentunnel.net' \
  -H 'Access-Control-Request-Method: POST' \
  -H 'Access-Control-Request-Headers: authorization,content-type' \
  https://api.zentunnel.net/api/v1/playback/sessions
```

Require API/Web/CMS 200, anonymous `/me` 401, exact allowed CORS origin and credential headers, and no allow-origin for an untrusted origin. Check dependency readiness separately: API `/health` is liveness only. Use only existing approved disposable accounts/content for authenticated RBAC, series/season/episode ordering, progress/resume and Continue Watching acceptance. Under current CDN gates, protected creation/renewal must fail with 503 and disclose no unsigned URL or key. Do not create administrators, publish/import content or enable CDN flags for acceptance convenience. An empty install needs separately authorized test identities before authenticated acceptance can complete.

For an **approved existing-install upgrade**, capture an operator-reviewed private `rollback.compose.json` **before** replacing containers. It must pin the previous API/Web/CMS/worker/scheduler IDs and their runtime environment/commands, preserving APP_KEY, credentials, mounts and the original Compose project/base configuration. Keep the expanded database schema; old API movie/auth/progress compatibility was tested on it. Example scoped application rollback after writers are safely quiesced:

```bash
: "${ROLLBACK_OVERRIDE:?Reviewed private override captured before upgrade}"
rollback() {
  docker compose --project-directory "$RELEASE_DIR" --env-file "$RELEASE_DIR/.env" \
    -p hyperline -f "$RELEASE_DIR/compose.yaml" -f "$ROLLBACK_OVERRIDE" "$@"
}
rollback up -d --no-deps --no-build --pull never --wait --wait-timeout 180 api web cms
# Check upstream IPs and Host routes; reload internal gateway only if necessary/approved.
rollback up -d --no-deps --no-build --pull never --wait --wait-timeout 240 worker scheduler
rollback ps
```

Use the original reviewed base Compose if its service topology differs from this release. Recheck health, auth, CORS, movies and fail-closed behavior against captured IDs. A fresh install has no prior release to roll back to; stop only its new application roles and preserve its data for investigation. Database recovery is a separately approved restore into a **new database**, with original data preserved and post-backup writes reconciled before any switch. Do not use `down -v`, volume deletion, database refresh/reset, forced Git updates, `git reset --hard`, or destructive migration rollback.
