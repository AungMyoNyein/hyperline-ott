#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Build and run a secret-free copy so local builds never replace the checkout's
# release assets or read deployed .env/config caches. Dependencies are reused.
if [ "${HYPERLINE_BROWSER_ISOLATED:-0}" != 1 ]; then
    fixture_root=$(mktemp -d /tmp/hyperline-phase1-e2e-XXXXXX)
    python3 - "$PWD" "$fixture_root" <<'ISOLATE'
import pathlib, shutil, sys
source, target = map(pathlib.Path, sys.argv[1:])
def ignore(directory, names):
    return [n for n in names if n in {'node_modules','vendor','.next','.git','reports','logs','cache','database.sqlite','tsconfig.tsbuildinfo'} or n.startswith('.env')]
for name in ('apps', 'services', 'tests'):
    shutil.copytree(source/name, target/name, ignore=ignore)
for name in ('apps/web/node_modules', 'apps/cms/node_modules', 'services/api/vendor'):
    (target/name).symlink_to(source/name, target_is_directory=True)
for name in ('bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'):
    (target/'services/api'/name).mkdir(parents=True, exist_ok=True)
ISOLATE
    export HYPERLINE_BROWSER_ISOLATED=1
    export APP_CONFIG_CACHE="$fixture_root/services/api/bootstrap/cache/config.php"
    export DB_URL=''
    exec bash "$fixture_root/tests/run-browser-tests.sh"
fi
python3 - <<'PY'
import socket
for port in (18081,18082,18083,18084):
    with socket.socket() as sock:
        sock.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1)
        try:
            sock.bind(('127.0.0.1',port))
        except OSError:
            raise SystemExit(f'Test port {port} is busy; stop only the corresponding test server first.')
PY
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/hyperline-browser.sqlite
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export OTT_SIGNED_URLS=false OTT_REJECT_UNRESOLVED_STREAM_HOSTS=false
if [ "${HYPERLINE_SIGNED_FIXTURE:-0}" = 1 ]; then
    export OTT_SIGNED_URLS=true
    export OTT_PLAYBACK_SIGNER='Tests\Support\LoopbackPlaybackSigner'
fi
export CORS_SUPPORTS_CREDENTIALS=true
export CORS_ALLOWED_ORIGINS=http://127.0.0.1:18082,http://127.0.0.1:18083
export NEXT_PUBLIC_API_URL=http://127.0.0.1:18081/api/v1
export PLAYWRIGHT_BROWSERS_PATH=/tmp/hyperline-playwright
export APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
# The fixture database is disposable: start every run from a clean file so
# progress rows or catalog entries from earlier runs cannot leak across specs.
rm -f "$DB_DATABASE"
touch "$DB_DATABASE"
(cd services/api && php artisan migrate --force)
php tests/fixtures/seed.php
mkdir -p /tmp/hyperline-test-media
for variant in low high; do
    if [ "$variant" = low ]; then size=320x180; else size=640x360; fi
    if [ ! -f "/tmp/hyperline-test-media/$variant.m3u8" ]; then
        ffmpeg -hide_banner -loglevel error -f lavfi -i "testsrc2=size=$size:rate=24" -t 8 -c:v libx264 -preset ultrafast -pix_fmt yuv420p -g 48 -sc_threshold 0 -f hls -hls_time 2 -hls_playlist_type vod "/tmp/hyperline-test-media/$variant.m3u8"
    fi
done
cat > /tmp/hyperline-test-media/master.m3u8 <<'MANIFEST'
#EXTM3U
#EXT-X-STREAM-INF:BANDWIDTH=250000,RESOLUTION=320x180
low.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=900000,RESOLUTION=640x360
high.m3u8
MANIFEST
if [ ! -f "/tmp/hyperline-test-media/dash/manifest.mpd" ]; then
    mkdir -p /tmp/hyperline-test-media/dash
    ffmpeg -hide_banner -loglevel error -f lavfi -i "testsrc2=size=320x180:rate=24" -t 8 -c:v libx264 -preset ultrafast -pix_fmt yuv420p -g 48 -sc_threshold 0 -f dash -seg_duration 2 -use_template 1 -use_timeline 1 -init_seg_name 'init-$RepresentationID$.m4s' -media_seg_name 'seg-$RepresentationID$-$Number$.m4s' /tmp/hyperline-test-media/dash/manifest.mpd
fi
openssl req -x509 -newkey rsa:2048 -nodes -keyout /tmp/hyperline-test-media/key.pem -out /tmp/hyperline-test-media/cert.pem -days 1 -subj /CN=127.0.0.1 2>/tmp/hyperline-test-media/cert.log
npm run build --prefix apps/web
npm run build --prefix apps/cms
pids=()
cleanup() { for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || true; done; }
trap cleanup EXIT
# Start directly so the recorded PID owns the actual listener.
(cd services/api/public && exec php -S 127.0.0.1:18081 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) >/tmp/hyperline-test-api.log 2>&1 &
pids+=("$!")
python3 tests/fixtures/media-server.py >/tmp/hyperline-test-media.log 2>&1 &
pids+=("$!")
(cd apps/web && exec node node_modules/next/dist/bin/next start --port 18082 --hostname 127.0.0.1) >/tmp/hyperline-test-web.log 2>&1 &
pids+=("$!")
(cd apps/cms && exec node node_modules/next/dist/bin/next start --port 18083 --hostname 127.0.0.1) >/tmp/hyperline-test-cms.log 2>&1 &
pids+=("$!")
python3 - <<'PY'
import time, urllib.request
for url in ('http://127.0.0.1:18081/api/v1/health','http://127.0.0.1:18082','http://127.0.0.1:18083'):
    for attempt in range(60):
        try:
            with urllib.request.urlopen(url, timeout=1) as response:
                assert response.status == 200
            break
        except Exception:
            if attempt==59: raise
            time.sleep(.5)
PY
npm run test:e2e --prefix apps/web
