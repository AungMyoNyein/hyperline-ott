import {
  test,
  expect,
  Page,
} from "../../apps/web/node_modules/@playwright/test";
const web = "http://127.0.0.1:18082";
const genre = { id: 1, name: "Drama", slug: "drama" };
const movies = Array.from({ length: 25 }, (_, i) => ({
  id: i + 1,
  slug: `catalog-${i + 1}`,
  title: i % 2 ? `English story ${i + 1}` : `မြန်မာ ရုပ်ရှင် ${i + 1}`,
  synopsis: "A story to discover",
  poster_url: null,
  backdrop_url: null,
  featured: i < 15,
  playable: true,
  published: true,
  release_year: 2026,
  duration_minutes: 90,
  genres: [genre],
}));
async function fixtures(page: Page) {
  const requests: string[] = [];
  let fail = false;
  await page.route("**/api/v1/**", async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.split("/api/v1")[1];
    if (path === "/genres") return route.fulfill({ json: { data: [genre] } });
    if (path.startsWith("/movies/"))
      return route.fulfill({
        json: { data: movies.find((m) => path.endsWith(m.slug)) },
      });
    if (path !== "/movies") return route.fulfill({ json: { data: [] } });
    requests.push(url.search);
    if (fail) {
      fail = false;
      return route.fulfill({
        status: 503,
        json: { message: "Temporary catalog error" },
      });
    }
    const q = url.searchParams.get("q") || "";
    const rows = movies.filter(
      (m) =>
        m.title.includes(q) &&
        (!url.searchParams.has("featured") || m.featured),
    );
    const current = Number(url.searchParams.get("page") || 1);
    await new Promise((resolve) => setTimeout(resolve, 100));
    return route.fulfill({
      json: {
        data: rows.slice((current - 1) * 12, current * 12),
        meta: {
          current_page: current,
          last_page: Math.max(1, Math.ceil(rows.length / 12)),
          total: rows.length,
        },
      },
    });
  });
  return {
    requests,
    failNext: () => {
      fail = true;
    },
  };
}
test("search validates separately and recovers for the same query, empty search, Burmese, English and no results", async ({
  page,
}) => {
  const { requests } = await fixtures(page);
  await page.goto(`${web}/movies`);
  await expect(page.getByRole("status")).toContainText("25 movies");
  const input = page.getByRole("searchbox", { name: "Search movies" });
  await input.fill("x");
  await input.press("Enter");
  await expect(page.locator("main").getByRole("alert")).toContainText(
    "at least two",
  );
  await input.fill("");
  await input.press("Enter");
  await expect(page.locator("main").getByRole("alert")).toHaveCount(0);
  await expect(page.locator(".card")).toHaveCount(12);
  await input.fill("English");
  await input.press("Enter");
  await expect(page.getByRole("status")).toContainText("12 movies");
  await input.fill("x");
  await input.press("Enter");
  await input.fill("English");
  await input.press("Enter");
  await expect(page.locator("main").getByRole("alert")).toHaveCount(0);
  await expect(page.locator(".card")).toHaveCount(12);
  await input.fill("မြန်မာ");
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("13 movies");
  await expect(page.locator(".card h3").first()).toHaveAttribute("lang", "my");
  await input.fill("zzzz-nothing");
  await input.press("Enter");
  await expect(
    page.getByRole("heading", { name: "No movies found" }),
  ).toBeVisible();
  await expect(page.getByRole("status")).toContainText("0 movies");
  await page.getByRole("button", { name: "Clear search" }).click();
  await expect(input).toHaveValue("");
  await expect(page.getByRole("status")).toContainText("25 movies");
  expect(requests.some((q) => new URLSearchParams(q).get("q") === "x")).toBe(
    false,
  );
});
test("loading, retry and submitting an unchanged query recover after request errors", async ({
  page,
}) => {
  const fixture = await fixtures(page);
  await page.goto(`${web}/movies?q=English`);
  await expect(page.getByRole("status")).toContainText("12 movies");
  fixture.failNext();
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await expect(page.locator("main").getByRole("alert")).toContainText(
    "Temporary catalog error",
  );
  await page.getByRole("button", { name: "Retry", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("Loading movies");
  await expect(page.getByRole("status")).toContainText("12 movies");
  fixture.failNext();
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await expect(page.locator("main").getByRole("alert")).toBeVisible();
  await page.getByRole("searchbox").press("Enter");
  await expect(page.getByRole("status")).toContainText("12 movies");
  await expect(page.locator("main").getByRole("alert")).toHaveCount(0);
});
test("genre, featured, query and page survive details return, browser back and reload", async ({
  page,
}) => {
  await fixtures(page);
  await page.goto(`${web}/movies?q=story&genre=drama&page=1`);
  await expect(page.getByRole("status")).toContainText("12 movies");
  await page
    .getByRole("link", { name: /Details/ })
    .first()
    .click();
  await page.getByRole("link", { name: "Back to movies" }).click();
  await expect(page.getByRole("searchbox")).toHaveValue("story");
  await expect(page.getByLabel("Genre", { exact: true })).toHaveValue("drama");
  await page
    .getByRole("button", { name: "Clear filters", exact: true })
    .click();
  await page.getByLabel("Genre", { exact: true }).selectOption("drama");
  await page.getByLabel("Featured only").check();
  await expect(page.getByRole("status")).toContainText("15 movies");
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page.locator(".pagination")).toContainText("Page 2 of 2");
  const catalogUrl = page.url();
  await page
    .getByRole("link", { name: /Details/ })
    .first()
    .click();
  await expect(
    page.getByRole("link", { name: "Back to movies" }),
  ).toBeVisible();
  await page.goBack();
  await expect(page).toHaveURL(catalogUrl);
  await expect(page.locator(".pagination")).toContainText("Page 2 of 2");
  await page
    .getByRole("link", { name: /Details/ })
    .first()
    .click();
  await page.getByRole("link", { name: "Back to movies" }).click();
  await expect(page).toHaveURL(catalogUrl);
  await page.reload();
  await expect(page.getByLabel("Genre", { exact: true })).toHaveValue("drama");
  await expect(page.getByLabel("Featured only")).toBeChecked();
  await expect(page.locator(".pagination")).toContainText("Page 2 of 2");
  await page
    .getByRole("button", { name: "Clear filters", exact: true })
    .click();
  await expect(page.getByRole("status")).toContainText("25 movies");
});
for (const [width, height] of [
  [360, 800],
  [390, 844],
  [768, 1024],
  [1366, 768],
  [1920, 1080],
]) {
  test(`catalog layout and accessible actions at ${width}px`, async ({
    page,
  }) => {
    await fixtures(page);
    await page.setViewportSize({ width, height });
    await page.goto(`${web}/movies`);
    await expect(page.locator(".card")).toHaveCount(12);
    await page.evaluate(() => document.fonts.ready);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
    const heading = await page.locator(".catalog-heading").boundingBox();
    expect(heading!.height).toBeGreaterThanOrEqual(width <= 600 ? 120 : 160);
    expect(heading!.height).toBeLessThanOrEqual(width <= 600 ? 160 : 220);
    for (const action of await page.locator(".card-actions a").all()) {
      const box = await action.boundingBox();
      expect(box!.height).toBeGreaterThanOrEqual(44);
    }
    await expect(
      page
        .locator(".card-actions")
        .first()
        .getByRole("link", { name: /Watch/ }),
    ).toHaveAttribute("href", "/watch?id=1");
    await page.screenshot({
      path: `/tmp/hyperline-movies-layout-${width}.png`,
      fullPage: true,
    });
  });
}

test("Burmese titles use the bundled Unicode font", async ({ page }) => {
  await fixtures(page);
  await page.goto(`${web}/movies?q=${encodeURIComponent("မြန်မာ")}`);
  await expect(page.locator(".card h3").first()).toHaveAttribute("lang", "my");
  await page.evaluate(() => document.fonts.ready);
  const client = await page.context().newCDPSession(page);
  await client.send("DOM.enable");
  await client.send("CSS.enable");
  const { root } = await client.send("DOM.getDocument");
  const { nodeId } = await client.send("DOM.querySelector", {
    nodeId: root.nodeId,
    selector: ".card h3",
  });
  const { fonts } = await client.send("CSS.getPlatformFontsForNode", {
    nodeId,
  });
  expect(
    fonts.some(
      (font) =>
        font.isCustomFont && /NotoSansMyanmar/i.test(font.postScriptName),
    ),
  ).toBe(true);
  expect(
    await page
      .locator(".card h3")
      .first()
      .evaluate((el) => getComputedStyle(el).letterSpacing),
  ).toBe("normal");
  await client.detach();
});

test("detail navigation carries only public filters and handles repeated return parameters", async ({
  page,
}) => {
  await fixtures(page);
  await page.goto(
    `${web}/movies?genre=drama&token=private-value&next=https://example.invalid`,
  );
  await expect(page.locator(".card")).toHaveCount(12);
  const details = page.getByRole("link", { name: /Details/ }).first();
  const href = await details.getAttribute("href");
  expect(href).not.toContain("private-value");
  expect(href).not.toContain("example.invalid");
  await details.click();
  await expect(
    page.getByRole("link", { name: "Back to movies" }),
  ).toHaveAttribute("href", "/movies?genre=drama");
  await page.goto(
    `${web}/movies/catalog-1?from=/movies&from=/movies?genre=drama`,
  );
  await expect(
    page.getByRole("link", { name: "Back to movies" }),
  ).toHaveAttribute("href", "/movies");
  await page.goto(
    `${web}/movies/catalog-1?from=${encodeURIComponent("/movies?genre=drama&token=private-value")}`,
  );
  await expect(
    page.getByRole("link", { name: "Back to movies" }),
  ).toHaveAttribute("href", "/movies?genre=drama");
});
