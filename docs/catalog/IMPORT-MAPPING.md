# Myanmar YouTube sample catalog

The importer targets the 13-migration schema and the dual-source application at `20e49fc305932a3a3189395725e069930369af91`, which includes Premium UI revision `3378d17e058d51d62cbf803315f1334abc8909f7`. It does not imply that either revision is deployed. The attached UAT runtime remains the earlier release.

The requested `/root/HyperLine_Pyone_Play_Mahar_50(1).csv` was absent. The actual input was `/opt/hyperline-ott/HyperLine_Pyone_Play_Mahar_50.csv`, SHA-256 `722204f44ba1fa1f6ae3d636e08a44bbd596520223e3b5e4e443482ecc7b0703`. It is UTF-8 with a BOM, 50 data rows, and ten unique headers. Burmese text is preserved; it is not transliterated or rewritten. [validation-report.json](validation-report.json) classifies every row and records actual isolated dispositions. The CSV and private database/verification artifacts are not committed.

## Mapping

| CSV field | Handling |
| --- | --- |
| `title` | Movie/episode title, unchanged; never derive a slug by transliterating Burmese. Movie slug is a stable hash of the case-sensitive video ID. |
| `youtube_title` | Original title retained in import provenance; shown with source attribution on details/playback. No description/cast/genre is inferred from it. |
| `video_kind` | Explicit classification below; never confuse transport `youtube` with the content classification. |
| `youtube_url` | Existing `YouTubeUrl` validation and canonical `https://www.youtube.com/watch?v=ID`; duplicate detection normalizes all existing YouTube source URLs, including legacy short/embed forms. |
| `channel_url` | Restrict to supplied Pyone Play/Mahar handles; compare independently to official oEmbed author URL. Show YouTube and verified channel attribution. |
| `series_playlist_url` | Validate URL syntax; retain supplied link. Existence/membership remain unverified; never derive series/season/episode relationships from it. |
| `artwork_url` | Only HTTPS `i.ytimg.com/vi/VIDEO_ID/...jpg` for the same ID. Use the remote URL only after a successful image HEAD result; otherwise store null and show fallback. No server download, Next image optimization or storage copy. |
| `artwork_type` | Must be `youtube_thumbnail` when artwork is present. Thumbnail content is displayed without cropping. |
| `artwork_check`, `youtube_check` | Retain as supplier claims, never treat them as verification evidence. |

Synopses, cast, ratings, genres, durations, language labels, release/publication dates and season metadata are absent. No values are invented. Imported records are always unpublished, unfeatured drafts with no publication timestamp. The command has no `--publish`, update-existing or bypass-unverified option.

| Classification | CSV count | Actual isolated result |
| --- | ---: | --- |
| `full_movie` | 25 | 25 new draft Movies and 25 canonical YouTube sources |
| `series_episode` | 20 | 19 held for relationship mapping; row 15 additionally held as unverified |
| `mini_episode` | 2 | 2 held for relationship mapping |
| `trailer` | 1 | Supplemental classification retained in report; no Movie created |
| `film_promo` | 1 | Supplemental classification retained in report; no Movie created |
| `fan_meet` | 1 | Supplemental classification retained in report; no Movie created |

All 50 rows are structurally valid. There are zero duplicate normalized video IDs. Independent official oEmbed metadata matches channel attribution and original title for 49 rows; row 15 (`zRSYiKFFf98`, `မောင့်မြဒါလီ`) returns HTTP 401 and remains unverified. All 50 thumbnail HEAD responses were 200. There are 25 supplied playlist links; their existence and membership are **not verified**, even where the URL syntax is valid. Video oEmbed success does not prove playlist validity or playable embedding in every environment.

## Command and integrity behavior

```bash
php artisan hyperline:import-youtube-catalog \
  --source-file=/input/catalog.csv \
  --verification-report=/input/verification.json \
  --batch=myanmar-sample-20261120 \
  --dry-run
```

`--batch` is required and may not be reused with a different CSV SHA-256. `--source-file` is required. `--report=/new/report.json` optionally reserves a new report file before any DB mutation and refuses existing files, including input files. JSON output includes every data row, original metadata, validation errors/warnings, classification, verification, duplicate references, disposition and actual created IDs. IDs in isolated test reports are **not** proposed UAT IDs.

The importer validates the entire CSV before any write; any structurally invalid/duplicate row aborts the whole import. Eligible writes use one transaction. PostgreSQL importer invocations serialize with a transaction-scoped advisory lock. Existing content, even existing drafts, is never overwritten or claimed by a new batch. Normalize legacy sources before duplicate comparison. A hash-slug conflict or occupied episode position is held for review. Concurrent external CMS writers do not honor the importer lock: use a controlled import window and rerun the dry-run immediately before approved execution.

Dry-run uses SELECTs only: it does not insert and roll back, consume PostgreSQL sequences, write audit entries, create users, or fetch external URLs. Unverified rows are held by default. A verification report is operator-reviewed input, not a cryptographic attestation. It must match the exact CSV hash, contain unique row numbers, and be no older than 30 days. Its author/title/provider evidence is checked again rather than trusting a `status` string alone.

No schema migration is needed. New-record provenance uses the existing indexed `admin_audit_logs` polymorphic target, action `catalog.youtube.imported`, null CLI user, explicit batch/hash/row, canonical video ID and supplied metadata. The audit target is the Movie or SeriesEpisode, so the CMS's wholesale source replacement retains provenance when the normalized video ID is unchanged. A changed video ID never inherits the previous video's attribution. Public resources expose only a small attribution whitelist, never audit properties/batch IDs or protected media URLs. Keep these import-provenance records with their owned content.

## Episode mapping

The CSV supplies episode numbers within some original titles but no season numbers or established parent IDs. Automatically creating season 1 would invent metadata. The importer therefore creates no parent hierarchy. A reviewer may provide `--episode-map=/input/episode-map.json`:

```json
{
  "uMHr8FocWaA": {
    "series_id": 123,
    "season_id": 456,
    "episode_number": 1,
    "evidence": "Reviewed supplied Episode 1 title and independently established parent IDs"
  }
}
```

The numbers above are placeholders, not known UAT records. The existing series and season must both be drafts; season ownership and the CSV series title must match. The episode number must be explicitly and unambiguously present in the original title, including Myanmar digits; contradictory numbers are held. Existing episode positions are never overwritten. No season number, second part, missing episode or playlist membership is inferred. Establish missing series/season metadata and test the reviewed mapping in isolation before requesting any live write.

Trailers, promotions and fan events need a separately reviewed supplemental-content workflow or explicit association with an existing title. They are deliberately retained as row-level classified review records rather than misrepresented as standalone full movies.

## YouTube verification, artwork and playback

`tools/verify-youtube-catalog.py INPUT.csv OUTPUT.json` uses only official YouTube oEmbed metadata and thumbnail HEAD requests, with four concurrent workers. It does not scrape watch pages, download videos/images, query protected origins, or claim every entry is embeddable. No YouTube Data API key was supplied; playlist existence/membership remains an explicit review task. Keep raw metadata evidence private and refresh it before publication and within the applicable API-data retention interval; stale reports cannot authorize a new import. The provided CSV's provenance is retained separately from claims that API data is current.

The existing official IFrame Player API, native controls, embed errors (including owner-disabled embedding), progress adapter, seek/resume and owner-safe navigation remain. Attribution is visible in movie cards/details, CMS and playback; original YouTube titles appear on details/playback. Remote artwork uses browser image requests and a responsive fallback. Playback tests distinguish deterministic player-contract mocks from actual external playback of Google's official `M7lc1UVf-VE` developer sample. No individual CSV film's browser embedding/rights/region availability is asserted.

HLS.js/native HLS and DASH transports remain unchanged. Signed protected media remains fail-closed; no unsigned URL fallback or CDN/origin setting is introduced. Public catalog responses still hide media video IDs/locations until an authorized playback session.

Primary references: [YouTube IFrame API](https://developers.google.com/youtube/iframe_api_reference), [developer policy guide](https://developers.google.com/youtube/terms/developer-policies-guide), [developer policies](https://developers.google.com/youtube/terms/developer-policies). Metadata verification is not permission to rehost copyrighted media or bypass embed restrictions.
