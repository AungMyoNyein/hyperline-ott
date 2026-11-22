# Movies catalog UX — UAT candidate

Date: 2026-11-22. Branch: `feat/movies-catalog-ux`. Base: `2761b7c`.

The Movies page keeps “Find your next story” in a 180px desktop / 140px mobile heading. Search and filters sit in a compact responsive toolbar. Search supports Enter, an explicit Search button, clear, validation, loading, no results, request errors and retry. Counts come from the API's `meta.total`, rather than the number of cards on the current page.

Validation errors no longer replace catalog request state. Previously, submitting a one-character query put the entire page in an error state; correcting it to the existing applied query did not change the fetch dependencies, leaving the error stuck. Validation is now separate, and resubmitting the same query explicitly reloads results. Aborted responses cannot overwrite newer results.

Search, genre, featured and page are stored in the catalog URL. Both card details links carry an internal catalog return URL containing only validated public filters. Repeated return parameters fall back to the catalog instead of crashing the server-rendered detail page; unrelated URL parameters are excluded from return links. Browser Back, the explicit Back to movies link, and reload restore the applied catalog state. Editing search text alone does not submit it.

Catalog cards use responsive columns, full wrapping titles, Burmese `lang="my"` annotations and a bundled Unicode font. Portrait posters retain 2:3 frames; YouTube thumbnails use 16:9 frames with their existing contain treatment. Watch and Details links have at least 44px height. Catalog image zoom/reveal animation is disabled.

## Screenshots

Before images were captured directly from `https://ott.zentunnel.net/movies`. After images use the local production build with the public UAT `/movies` and `/genres` response snapshots intercepted in the browser, giving the comparison the same 16 catalog entries. The snapshot stays in `/tmp`; it is not imported into a database. External artwork is requested directly as before and may vary with remote availability. Full-page screenshots include the footer.

| Viewport | Before | After |
| --- | --- | --- |
| 360 × 800 | [Before](screenshots/before-360.png) | [After](screenshots/after-360.png) |
| 390 × 844 | [Before](screenshots/before-390.png) | [After](screenshots/after-390.png) |
| 768 × 1024 | [Before](screenshots/before-768.png) | [After](screenshots/after-768.png) |
| 1366 × 768 | [Before](screenshots/before-1366.png) | [After](screenshots/after-1366.png) |
| 1920 × 1080 | [Before](screenshots/before-1920.png) | [After](screenshots/after-1920.png) |

Measured screenshot geometry and counts are recorded in [screenshot-metrics.json](screenshot-metrics.json).

## Validation

- `npm run typecheck --prefix apps/web`
- `npm run lint --prefix apps/web`
- `apps/web/node_modules/.bin/tsc -p tests/e2e/tsconfig.json`
- `HYPERLINE_SIGNED_FIXTURE=1 bash tests/run-browser-tests.sh`

The browser runner builds secret-free copies under `/tmp`, runs web and CMS production builds and uses a dedicated disposable SQLite fixture database. It does not start, stop or change Docker services. Visual baselines for the changed catalog and detail views were prepared before validation; the final full suite compares them without updating snapshots. The existing DASH/YouTube navigation test now checks the details pathname while allowing the catalog return query parameter.

The 10 Movies regression tests pass, covering empty search, Burmese and English queries, one-character validation followed by the previous query, no results, clear search/filters, featured filtering, loading, retry, URL state restoration and actual font rendering. Each requested viewport asserts heading height, overflow and action target height. The fixtures expose 25 matches over multiple pages to verify total counts are not confused with visible card counts.

Full browser-suite results: **56 passed, 3 skipped, 0 failures** (3.4 minutes). The final production-build and browser output is saved in [browser-validation.txt](browser-validation.txt). All 10 Movies tests passed, including repeated return parameters and exclusion of unrelated query values from detail/return links. The skipped tests require the separate CDN fixture, the optional catalog-import CSV, or live third-party YouTube playback; none is needed for this frontend change. Both viewer and CMS production builds passed, as did viewer TypeScript, viewer lint and browser-test TypeScript compilation. All four changed visual baselines passed the final comparisons. Font binaries also match their recorded SHA-256 provenance.

## Deployment readiness

**Ready for controlled UAT deployment review.** No deployment was performed. This is a frontend candidate for the existing UAT release process; build with the existing UAT `NEXT_PUBLIC_API_URL` configuration, review the screenshot evidence, and run a post-deployment Movies smoke test when deployment is explicitly authorized. No Laravel API, catalog data, publication state, authentication, streaming authorization, CDN/origin, or Docker service changes are included. The pre-existing `services/api/Dockerfile` edit and other unrelated workspace changes are excluded from this commit.

Native mobile devices and a Burmese reader's visual acceptance remain manual follow-up; automated viewport checks run in Chromium, with a DevTools assertion of the actual Myanmar font used for glyphs.
