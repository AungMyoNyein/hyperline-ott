# HyperLine Phase 2 source release

Repository: **https://github.com/AungMyoNyein/hyperline-ott**. This is one monorepo, with Laravel in `services/api`, OTT Web in `apps/web` and CMS in `apps/cms`. The verified existing default branch is `master`; its head before publication was `6e9a6986f60e28c4b2636963a1fd34b0aae057d4`. That parent already contains the Phase 2 implementation. The release commit adds the reviewed frontend security override and installation/release documentation. The annotated release tag is **`v2.0.0-phase2`**; resolve it to obtain the full published commit SHA, also recorded in the GitHub release notes.

The release workflow runs on **157.85.104.49**. Older local preparation documents describe superseded host-selection decisions; they do not govern this GitHub source release. No production deployment is performed by publishing Git refs or release notes. No repository Actions workflow or active local Git deployment hook was present during review.

## Security review and intended commit contents

Both frontend manifests use the scoped override `"next": { "postcss": "8.5.23" }`. Next remains **15.5.29**. The only changed package record in either lockfile is PostCSS, upgraded from 8.4.31 to 8.5.23; other package versions and the lockfile package sets are unchanged. Fresh dependency-tree checks, production/full audits and production builds accept the override. No forced audit fix or major framework upgrade was used.

The commit includes `.gitignore`, the four dependency files, this release review, the Ubuntu installation runbook and a sanitized public source manifest. The ignore rules exclude environments, local agent/cloud state, installed dependencies, frontend build outputs, private key formats, database dumps, local SQLite databases, logs and generated test reports. The existing `.env.example` contains placeholders and no production key or credential.

Local inventory reports, preparation tools, private rollback environment, database backups, runtime logs, Docker volumes and the private `deploy/releases/` packets are **not staged or published**. Publication uses an explicit file allowlist. A high-confidence token/private-key scan and an exact search for known production secret values are run locally without printing those values. GitHub's public destination and remote head are checked again before the non-forced push.

## Final validation

| Check | Result |
| --- | --- |
| Full Laravel suite | **PASS — 281 tests, 1,272 assertions**, forced disposable in-memory SQLite |
| Dedicated existing-CDN fail-closed suite | **PASS — 9 tests, 35 assertions**; these cases are also included in the full suite |
| Web/CMS TypeScript checks | **PASS** |
| Web/CMS production builds | **PASS**, existing CSS/autoprefixer warnings |
| Web/CMS dependency-tree checks | **PASS**, Next 15.5.29 and overridden PostCSS 8.5.23 |
| Web/CMS production audits | **PASS**, zero critical/high/moderate/low/info findings in each |
| Web/CMS full audits including dev packages | **PASS**, zero findings in each |
| Signed-fixture browser integration | **PASS — 14 passed, 1 skipped** |
| Base/candidate/rollback Compose validation | **PASS** |
| Migration checksums | **PASS — all 13 files match the candidate image/manifest; 4 remain pending in production** |
| Production container/environment/migration preservation | **PASS**, original IDs, image IDs, start times, mounts and `.env` checksum retained |
| Isolated PostgreSQL compatibility/restore | Earlier security-candidate validation **PASS**; no backend/schema source changes since that validation |

The browser skip is the separate Nginx CDN-fixture spec, which requires its own harness. Its earlier passing evidence remains local; that harness was not rerun in this final source-release pass. It is not a suppressed test failure. Fresh production builds and all enabled browser tests completed successfully. Test logs and publication receipts remain in an ignored private packet; the public manifest contains sanitized results.

The Laravel suite unsets an ambient `CORS_ALLOWED_ORIGINS` value so tests can exercise their own CORS configuration. Forced SQLite/array cache, generated test keys and the isolated browser harness prevent tests from using production data or replacing deployed frontend assets.

## Manifest and installation decision

Public manifest: [docs/releases/phase2-source-manifest.json](releases/phase2-source-manifest.json). It records the base revision, exact dependency patch digest, release tag, source/migration/Dockerfile checksums, prebuilt immutable candidate image IDs and actual validation results. The prepared images were built before the Git release commit from the same application bytes; their Git labels still identify the base revision and their extra labels identify the frozen security patch. They are local images, not registry publications. Fresh installation builds must record and validate their own IDs.

Installation runbook: [INSTALL-UBUNTU-24.04.md](INSTALL-UBUNTU-24.04.md). It uses the verified clone URL and exact release tag, documents secure configuration, fresh database/restoration paths, Redis, private gateway routing, HTTPS, migrations, health checks and application/database recovery.

**GO for the tested source release. NO-GO for installation execution within this task:** deployment/migrations/Nginx/TLS changes remain separately authorized operational actions and require current target-specific preflight and a refreshed verified backup. The source has no remaining mandatory-test or dependency-audit failure. Protected playback activation remains **NO-GO** until the actual CDN protocol/adapter, credentials, nested-resource authorization, CORS and renewal pass separate validation. Preserve HTTP 503 without unsigned URL/key disclosure. Real CDN playback, physical Safari/iOS/mobile devices and production load are not proven by fixture tests. YouTube remains a public-embed integration; no caching/redistribution or YouTube progress/resume is claimed.

No production accounts or content, database schema, application containers, host Nginx/TLS, DNS, VPN, VOD origin or external CDN were changed by this source release workflow.
