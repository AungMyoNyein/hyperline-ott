# Existing CDN verification — current application architecture

No additional CDN or origin is required or deployed. No production video configuration, service, secret, DNS record, certificate, Docker volume or database data was changed. Zentunnel VPN services were left running. Historical same-site-cookie tests do not establish anything about the existing external CDN's protocol.

| Status | Check | Current evidence |
| --- | --- | --- |
| PASS | Laravel suite | 160 tests / 644 assertions, including CMS-source-to-existing-host transport, path preservation, renewal, failed gates, unknown protocol, and invalid authorizer responses |
| PASS | Production frontend builds | Web and CMS compiled and passed build-time type validation using only local fixture API addresses |
| PASS | Browser application tests | 5 passed: CMS CRUD/validation/publishing/logout; catalog/filters/mobile; generated HLS/fullscreen; negative CORS; signed-URL refresh with preserved position |
| NOT RUN | Superseded same-site cookie browser test | Intentionally skipped in the current application runner; not a prerequisite for the existing external CDN |
| PASS | Existing HLS URL metadata | HTTPS HEAD 200; HLS MIME type; anonymous wildcard CORS; Accept-Ranges bytes |
| PASS | Existing HLS URL browser metadata | Anonymous HEAD from locally intercepted exact OTT origin returned 200 with browser CORS enforcement enabled; no media or playlist body fetched |
| FAIL | Preflight probe on sample path | OPTIONS for GET/Range returned 403 without allow-origin. Preflight-dependent access is not verified; this does not mean every anonymous HLS GET requires preflight. |
| FAIL | Credentialed CORS compatibility on observed response | Wildcard allow-origin and no allow-credentials cannot authorize credentialed browser requests. This observation does not establish whether the CDN has another cookie/token endpoint. |
| UNKNOWN / BLOCKED | Existing CDN signed URLs/cookies/other controls | No operator protocol/configuration supplied; Nginx headers are insufficient. Production stays gated. |
| NOT TESTED | Live HLS GET playback and nested resources | Await a rights-approved test asset and actual access protocol; no live playlist body, segment, init segment or key downloaded |
| PASS | Docker Compose validation | `docker compose config --quiet` passed; no containers recreated |
| PASS | Running API/PostgreSQL/Redis | HyperLine-only read-only status inspection reports healthy |
| PASS | PostgreSQL migration status | All current migrations applied; no writes/reset/fresh migration performed |
| FAIL | Worker/scheduler health status | Existing CLI services inherit a PHP-FPM listener probe and report unhealthy; operational readiness requires service-specific probes |
| NOT TESTED | Application production rollout | Running images were not rebuilt/recreated; release builds require the production API address and normal application rollout review |
| NOT TESTED | Native HLS/device/soak behavior | Safari/iOS and production load coverage remain outstanding |

The generated-media fixture signer is guarded to APP_ENV=testing and a fixed disposable SQLite database, lives only in test source, and is excluded from the production API image. Its query markers are not presented as the existing CDN's algorithm. The actual existing-CDN bridge refuses production authorizations until both verification and the operator-specific adapter are configured.

Current rollout requirements: [CDN-PLAYBACK.md](CDN-PLAYBACK.md), [DEPLOYMENT-BLOCKERS.md](DEPLOYMENT-BLOCKERS.md), and [application-only environment](../deploy/existing-cdn.env.example).
