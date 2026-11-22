// Read-only public UAT capture. Replay the same API responses against a local
// production build for comparable screenshots; never import catalog data.
const {
  chromium,
  request,
} = require("../apps/web/node_modules/@playwright/test");
const fs = require("node:fs/promises");
const path = require("node:path");

async function main() {
  const phase = process.env.CAPTURE_PHASE || "before";
  const target = process.env.CAPTURE_URL || "https://ott.zentunnel.net";
  const snapshotFile = "/tmp/hyperline-compact-public.json";
  const output = path.resolve("docs/ui/compact-spotlight");
  await fs.mkdir(`${output}/screenshots`, { recursive: true });
  let snapshot;
  if (phase === "before") {
    const client = await request.newContext();
    snapshot = {};
    for (const endpoint of [
      "/home",
      "/movies",
      "/genres",
      "/series?category=vertical&per_page=12",
    ]) {
      const response = await client.get(
        `https://api.zentunnel.net/api/v1${endpoint}`,
      );
      if (!response.ok()) throw new Error(`${endpoint}: ${response.status()}`);
      snapshot[endpoint] = await response.json();
    }
    await fs.writeFile(snapshotFile, JSON.stringify(snapshot));
    await client.dispose();
  } else snapshot = JSON.parse(await fs.readFile(snapshotFile, "utf8"));
  const browser = await chromium.launch({ args: ["--no-sandbox"] });
  try {
    const metrics = [];
    for (const [width, height] of [
      [360, 800],
      [390, 844],
      [768, 1024],
      [1366, 768],
      [1920, 1080],
    ]) {
      const page = await browser.newPage({ viewport: { width, height } });
      if (phase !== "before")
        await page.route("**/api/v1/**", (route) => {
          const url = new URL(route.request().url());
          const endpoint = url.pathname.split("/api/v1")[1] + url.search;
          const body =
            snapshot[endpoint] || snapshot[url.pathname.split("/api/v1")[1]];
          if (!body) return route.abort();
          return route.fulfill({ json: body });
        });
      for (const [name, route] of [
        ["home", "/"],
        ["movies", "/movies"],
      ]) {
        await page.goto(`${target}${route}`);
        await page
          .locator(name === "home" ? ".hero h1" : ".card h3")
          .first()
          .waitFor();
        await page.evaluate(async () => {
          await document.fonts.ready;
          await Promise.all(
            Array.from(document.images).map((image) =>
              image.complete
                ? Promise.resolve()
                : new Promise((resolve) => {
                    image.addEventListener("load", resolve, { once: true });
                    image.addEventListener("error", resolve, { once: true });
                  }),
            ),
          );
        });
        metrics.push({
          name,
          width,
          height,
          ...(await page.evaluate(() => {
            const box = (selector) => {
              const element = document.querySelector(selector);
              if (!element) return null;
              const { x, y, width, height } = element.getBoundingClientRect();
              return { x, y, width, height };
            };
            return {
              hero: box(".hero"),
              heading: box(".catalog-heading"),
              toolbar: box(".catalog-toolbar"),
              firstCard: box(".card"),
              overflow: document.documentElement.scrollWidth > innerWidth,
            };
          })),
        });
        await page.screenshot({
          path: `${output}/screenshots/${phase}-${name}-${width}.png`,
          fullPage: true,
          animations: "disabled",
        });
      }
      await page.close();
    }
    await fs.writeFile(
      `${output}/${phase}-metrics.json`,
      JSON.stringify(metrics, null, 2) + "\n",
    );
    console.log(`Saved ${phase} screenshots at all five viewports.`);
  } finally {
    await browser.close();
  }
}
main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
