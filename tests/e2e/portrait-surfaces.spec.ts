import { test, expect } from "../../apps/web/node_modules/@playwright/test";

const web = "http://127.0.0.1:18082";
const movie = {
  id: 901,
  slug: "portrait-review",
  title: "မြန်မာ ရုပ်ရှင် ဇာတ်လမ်း",
  poster_url: "https://artwork.hyperline.test/landscape.svg",
  published: true,
  playable: true,
  genres: [],
};

for (const width of [360, 390, 768, 1366, 1920]) {
  test(`portrait genre discovery and saved movie navigation at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 1000 });
    await page.route("https://artwork.hyperline.test/**", (route) =>
      route.fulfill({
        contentType: "image/svg+xml",
        body: '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="900"><rect width="1600" height="900" fill="#501016"/><text x="100" y="400" fill="white">Isolated landscape fixture — preserve complete image</text></svg>',
      }),
    );
    await page.route("**/api/v1/**", (route) => {
      const pathname = new URL(route.request().url()).pathname.split(
        "/api/v1",
      )[1];
      if (pathname === "/auth/login")
        return route.fulfill({
          json: {
            data: {
              token: "isolated-poster-token",
              user: {
                id: 901,
                name: "Poster QA",
                email: "fixture@hyperline.test",
                roles: [],
              },
            },
          },
        });
      if (pathname === "/genres")
        return route.fulfill({
          json: { data: [{ id: 901, name: "Drama", slug: "drama" }] },
        });
      if (pathname === "/home")
        return route.fulfill({
          json: {
            data: {
              hero: null,
              trending: [],
              latest: [],
              featured: [],
              series: [],
              continue_watching: [],
            },
          },
        });
      if (pathname === "/watchlist")
        return route.fulfill({
          json: {
            data: [{ id: 901, movie }],
            meta: { current_page: 1, last_page: 1 },
          },
        });
      if (pathname === "/movies")
        return route.fulfill({
          json: {
            data: [movie],
            meta: { current_page: 1, last_page: 1, total: 1 },
          },
        });
      return route.fulfill({ json: { data: [] } });
    });
    await page.goto(`${web}/genres`);
    await page.getByRole("link", { name: /Drama/ }).click();
    await expect(page).toHaveURL(/genre=drama/);
    const frame = await page.locator(".movie-card .artwork").boundingBox();
    expect(frame!.width / frame!.height).toBeCloseTo(2 / 3, 2);
    await expect(page.locator('[data-landscape-preview="true"]')).toBeVisible();
    expect(
      await page
        .locator(".movie-card img")
        .evaluate((element) => getComputedStyle(element).objectFit),
    ).toBe("contain");
    await expect(
      page.getByRole("link", { name: /Watch/ }).first(),
    ).toHaveAttribute("href", "/watch?id=901");
    await expect(
      page.getByRole("link", { name: /Details/ }).last(),
    ).toHaveAttribute("href", /\/movies\/portrait-review/);
    await page.goto(`${web}/login`);
    await page
      .getByLabel("Email", { exact: true })
      .fill("fixture@hyperline.test");
    await page
      .getByLabel("Password", { exact: true })
      .fill("IsolatedFixtureOnly!");
    await page.getByRole("button", { name: "Sign in", exact: true }).click();
    await page.waitForURL(web + "/");
    if (width <= 900)
      await page.getByRole("button", { name: "Menu", exact: true }).click();
    await page
      .getByRole("navigation", { name: "Main navigation" })
      .getByRole("link", { name: "My List", exact: true })
      .click();
    const saved = page.locator(".saved-movie");
    await expect(saved).toHaveCount(1);
    const poster = await saved.locator(".artwork").boundingBox();
    expect(poster!.width / poster!.height).toBeCloseTo(2 / 3, 2);
    await expect(
      saved.getByRole("link", { name: "Watch", exact: true }),
    ).toHaveAttribute("href", "/watch?id=901");
    await expect(
      saved.getByRole("link", { name: "Details", exact: true }),
    ).toHaveAttribute("href", "/movies/portrait-review");
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
  });
}
