> SUPERSEDED local experiment. Do not deploy or use as an existing-CDN requirement. See ../CDN-PLAYBACK.md for the current architecture.

# Local verification report

No production deployment or infrastructure modification was performed. Existing origin/CDN services, host Nginx configuration, DNS, certificates, Docker containers, database data and volumes were not changed. Test fixtures use separate temporary SQLite databases and original generated media. Test processes shut down after verification; ephemeral signing files are removed.

| Status | Check | Evidence / scope |
| --- | --- | --- |
| PASS | Laravel suite | 151 tests, 609 assertions; includes exact signing vector, issuance, owner-only renewal, entitlement/source/publication changes, HTTPS, expiry and maximum lifetime |
| PASS | Asset tooling | 3 Python tests; recursive local URI audit including keys/init and unusual playlist extensions; external/ambiguous references and unsafe map prefixes rejected |
| PASS | Web and CMS production builds | Next.js production compilation and type validation completed |
| PASS | Frontend typechecks | Both apps passed TypeScript checks |
| PASS | Existing browser integration | 4 Chromium tests: CMS CRUD/validation/publication/logout, catalog/mobile, generated HLS/fullscreen, negative CORS; dedicated CDN test skipped in this runner because it uses a different harness |
| PASS | CDN browser integration | 1 dedicated Chromium test: real local sibling HTTPS domains, Secure/HttpOnly/Lax/path cookie, credentials omitted=403/included=200, nested fMP4 HLS playback and automatic renewal |
| PASS | Nginx syntax | Isolated config generated from the new template, with temporary TLS paths and a local fixture upstream; `nginx -t` passed |
| PASS | Nginx activation | Closed gate returned 503 before the isolated test gate was opened |
| PASS | Nginx token enforcement | Missing, invalid digest, noncanonical base64, expired, cross-movie, traversal, encoded and query-bearing requests rejected; valid cookie accepted |
| PASS | Nested resources | Variant playlists, media fragments, initialization segments and encryption-key resources all require authorization |
| PASS | Shared cache | Two distinct sessions received HITs for the same object with one origin fetch; missing/expired cookies remained blocked after cache fill; viewer credentials were not forwarded |
| PASS | Range/CORS | Byte ranges returned 206; exact-origin credentialed preflight accepted; hostile Origin rejected |
| PASS | Docker Compose | `docker compose config --quiet` passed; no containers rebuilt/recreated by these tests |
| PASS | Formatting / whitespace | Targeted PHP Pint and `git diff --check` passed |
| FAIL | Current final checks | None |
| NOT TESTED | Production DNS/TLS | New hostname/certificate are deployment prerequisites; none provisioned here |
| NOT TESTED | Production Nginx configuration | Actual existing full configuration, inherited directives and operator-specific origin ACL require approved review; no system `nginx -t`/reload was performed |
| NOT TESTED | Live origin manifests/rights | No third-party manifest, segment or key was downloaded; self-contained asset closure and content authorization require operator verification |
| NOT TESTED | Real secret distribution | No production signing secret was generated, read or installed |
| NOT TESTED | Live CDN CORS/cookies/cache | Local behavior verified; real hostname behavior requires an approved canary |
| NOT TESTED | Safari/iOS | Native HLS fallback requires actual device testing |

Implementation commands and exact production requirements are in [CDN-PLAYBACK.md](CDN-PLAYBACK.md). The production environment example keeps `OTT_NGINX_CDN_VERIFIED=false`; the Nginx deployment instructions require `gate.conf` to start at 0. The local harness opens its own test gates only. No production activation follows from this report.

Important review decisions: parent-domain cookie trust covers sibling Zentunnel hosts; offline secure_link cannot immediately revoke an issued cookie; all approved manifest URI references must remain relative inside the allowlisted directory; the original CDN's existing access controls must remain intact.
