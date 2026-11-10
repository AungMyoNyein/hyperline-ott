> SUPERSEDED local experiment. Do not deploy or use as an existing-CDN requirement. See ../CDN-PLAYBACK.md for the current architecture.

# Nginx protected CDN integration — deployment review

**Status: local implementation only. No production CDN, origin, DNS, certificate, host Nginx, Docker service or volume has been changed. Production activation requires approval and live verification.** The existing `cdn.goldendragon2000.com` remains operational. The new hostname is `cdn.zentunnel.net`; it is a separate vhost, not a replacement for the old CDN.

The Laravel provider is `App\Support\Playback\NginxSecureLinkSigner`, implementing the existing `PlaybackUrlSigner` interface. `OTT_NGINX_CDN_VERIFIED` defaults to **false**. The Nginx gate also starts at **0**. Selecting the provider alone cannot enable playback. Production still requires signing regardless of `OTT_SIGNED_URLS=false`.

## Exact signing protocol: hl-cookie-v1

All fields below are ASCII bytes. There is **no trailing newline, NUL, whitespace, BOM, percent decoding or Unicode normalization** in the signing input:

```text
v1|<EXP>|<SCOPE>|<SESSION>|<SECRET>
```

- `EXP`: Unix UTC expiry in integer seconds, ten decimal digits, no leading zero. Nginx secure_link treats expiry strictly before its current second as expired. Application issuance always requires a future expiry.
- `SCOPE`: exact `/hls/<movie_id>/<video_source_id>/`, positive canonical decimal database IDs and a trailing slash.
- `SESSION`: lowercase UUID (`8-4-4-4-12` hex groups), the playback session public ID.
- `SECRET`: **64 lowercase hex ASCII characters** generated from 32 cryptographically random bytes. Use these literal 64 characters as the suffix on both systems; do NOT hex-decode them. Keep the real value server-side.
- `SIG`: `base64url(MD5(input, binary=true))` without `=` padding; exactly 22 characters, ending in one of `A`, `Q`, `g`, `w` (canonical zero padding bits). The parser rejects alternate noncanonical encodings even if Nginx would decode them to the same digest. This uses Nginx secure_link's MD5 protocol, **not HMAC**, as required by that module.
- Cookie value: `v1.<EXP>.<SESSION>.<SIG>`.

Nginx computes:

```nginx
secure_link $hl_link; # extracted SIG,EXP from the cookie
secure_link_md5 "v1|$secure_link_expires|$hl_scope|$hl_session|$hl_signing_secret";
```

The scope is derived from the requested resource path, never taken from the cookie. Replaying a valid cookie header against another movie/source fails the signature even if the caller bypasses browser Path restrictions. Signature comparison and expiry enforcement are performed by Nginx. Invalid/missing tokens return 403; a correctly signed expired token returns 410. No unexpiring token form is accepted.

Public test vector (not a deployment key): SECRET is `0123456789abcdef` repeated four times; EXP `2000000000`; SCOPE `/hls/7/42/`; SESSION `12345678-1234-4234-8234-123456789abc`; SIG **`DNU1mQNfvcmaLXQM3d9k1Q`**. PHP and independent Python/Nginx tests exercise this format.

References: [Nginx secure_link](https://nginx.org/en/docs/http/ngx_http_secure_link_module.html), [Nginx proxy/cache directives](https://nginx.org/en/docs/http/ngx_http_proxy_module.html), [HLS.js configuration](https://hlsjs.video-dev.org/api-docs/hls.js.hlsconfig).

## Issuance, renewal and cookies

Authenticated `POST /api/v1/playback/sessions` rechecks entitlement and selects an enabled source. The provider checks the HTTPS request, verification gate, secret format, matching parent domain, canonical registry entry and exact stored origin URL. It returns a URL such as `https://cdn.zentunnel.net/hls/7/42/master.m3u8` and a cookie. Source URLs and origin credentials are not in the public playback response.

Laravel issues `__Secure-hl_playback` with **Secure; HttpOnly; SameSite=Lax; Domain=zentunnel.net; Path=/hls/7/42/** and a 300-second default expiry (allowed TTL 30–600 seconds). The signed value is intentionally excluded from Laravel cookie encryption; Nginx must see the exact protocol bytes. The response is `private, no-store`. The cookie value and secret are not JSON fields. Different movie/source paths coexist; renewing a session overwrites the cookie for the same directory. Two simultaneous sessions for the same directory share that browser cookie, which is acceptable because both authorize the same resource tree.

An API host cannot set a host-only cookie on its sibling CDN. The chosen parent-domain cookie can reach **any trusted sibling host at that exact Path**. All `*.zentunnel.net` operators must therefore be trusted; a compromised sibling can receive/replay this short-lived bearer cookie. HttpOnly does not remove that domain-trust requirement. If that trust is unacceptable, do not deploy this mode: use a separately reviewed host-only CDN issuance/exchange endpoint instead. No existing cookie names or paths are reused.

The frontend makes only playback create/renew calls with `credentials: include`; bearer authentication remains mandatory. Laravel must allow exact frontend origins and credentialed CORS. Do not put OTT/CMS in `SANCTUM_STATEFUL_DOMAINS`: this is bearer authentication, not a Sanctum cookie login/CSRF flow. Use HTTPS for API, OTT and CDN: SameSite is schemeful.

The HLS player uses `xhr.withCredentials` and fetch credentials for signed playback; native video uses `crossOrigin=use-credentials`. This covers every HLS.js playlist, segment, initialization segment and key request. The renewal timer uses `renew_after_seconds` (60% of remaining TTL). `POST /api/v1/playback/sessions/{public_uuid}/renew` locks the session, verifies owner, bearer identity, active/unexpired status, current entitlement, publication, source availability, registry and signing configuration. It cannot extend past `started_at + OTT_PLAYBACK_MAX_SESSION_SECONDS` (default four hours). Cookie possession alone cannot renew. Failure pauses playback and asks the user to restart. Background suspension can miss renewal; expired sessions must be started again. Successful renewal does not require reloading manifests.

Nginx authorization is offline: logging out or disabling a title prevents renewal but **does not revoke an already issued cookie instantly**. It remains usable until its short expiry. Already buffered content cannot be recalled. Secret rotation revokes all outstanding cookies; immediate selective revocation would require an online authorization service, outside this secure_link mode.

## Asset mapping and protecting every resource

The registry is a reviewed JSON file, mounted read-only into the API at `OTT_NGINX_ASSETS_FILE`. See `deploy/cdn/assets.example.json`. A source ID maps to its movie ID, exact origin directory and master filename. For source 42/movie 7, stored source URL must exactly equal:

```text
https://cdn.goldendragon2000.com/vod/authorized_title/master.m3u8
```

Generate the matching Nginx map from that same file:

```sh
python3 deploy/cdn/render-assets.py /secure/approved-assets.json > /secure/assets.map
```

Both outputs must be deployed atomically as a reviewed pair. IDs in the example are illustrative only; never publish the supplied third-party sample without authorization. CMS changes to a source URL invalidate its registry match until approved again. Unknown source mappings fail closed.

The vhost has a single protected `/hls/` location for **all** resource types, not only `.m3u8`: master/variant playlists, subtitle/audio playlists, `.ts`, `.m4s`, initialization `.mp4`, encryption keys and any other canonical asset all go through the same authorization check. No unprotected extension location exists. Root paths are 404. Raw percent encodings, dot segments, backslashes, double slashes, noncanonical IDs and query strings are rejected. Only ASCII directory names `[A-Za-z0-9_-]+` and filenames `[A-Za-z0-9_-][A-Za-z0-9_.-]*` are supported.

**Manifest prerequisite:** all URI references must be relative and self-contained inside the reviewed movie/source directory. Existing absolute links to the old CDN, root-relative links, `../`, query credentials, redirects, external key servers or shared resources outside the directory are NOT secured by these cookies. Do not enable such an asset. Package an authorized self-contained tree using the origin operator's approved process; this implementation does not download, copy or rewrite third-party video. Audit local authorized files, recursively including every `URI="..."` attribute:

```sh
python3 deploy/cdn/audit-hls-tree.py /approved/local/tree master.m3u8
```

Then verify the live authorized tree with the operator before opening the gate. The application cannot certify unknown origin manifest contents. Origin redirects are rejected with 502, never followed to an unrestricted hostname.

## Caching and existing origin access controls

Authorization checks execute in the rewrite phase **before** the proxy cache/content phase, for HITs and MISSes. The cache key is `goldendragon:<approved_origin_directory><relative_file>`, without cookies, session IDs, viewer headers or token query parameters. Different viewers therefore share cached content. Only 200 responses are cached by default. Upstream cache-control, private/no-store, Set-Cookie and Vary behavior are respected. Browser responses get `private, no-store` so a browser cache does not silently serve a resource after authorization expires. Error responses include CORS headers but contain no media.

The upstream is fixed to `cdn.goldendragon2000.com:443`, with matching Host/SNI and TLS certificate verification; clients cannot supply a destination URL. Client headers are not forwarded wholesale: only Host, connection/range controls and explicitly approved service headers are configured. Viewer Cookie, Authorization, Origin and Referer headers are stripped. Do not use a signing secret as an origin credential. `origin-access.conf` must reproduce **only operator-approved service access controls**. Do not spoof viewer identity, suppress a required upstream ACL, ignore private cache-control, expose credentials, follow redirects or make the origin public. An upstream that requires per-user authorization cannot safely use this shared-cache design without additional review. Audit that no inherited error_page/rewrite/cache directives or public aliases bypass the new location. Requests to the original CDN remain governed by its existing controls; this vhost cannot retroactively protect an already public origin URL.

## DNS, TLS, Nginx and Laravel requirements

1. New DNS A record `cdn.zentunnel.net` to the approved CDN reverse proxy address; add AAAA only if its IPv6 routing/firewall is ready. Do not change the existing CDN/origin records. API and OTT must remain HTTPS under the same registrable domain as the CDN.
2. Valid TLS certificate/key for the new hostname, provisioned through the operator's existing method. Template paths are `/etc/letsencrypt/live/cdn.zentunnel.net/{fullchain,privkey}.pem`. No certificate issuance is performed by these source changes.
3. Nginx with `--with-http_secure_link_module`, SSL and proxy modules. Include `deploy/cdn/cdn.zentunnel.net.conf.template` as a new http-context file after review, alongside existing vhosts. It defines a new cache zone/upstream/maps/log format/server; do not paste it inside another server block.
4. Writable, dedicated cache directory `/var/cache/nginx/hyperline-cdn` and log directory owned according to the existing Nginx service account. Use a conservative cache capacity. The template never changes existing cache zones.
5. Root-owned `/etc/nginx/hyperline-cdn/secret.conf` readable only by the Nginx master: `set $hl_signing_secret "<64 lowercase hex ASCII characters>";`. Generate once with a cryptographically secure generator (for example `openssl rand -hex 32` directed to a protected file), securely distribute the same value to Laravel, and do not echo it into logs/chat/shell history. Do not use the public test vector. Avoid `nginx -T` or `docker compose config` without `--quiet`, which can print secrets.
6. `assets.map` from the reviewed registry; `gate.conf` containing `set $hl_cdn_enabled 0;`; `origin-access.conf` containing approved upstream access configuration (or an explicit reviewed empty file if none is required). Restrict permissions on any file containing origin credentials.
7. Merge `deploy/cdn/production.env.example` into HyperLine's API environment through the existing secret-management workflow. Supply the real secret and mount the reviewed registry file into API containers read-only. The example does not change Compose automatically. Configure trust only for the actual reverse proxies so HTTPS is correctly detected. Do not rotate APP_KEY or alter existing database credentials.
8. CORS API: exact OTT/CMS allowlist with `CORS_SUPPORTS_CREDENTIALS=true`; retain bearer auth. CDN: exact `https://ott.zentunnel.net`, `Access-Control-Allow-Credentials: true`, GET/HEAD/OPTIONS, Range, and response headers on success/error. Never use `*` with credentials. Preflight is public metadata only, and only for known canonical asset paths while the gate is open.
9. Synchronize API/CDN UTC clocks using the existing time service. A skewed clock can expire valid tokens early; do not create a long expiry window to mask it. Keep the gate false until live tests pass.

## Deployment sequence — approval required before production changes

Review the new vhost, domain-trust decision, approved asset registry/tree, origin ACL, DNS/TLS plan, cache budget, secret distribution, rollout window and rollback with the CDN operator. None of these production actions have been performed.

After explicit approval:

1. Back up the existing relevant Nginx configuration and HyperLine environment securely. Record baseline old-CDN/origin health and approved test asset behavior. Preserve data and Docker volumes.
2. Stage the **new** files/hostname/certificate with Nginx gate 0 and Laravel verified=false. Keep existing vhosts untouched. Test the actual config with `nginx -t` (not `-T`); verify name routing/TLS without directing real viewers there. Reload only through the operator's approved procedure after a successful test.
3. Deploy the reviewed application changes/registry mounts with verified=false. Do not start production playback yet. Build web/CMS with the correct production API URL. Database schema changes are not required for this integration.
4. In the approved isolated canary, open only the new Nginx gate and validate missing/invalid/expired/cross-movie tokens, a HIT followed by unauthorized access, manifest/segment/init/key CORS/cookies, no credential forwarding, origin ACL, renewal failure on revoked entitlement, and old-CDN/origin health. Keep real traffic gated. Use authorized test content and a protected service workflow for canary cookie generation; never put real secrets into command history or test reports.
5. Only when those real CDN checks pass, set `OTT_NGINX_CDN_VERIFIED=true` for the reviewed API rollout, retaining signing=true and the short TTL. Monitor only sanitized metrics/logs. This is a release gate requiring operator approval, not an instruction to enable it automatically.

## Rollback

Set Laravel `OTT_NGINX_CDN_VERIFIED=false` and restore the new vhost's gate to 0 through the approved environment/config reload workflow. This stops new issuance/renewal and blocks new requests even for existing signed cookies. Already buffered video can finish. Restore the prior application image if needed, but **do not** restore an insecure unsigned production fallback. Remove only the newly added vhost/DNS/certificate if the operator approves; never replace or remove old CDN/origin configuration. Cache deletion is unnecessary; preserve volumes and data. For a key incident, close both gates and rotate the shared signing secret via protected files before reopening after verification.

## Verification and limits

`php artisan test` covers exact format, cookie flags/scope, configuration failure, HTTPS, ownership, current entitlement, source/publication changes, expiry and maximum lifetime. `python3 tests/cdn/run.py` starts an isolated Nginx with temporary cache/config/key and a generated eight-second fMP4 HLS test pattern, verifies secure_link and shared-cache isolation, then runs a real Chromium test over local HTTPS sibling hosts (no system hosts-file changes). It never reads production secrets or reloads production Nginx. Temporary signing material is removed after the run. `bash tests/run-browser-tests.sh` verifies the existing CMS/catalog/player flows separately using only its temporary database.

Actual production DNS/TLS, origin manifest closure, operator ACL, live CORS, live signing-key distribution and native Safari/iOS behavior remain **NOT TESTED** until the approved deployment verification. Local test success does not change either production activation gate.
