import { test, expect } from "../../apps/web/node_modules/@playwright/test";
import AxeBuilder from "../../apps/web/node_modules/@axe-core/playwright";
import { mkdirSync } from "node:fs";
import { resolve } from "node:path";
const web = "http://127.0.0.1:18082",
  cms = "http://127.0.0.1:18083";
const shots =
  process.env.HYPERLINE_GENRE_SCREENSHOTS ||
  resolve(__dirname, "../../docs/genre-cms/screenshots");
for (const width of [360, 390, 768, 1366, 1920]) {
  test(`genre CMS, Unicode discovery and accessibility at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    if (process.env.HYPERLINE_IMAGE_CANDIDATE === "1") {
      // Production image API URLs are intercepted before any browser request
      // leaves localhost. All writes reach only the disposable fixture API.
      await page.route("https://api.zentunnel.net/api/v1/**", async (route) => {
        const url = route
          .request()
          .url()
          .replace(
            "https://api.zentunnel.net/api/v1",
            "http://127.0.0.1:18081/api/v1",
          );
        await route.fulfill({ response: await route.fetch({ url }) });
      });
    }
    // No third-party asset downloads: deterministic local artwork fixture.
    await page.route(
      /https:\/\/(artwork\.hyperline\.test|i\.ytimg\.com)\//,
      (route) =>
        route.fulfill({
          contentType: "image/svg+xml",
          body: '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="900"><rect width="100%" height="100%" fill="#302028"/></svg>',
        }),
    );
    mkdirSync(shots, { recursive: true });
    async function capture(name: string) {
      await page.evaluate(() => document.fonts.ready);
      await expect
        .poll(() =>
          page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
          ),
        )
        .toBe(true);
      const result = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa"])
        .analyze();
      expect(result.violations).toEqual([]);
      await page.screenshot({
        path: resolve(shots, `${name}-${width}.png`),
        fullPage: true,
      });
    }
    await page.goto(cms);
    await page
      .getByLabel("Email", { exact: true })
      .fill("admin@hyperline.test");
    await page
      .getByLabel("Password", { exact: true })
      .fill("TestPassword!2026");
    await page.getByRole("button", { name: "Sign in", exact: true }).click();
    await expect(page.getByLabel("Dashboard", { exact: true })).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Save homepage order", exact: true }),
    ).toBeEnabled();
    await capture("cms-dashboard");
    if (width === 1366) {
      await page
        .getByRole("button", { name: "Move Trending Movies up", exact: true })
        .click();
      await page
        .getByRole("button", { name: "Save homepage order", exact: true })
        .click();
      await expect(
        page.getByRole("status").filter({ hasText: "Homepage order saved" }),
      ).toBeVisible();
      const order = await page.request.get(
        "http://127.0.0.1:18081/api/v1/home",
      );
      expect((await order.json()).data.section_order[0]).toBe("trending");
    }
    async function nav(name: string) {
      if (width <= 900) {
        const menu = page.getByRole("button", {
          name: "Menu",
          exact: true,
        });
        if (await menu.isVisible()) await menu.click();
      }
      await page
        .getByRole("navigation", { name: "Studio navigation" })
        .getByRole("button", { name, exact: true })
        .click();
    }
    await nav("Genres");
    const manager = page.getByRole("region", { name: "Genre management" });
    await manager
      .getByLabel("English name", { exact: true })
      .fill(`Browser genre ${width}`);
    await manager
      .getByLabel("Myanmar name", { exact: true })
      .fill("စွန့်စားခန်း");
    await manager.getByLabel(/^Slug/).fill(`browser-genre-${width}`);
    await manager.getByLabel("Sort order", { exact: true }).fill("5");
    await manager
      .getByRole("button", { name: "Save genre", exact: true })
      .click();
    await expect(
      manager.getByRole("status").filter({ hasText: "Genre saved" }),
    ).toContainText("Genre saved");
    await manager
      .getByLabel("Find genres", { exact: true })
      .fill("စွန့်စားခန်း");
    await expect(
      manager.getByRole("button", {
        name: `Edit Browser genre ${width}`,
        exact: true,
      }),
    ).toBeVisible();
    await capture("cms-genres");
    await manager
      .getByRole("button", { name: `Edit Browser genre ${width}`, exact: true })
      .click();
    await manager.getByLabel("Enabled", { exact: true }).uncheck();
    await manager
      .getByRole("button", { name: "Save genre", exact: true })
      .click();
    await expect(
      manager.getByRole("row").filter({ hasText: `browser-genre-${width}` }),
    ).toContainText("Disabled");
    await nav("Movies");
    await expect(
      page.getByRole("heading", { name: "Create movie", exact: true }),
    ).toBeVisible();
    await capture("cms-movie-editor");
    for (const [path, name] of [
      ["/", "home"],
      ["/movies?genre=test-patterns", "movies-filter"],
      ["/genres", "genres"],
      ["/movies/local-test-pattern", "movie-details"],
      ["/series", "series"],
    ]) {
      await page.goto(web + path);
      await expect(page.locator(".skeleton")).toHaveCount(0);
      await expect(
        page.getByRole("alert").filter({ hasText: /\S/ }),
      ).toHaveCount(0);
      await capture(name);
    }
  });
}
