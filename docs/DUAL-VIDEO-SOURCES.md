# YouTube, HLS and DASH sources

The Premium UI baseline already supported explicit source kinds in Movie and Episode forms, authenticated playback sessions, public catalog URL redaction, HLS.js/native HLS, dash.js, and progress/resume for native media. YouTube previously used a plain iframe without IFrame API events, errors or application progress. This change keeps the existing schema and playback authorization paths and adds the official YouTube API adapter to the shared progress engine.

## CMS and API contract

Select Format explicitly for each source. HLS requires an HTTPS `.m3u8` path; DASH requires an HTTPS `.mpd` path. Query parameters remain supported. Existing SSRF checks, owner relationships, source enable/default rules and source-label selection remain enforced. Saving an authorized HTTPS URL does not establish CDN authorization or make protected playback ready. The CMS provides no switch to bypass signing.

YouTube accepts HTTPS video links on `youtube.com`, `www.youtube.com`, `m.youtube.com`, `music.youtube.com`, `youtu.be` and `www.youtu.be`: exact `/watch?v=ID`, `/embed/ID`, `/shorts/ID`, `/live/ID` or short-link paths. IDs must contain exactly 11 ASCII letters/digits/underscores/hyphens. Credentials, unexpected ports/hosts, extra path segments, ambiguous duplicate video IDs, playlists without a video and malformed IDs are rejected. Tracking/start parameters are discarded: application progress determines resume position.

New approved sources store `https://www.youtube.com/watch?v=ID`. Existing valid links are normalized in the playback response without a bulk database rewrite. Invalid legacy YouTube links are not playable. Authenticated sessions return `source.kind: "youtube"`, `source.video_id`, the canonical public watch URL, `signature_required: false`, `credentials_required: false`, and `renew_after_seconds: null`. Movie/subscription/episode/season visibility and source ownership checks still apply. Public movie/series APIs expose neither HLS/DASH locations nor YouTube source IDs/URLs. An unavailable protected default source never silently falls back to YouTube.

## Player behavior and limits

The player uses the [official YouTube IFrame Player API](https://developers.google.com/youtube/iframe_api_reference), HLS.js or native HLS, and the existing DASH engine according to the authorized source kind. It loads the official YouTube script directly, sets the embedding origin, uses a normal referrer policy, and retains official controls, branding and advertisements. No YouTube video is downloaded, cached, proxied or re-streamed by HyperLine. The player and mobile layouts maintain the documented minimum 200×200 viewport.

| Behavior | YouTube | HLS / DASH |
| --- | --- | --- |
| Start / pause / seek | Official embedded controls; API state/position observation | Native video controls and existing transport engines |
| Resume / progress | Same owner-specific progress API and write gate, through an IFrame API adapter | Existing shared engine |
| Position precision | Polled about once per second; seeking follows YouTube's available positions | HTML media position/events |
| Pause / completion / exit | API events and scoped cleanup snapshots | Existing media events and snapshots |
| Progress read/write failures | Nonfatal notice; 401 expires authentication and destroys embed | Nonfatal notice; 401 removes media |
| Episode switching | Destroys old player/poller, saves old owner's position, mounts new owner | Existing transport teardown and new session |
| Renewal | Public embed has no CDN token renewal; application auth/entitlements still checked by API calls | Existing signed renewal; denial detaches transport and clears media |
| Errors | Script/load timeout and official 2/5/100/101/150/153 error messages | Existing fatal media/CORS/network/renewal states |

YouTube progress requires a known finite duration. Live/unknown-duration streams, exact seek positions, offline persistence, background timing, blocked autoplay and network-stall events cannot be promised to behave like HTML video. YouTube can reject an embed for owner, account, geography, browser, network or client-identity reasons. Errors explain the failure and provide the existing retry path; autoplay is not forced. Application access checks cannot turn a public YouTube URL into a protected private CDN asset.

Reference: [YouTube player parameters](https://developers.google.com/youtube/player_parameters) and [embedded-player identity requirements](https://developers.google.com/youtube/terms/required-minimum-functionality). The implementation supplies `origin` and preserves `HTTP Referer`; it does not suppress attribution, controls or monetization.

## Validation and UAT boundary

The official sample is `https://www.youtube.com/watch?v=M7lc1UVf-VE`. Deterministic browser tests use that ID with a test-only IFrame API contract fixture; those results do not prove third-party video delivery. The separate `youtube-live.spec.ts` attempts the actual official service when `HYPERLINE_YOUTUBE_LIVE=1`; external restrictions are reported separately. Test mocks exist only under `tests/`, with no production mock switch. HLS uses the isolated signed loopback fixture; DASH retains its real dash.js fixture playback and failure/renewal regressions.

Run the full isolated stack with:

```bash
HYPERLINE_YOUTUBE_LIVE=1 HYPERLINE_SIGNED_FIXTURE=1 bash tests/run-browser-tests.sh
```

The runner builds secret-free temporary source copies and uses disposable SQLite/media and loopback services. Never point it at UAT. No UAT viewer/admin account or content is created by this work. Live UAT CMS/playback acceptance requires approved existing disposable references or separate operator authorization.

Executed validation on 2026-11-20:

| Check | Actual result |
| --- | --- |
| Full Laravel suite | **PASS: 289 tests, 1,351 assertions; zero failures/errors/skips** |
| VideoFormatsTest (normalization, episode contract, legacy links, ownership, signing and validation) | **PASS: 12 tests, 106 assertions**, included above |
| Existing-CDN fail-closed suite | **PASS: 9 tests, 35 assertions**, included above |
| Nginx playback/security suite | **PASS: 18 tests, 70 assertions**, included above |
| Ownership command regression | **PASS: 2 tests, 11 assertions**, included above |
| Web/CMS TypeScript and configured lint; browser-spec TypeScript | **PASS** |
| Isolated Web/CMS production builds | **PASS** |
| Full Chromium comparison/integration run with both flags above | **PASS: 47 passed, 1 skipped, zero failures (3.3 minutes)** |
| Real official YouTube sample | **PASS:** embedded `M7lc1UVf-VE`, user-initiated Play, advancing video time and pause in the isolated application |
| CMS movie sources | **PASS:** YouTube canonicalization, HLS and DASH types persisted through real local HTTP/API calls |
| CMS episode YouTube source | **PASS:** URL normalized and hierarchy publication/browse retained |
| YouTube API contract regressions | **PASS:** resume/seek/completion, progress failures/401, 100/101/150/153/5 errors, script failure, owner-scoped episode switching and cleanup |
| HLS/DASH regressions | **PASS:** real generated signed fixture playback, authorization/renewal denial, CORS/fatal errors, progress and mixed-engine episode navigation |
| Mobile/responsive | **PASS:** existing 360/390/768/1366/1920px checks; YouTube iframe dimensions/overflow checked at 360/390/1366px |
| Web/CMS full npm audits | **PASS: zero vulnerabilities at every severity** |
| Composer audit | **PASS: no advisories or abandoned packages** |
| Actual protected CDN / authenticated UAT acceptance / physical mobile devices | **NOT TESTED** |

The single browser skip is the separate signed-cookie CDN harness; it does not establish external CDN authorization. The live YouTube test passed against the official service, not the contract mock; authenticated UAT-host playback remains untested. The initial expanded browser run had 41 passes, six failures and one skip: two expected CMS screenshot changes, a live-service Play button selector, the progress-insert 201 expectation, and two navigation/read-readiness races. These were corrected and the full run repeated. Only the two visually reviewed CMS source-help screenshots were refreshed. The initial full backend run exposed a timestamp-boundary assumption in the audit test; freezing time made that assertion deterministic without changing application audit behavior.

See the new sealed local `deploy/releases/dual-source-*` packet's `REPORT.md`, validation summary, image IDs and concrete activation/rollback runbook for executed results. Preserve `deploy/releases/premium-ui-20261120T131742Z/` and all existing images. The shared [UAT procedure](ui/UAT-RELEASE.md) provides the rollout/backup sequence; the new packet supplies its exact paths and commit.

All 13 UAT migrations are already applied. This feature adds no migration. Deploy API before Web/CMS because the YouTube player consumes `source.video_id`; use matching API images for worker/scheduler. Activation remains approval-only and requires refreshed capacity checks, a fresh quiesced DB/config/storage/public backup, isolated restoration, exact image/config validation and approved browser acceptance. Rollback uses captured original application images with the expanded database retained. No live migration or destructive restore is part of normal activation or rollback.

Real `cdn.goldendragon2000.com` end-to-end playback is **NOT TESTED** until its actual authorization protocol/credentials, protected nested playlists/segments/keys, CORS and renewal are configured and independently verified. Keep signing required, verification flags false, and HTTP 503 without unsigned protected URL/key disclosure. No CDN/origin change is included.
