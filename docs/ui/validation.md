# Premium UI validation results

Validation uses local source and disposable test services. No UAT endpoint, live database or running Docker container is used by the browser runner.

## Static checks and backend regression

| Check | Result |
| --- | --- |
| Web `npm run lint --prefix apps/web` | PASS: TypeScript and configured Prettier checks |
| CMS `npm run lint --prefix apps/cms` | PASS: TypeScript and configured Prettier checks |
| Browser specs `apps/web/node_modules/.bin/tsc -p tests/e2e/tsconfig.json` | PASS |
| `bash -n tests/run-browser-tests.sh` | PASS |
| `git diff --check` | PASS |
| `php services/api/vendor/bin/phpunit -c services/api/phpunit.xml` | PASS: 283 tests, 1,283 assertions |
| Web `npm audit --prefix apps/web --json` | PASS: zero reported vulnerabilities |
| CMS `npm audit --prefix apps/cms --json` | PASS: zero reported vulnerabilities |
| `composer audit --working-dir=services/api --format=json` | PASS: no advisories or abandoned packages |

Audits were run on 2026-11-20 after retrying with network access. They include development dependencies. Existing locked runtime versions were preserved.

## Production build results

Both `next build` commands pass using a secret-free temporary source copy, reused installed dependencies and a loopback API URL. No release build assets in the checkout are replaced.

| App or route | First load JavaScript |
| --- | --- |
| Web shared | 102 kB |
| Homepage | 117 kB |
| Movie catalog | 116 kB |
| Movie detail | 115 kB |
| Series detail | 116 kB |
| Watch | 112 kB |
| CMS homepage | 114 kB |

These are Next.js build estimates, not measured Core Web Vitals. HLS and DASH are loaded on demand and are not included in the browsing route payload.

## Browser regression

Run from the repository root:

```bash
HYPERLINE_SIGNED_FIXTURE=1 bash tests/run-browser-tests.sh
```

For an intentional reviewed visual change, refresh deterministic screenshots and then run the full comparison suite against the same builds:

```bash
HYPERLINE_UPDATE_UI_SNAPSHOTS=1 HYPERLINE_SIGNED_FIXTURE=1 bash tests/run-browser-tests.sh
```

The runner copies source without environment files or config caches, creates disposable SQLite data, generates local media, builds both apps, starts only loopback processes on ports 18081–18084 and stops its own server processes on exit. It never starts or changes Docker containers. Its migration/seed operations target the disposable SQLite database only. Do not run concurrent copies because the fixture paths and ports are shared.

Coverage includes real HTTP authentication/revocation, movie CRUD and publication, draft exclusion, genre/search filters, watchlists, progress/resume/completion, nested series/season/episode CRUD, episode selection and navigation, HLS variants/quality/fullscreen, DASH readiness, YouTube embed construction, missing-CORS errors and signed URL renewal. A fullscreen rejection regression checks that video and native controls remain available.

Visual tests cover desktop/mobile home, catalog, movie detail, series detail, viewer sign-in, CMS sign-in/dashboard/library, empty hero and unavailable playback. Responsive tests check page overflow at 360, 390, 768, 1366 and 1920px. Additional assertions cover keyboard skip navigation, reduced motion, mobile menus, dialog cancellation/focus restoration, unsaved drafts and artwork failure. Authorization-unavailable fixtures assert that no media or key request is made.

The separate signed-cookie CDN browser case requires `tests/cdn/run.py` and is skipped in this suite. It does not establish the availability or authorization of the actual UAT CDN. Real-device iOS/Android, live UAT authenticated fixtures, external YouTube playback and measured Core Web Vitals remain unverified.

Final release-review result on 2026-11-20: **34 passed, 1 skipped, 0 failures** in the full comparison run (2.6 minutes). The skipped case is the separate signed-cookie CDN fixture. Existing desktop/mobile baselines passed without update mode. Both production builds ran in the isolated fixture copy. No UAT account or catalog was used.

The initial expanded run had **28 passed, 6 failed, 1 skipped**: five new assertions matched Next.js's route-announcement alert as well as the application alert; the signed-renewal case inherited a near-end resume position from preceding cases. Selectors now target the relevant alert text, and the renewal-only test fixes its resume lookup at zero while retaining real signed session creation/renewal. The final full run above includes all corrections and the transport-detachment change.

Included backend results (subsets of the 283-test full suite): AdminAuth **10 tests / 48 assertions**, TokenExpiration **11 / 48**, ViewerApi **10 / 72**, PlaybackAuthorization **11 / 60**, PlayableOwnershipAudit **2 / 11**. Series/episode coverage includes AdminSeriesCrud **16 / 88**, SeriesCatalog **7 / 27**, SeriesVisibility **9 / 33**, SeriesPlayback **8 / 26**, EpisodeOrdering **5 / 14**, EpisodeProgress **8 / 59**, SeasonsSchema **9 / 12**, ImportSeriesSeasons **7 / 30**. All passed; no backend skips or failures.

## Restored ownership audit

The [sanitized verification report](ownership-audit-verification.json) records a successful prior isolated PostgreSQL restore check, including reproduction with the original image, successful correction, 13 applied migrations in the migration ledger, zero ownership violations and unchanged restored row fingerprints. The report's SHA-256 matches the current command. The current Laravel suite also passes both JSON/text command regression tests. No live migration was run for UI validation.


## Release review correction

Progress-service errors previously shared the player's fatal stream-error state. The redesigned fatal state hides the video, so an unavailable resume/progress service could interrupt otherwise authorized playback. Progress errors now have a separate live status message. Browser cases inject progress-only HTTP **503**, **403**, and network failures while the normal authorized HLS session loads and plays, then verify read/save notices, visible video, native controls and fullscreen availability. Progress **401** still expires the session and removes media.

Authorization renewal failures now destroy HLS, reset DASH, pause media and clear its source. Browser cases cover HLS/DASH renewal **403**, expired-session **410**, authentication **401**, and fatal DASH manifest failure; the existing missing-CORS/HLS case also passes. Resume, movie completion, episode progress/switching and successful signed renewal retain their existing browser coverage. No unsigned fallback URL, authorization bypass or CDN configuration change was introduced.

The reviewed activation/rollback and browser acceptance plan is [UAT-RELEASE.md](UAT-RELEASE.md). Immutable image IDs, fresh isolated restore results, runtime-preservation evidence and private resolved Compose files belong to the sealed local release packet, not source control. Preparation does not authorize activation.
