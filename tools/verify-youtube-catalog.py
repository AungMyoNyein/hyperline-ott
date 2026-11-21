#!/usr/bin/env python3
"""Metadata-only official oEmbed + thumbnail HEAD checks. Never fetch media/images.

Playlist existence/membership requires an authorized Data API check or manual
review; URL syntax and a successful video oEmbed do not prove either embedding
permission in every region or playlist membership.
"""
import argparse
import concurrent.futures
import csv
import datetime
import hashlib
import json
import pathlib
import urllib.error
import urllib.parse
import urllib.request


def inspect(item):
    number, row = item
    result = {"row": number, "youtube_url": row["youtube_url"], "status": "unverified"}
    endpoint = "https://www.youtube.com/oembed?" + urllib.parse.urlencode(
        {"url": row["youtube_url"], "format": "json"}
    )
    try:
        with urllib.request.urlopen(endpoint, timeout=20) as response:
            data = json.loads(response.read(262144))
        result["oembed"] = {key: data.get(key) for key in (
            "title", "author_name", "author_url", "provider_name", "type", "thumbnail_url"
        )}
        result["channel_matches"] = data.get("author_url", "").rstrip("/").lower() == row["channel_url"].rstrip("/").lower()
        result["title_matches"] = " ".join(data.get("title", "").split()) == " ".join(row["youtube_title"].split())
        result["thumbnail_matches"] = data.get("thumbnail_url") == row["artwork_url"]
        if data.get("provider_name") == "YouTube" and data.get("type") == "video" and result["channel_matches"] and result["title_matches"]:
            result["status"] = "verified_metadata"
    except (urllib.error.URLError, ValueError, TimeoutError) as error:
        result["oembed_error"] = getattr(error, "code", type(error).__name__)
    artwork = urllib.parse.urlsplit(row["artwork_url"])
    if artwork.scheme == "https" and artwork.netloc == "i.ytimg.com":
        try:
            request = urllib.request.Request(row["artwork_url"], method="HEAD")
            with urllib.request.urlopen(request, timeout=20) as response:
                result["artwork_http"] = response.status
                result["artwork_content_type"] = response.headers.get("Content-Type", "")
        except (urllib.error.URLError, TimeoutError) as error:
            result["artwork_http"] = getattr(error, "code", type(error).__name__)
    else:
        result["artwork_http"] = "unsupported_url"
    result["playlist_status"] = "not_provided" if not row["series_playlist_url"] else "existence_and_membership_unverified"
    result["embedding_status"] = "not_browser_tested"
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source_file", type=pathlib.Path)
    parser.add_argument("report", type=pathlib.Path)
    args = parser.parse_args()
    raw = args.source_file.read_bytes()
    with args.source_file.open(encoding="utf-8-sig", newline="") as source:
        rows = list(csv.DictReader(source))
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        results = list(pool.map(inspect, enumerate(rows, start=1)))
    report = {
        "source_sha256": hashlib.sha256(raw).hexdigest(),
        "checked_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "method": "Official YouTube oEmbed metadata and thumbnail HEAD only; no media/image body downloads",
        "rows": results,
    }
    args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({"rows": len(rows), "verified_metadata": sum(r["status"] == "verified_metadata" for r in results), "unverified": sum(r["status"] != "verified_metadata" for r in results)}))


if __name__ == "__main__":
    main()
