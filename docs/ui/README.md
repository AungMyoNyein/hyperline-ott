# HyperLine premium UI review

The viewer and Content Studio use a cinematic dark design with the existing HyperLine logo. Changes target `feature/hyperline-premium-ui`, based on `v2.0.0-phase2`. No API contract or production infrastructure changes are required. UAT activation remains a separate operation.

## Source inspection

| Area | Reused foundation | UI changes |
| --- | --- | --- |
| `apps/web/lib/api.ts` | Typed catalog, series, episode, progress and session contracts; bearer expiry event | Optional series genres type |
| `apps/web/app/session.tsx` | In-memory bearer session and server logout | Sticky navigation, mobile menu, account actions, skip link and footer |
| `apps/web/app/cards.tsx` | Real API title and progress data | Reusable movie, series, vertical and continue-watching cards |
| `apps/web/app/watch` | HLS, DASH and YouTube transport; authorization, renewal, progress, resume and episode navigation | Responsive player frame, unavailable state and fullscreen feedback |
| `apps/cms/app/components` | Existing movie and nested series/season/episode editors, source validation and audit history | Studio layout, artwork previews, filters, responsive library and confirmation dialog |
| `services/api/routes/api.php` | Existing public and administrator routes | No route or response changes |

The original apps used independent CSS and basic grids/forms. There is no shared UI package or Tailwind dependency. CSS custom properties provide consistent foundations without adding a styling framework. Token files are mirrored because Web and CMS have separate Docker build contexts.

Public endpoints enforce publication of movies, series, seasons and episodes. Public payloads do not disclose protected source URLs. Authentication and playback permissions remain server decisions. Viewer and CMS tokens stay in memory and clear on refresh; no new persistent credential store was added.

## Design system

`apps/web/app/tokens.css` and `apps/cms/app/tokens.css` define backgrounds `#08080B` / `#131318`, surface `#1D1D24`, red `#E50914`, main text `#F8F8FA` and secondary text `#A1A1AA`. Red-soft supports readable accent text on dark surfaces.

- Typography: system sans-serif; 12, 14, 16, 20 and 28px tokens; fluid display type.
- Spacing: 4, 8, 12, 16, 24, 32, 48 and 64px; 8/12/20px and pill radii.
- Controls: primary, secondary, small and icon variants; disabled, hover and visible focus states.
- Artwork: 2:3 posters, 9:16 vertical dramas and 16:9 backdrops, with branded failure placeholders.
- Feedback: skeletons, empty states, retry/error states, live status messages and movie watchlist toasts.
- Confirmation: native modal dialog for movie deletion, focus containment, Escape cancellation and focus restoration. Series, season and episode destructive actions retain browser confirmation dialogs.
- Responsive layouts: phone, tablet and desktop CSS rules; tests cover 360, 390, 768, 1366 and 1920px widths.
- Accessibility: labeled navigation and forms, skip links, semantic buttons, keyboard-accessible card actions, reduced-motion styles and native video controls.

## Supported product behavior

Home uses `/home` for featured artwork, trending/latest movies and authenticated continue watching, and `/series?category=vertical` for vertical dramas. Missing featured content renders a branded hero. Cards display only available API metadata; progress comes from authenticated API responses. The series rail is labeled “Discover series” because the API does not supply a popularity ranking.

Search queries the paginated movie and series endpoints. Genres link to existing movie genre filters. Detail pages preserve sign-in redirects, real resume progress, movie watchlist actions, season selection and published episode navigation.

Content Studio provides real dashboard metrics, movie CRUD/publication, nested series/season/episode management, source editing, artwork URL previews and audit history. Genres can be viewed and assigned; the backend has no genre mutation or artwork-upload route. Cast, related-title recommendations, series watchlists and popularity scores are omitted because the current contracts do not support them.

## Screenshots

All screenshots use isolated fixtures and original geometric test artwork. They do not represent the UAT catalog. Before images show the earlier UI with an empty catalog/sign-in state; after images also demonstrate populated fixture states, so catalog differences are intentional.

| Page | Desktop | Mobile |
| --- | --- | --- |
| Original homepage | [Before](screenshots/before-home-desktop.png) | [Before](screenshots/before-home-mobile.png) |
| Homepage | [After](screenshots/after-home-desktop.png) | [After](screenshots/after-home-mobile.png) |
| Movie catalog | [After](screenshots/after-catalog-desktop.png) | [After](screenshots/after-catalog-mobile.png) |
| Movie detail | [After](screenshots/after-movie-desktop.png) | [After](screenshots/after-movie-mobile.png) |
| Series detail | [After](screenshots/after-series-desktop.png) | [After](screenshots/after-series-mobile.png) |
| Viewer sign-in | [After](screenshots/after-signin-desktop.png) | [After](screenshots/after-signin-mobile.png) |
| Original CMS | [Before](screenshots/before-cms-desktop.png) | [Before](screenshots/before-cms-mobile.png) |
| CMS sign-in | [After](screenshots/after-cms-signin-desktop.png) | [After](screenshots/after-cms-signin-mobile.png) |
| CMS dashboard | [After](screenshots/after-cms-dashboard-desktop.png) | [After](screenshots/after-cms-dashboard-mobile.png) |
| CMS movies | [After](screenshots/after-cms-movies-desktop.png) | [After](screenshots/after-cms-movies-mobile.png) |

Additional comparisons cover [unavailable playback](screenshots/after-player-unavailable-desktop.png) and [empty homepage](screenshots/after-empty-home-mobile.png). Playwright compares the major pages using `toHaveScreenshot`, fixed fixture time, disabled animations and a 0.5% pixel tolerance. Review visual changes before updating snapshots.

## Ownership audit diagnosis

At release `v2.0.0-phase2`, `CheckPlayableOwnership::handle()` references `JSON_UNESCAPES_SLASHES` only in its `--json` output path. PHP cannot resolve that misspelled constant. Commit `fc7c308` changes it to `JSON_UNESCAPED_SLASHES`; no ownership queries or database writes change. `PlayableOwnershipAuditTest` verifies JSON parsing, all 13 migrations, exit status, text output and unchanged playable rows.

[Sanitized isolated restore evidence](ownership-audit-verification.json) records the earlier verification at 2026-11-19 17:58 UTC. The original immutable release image reproduced the error against a fresh PostgreSQL 16 restore. Mounting only the corrected command returned exit 0, valid JSON, 13 applied migrations and zero violations. Text output passed and fingerprints of all original restored tables remained equal. The disposable database used no external network, exposed ports or existing volumes; the owned test container was removed. The evidence's source SHA-256 matches the current corrected command. No UAT migration or container restart is needed to validate this source correction.

## Performance and remaining validation

Both apps preserve locked Next.js 15.5.29, React 19.3.1 and the patched PostCSS baseline. The only added dependency is development-only Prettier. Playback libraries remain dynamically imported so browsing does not load HLS/DASH engines. Static shells, scoped client interaction boundaries, fixed artwork dimensions, skeletons and lazy images limit unnecessary work.

External artwork uses `next/image` with `unoptimized` to retain direct delivery and avoid turning the application into an arbitrary-host proxy. Responsive layout, sizes hints and lazy loading are implemented; server resizing, format conversion and CDN caching depend on a future approved artwork host policy. Browser caching of external artwork remains controlled by its origin. API/session requests remain uncached. No measured Core Web Vitals or real-device Safari result is claimed.

Authenticated browser coverage uses throwaway local accounts, SQLite fixtures and loopback media. Screenshot fixtures intercept requests in Playwright only. No UAT administrator/viewer credentials were used. Actual protected CDN playback remains unavailable until separately verified; its dedicated cookie/CDN test requires the separate CDN harness. YouTube coverage verifies the authorized embed rather than third-party playback availability.

See [validation results](validation.md) for commands and outcomes.

## UAT preparation

1. Review this branch and screenshots, then merge only through the separately approved release process. No automatic merge or deployment is part of this task.
2. Build immutable Web/CMS artifacts with the correct public API URL baked into Next.js; prepare an API artifact containing the isolated ownership correction. Preserve the existing patched lockfiles and secrets.
3. Confirm the existing UAT database has all 13 expected migrations. Do not rerun migrations as part of this UI release. Keep original active application images available for rollback.
4. Recheck publication visibility, viewer/admin login, season navigation, artwork failure and responsive layouts with approved read-only UAT sessions after a separately authorized activation.
5. Keep protected playback fail-closed until CDN authorization is independently verified. A clear unavailable state is the expected result while that gate remains closed.
6. Follow the existing release and recovery runbooks only within a separately approved activation scope. No DNS, Nginx, TLS, CDN/origin, VPN or running-container changes were made for this UI review.
