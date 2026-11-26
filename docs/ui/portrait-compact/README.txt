HyperLine OTT: compact Spotlight and portrait poster candidate

Baseline: deployed PR #4, ca37ed304459c565fabe25c90545152d8ec564d3.
Branch: feature/compact-portrait-posters (stacked on feat/genre-cms-management while PR #4 remains open).
Application source commit: e66a301325b6462cc0b74132d4b13f158eee9880.
Status: candidate preparation only. No UAT activation is authorized or performed by these files.

Layout
Measured at 360 / 390 / 768 / 1366 / 1920 pixels:
  Spotlight height: 220 / 220 / 260 / 300 / 300 pixels.
  Previously:       320 / 320 / 380 / 460 / 460 pixels.
  Catalog columns:  2 / 2 / 4 / 5 / 6.
The hero is the homepage Spotlight; there is no second independent Spotlight component. Its decorative bottom label is hidden and the following introduction strip is shorter, reducing the total top-of-page area rather than moving whitespace elsewhere. Buttons retain a 44px touch target. Titles wrap without truncation; unusually long content may expand the minimum-height hero rather than clip or overlap. The long Myanmar title regression verifies safe text/action bounds.

Artwork
All reusable movie cards, genre-filtered/search catalogs, home rails, movie resume cards and My List use 2:3 poster frames. Series cards also use portrait frames; episode stills and existing immersive landscape backdrops keep their separate semantics. The baseline has no separate Related Movies component; no recommendation API or unrelated feature was added. Shared Cards already supplies the poster behavior to any movie collection using it.
Dedicated poster_url is preferred. Only when absent, the existing backdrop can be displayed as a browser-only fallback; no record is populated or overwritten. Landscape sources are detected by natural dimensions (known YouTube previews are identified immediately), shown intact with contain inside a branded gradient frame and explicitly labeled Landscape preview. No text/face cropping, image stretching, artificial poster generation, application image proxy or image rehosting is introduced. Loading reserves geometry; failed images use the existing branded fallback. Original image files remain at their configured origins. Burmese titles wrap visibly outside the image; decorative preview labels are hidden from assistive technology. Watch remains available for playable titles, Details remains available, and My List retains Remove.

CMS
Existing separate poster_url and backdrop_url fields are retained and labeled Portrait poster HTTPS URL · 2:3 and Landscape backdrop HTTPS URL · 16:9. Both previews preserve the whole image. Missing/unavailable/landscape-only poster sources are flagged for manual portrait review. Existing portrait assets outside the target ratio receive a distinct ratio-review message, not a false claim that they are missing. No URL is automatically changed or saved.
Read-only audit: 22 movie records lack genuine portrait artwork; four have portrait assets requiring a 2:3 ratio review. See missing-portrait-artwork.json for every record, title, reason and browser-observed dimensions where available. These are manual editorial review items, not generated replacement assets.

Evidence and privacy
comparisons/ contains before/after home and catalog screenshots at all five requested widths, using the identical synthetic artwork/data fixture and actual baseline/candidate production Web images. No third-party poster file is redistributed in this public evidence. The original visual regression snapshots under docs/ui/screenshots are updated for the intentional design change.
Real UAT catalog before/after screenshots were also captured read-only and are retained privately under the release directory's uat-screenshots/ folder. They are not committed because third-party artwork redistribution permission is unverified. The public before/after measurements and separate fixture measurements record actual dimensions and lack of page overflow. Browser tests exercise the standard 2:3 frame, mixed landscape/portrait/error/loading cases, title wrapping, navigation, search, genres, My List and CMS review behavior; accessibility scans cover home/catalog and the existing CMS regression.

Immutable candidate and rollback
Web: sha256:78d8b736e6b43db61bda002922bba32191c4a9b4b56d8c82d2b456501c2fcb1c
CMS: sha256:1d5ccc789aede1407326aea20966364831d4bad90eed631a4dd1d29766cb3a79
API / Worker / Scheduler reused unchanged: sha256:db69c7011b71b78cab7ccfe02a7ea4a6a7721ac99567632dd8895b05995fbaa6
Images are local on the UAT host, not registry-pushed. release-manifest.json binds the source archive SHA-256, complete frontend Git trees, immutable dependency bases and original running identities. Package/package-lock bytes were checked against the deployed dependency image before rebuilding all frontend production output. This is not reuse of stale compiled assets. The source commit predates evidence-only commits; frontend tree equality must be checked against the PR head.
candidate.compose.json and rollback.compose.json are immutable-ID overrides, not standalone runtime configuration. They retain the reviewed frontend address reservations; future activation must verify actual endpoint assignments, service discovery and effective gateway routing, never assume address reuse. Only Web/CMS change; no API or database migration is needed. Original Web/CMS rollback IDs remain present locally.
Release directory: /opt/hyperline-ott/genre-cms/deploy/releases/portrait-compact-20261126T163444Z-e66a301325b6.

Safety boundary
Tests build and seed only uniquely named disposable SQLite fixtures; mocked genre/CMS writes never reach UAT. All live inspection and capture is read-only. The eight UAT containers must retain their IDs, images and start times through preparation, and catalog-before/catalog-after hashes must match. No publishing, source URL change, genre assignment, database reset, CDN/origin change, playback-authorization change, volume operation or unrelated infrastructure modification is included.
Deployment readiness is preparation/review readiness only. Stop before activation. Any later activation requires a separate scoped operator decision, fresh runtime/authentication/capacity/data gates and the reviewed state-aware maintenance/recovery procedure; these overrides do not authorize docker compose up against UAT.

Validation result files
Final result: 75 full browser regressions passed, three existing opt-in cases skipped; 19 exact candidate-image browser checks passed. Web/CMS production builds, configured lint/TypeScript and browser-test TypeScript checks passed. All eight live container identities/images/start times and all eight catalog fingerprints are unchanged; live schema remains at 14 migrations. Candidate and rollback Compose inputs validate without activation.
web-lint.txt / cms-lint.txt: TypeScript and configured formatting/lint.
browser-regression.txt: clean full regression run against isolated source production builds; three opt-in external/CDN/import cases are explicitly skipped. Production build logs are provided separately. Initial incomplete mock payload/scope failures were corrected in tests and the entire suite rerun successfully; diagnostic logs remain separate.
image-smoke.txt / image-smoke.json: exact immutable Web/CMS candidates exercised with disposable loopback API fixtures.
before-measurements.json / after-measurements.json: read-only actual-catalog geometry.
before-fixture-measurements.json / after-fixture-measurements.json: comparable public synthetic-fixture geometry.
preservation.json: final live runtime/catalog preservation evidence.
