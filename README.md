# HyperLine OTT Phase 1

**IMPORTANT: Preserve existing production Zentunnel VPN, CDN and origin services.** The domain zentunnel.net is already used by another service. This stack binds ONLY 127.0.0.1:18080 and must be attached to the **existing** reverse proxy using the new subdomains ott.zentunnel.net, cms.zentunnel.net, api.zentunnel.net. Do not replace the existing zentunnel.net virtual host or bind Docker directly to 80/443.

## Prerequisites
Ubuntu 24.04, Docker Engine + Compose V2, working DNS A records for the three subdomains pointing to 157.85.104.49, existing host reverse proxy with HTTPS and certificates. The existing CDN must allow CORS for https://ott.zentunnel.net.

## Setup
```bash
sudo mkdir -p /opt/hyperline-ott
# unzip this archive so /opt/hyperline-ott/compose.yaml exists
cd /opt/hyperline-ott
cp .env.example .env
chmod 600 .env
# Edit .env: set DB_PASSWORD and POSTGRES_PASSWORD to the SAME strong random password;
# set REDIS_PASSWORD separately; generate APP_KEY below; set initial admin password.
python3 - <<'PY2'
import base64,secrets
print('APP_KEY=base64:'+base64.b64encode(secrets.token_bytes(32)).decode())
print('DB/POSTGRES_PASSWORD='+secrets.token_urlsafe(32))
print('REDIS_PASSWORD='+secrets.token_urlsafe(32))
print('HYPERLINE_INITIAL_ADMIN_PASSWORD='+secrets.token_urlsafe(32))
PY2
sudo docker compose config --quiet
sudo docker compose build
sudo docker compose up -d
sudo docker compose exec api php artisan migrate --force
sudo docker compose exec api php artisan hyperline:admin admin@YOUR_DOMAIN 'HyperLine Admin'
# After initial admin creation remove HYPERLINE_INITIAL_ADMIN_PASSWORD from .env and recreate api/worker/scheduler containers.
sudo docker compose ps
sudo docker compose logs --tail=100 api gateway
```

## Existing reverse proxy / TLS
`deploy/host-nginx.conf` provides HTTP routing. Preserve the existing zentunnel.net server and TLS certificates. Add three new virtual hosts and issue certificates (for example `sudo certbot --nginx -d ott.zentunnel.net -d cms.zentunnel.net -d api.zentunnel.net`) only after DNS and host Nginx configuration are correct. Run `sudo nginx -t && sudo systemctl reload nginx` after changes. The external proxy MUST forward the original Host header.

## Test
```bash
curl -i -H 'Host: api.zentunnel.net' http://127.0.0.1:18080/api/v1/health
curl -i https://api.zentunnel.net/api/v1/movies
curl -I -H 'Origin: https://ott.zentunnel.net' 'https://cdn.goldendragon2000.com/vod/Hollywood_Movies/2024/May/Week4/The_Wolf_of_Wall_Street_2013_1/playlist.m3u8'
```

## Existing video infrastructure

Use the existing `https://cdn.goldendragon2000.com` HLS URLs through the Admin CMS. No additional CDN, origin, virtual host or CDN DNS alias is required. The prior same-site cookie/new-host proposal is superseded and must not be deployed. Production playback remains fail-closed pending confirmation of the existing CDN's actual access protocol. See [current integration](docs/CDN-PLAYBACK.md), [application environment template](deploy/existing-cdn.env.example) and [remaining blockers](docs/DEPLOYMENT-BLOCKERS.md).

## CDN sample
Do not automatically publish the provided third-party video URL. Confirm rights and CDN authorization, then add the HLS URL in CMS. Browser HLS requires CORS on manifests and all segments. The app does not download, cache or rehost CDN video.

## Phase 1 completion

See [implementation and architecture decisions](docs/PHASE-1-IMPLEMENTATION.md) and [current validation](docs/PHASE-1-VALIDATION.md). My List, throttled movie progress/resume/completion, CMS filters/inline validation/unsaved protection/audit history and bounded token lifetimes extend the existing stack. HLS and MP4 are eligible for playback; DASH metadata is preserved and marked unsupported. Later phases remain separate.

## Scope and limitations

Phase 1 includes a Laravel 12 API with Sanctum bearer authentication, RBAC, PostgreSQL migrations, admin movie/source CRUD, audit logs, public catalog/search, watchlist/progress and entitlement checks. The Next.js CMS integrates dashboard, authentication, movie editing, publishing, genre assignment and source management. The OTT web integrates home sections, catalog/search/filters, movie details, registration/login and an authenticated HLS player using the supplied logo and a responsive Black + Red theme.

Protected production playback requires a CDN signing provider. No signing credentials or algorithm are assumed: without a configured provider the updated API returns 503 and never falls back to an unsigned origin. Existing running Docker images are not automatically updated by these source changes. Native HLS device testing, actual CDN authorization/CORS, billing, backup automation, observability and production load testing remain outstanding. Genre creation/deletion has no API support; CMS assigns existing genres. Tokens remain in React memory and are lost on refresh.

See [frontend integration and browser tests](docs/FRONTEND-INTEGRATION.md) and [CDN signing contract](docs/CDN-PLAYBACK.md). Isolated browser tests generate original test-pattern media and use a dedicated temporary SQLite database. Source builds and tests do not modify existing production Zentunnel services or Docker volumes.

## Backups
Back up PostgreSQL with `docker compose exec -T postgres pg_dump -U hyperline hyperline > hyperline-$(date +%F).sql` and store encrypted off-server. Also back up `.env` securely. Test restoration separately.

## Deployment readiness and scoped maintenance review

See [readiness results](docs/DEPLOYMENT-READINESS-RESULTS.md), [Zentunnel web/admin maintenance proposal](docs/ZENTUNNEL-SHUTDOWN-REVIEW.md) and [HyperLine deployment plan](docs/HYPERLINE-DEPLOYMENT-PLAN.md). No production shutdown, Nginx change or application deployment is performed automatically. The shared VPN/device API remains running; only website/admin maintenance is proposed.

## HTTPS staging review

DNS now resolves the three application names. Separate per-host HTTPS/ACME staging files are in [deploy/https](deploy/https/README.md); see [HTTPS review](docs/HYPERLINE-HTTPS-REVIEW.md) and [approval-only operations](deploy/https/OPERATIONS.md). No installation, certificate issuance or production reload has been performed.
