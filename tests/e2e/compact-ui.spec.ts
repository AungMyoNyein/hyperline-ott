import {
  test,
  expect,
  Page,
} from "../../apps/web/node_modules/@playwright/test";
import AxeBuilder from "../../apps/web/node_modules/@axe-core/playwright";

const web = process.env.HYPERLINE_COMPACT_WEB_URL || "http://127.0.0.1:18082";
const movie = {
  id: 1,
  slug: "spotlight",
  title: "An English spotlight",
  synopsis: "Discover cinema, series and stories to share.",
  poster_url: "https://i.ytimg.com/vi/fixture1234/hqdefault.jpg",
  backdrop_url: "https://artwork.hyperline.test/backdrop.svg",
  genres: [],
  featured: true,
  published: true,
  playable: true,
  release_year: 2026,
  duration_minutes: 90,
};
const rows = [
  movie,
  {
    ...movie,
    id: 2,
    title: "မြန်မာ ရုပ်ရှင်",
    poster_url: "https://artwork.hyperline.test/portrait.svg",
  },
  {
    ...movie,
    id: 3,
    title: "Missing artwork with a longer readable title",
    poster_url: "https://artwork.hyperline.test/missing.svg",
  },
];

async function fixtures(page: Page, hero: typeof movie | null = movie) {
  await page.route(
    /https:\/\/(artwork\.hyperline\.test|i\.ytimg\.com)\//,
    (route) => {
      const url = route.request().url();
      if (url.includes("missing")) return route.abort();
      const portrait = url.includes("portrait");
      return route.fulfill({
        contentType: "image/svg+xml",
        body: `<svg xmlns="http://www.w3.org/2000/svg" width="${portrait ? 600 : 1600}" height="${portrait ? 900 : 900}"><rect width="100%" height="100%" fill="#302028"/><circle cx="300" cy="300" r="180" fill="#762030"/></svg>`,
      });
    },
  );
  await page.route("**/api/v1/**", (route) => {
    const path = new URL(route.request().url()).pathname.split("/api/v1")[1];
    if (path === "/home")
      return route.fulfill({
        json: {
          data: {
            hero,
            trending: rows,
            latest: rows,
            featured: rows,
            series: [],
            continue_watching: [],
          },
        },
      });
    if (path === "/movies")
      return route.fulfill({
        json: { data: rows, meta: { total: 3, current_page: 1, last_page: 1 } },
      });
    return route.fulfill({ json: { data: [] } });
  });
}

for (const [width, height] of [
  [360, 800],
  [390, 844],
  [768, 1024],
  [1366, 768],
  [1920, 1080],
]) {
  test(`compact spotlight, toolbar, media and accessibility at ${width}px`, async ({
    page,
  }, testInfo) => {
    await page.setViewportSize({ width, height });
    await fixtures(page);
    await page.goto(web);
    await expect(page.locator(".hero h1")).toHaveText(movie.title);
    await page.evaluate(() => document.fonts.ready);
    const hero = await page.locator(".hero").boundingBox();
    expect(hero!.height).toBeGreaterThanOrEqual(
      width <= 600 ? 280 : width <= 1200 ? 340 : 420,
    );
    expect(hero!.height).toBeLessThanOrEqual(
      width <= 600 ? 360 : width <= 1200 ? 400 : 480,
    );
    const font = await page
      .locator(".hero h1")
      .evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    expect(font).toBeGreaterThanOrEqual(
      width <= 600 ? 30 : width <= 1200 ? 38 : 48,
    );
    expect(font).toBeLessThanOrEqual(
      width <= 600 ? 36 : width <= 1200 ? 46 : 60,
    );
    for (const button of await page.locator(".hero-actions a").all()) {
      const box = await button.boundingBox();
      expect(box!.height).toBeGreaterThanOrEqual(44);
      expect(box!.height).toBeLessThanOrEqual(48);
      expect(box!.y + box!.height).toBeLessThanOrEqual(hero!.y + hero!.height);
    }
    const trending = await page
      .getByRole("region", { name: "Trending now" })
      .boundingBox();
    if (height === 1080) expect(trending!.y + 100).toBeLessThan(height);
    await expect(page.locator(".hero .artwork img")).toHaveAttribute(
      "src",
      movie.backdrop_url,
    );
    let audit = await new AxeBuilder({ page })
      .withTags(["wcag2a", "wcag2aa", "wcag21aa"])
      .analyze();
    await testInfo.attach("home-accessibility", {
      body: JSON.stringify(audit.violations, null, 2),
      contentType: "application/json",
    });
    expect(audit.violations).toEqual([]);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
    await page.goto(`${web}/movies`);
    await expect(page.locator(".card")).toHaveCount(3);
    await expect(page.locator(".artwork-fallback")).toHaveCount(1);
    await expect(page.locator(".movie-card img").first()).toHaveAttribute(
      "src",
      "https://i.ytimg.com/vi/fixture1234/mqdefault.jpg",
    );
    const toolbar = await page.locator(".catalog-toolbar").boundingBox();
    const input = await page.getByRole("searchbox").boundingBox();
    expect(input!.height).toBeGreaterThanOrEqual(44);
    expect(input!.height).toBeLessThanOrEqual(48);
    expect(input!.width).toBeLessThanOrEqual(600);
    if (width >= 1366) {
      expect(toolbar!.height).toBeGreaterThanOrEqual(110);
      expect(toolbar!.height).toBeLessThanOrEqual(140);
      const genre = await page
        .getByLabel("Genre", { exact: true })
        .boundingBox();
      expect(Math.abs(genre!.y - input!.y)).toBeLessThan(2);
    }
    if (width <= 600) expect(toolbar!.height).toBeLessThanOrEqual(210);
    const frames = await page.locator(".card-image").all();
    const first = await frames[0].boundingBox();
    for (const frame of frames) {
      const box = await frame.boundingBox();
      expect(Math.abs(box!.width / box!.height - 16 / 9)).toBeLessThan(0.02);
      expect(Math.abs(box!.height - first!.height)).toBeLessThan(1);
    }
    expect(first!.y).toBeLessThan(width > 600 ? 500 : 470);
    for (const image of await page.locator(".movie-card img").all()) {
      expect(await image.evaluate((el) => getComputedStyle(el).objectFit)).toBe(
        "contain",
      );
    }
    const action = await page.locator(".card-actions a").first().boundingBox();
    expect(action!.height).toBeGreaterThanOrEqual(44);
    await page.getByRole("searchbox").focus();
    await page.keyboard.press("Tab");
    await expect(
      page.getByRole("button", { name: "Search", exact: true }),
    ).toBeFocused();
    audit = await new AxeBuilder({ page })
      .withTags(["wcag2a", "wcag2aa", "wcag21aa"])
      .analyze();
    await testInfo.attach("movies-accessibility", {
      body: JSON.stringify(audit.violations, null, 2),
      contentType: "application/json",
    });
    expect(audit.violations).toEqual([]);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
  });
}

test("brand fallback and long Burmese spotlight titles fit without clipping", async ({
  page,
}) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await fixtures(page, null);
  await page.goto(web);
  await expect(page.locator(".hero h1")).toHaveText(
    "Stories that stay with you.",
  );
  await expect(
    page.getByRole("link", { name: /Explore movies/ }),
  ).toBeVisible();
  await page.unroute("**/api/v1/**");
  await fixtures(page, {
    ...movie,
    title: "မြန်မာ ရုပ်ရှင် ဇာတ်လမ်းများ အမှတ်တရ ပုံပြင်များ",
  });
  await page.reload();
  await expect(page.locator(".hero h1")).toHaveAttribute("lang", "my");
  await page.evaluate(() => document.fonts.ready);
  expect(
    await page.locator(".hero h1").evaluate((el) => {
      const range = document.createRange();
      range.selectNodeContents(el);
      const text = range.getBoundingClientRect();
      const hero = el.closest(".hero")!.getBoundingClientRect();
      return (
        text.top >= hero.top &&
        text.bottom <= hero.bottom &&
        text.left >= hero.left &&
        text.right <= hero.right
      );
    }),
  ).toBe(true);
  const actions = await page.locator(".hero-actions").boundingBox();
  const bottom = await page.locator(".hero-bottom").boundingBox();
  expect(actions!.y + actions!.height).toBeLessThan(bottom!.y);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
});

test("mixed artwork reserves its dimensions while images load or fail", async ({
  page,
}) => {
  await fixtures(page);
  let release!: () => void;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route(
    "https://artwork.hyperline.test/portrait.svg",
    async (route) => {
      await gate;
      await route.fallback();
    },
  );
  await page.goto(`${web}/movies`, { waitUntil: "domcontentloaded" });
  await expect(page.locator(".card")).toHaveCount(3);
  const before = await page.locator(".card-image").nth(1).boundingBox();
  release();
  await expect(page.locator(".card-image img").nth(1)).toBeVisible();
  await expect
    .poll(() =>
      page
        .locator(".card-image img")
        .nth(1)
        .evaluate((el) => (el as HTMLImageElement).complete),
    )
    .toBe(true);
  const after = await page.locator(".card-image").nth(1).boundingBox();
  expect(after).toEqual(before);
});

test("small catalogs hide covered featured rows and empty series sections", async ({
  page,
}) => {
  await fixtures(page);
  await page.route("**/api/v1/home", (route) =>
    route.fulfill({
      json: {
        data: {
          hero: movie,
          trending: rows,
          latest: rows,
          featured: [rows[0]],
          series: [],
          continue_watching: [],
        },
      },
    }),
  );
  await page.goto(web);
  await expect(
    page.getByRole("heading", { name: "Trending now", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Latest movies", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("heading", { name: "Featured movies", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("heading", { name: "Discover series", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("heading", { name: "Vertical dramas", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.locator(".hero").getByRole("link", { name: "Details", exact: true }),
  ).toBeVisible();
});
