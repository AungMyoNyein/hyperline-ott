#!/usr/bin/env python3
"""Audit a LOCAL authorized HLS tree before allowlisting. Never downloads media.
Usage: audit-hls-tree.py DIRECTORY master.m3u8
"""
from pathlib import Path
import re
import sys
root=Path(sys.argv[1]).resolve()
seen=set()
path_pattern=re.compile(r'(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*')
def audit(relative):
    if not path_pattern.fullmatch(relative):
        raise ValueError(f'Noncanonical or external HLS resource: {relative}')
    target=(root/relative).resolve()
    if not target.is_relative_to(root) or not target.is_file():
        raise ValueError(f'Missing resource or unsafe symlink: {relative}')
    if target in seen:return
    seen.add(target)
    with target.open('rb') as stream:
        is_playlist=stream.read(7)==b'#EXTM3U'
    if not is_playlist:
        if target.suffix.lower()=='.m3u8':raise ValueError(f'Invalid playlist: {relative}')
        return
    text=target.read_text(encoding='utf-8')
    for line in text.splitlines():
        line=line.strip()
        if not line:continue
        if line.startswith('#'):
            resources=re.findall(r'\bURI="([^"]*)"',line)
            if len(re.findall(r'\bURI=',line))!=len(resources):
                raise ValueError(f'Malformed URI attribute in {relative}')
        else:resources=[line]
        for uri in resources:
            if not path_pattern.fullmatch(uri):raise ValueError(f'Unsupported URI {uri} in {relative}')
            child=str(Path(relative).parent/uri)
            audit(child)
try:
    audit(sys.argv[2])
except (ValueError,OSError,UnicodeError) as error:
    raise SystemExit(str(error))
print(f'PASS {len(seen)} local playlists/resources confined to the approved directory')
