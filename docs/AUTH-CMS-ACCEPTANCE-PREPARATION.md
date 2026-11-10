# Authentication and disposable CMS acceptance — prepared, NOT deployed

Local changes and isolated verification only. Production users, the two existing unpublished movies, genres, token rows, database/schema/volumes, Nginx/TLS, VPN, CDN/origin, container images and services are not changed by this preparation. No production account is created. Do not run production commands below until the stated separate approvals are given.

## Existing administrator provisioning

The deployed command supports `hyperline:admin {email} {name=HyperLine Admin} {--password=}`. It reads its fallback bootstrap password from `ott.admin.initial_password` / HYPERLINE_INITIAL_ADMIN_PASSWORD, hashes it, creates a new user or resets a matching user's name/password, verifies email if absent, and attaches the `admin` role with syncWithoutDetaching. Other users are preserved, but the legacy command is not create-only. Do not use it against the existing production administrator for acceptance. No existing password/hash/token was retrieved to make this determination.

The local prepared revision adds:

- `--create-only`: reject an already-existing email case-insensitively, before modifying a user or role.
- `--password-stdin`: read a single line from standard input without echo; never fall back to the bootstrap password in this mode. Empty input and conflicting `--password` are refused. Nothing prints the supplied password. The legacy options remain for compatibility; do not use password arguments for production provisioning.

**These new flags do not exist in the currently deployed image.** First obtain approval for a reviewed deployment, validate the new command's help in that image, then obtain approval for the specific dedicated email/account before provisioning. Use a unique operator-approved email and an operator-supplied strong password stored in a password manager; do not generate or print it in logs.

### Exact secure provisioning command — after deployment and account-creation approval only

Run in an operator-controlled Bash shell, outside terminal/session recording, with shell tracing disabled. The password is held only in shell memory, passed by a Bash builtin over stdin, never exported or placed in argv/history. Do not replace this with `--password` or `docker exec -e PASSWORD=...`.

```bash
set -euo pipefail
set +x
trap 'unset HYPERLINE_TEST_ADMIN_EMAIL HYPERLINE_TEST_ADMIN_PASSWORD' EXIT
IFS= read -r -p 'Approved dedicated administrator email: ' HYPERLINE_TEST_ADMIN_EMAIL
IFS= read -r -s -p 'Operator-provided test password: ' HYPERLINE_TEST_ADMIN_PASSWORD
builtin printf '\n'
builtin printf '%s\n' "$HYPERLINE_TEST_ADMIN_PASSWORD" |
  docker exec -i hyperline-api-1 php artisan hyperline:admin \
    "$HYPERLINE_TEST_ADMIN_EMAIL" 'HyperLine Acceptance Administrator' \
    --create-only --password-stdin --no-interaction
unset HYPERLINE_TEST_ADMIN_PASSWORD HYPERLINE_TEST_ADMIN_EMAIL
trap - EXIT
```

An existing email must result in refusal, not a reset. Stop on failure; do not retry without --create-only. Do not reuse a bootstrap secret or an existing user's password. The command is not executed as part of this document or preparation.

## Proposed absolute token policy

`SANCTUM_TOKEN_EXPIRATION=1440` configures minutes; default 1,440 = 24 hours and config clamps zero/invalid numeric input to a minimum one minute so zero cannot disable expiry. A future deployment must validate a positive integer configuration and the intended value before rollout.

- `config/sanctum.php` sets the global creation-age cutoff.
- `IssueAccessToken` sets each new token's explicit `expires_at` when viewer registration/login or CMS login issues it. The API also returns expiry metadata.
- Reads update only normal last-use metadata; they do not slide the deadline. There is no refresh-token or silent re-login path. Increasing the global lifetime does not extend an already-issued explicit deadline.
- Existing tokens without expires_at are bounded by creation time once the new global policy is deployed. Tokens older than the configured age are rejected; plan for affected users to sign in again. No token migration/deletion is executed now.
- Existing logout endpoints delete only the current bearer token. Other sessions remain intact. Expired/revoked tokens return JSON 401; an expired logout request cannot mint or extend a token.
- Sanctum API guards are deliberately bearer-only (`guard => []`). The current React clients keep bearer tokens in memory; a web-cookie session must not substitute for a valid token or bypass its deadline. The existing stateful/CSRF middleware is not removed. Any future cookie-login API design needs its own reviewed session-expiry policy.
- Refreshing a tab clears its in-memory credential; that alone does not revoke the server token. The server deadline and logout revocation provide enforcement. CMS handles a 401 by showing sign-in again; OTT playback/watchlist flows clear their session on 401. No browser timer or token refresh is added.
- No schema change is needed: personal_access_tokens.expires_at already exists. Expired-row pruning is not added or run in this scope.

Both environment example files document the new variable. The live .env/config cache, existing production tokens and deployed images remain untouched; live runtime still reports the old null expiry until deployment is approved.

## Disposable production CMS lifecycle — prepared, NOT executed

Runner: `tools/cms-disposable-acceptance.py`. Default invocation is plan-only and makes no network request. Running it in production requires an explicit approval for the dedicated test identity, an approved credential-free HLS metadata URL and temporary publication of a clearly marked test movie. It is API lifecycle acceptance; successful visual CMS/browser login/logout should additionally use the existing `deploy/https/browser-smoke.cjs` with securely supplied approved administrator/viewer credentials.

After separate lifecycle execution approval only:

```bash
set -euo pipefail
cd /opt/hyperline-ott
: "${HYPERLINE_TEST_ADMIN_EMAIL:?Set only the separately approved dedicated account email}"
: "${HYPERLINE_ACCEPTANCE_HLS_URL:?Set an approved credential-free HTTPS .m3u8 metadata URL}"
python3 tools/cms-disposable-acceptance.py \
  --execute --confirm DISPOSABLE-CMS-LIFECYCLE \
  --approved-admin-email "$HYPERLINE_TEST_ADMIN_EMAIL" \
  --hls-source "$HYPERLINE_ACCEPTANCE_HLS_URL" \
  --report /tmp/hyperline-production-cms-acceptance.json
```

The runner prompts using getpass; do not paste the password into a command. The optional --password-stdin accepts an operator's hidden-input pipe, not an environment/bootstrap fallback. It rejects HTTP production targets, redirect following and credential/query-bearing HLS URLs. Local-fixture HTTP is restricted to 127.0.0.1:18081 and is never used for production. Responses/tokens/passwords are not logged; credentials stay in memory; reports are mode 0600.

Lifecycle and cleanup:

1. Sign in with the approved dedicated account, verify /me email/admin role, snapshot the complete existing movie inventory as IDs plus in-memory hashes.
2. Read `/genres`. Assign an explicitly selected existing slug, or an existing available slug. With no genres, report genre assignment/filtering NOT TESTED; never POST an unsupported genre endpoint.
3. Create exactly one draft with unique `hyperline-acceptance-<UTC>-<random marker>` slug and `[ACCEPTANCE ONLY]` title. The returned ID is the only permitted mutation/deletion target.
4. Attach the approved HLS metadata with source **disabled**, verify it, edit synopsis/source quality, and verify draft detail is public 404. No HLS/segment URL is fetched and no signing bypass is introduced.
5. Publish the marked movie briefly, verify anonymous catalog/detail and existing genre-filter visibility, and verify no source URL is disclosed. A disabled source remains unplayable; this is metadata/publication acceptance, not a CDN playback test.
6. Unpublish, verify anonymous detail 404, then delete only that created ID; verify both admin and public detail are absent.
7. Recompare every pre-existing movie ID/metadata hash; never restore or edit someone else's record even if a concurrent change is detected. Log out and verify the test token is rejected.

Before every mutation/cleanup, verify returned ID, exact unique slug/title marker, creator ID and absence from the pre-run inventory. The finally block cleans up only that acknowledged record. It will not adopt records by prefix/query or delete an unacknowledged POST result. If creation response is lost, report the unique run marker for operator review; never guess an ID. If ownership verification, cleanup authorization or deletion fails, stop and report only the run's recorded ID for manual scoped recovery. Do not bulk-delete movies or existing users. Test login/logout naturally updates only the approved account's last-login/current token and audit rows; no other user is a test fixture.

Current production has no genres, so a production run will explicitly leave genre assignment/filtering untested unless a genre is provisioned through a separately approved supported operational process. No genre API is added here.

## Local verification

- Laravel: 193 tests / 839 assertions pass using forced in-memory SQLite, array cache/sessions and no production database.
- Administrator command focused tests: 5 pass after the final warning-output adjustment.
- Cleanup/default-no-execute guard tests: 5 pass, including refusal of pre-existing IDs, foreign creators and wrong markers.
- Next.js web and CMS production builds pass in /tmp/hyperline-auth-acceptance-uegv7urg. Existing CSS `align-items:end` compatibility warnings are non-blocking; no styling change was bundled.
- Isolated Playwright integration: 5 pass; one separate legacy HttpOnly CDN-cookie suite skipped because that fixture is outside this run. Coverage includes real fixture CMS login/CRUD/publish/unpublish/delete/logout, catalog/detail/genre/mobile, generated HLS/quality/fullscreen, CORS errors and signed URL renewal. These are local fixture tests, not production signing acceptance.
- The new disposable lifecycle runner passes 28 checks against only the local SQLite API fixture and verifies original fixture movies unchanged and its own record removed.

The isolated browser copy has no production .env, uses dedicated loopback ports 18081–18084, clears DB_URL, pins its local config-cache path and writes only the dedicated /tmp/hyperline-browser.sqlite and generated local media. Its dependencies are shared read-only source dependencies, not deployed images. Production preservation is recorded under deploy/reports/auth-cms-preparation-20261110.

## Deployment approval and rollback

No build tag, deployed image or live user was changed. Before a future approved deployment, capture immutable current image IDs and non-secret runtime state, review an immutable candidate release for API and both frontends (and the shared API worker/scheduler image policy), confirm the positive expiry value and cookie-auth behavior, then obtain final rollout approval. No migration, production Nginx/TLS change, VPN restart, CDN edit, Docker volume action or live config-clear is required/authorized by this preparation.

If a future application rollout fails, restore only its reviewed previous application images via the service-specific rollout/rollback runbook, keeping data/volumes and host configuration intact. Do not `git reset` the shared dirty checkout or revert earlier work wholesale. A rollback to the old API can restore its unbounded creation-age policy for legacy null-expiry tokens, although explicit new-token expires_at is still enforced by Sanctum. Review that security consequence; prefer a forward fix or a compatible rollback release retaining finite expiry. Do not silently re-enable the legacy policy or revoke other users' tokens.

If a future disposable test fails, unpublish/delete only its acknowledged, ownership-verified movie using the runner's scoped cleanup; preserve existing records and audit history. Revoke only the test run's session if cleanup is separately needed. Keep the dedicated account for subsequent tests unless separately approved deletion is requested and its created identity is verified. Never reset the original administrator or delete all tokens/users.

## Remaining production blockers / approval boundary

Prepared-code tests pass; production authentication/CMS acceptance remains pending. Required next approvals: reviewed deployment first; dedicated test email/account creation after the new safe command is deployed; disposable lifecycle and successful existing-account CMS/viewer browser smoke. Production still has one administrator, two unpublished movies, zero genres and null token expiry. The live runtime intentionally remains unchanged.

Protected CDN signing/protocol integration and certificate renewal dry-run remain separate tasks/approvals. No signing bypass, unsigned production playback, renewal operation or infrastructure change is included here. Stop before production account creation or deployment.
