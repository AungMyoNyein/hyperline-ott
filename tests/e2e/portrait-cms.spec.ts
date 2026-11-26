import { test, expect } from "../../apps/web/node_modules/@playwright/test";

test("CMS flags missing and off-ratio portraits without changing artwork URLs", async ({
  page,
}) => {
  const movies = [
    {
      id: 701,
      title: "Landscape fixture",
      poster_url: "https://artwork.hyperline.test/landscape.svg",
    },
    {
      id: 702,
      title: "Vertical fixture",
      poster_url: "https://artwork.hyperline.test/vertical.svg",
    },
    {
      id: 703,
      title: "Portrait fixture",
      poster_url: "https://artwork.hyperline.test/portrait.svg",
    },
  ].map((movie) => ({
    ...movie,
    slug: `fixture-${movie.id}`,
    backdrop_url: "https://artwork.hyperline.test/landscape.svg",
    genres: [],
    published: false,
    sources: [],
  }));
  const writes: string[] = [];
  await page.route("https://artwork.hyperline.test/**", (route) => {
    const url = route.request().url();
    const [width, height] = url.includes("vertical")
      ? [900, 1600]
      : url.includes("portrait")
        ? [600, 900]
        : [1600, 900];
    return route.fulfill({
      contentType: "image/svg+xml",
      body: `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="100%" height="100%" fill="#501016"/></svg>`,
    });
  });
  await page.route("**/api/v1/**", (route) => {
    const pathname = new URL(route.request().url()).pathname.split(
      "/api/v1",
    )[1];
    if (pathname === "/admin/login")
      return route.fulfill({ json: { data: { token: "isolated-cms-token" } } });
    if (!["GET", "HEAD", "OPTIONS"].includes(route.request().method())) {
      writes.push(pathname);
      return route.abort();
    }
    if (pathname === "/admin/movies")
      return route.fulfill({
        json: { data: movies, meta: { current_page: 1, last_page: 1 } },
      });
    if (pathname === "/admin/dashboard")
      return route.fulfill({
        json: {
          data: {
            movies: { total: 3 },
            viewers: { playback_sessions_24h: 0 },
            genres: 0,
            recent_activity: [],
          },
        },
      });
    if (pathname === "/admin/homepage")
      return route.fulfill({
        json: { data: { sections: ["trending", "latest"] } },
      });
    if (pathname === "/admin/series")
      return route.fulfill({
        json: { data: [], meta: { current_page: 1, last_page: 1 } },
      });
    return route.fulfill({ json: { data: [] } });
  });
  await page.goto("http://127.0.0.1:18083");
  await page
    .getByLabel("Email", { exact: true })
    .fill("fixture@hyperline.test");
  await page
    .getByLabel("Password", { exact: true })
    .fill("IsolatedFixtureOnly!");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page
    .getByRole("navigation", { name: "Studio navigation" })
    .getByRole("button", { name: "Artwork", exact: true })
    .click();
  const artwork = page.getByRole("region", { name: "Artwork", exact: true });
  await expect(
    artwork.getByText(/Missing portrait artwork — manual review required/),
  ).toHaveCount(1);
  await expect(
    artwork.getByText(/Portrait ratio needs manual review/),
  ).toHaveCount(1);
  for (const movie of movies) {
    const preview = artwork.locator(`img[src="${movie.poster_url}"]`).first();
    await preview.scrollIntoViewIfNeeded();
    await expect(preview).toHaveAttribute("src", movie.poster_url);
  }
  await page
    .getByRole("button", { name: "Edit Portrait fixture", exact: true })
    .click();
  await expect(
    page.getByLabel("Portrait poster HTTPS URL · 2:3", { exact: true }),
  ).toHaveValue(movies[2].poster_url);
  await expect(
    page.getByLabel("Landscape backdrop HTTPS URL · 16:9", { exact: true }),
  ).toHaveValue(movies[2].backdrop_url);
  expect(writes).toEqual([]);
});
