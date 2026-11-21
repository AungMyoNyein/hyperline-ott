# Myanmar YouTube Catalog Import — Regression Results

Run against the feature worktree and disposable local databases on 2026-11-21. No UAT catalog writes, deployment, image activation, migrations against UAT, or service restarts were performed.

## Input and importer

- Input: `HyperLine_Pyone_Play_Mahar_50.csv`, UTF-8 with BOM, 50 data rows; SHA-256 `722204f44ba1fa1f6ae3d636e08a44bbd596520223e3b5e4e443482ecc7b0703`.
- Independent official YouTube oEmbed metadata verification: 49 matched title/channel; row 15 returned HTTP 401 and is held unverified. All 50 thumbnail URL HEAD checks returned 200. No thumbnail or video body was downloaded. Playlist membership/existence and per-video embedding permissions remain unverified.
- Classification: 25 full movies, 20 series episodes, 2 mini episodes, 1 trailer, 1 film promotion, 1 fan meeting. Duplicate normalized YouTube video IDs: 0.
- Isolated database: all 13 existing migrations present; importer dry-run processed all 50 rows without changing the preexisting database fingerprint; import created 25 draft movies and 25 YouTube sources; repeat import created 0 additional rows. Existing test records stayed unchanged. 21 episode/mini rows were held for missing approved series/season/episode mapping, one additional row was held for unverified metadata, and trailers/promotional/fan-meet rows were held from movie creation. No series or seasons were fabricated.
- The importer feature suite passed 12 tests / 108 assertions. Full Laravel suite passed 301 tests / 1,459 assertions.

## Build and browser regression

- Web and CMS lint, TypeScript checks, and production builds: PASS.
- Web and CMS `npm audit --audit-level=low`: PASS, zero advisories. Composer audit: PASS, zero advisories/abandoned packages.
- Full Playwright suite: PASS, 48 passed, 1 skipped (separately gated protected-CDN harness). Includes CMS draft-only visibility, publication-gated Unicode detail/search, attribution, player error handling, progress/resume, and an approved embeddable Google YouTube sample playback check. The 50 CSV videos were not individually played and must not be presumed embeddable.
- Isolated protected HLS/CDN security harness: PASS, including fail-closed activation, signature, expiry, cross-movie, path traversal, encoded-path, query, unauthorized segment/key, cache isolation, credential forwarding, byte-range and exact-origin CORS cases, plus its browser integration check (1 passed). It uses generated disposable media, temporary credentials/certificates and isolated loopback services only; it does not configure or signal host Nginx.
- Migration/restore checks used a private read-only UAT snapshot and disposable PostgreSQL. No UAT migration was executed. The migration ledger matched all 13 applied migrations.

## Limits and release gate

No new immutable application image was built. UAT still runs the existing release; the importer source is not active there. Live import, test-account/content approval, the fresh quiesced activation checkpoint/restore gate, and a scoped activation window remain separate approvals. Imported rows remain unpublished drafts by design. See `UAT-IMPORT-PLAN.md` for the controlled one-off commands and rollback boundaries.
