# Remaining application deployment blockers

The existing CDN and VOD origin must remain unchanged. No new CDN hostname is required. Zentunnel VPN services stay running. The current request explicitly gates application shutdown on a reviewed plan and a later confirmation; see ZENTUNNEL-SHUTDOWN-REVIEW.md.

| Blocker | Status / action |
| --- | --- |
| Existing CDN access-control contract | UNKNOWN: confirm signed URLs/cookies/token service/header/referrer or other method with the operator. HTTP headers cannot identify it. |
| Real CDN authorizer | NOT CONFIGURED: supply the documented protocol and securely provision its existing credentials, then implement the adapter. Never use the test signer in production. |
| Production playback activation | BLOCKED intentionally: verified=false and no unsigned fallback. Activation requires live verification and explicit approval. |
| Rights-approved live test content | Not confirmed. Metadata-only HEAD was checked; live playlist/segment/key downloads and actual playback remain untested. |
| Nested-resource authorization | Unverified for the existing CDN. Signing a master alone does not authorize its child references automatically. |
| Live browser compatibility | Anonymous HEAD succeeded on the sample; OPTIONS returned 403 and wildcard CORS is not credential-compatible. Verify all actual resource requests with the established protocol. |
| Cookie mode, if required by the operator | API and CDN have unrelated registrable domains. Requires the CDN's existing cookie issuance/exchange mechanism and browser-policy verification; the old same-site plan is inapplicable. |
| Application release | Updated source has not been rolled out to running Docker images. Build with the production API address; use the current application proxy/TLS workflow and health checks. |
| Operational health | API/PostgreSQL/Redis healthy; migrations applied. Worker/scheduler health is fixed and locally tested in source, but running images retain old probes until approved app rollout. |
| Public application DNS/TLS/routes | DNS now resolves ott/cms/api to 157.85.104.49. No HyperLine certificate/vhosts are installed; new per-host templates and webroot SAN issuance plan are prepared, pending explicit installation/issuance/reload approval. |
| Production device / load coverage | Safari/iOS native HLS and production load/soak tests remain outstanding. |
| Zentunnel maintenance approval | No shutdown performed. Full API stop is blocked because vpn-api.service also serves VPN device/provisioning routes. Only website/admin HTTP maintenance is proposed, pending explicit confirmation. |

Application deployment with playback still gated can be prepared independently of video infrastructure. This report does not authorize opening playback or changing the CDN, origin, DNS, certificates or VPN services.
