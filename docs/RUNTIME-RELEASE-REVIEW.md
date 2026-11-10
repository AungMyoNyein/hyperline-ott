# Runtime release review — 2026-11-09

**NO-GO for production activation now.** Fixes and images are ready for review; no production container was restarted/recreated, no host configuration installed/reloaded, and no certificate issued. Separate explicit approval is required for application rollout and HTTPS operations.

## Findings and fixes

The running API attempted Laravel's missing `login` route when an unauthenticated request omitted JSON Accept, producing `Route [login] not defined` and HTTP 500. API requests now suppress the browser redirect and use the existing JSON authentication exception handler. Sanctum, role checks, entitlements and bearer authentication remain unchanged. Regression coverage includes missing Accept, JSON Accept and HTML Accept: 401 unauthenticated, 403 forbidden, 422 validation, opaque 500 even with debug enabled. API 5xx responses omit exception messages, paths and traces; reporting remains server-side. Retry-After is preserved where supplied.

Laravel API CORS now defaults to credentials enabled with explicit OTT/CMS origins. Configuration removes wildcard/non-origin entries, allows Authorization/Content-Type preflight, and exposes only existing rate-limit/retry headers. Tests cover actual errors as well as OPTIONS; no auth token or login cookie is added. The release override pins the two HTTPS origins and credentials setting. This configuration does not authorize browser access to the external video CDN and does not alter CDN CORS.

Worker/scheduler source uses role-specific heartbeat, process and dependency probes; worker graceful-stop allowance exceeds its 90-second job timeout. Scheduler uses the supervised heartbeat runner. Production image contains pcntl and pdo_pgsql. Build context excludes local SQLite databases, storage data, tests and environment files. APP_KEY and production environment files were preserved.

## Prepared images and persistent storage

Release: `runtime-20261109T003915Z`. See `deploy/releases/runtime-20261109T003915Z/manifest.json` for immutable new/old image IDs, container IDs, start times, restart policies and mounts. Build logs are in that directory. Only API, Web and CMS were built; worker/scheduler share the API image. No gateway/database/cache image was rebuilt.

Affected rollout containers: `hyperline-api-1`, `hyperline-web-1`, `hyperline-cms-1`, `hyperline-worker-1`, `hyperline-scheduler-1`.

Keep named volumes `hyperline_pgdata`, `hyperline_redisdata`, `hyperline_api_storage`, `hyperline_api_public` and the existing project name `hyperline`. Gateway remains bound to `127.0.0.1:18080`. PostgreSQL/Redis and existing APP_KEY/credentials are unchanged. No migration is required by this runtime correction; no production schema or data mutation was performed.

The captured legacy API predates signer enforcement. **Never restore its raw rollback tag into service.** The rollback override references `hyperline-api:rollback-guarded-runtime-20261109T003915Z`: the legacy image plus API error/CORS fixes and a playback controller returning 503 after authentication/validation. This fallback never issues a playback URL. It passed the same isolated runtime contract probe. Original worker/scheduler commands and health definitions are captured; final preflight recovery uses worker120s/scheduler75s stop allowances; full rollback restores their known unhealthy legacy probes. Prefer retaining the secure new API when only a frontend rollback is needed. The capture helper deliberately emits an unusable API placeholder until a guarded rollback is prepared.

## Verification

| Result | Check | Evidence/limit |
| --- | --- | --- |
| PASS | Laravel suite | 178 tests, 780 assertions |
| PASS | API contract/CORS regressions | Missing/JSON/HTML Accept; 401/403/422/opaque500; exact-origin credentialed preflight/actual errors; hostile-origin rejection |
| PASS | New API production image | Isolated network-disabled container, disposable SQLite, no production mounts; extensions, error/CORS contracts and authenticated valid-movie fail-closed 503 |
| PASS | Guarded rollback API image | Same isolated contracts; playback unavailable, no unsigned source disclosure |
| PASS | Frontend production builds | Web and CMS host builds and release Docker builds |
| PASS | TypeScript | Web and CMS `npm run typecheck` |
| PASS | Browser integration | 5 passed; 1 superseded same-site CDN cookie scenario intentionally skipped; generated authorized local HLS fixtures |
| PASS | Worker/scheduler replacement probes | Actual local worker/scheduler, disposable SQLite and isolated Redis; stopped worker and Redis outage correctly fail |
| PASS | Compose | Base, release override and guarded rollback override validate; rollback dollar signs escaped to preserve PHP health commands |
| PASS | Staged HTTPS integration | Trusted temporary test SAN, ACME/308, real Host-routed pages/assets/API health; mock two-hop Upgrade 101/streaming; syntax alongside actual read-only host sites |
| PASS | Current dependencies/API health | PostgreSQL/Redis healthy; `/api/v1/health` 200 |
| PASS | Production preservation | All 8 container IDs/images/start times/restart policies unchanged; protected systemd services active; Zentunnel vhost SHA256 unchanged |
| FAIL | Current deployed API | Bare auth request still 500; current credentialed CORS lacks Allow-Credentials. Source/image fixes await approved recreation |
| FAIL | Current deployed CLI health | Legacy worker/scheduler FPM probes unhealthy; fixes await approved recreation |
| FAIL | Public HTTPS readiness | Existing certificate covers zentunnel.net only; HyperLine SAN issuance and vhost installation pending |
| NOT TESTED | New images against production DB/Redis | Requires approved rollout; isolated tests do not prove production runtime configuration |
| NOT TESTED | Real public HTTPS/browser credentials | New certificate/vhosts not activated; local API/browser and staged TLS coverage only |
| NOT TESTED | Actual application WebSocket endpoint | Mock transport passed; current live gateway lacks two-hop Upgrade support; optional staged change requires separate approval |
| NOT TESTED | Live WG/VLESS client continuity, forwarding/DNS | Service states are not proof of client traffic; operator client checks required before/during/after activation |
| NOT TESTED | Production CDN authorization/playback/renewal | Real access-control protocol and credentials unverified; production signer gates remain false |

The existing CDN is cross-site to zentunnel.net. Observed anonymous manifest HEAD accessibility and wildcard CORS do not establish authorization or credential compatibility; the earlier OPTIONS returned 403. No actual media was downloaded. A verified existing-CDN authorization adapter and nested-resource/browser renewal checks remain playback deployment blockers. Do not invent credentials, enable verified flags, reuse the superseded same-site cookie design, require another CDN hostname, or weaken fail-closed behavior.

Activation and rollback commands are in [ACTIVATION-SEQUENCE.md](ACTIVATION-SEQUENCE.md); host HTTPS phase commands remain in [../deploy/https/OPERATIONS.md](../deploy/https/OPERATIONS.md).

Final activation preflight: [RELEASE-ACTIVATION-PREFLIGHT.md](RELEASE-ACTIVATION-PREFLIGHT.md). Immutable images and live DB/Redis/migration compatibility reverified; fresh activation backup/isolated restore have since passed; see [BACKUP-RECOVERY-VERIFICATION.md](BACKUP-RECOVERY-VERIFICATION.md). Production activation still requires approval. No production changes were executed.
