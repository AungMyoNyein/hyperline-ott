# HyperLine OTT Phase 1 — integrated source scaffold

**IMPORTANT: No deployment was performed on 157.85.104.49.** The domain zentunnel.net is already used by another service. This stack binds ONLY 127.0.0.1:18080 and must be attached to the **existing** reverse proxy using the new subdomains ott.zentunnel.net, cms.zentunnel.net, api.zentunnel.net. Do not replace the existing zentunnel.net virtual host or bind Docker directly to 80/443.

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

## CDN sample
Do not automatically publish the provided third-party video URL. Confirm rights and CDN authorization, then add the HLS URL in CMS. Browser HLS requires CORS on manifests and all segments. The app does not download, cache or rehost CDN video.

## Scope and limitations
Implemented: Laravel 12 source overlays (generated at Docker build), PostgreSQL movie migration, Sanctum bearer login for admin, RBAC gate, movie CRUD, public movie listing and HLS playback URL, CMS editor, Next.js catalog and HLS.js player. Laravel dependencies are downloaded during Docker build; Internet access required. No build or runtime integration test has been performed here. **Not yet production-ready**: subscriber auth/entitlements, signed CDN URLs, detailed audit logs, TV series, EPG, subscription billing, refresh/revocation UX, backup automation, observability, security review, and production load tests. Public playback endpoint currently reveals CDN URL for published content. Restrict publishing to authorized/public streams until entitlement protection is added. Browser CMS bearer token is kept in memory and lost on refresh; do not use shared/public devices.

## Backups
Back up PostgreSQL with `docker compose exec -T postgres pg_dump -U hyperline hyperline > hyperline-$(date +%F).sql` and store encrypted off-server. Also back up `.env` securely. Test restoration separately.
