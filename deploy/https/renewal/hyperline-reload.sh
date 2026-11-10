#!/bin/sh
# STAGING ONLY. Install after HTTPS activation approval, not before first issuance.
# Certbot may invoke this directory hook for OTHER lineages: leave them alone.
set -eu
[ "${RENEWED_LINEAGE:-}" = /etc/letsencrypt/live/hyperline-ott ] || exit 0
/usr/sbin/nginx -t
/usr/bin/systemctl reload nginx.service
