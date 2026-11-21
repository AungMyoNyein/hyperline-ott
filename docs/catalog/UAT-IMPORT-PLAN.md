# UAT approval gate and rollback

This is a proposed procedure, **not executed**. No CSV rows have been imported into live UAT, no records published, no migration executed there, and no running service/image changed. The sealed `dual-source-20261120T140153Z` packet remains untouched. Current UAT is the earlier runtime, not the new feature worktree.

## Prerequisites

1. Review this feature branch on top of `20e49fc`, its regression evidence, full row report, and the 25 held rows. Do not force-push or merge automatically.
2. Build new API/Web/CMS images from the exact approved new commit. Existing candidate images do not include this importer/attribution work. Store image IDs and revision labels in a **new** private release packet, retaining all previous packets, runtime/rollback images, APP_KEY and 29 volumes. No new immutable application images were built by this task; source production builds and isolated CLI tests do not replace image smoke tests.
3. Refresh server/health/migration/backup readiness, take and independently restore a fresh checkpoint in the separately approved window, and smoke-test the exact new immutable images. The schema remains at 13 migrations: no new migration is required by this feature. Do not run migration commands against live UAT merely to install this importer.
4. Obtain separate scoped activation and import approvals. Do not reuse fixture admin credentials or test-only generated tokens. The sole existing UAT admin and published movie are not automatically disposable fixtures. Confirm a dedicated admin/viewer, allowed test writes, verified asset references and no concurrent CMS/import writers.
5. Refresh official metadata verification against the unchanged CSV hash, review row 15 and all playlist/relationship holds. Obtain separate publication approval; import never publishes.

## Image build information

These are future build commands, not executed. Use a clean checkout of the approved new commit, a unique tag, enough disk/memory, and preserve rollback images. Do not retag a sealed candidate.

```bash
hyperline_revision=$(git rev-parse HEAD)
hyperline_tag=catalog-$(date -u +%Y%m%dT%H%M%SZ)-${hyperline_revision:0:12}
docker build --label "org.opencontainers.image.revision=$hyperline_revision" \
  -t "hyperline-api:$hyperline_tag" services/api
docker build --label "org.opencontainers.image.revision=$hyperline_revision" \
  --build-arg NEXT_PUBLIC_API_URL=https://api.zentunnel.net/api/v1 \
  -t "hyperline-web:$hyperline_tag" apps/web
docker build --label "org.opencontainers.image.revision=$hyperline_revision" \
  -t "hyperline-cms:$hyperline_tag" apps/cms
```

Review actual immutable IDs and dependency audits; test candidate and rollback against the restored schema before activation. API must precede Web/CMS; worker/scheduler use the same API image. Follow the newly captured complete Compose packet and approved internal gateway reload procedure. Host Nginx/TLS, DNS, VPN and CDN/origin are outside scope.

## Verification and import commands

Prepare a private operator bundle with the CSV and fresh verification JSON. The verifier fetches metadata and thumbnail headers only; it has no database access.

```bash
python3 tools/verify-youtube-catalog.py \
  /opt/hyperline-ott/HyperLine_Pyone_Play_Mahar_50.csv \
  /PRIVATE/NEW-BUNDLE/verification.json
```

After approval, set `hyperline_packet` to the **new approved packet**, not the old sealed dual-source packet. Its API reference must be the newly reviewed immutable image containing the command. Set `hyperline_bundle` to the reviewed private input bundle. The one-off below uses the captured application environment; never print resolved secrets. It does not build/pull images or start dependencies. Its entrypoint directly runs PHP, so the normal startup public-file copy does not run.

```bash
set -euo pipefail
umask 077
: "${hyperline_packet:?Set the NEW approved private release packet}"
: "${hyperline_bundle:?Set the reviewed private CSV/verification bundle}"
hyperline_batch=myanmar-sample-20261120

# Read-only database plan; reserve a NEW report filename in the operator shell.
test ! -e "$hyperline_bundle/uat-dry-run.json"
docker compose -p hyperline -f "$hyperline_packet/private/candidate.compose.json" \
  run --rm --no-deps --pull never -T --entrypoint php \
  -v "$hyperline_bundle/input.csv:/input/catalog.csv:ro" \
  -v "$hyperline_bundle/verification.json:/input/verification.json:ro" \
  api artisan hyperline:import-youtube-catalog \
  --source-file=/input/catalog.csv --verification-report=/input/verification.json \
  --batch="$hyperline_batch" --dry-run > "$hyperline_bundle/uat-dry-run.json"
```

Review every disposition before proceeding. With the verified input and no matching existing sources, expect 25 proposed movie drafts, 21 relationship holds, one unverified hold and three supplemental-content holds. Counts may decrease when UAT already has matching normalized video IDs. Never blindly assert the isolated created IDs are UAT IDs.

Only after explicit live **draft import** approval, repeat the same command with `--dry-run` omitted and a **new** output filename `uat-import.json`. Preserve the return code, JSON success flag, exact created IDs, source IDs and immutable batch/hash provenance. Re-run dry-run afterward; created records must become `existing_preserved`, with zero proposed duplicates. Compare original published catalog IDs and existing-record fingerprints to the approved baseline; verify draft visibility in CMS, public exclusion, all 8 containers/7 checks/HTTPS 200s, volume mappings and fail-closed policy. Do not use the disposable browser harness or its SQLite paths against UAT.

The importer does not ingest the 25 held records as movies. Resolve series/season mappings with existing verified parents in an isolated restored database, then seek separate approval for a reviewed mapping/import. Unsupported supplemental content stays classified in the report until an appropriate content workflow is approved.

## Proposed publication acceptance

Initially publish nothing. Propose only one independently reviewed, actually embeddable full movie for the first acceptance cycle, selected by normalized video ID and the resulting UAT batch audit record. Require operator approval of that record's rights/embedding status, exact new UAT ID, dedicated viewer/admin, and progress/session writes. Observe the actual official iframe play/pause/error behavior without bypassing regional or owner restrictions. Verify Myanmar search/detail text, intact thumbnail/fallback, original title/channel attribution, mobile/desktop layout and progress/resume where supported. Unpublish that approved test record if acceptance fails. Do not publish all 25 based on oEmbed alone.

Series acceptance needs separate verified parent/season/episode metadata. The 20 series and 2 mini-episode rows are not permission to invent season 1, publish a hierarchy, or infer a second part. Full protected HLS/DASH playback remains unavailable until the existing separate CDN authorization/CORS/nested-resource/renewal gates pass.

## Rollback

Import creates drafts only and never overwrites existing content, so ordinary application rollback retains the expanded schema, new drafts and audit provenance. Use the captured previous application image IDs and approved Compose rollback; keep PostgreSQL, Redis, storage/public volumes and APP_KEY. No database reset, downgrade, blanket restore, source deletion or audit deletion is needed.

For a later approved publication rollback, use the CMS to unpublish **only** exact batch-owned newly created IDs after checking current ownership/publication state and operator approval. Keep records as drafts and retain the import reports. Do not delete existing movies/series or restore an old database over subsequent writes. If a source was edited or an imported record acquired other data, stop automated cleanup and review it. Any actual data-recovery operation needs separate approval and an independent restore plan.
