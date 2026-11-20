import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: "../../tests/e2e",
  snapshotPathTemplate:
    (process.env.HYPERLINE_UI_SNAPSHOT_DIR ||
      "{testDir}/../../docs/ui/screenshots") + "/{arg}{ext}",
  timeout: 60000,
  workers: 1,
  fullyParallel: false,
  outputDir: "/tmp/hyperline-playwright-results",
  reporter: [["list"]],
  use: {
    headless: true,
    ignoreHTTPSErrors: true,
    launchOptions: {
      args: [
        "--ignore-certificate-errors",
        "--no-sandbox",
        ...(process.env.HYPERLINE_CDN_E2E
          ? [
              "--host-resolver-rules=MAP *.hyperline.test 127.0.0.1",
              "--no-proxy-server",
            ]
          : []),
      ],
    },
    trace: "retain-on-failure",
  },
});
