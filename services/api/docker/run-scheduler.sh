#!/bin/sh
# Mark only successful schedule passes; stale/missing heartbeat fails health.
set -u
child=''
stopping=0
trap 'stopping=1; if [ -n "$child" ]; then kill -TERM "$child" 2>/dev/null || true; fi' TERM INT
while [ "$stopping" -eq 0 ]; do
    php artisan schedule:run --no-interaction &
    child=$!
    if wait "$child"; then
        php docker/mark-heartbeat.php scheduler "$$"
    fi
    child=''
    [ "$stopping" -eq 0 ] || break
    sleep 60 &
    child=$!
    wait "$child" || true
    child=''
done
