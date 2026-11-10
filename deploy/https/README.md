# Staged host HTTPS configuration — no installation performed

- `bootstrap/zz-hyperline-*.conf`: separate HTTP-only ACME sites; non-challenge paths return 503 until activation.
- `sites/zz-hyperline-*.conf`: final per-host HTTP ACME/308 redirect and HTTPS gateway routing.
- `00-hyperline-http-common.conf`: NEW http-context map/log format (install as `conf.d/zz-hyperline-http-common.conf`).
- `snippets/hyperline-edge-proxy.conf`: NEW location include preserving Host, path/query and authoritative edge forwarding headers.
- `renewal/hyperline-reload.sh`: scoped renewal hook, inert for any lineage except the new `hyperline-ott` SAN certificate. Install only after activation approval.
- `gateway.conf.staged`: optional gateway Upgrade support. Current production gateway drops Upgrade headers. Its workspace config is a live mount: do not overwrite it without the separate gateway-change approval.

Use the `zz-` site names so the existing `zentunnel` site remains the first/default server. Do not add default_server. The old aggregate `deploy/hyperline-host-nginx.conf.template` is a historical sketch; these per-host files supersede it for installation.

No CDN/origin configuration, service stop, DNS change, certificate issuance or automatic deployment is included. See `docs/HYPERLINE-HTTPS-REVIEW.md` for exact approved-operation commands and rollback.
