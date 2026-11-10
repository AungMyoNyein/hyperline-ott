#!/usr/bin/env python3
"""Observe Nginx worker handover only; never signal or change a service."""
import argparse
import json
import math
from pathlib import Path
import time


def identity(pid):
    # starttime protects against PID reuse; comm may contain spaces.
    stat = Path(f'/proc/{pid}/stat').read_text().rsplit(')', 1)[1].split()
    return f'{pid}:{stat[19]}'


def snapshot(pid):
    master = identity(pid)
    workers = []
    children = Path(f'/proc/{pid}/task/{pid}/children').read_text().split()
    for child in children:
        try:
            title = Path(f'/proc/{child}/cmdline').read_bytes().split(b'\0', 1)[0]
            if title == b'nginx: worker process':
                workers.append(identity(int(child)))
        except (FileNotFoundError, ProcessLookupError):
            continue
    return {'master': master, 'workers': sorted(workers)}


def wait_generation(pid, before, timeout):
    if not before.get('workers'):
        raise RuntimeError('Baseline contains no active workers')
    deadline = time.monotonic() + timeout
    previous = None
    while True:
        current = snapshot(pid)
        if current['master'] != before['master']:
            raise RuntimeError('Nginx master changed; stop for review')
        old_active = set(current['workers']) & set(before['workers'])
        ready = bool(current['workers']) and not old_active
        if ready and current == previous:
            return current
        previous = current if ready else None
        if time.monotonic() >= deadline:
            raise RuntimeError('Worker handover timed out; no routing success assumed')
        time.sleep(.05)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('mode', choices=['snapshot', 'wait'])
    p.add_argument('--pid-file', default='/run/nginx.pid')
    p.add_argument('--before', type=Path)
    p.add_argument('--timeout', type=float, default=5)
    args = p.parse_args()
    if not math.isfinite(args.timeout) or not 0 < args.timeout <= 30:
        p.error('--timeout must be finite and in (0,30] seconds')
    pid = int(Path(args.pid_file).read_text())
    if args.mode == 'snapshot':
        value = snapshot(pid)
        if not value['workers']:
            raise RuntimeError('No active Nginx workers')
    else:
        if args.before is None:
            p.error('--before is required in wait mode')
        value = wait_generation(pid, json.loads(args.before.read_text()), args.timeout)
    print(json.dumps(value))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError) as error:
        raise SystemExit(f'FAIL Nginx worker observation: {error}')
