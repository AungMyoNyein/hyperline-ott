#!/usr/bin/env python3
"""Validate the reviewed registry and emit the matching Nginx map (no secrets)."""
import json
import re
import sys
assets = json.load(open(sys.argv[1]))
if not isinstance(assets, dict) or not assets:
    raise SystemExit('Expected a nonempty reviewed asset registry')
lines = []
for source, asset in assets.items():
    if not re.fullmatch(r'[1-9][0-9]*', source) or not isinstance(asset, dict):
        raise SystemExit('Invalid source ID or asset')
    movie = asset.get('movie_id')
    directory = asset.get('origin_directory', '')
    manifest = asset.get('manifest', '')
    if type(movie) is not int or movie < 1 or not re.fullmatch(r'/(?:[A-Za-z0-9_-]+/)+', directory) or not re.fullmatch(r'[A-Za-z0-9_-][A-Za-z0-9_.-]*\.m3u8', manifest):
        raise SystemExit('Invalid asset mapping')
    lines.append(f'"/hls/{movie}/{source}/" "{directory}";')
print('\n'.join(lines))
