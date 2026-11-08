# HyperLine OTT — Qwen Code Implementation Prompt

You are the lead full-stack developer and DevOps engineer for HyperLine OTT. Implement working code in the attached `hyperline-ott/` project, not just an architecture proposal. Treat the existing files as an **unverified scaffold** and inspect every file before editing.

## Production context
- Ubuntu host: `157.85.104.49` (user-supplied; do not assume SSH access).
- Existing production VPN CRM/API uses `zentunnel.net`. **Do not disrupt or replace its Nginx, containers, databases, or certificates.**
- HyperLine domains: `ott.zentunnel.net`, `cms.zentunnel.net`, `api.zentunnel.net`.
- Project directory: `/opt/hyperline-ott`.
- HyperLine Docker gateway must bind only `127.0.0.1:18080` on the host. Existing host reverse proxy should route the three subdomains by Host header.
- Existing external HLS CDN (do not build origin/CDN): `https://cdn.goldendragon2000.com/vod/Hollywood_Movies/2024/May/Week4/The_Wolf_of_Wall_Street_2013_1/playlist.m3u8`. This is a **sample supplied URL only**. Check playback and distribution authorization before publishing; never download, mirror, or republish without rights.
- Branding: use `apps/web/public/hl-logo.png` and `apps/cms/public/hl-logo.png`; cinematic dark UI with `#080808`, `#141414`, `#FF0808`, white text.

## Required stack
- Next.js + React + TypeScript + Tailwind CSS for OTT Web and Admin CMS.
- Laravel 12 + PHP 8.3+ + Sanctum REST API.
- PostgreSQL 16, Redis 7, Nginx, Docker Compose V2.
- HLS.js browser player with native HLS fallback.

## Inspect first, no destructive actions
1. Inspect project tree, README, `.env.example`, `compose.yaml`, all Dockerfiles, Nginx configs, Laravel overlay, frontend source, and current tests.
2. Run read-only checks where available: `pwd`, `ls -la`, `docker compose config`, `docker ps`, `ss -tulpn`, `nginx -t`, DNS and certificate inspection. Never reveal secrets in reports.
3. Identify blockers, broken syntax, unsafe defaults, incomplete implementations, and compatibility issues. The source scaffold is NOT guaranteed to build. In particular validate Redis command quoting, shared Laravel public volume behavior, PHP-FPM/FastCGI configuration, application key handling, and Docker startup dependencies.
4. Present a short gap report, then implement in stages. Ask approval before changes to production host Nginx, public DNS, certificates, running Zentunnel services, or production data.

## Implement full Phase 1
### Laravel API and database
- Create a complete Laravel app, not only overlay files; pin dependencies and commit lockfiles.
- Migrations/models/indexes/foreign keys for users, roles/permissions, movies, genres, movie_genre, video_sources, watchlists, watch_progress, playback_sessions, admin_audit_logs.
- Public API: `/api/v1/health`, `/home`, `/movies`, `/movies/{slug}`, `/genres`, `/search`.
- User API: `/me`, `/watchlist`, `/watch-progress`, `/playback/sessions`.
- Admin API: secure login/logout, dashboard, paginated movie CRUD, publishing, metadata and source management.
- Use Sanctum, policies, validation, rate limiting, safe errors, server-side entitlement checks. Do not expose permanent unrestricted private CDN URLs.
- Validate CDN URLs safely; avoid SSRF and private IP fetches. Add automated tests for authentication, authorization, validation, CRUD, and playback entitlement.

### Web OTT
- Responsive Black + Red design using the supplied logo.
- Home hero, featured and trending rows, Movies catalog, search/filter, movie details, watch page, login/profile, watchlist, continue watching.
- Integrate actual API; do not leave mock data in production.
- HLS.js adaptive playback, cleanup on unmount, loading/error states, native HLS fallback, quality/subtitles when supported.

### CMS
- Secure admin login and protected routes.
- Movie CRUD with title, slug, synopsis, year, duration, poster/backdrop, genre, HLS URL, publish state.
- Admin dashboard, audit history, validation and actionable errors.
- Use secure session handling; avoid storing long-lived admin bearer tokens in localStorage.

### Docker and Nginx
- Working Compose services: gateway, web, cms, api, worker, scheduler, postgres, redis.
- Use health checks, restart policies, non-root where practical, pinned versions, persistent volumes, backup plan, `.env.example` with no real secrets.
- No public DB/Redis ports. Keep gateway bound to `127.0.0.1:18080`.
- Host Nginx: separate `ott`, `cms`, `api` virtual hosts with HTTPS and forwarded headers, without modifying `zentunnel.net` existing host routing.
- Use FastCGI for PHP-FPM and proxy_pass only for HTTP services. Verify certificates, trusted proxies, cookies, CORS, CSRF and security headers.
- Do not run destructive commands (`down -v`, `migrate:fresh`, pruning volumes, deleting databases).

### CDN integration
- Browser fetches `.m3u8` manifests and segments directly from existing CDN; API handles metadata and authorized playback sessions.
- Verify HTTPS, manifest and segment accessibility, CORS for `https://ott.zentunnel.net`, MIME types, byte ranges and redirects.
- Do not bypass browser CORS or introduce an open video proxy.
- For protected videos, design signed playback access that covers manifests, segments, and keys; document CDN changes required.

## Execution and verification
- Work in order: audit -> Laravel/models/migrations -> API tests -> CMS -> Web/player -> Docker builds -> isolated local tests -> Nginx/TLS plan -> approval for production changes.
- Run PHP syntax/tests, frontend typecheck/build, `docker compose config`, Docker image builds, migrations on disposable test DB, curl health/API checks, and browser playback tests where possible.
- Report exact PASS/FAIL/NOT RUN for every check; never claim a deployment or test succeeded without executing it.
- Produce complete source, updated README, `.env.example`, Docker/Nginx configs, backup and rollback instructions, and concise deployment commands.

## First commands
```bash
pwd
ls -la /opt/hyperline-ott
cd /opt/hyperline-ott && docker compose config
sudo docker ps
sudo ss -tulpn
sudo nginx -t
```
If files are not present, request the included ZIP be extracted first. Begin with a short inspection report and then implement the highest-priority missing code.
