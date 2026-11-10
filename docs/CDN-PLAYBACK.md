# Existing external CDN integration

HyperLine uses **the existing** `https://cdn.goldendragon2000.com` and the existing VOD Origin Server. No new CDN, origin, cache layer, CDN virtual host, DNS alias or media proxy is part of this deployment. The previously proposed same-site cookie/new-host experiment is superseded; its files are retained only as historical local references and must not be installed.

Application architecture: Next.js OTT + Next.js CMS → Laravel REST API → PostgreSQL/Redis for application data; the authenticated player fetches HLS directly from the existing external CDN. CMS stores the operator-provided `.m3u8` URL in `video_sources`. Neither Laravel nor Next.js downloads, mirrors, rehosts or proxies video.

## What is implemented

- Admin login/logout, movie/source creation, editing, enable/default selection, metadata, publishing and validation use the real Laravel API. Source URLs are admin-only; public catalog responses omit them.
- Playback creation checks bearer authentication, publication, entitlement and enabled source selection. Renewal checks ownership, active/unexpired status, current entitlement/source and a fixed maximum lifetime.
- `ExistingCdnPlaybackSigner` bridges the existing `PlaybackUrlSigner` interface to an `ExistingCdnAuthorizer` adapter. It preserves the CMS CDN hostname and manifest path. It rejects remapping, other hosts, plain stored URL fallback, invalid expiry and attempted cross-domain cookie issuance.
- `OTT_EXISTING_CDN_VERIFIED=false` and an unconfigured authorizer return 503 without retaining a new playback session. Production signing remains mandatory even if unsigned mode is requested. The bridge does NOT invent a CDN signing algorithm.
- HLS.js loads the authorized URL directly, uses anonymous CORS for signed-URL mode, and applies refreshed signed URLs while restoring playback position. Cookie credential mode is optional compatibility code, not a requirement for the external-CDN architecture.
- Local tests use original generated test patterns and a guarded test-only signer. That mock demonstrates URL transport/renewal, not cryptographic compatibility with the live CDN.

## Existing CDN capability status

**Signed URLs: UNKNOWN. Signed cookies: UNKNOWN. Other access control: UNKNOWN.** Nginx server headers do not identify enabled modules, token algorithms, key formats, expiry semantics, origin ACLs or nested-resource authorization. A publicly accessible URL is not approval to disable HyperLine's signing gate. No secret or access-control configuration was supplied, and no production Nginx configuration was read or changed.

Metadata-only checks from this workspace on the previously supplied sample manifest:

| Check | Observed result | Limit |
| --- | --- | --- |
| HTTPS HEAD, Origin `https://ott.zentunnel.net` | 200; `application/vnd.apple.mpegurl`; `Access-Control-Allow-Origin: *`; `Accept-Ranges: bytes` | No manifest body or media downloaded |
| Browser HEAD from the exact OTT origin | 200; CORS permitted without credentials | Locally intercepted diagnostic page; no production OTT page changed |
| OPTIONS requesting GET/Range | 403; no allow-origin header | Preflight-dependent requests are not verified as supported |
| Credentialed CORS | Not compatible with the observed wildcard/no-credentials response | Does not establish the CDN's behavior for other paths or configured credentials |
| CDN root HEAD/OPTIONS | 403 | A root response does not determine individual HLS resource accessibility |
| GET master/variant/segment/init/key | NOT TESTED on live CDN | Await an explicitly authorized test asset and operator access details |

These are point-in-time path-specific observations, not a guarantee that all HLS resources or signed requests work. No third-party playlist body, video, segment or key was fetched. The sample was not published in CMS. Diagnostics suppress cookie values, query credentials and redirects; no signing secrets were requested in chat.

Repeat metadata checks without placing signed query parameters in shell history:

```sh
python3 tools/check-existing-cdn.py --url-file /secure/operator-approved-url.txt
PLAYWRIGHT_BROWSERS_PATH=/tmp/hyperline-playwright node tools/check-existing-cdn-browser.cjs /secure/operator-approved-url.txt
```

The Python tool sends HEAD/OPTIONS only, validates the target is the existing CDN over HTTPS, verifies TLS and never follows redirects or reads media. The browser tool performs anonymous HEAD only with normal TLS verification. Test signed production URLs only through the operator's approved credential workflow; do not paste secret-bearing URLs in reports.

## Information required from the existing CDN operator

Confirm the existing protocol rather than changing video infrastructure:

1. Whether access uses signed query URLs, CDN-host cookies, a token service, header authorization, IP/referrer restrictions, public content authorization, or another established method.
2. For signing: exact bytes/encoding, URL parameter names, canonicalization, key ID/rotation, expiry/clock skew and the approved server-side secret delivery or token-service API. Do not send raw keys in chat.
3. How authorization covers **every** master/variant/audio/subtitle playlist, segment, initialization segment and encryption key. Signing a master URL alone does not automatically sign relative segment URLs. Do not assume query parameters propagate into relative references.
4. The rights-approved test asset, permitted hostname/path, redirects, master references and required identity/entitlement restrictions. Existing origin ACLs must stay intact; no access controls will be bypassed to make playback succeed.
5. CORS for the exact OTT origin on all referenced resources and error responses, Range behavior, MIME types, supported methods and any preflight requirements.
6. If access is cookie-based: an **existing CDN-owned issuance/exchange mechanism** and tested cross-site browser policy. `api.zentunnel.net` cannot set a cookie for unrelated `goldendragon2000.com`. Third-party cookie restrictions also apply. Do not reuse the superseded same-site cookie method or introduce a new hostname to evade this constraint.

References: [cookie domain rules](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Cookies), [CORS and credentials](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CORS).

## Application-only deployment configuration

Use `deploy/existing-cdn.env.example`. It selects `ExistingCdnPlaybackSigner`, keeps `OTT_SIGNED_URLS=true`, `OTT_EXISTING_CDN_VERIFIED=false` and the existing protocol adapter unset. No CDN hostname creation, origin credentials, CDN installation or Nginx media/cache configuration is required.

Implement `ExistingCdnAuthorizer::authorize(VideoSource, PlaybackSession): SignedPlaybackUrl` **only after** the real operator protocol is supplied. The adapter must authorize the CMS host/path with future expiry no later than the session limit, and protect child resources using the CDN's established mechanism. A mock query marker is not a production signer. A changed query string alone is not proof of authorization; only the documented operator implementation and live tests can establish that.

For a CDN-cookie or header protocol, further application integration with that **existing** mechanism is needed before activation. The present bridge deliberately rejects Laravel-issued foreign-domain cookies. Do not attach the Laravel bearer token to third-party CDN requests or expose origin credentials in the browser.

Keep the API/CMS/OTT domains, trusted proxy configuration, TLS, application secrets and existing database credentials. `NEXT_PUBLIC_API_URL` is embedded at build time and must point to the real API for release builds. API CORS allows the exact frontends; the current player API requests opt into credentialed CORS, so `CORS_SUPPORTS_CREDENTIALS=true` is included in the application template. OTT/CMS remain outside Sanctum's stateful cookie-login list because login uses bearer tokens. CDN signed-URL requests themselves remain anonymous/credential-free unless a verified CDN-owned cookie protocol is integrated later.

Do not change actual `.env` values to verified=true, fall back to unsigned production playback, rotate APP_KEY, reset databases, remove volumes, replace existing proxies/certificates or stop Zentunnel VPN services as part of this application work.

## Local verification

```sh
cd services/api
php artisan test
cd ../..
HYPERLINE_SIGNED_FIXTURE=1 bash tests/run-browser-tests.sh
npm run typecheck --prefix apps/web
npm run typecheck --prefix apps/cms
docker compose config --quiet
```

The runner starts/stops its own loopback application/media test fixtures, uses a disposable SQLite database and original generated video, and builds the frontends with the fixture API address. It does not install or deploy a CDN or origin, read production video secrets, or modify the real external CDN. The guarded signer lives under `tests/Support` and is removed/excluded from the production API image. Rebuild frontend artifacts with the production API URL before application deployment.

The historical `tests/cdn/run.py` tests a superseded cookie experiment and is **not** an external-CDN deployment prerequisite or proof of existing CDN capabilities.

## Rollout and rollback

Prepare the HyperLine application image/configuration with verification false; preserve all current data/volumes and running VPN/CDN/origin services. Stage only the application through the existing deployment workflow after reviewing the changes. Integrate the operator-confirmed adapter and test it against an authorized asset, including denied/expired/renewal cases and every nested resource. Keep production playback gated until those checks pass and explicit activation approval is obtained.

If application verification fails, keep or restore `OTT_EXISTING_CDN_VERIFIED=false` and the unconfigured provider. Restore the prior approved application image if necessary while retaining fail-closed production behavior. No video-infrastructure rollback is needed because none is changed. Existing source URLs, databases and volumes remain intact.

See [DEPLOYMENT-BLOCKERS.md](DEPLOYMENT-BLOCKERS.md) and [CDN-VERIFICATION.md](CDN-VERIFICATION.md) for the current release status.
