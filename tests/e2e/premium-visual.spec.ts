/** Deterministic visual fixtures only. No UAT endpoint, credential or catalog is used. */
import {
  test,
  expect,
  Page,
} from "../../apps/web/node_modules/@playwright/test";
const web = "http://127.0.0.1:18082",
  cms = "http://127.0.0.1:18083";
const genre = {
  id: 1,
  name: "Test patterns",
  slug: "test-patterns",
  movies_count: 1,
};
const movie = {
  id: 1,
  title: "Local test pattern",
  slug: "local-test-pattern",
  synopsis:
    "An original generated color pattern for authorized playback tests.",
  poster_url: "https://artwork.hyperline.test/poster.svg",
  backdrop_url: "https://artwork.hyperline.test/backdrop.svg",
  release_year: 2026,
  duration_minutes: 8,
  featured: true,
  playable: true,
  genres: [genre],
  published: true,
  sources: [],
};
const episode = {
  id: 1,
  season_id: 1,
  season_number: 1,
  episode_number: 1,
  title: "Fixture episode 1",
  synopsis: "An isolated episode fixture.",
  duration_seconds: 480,
  still_url: "https://artwork.hyperline.test/still.svg",
  published: true,
  playable: true,
};
const season = {
  id: 1,
  season_number: 1,
  title: null,
  synopsis: null,
  poster_url: null,
  backdrop_url: null,
  published: true,
  episode_count: 1,
  episodes: [episode],
};
const series = {
  id: 1,
  type: "series",
  title: "Local vertical series",
  slug: "local-vertical-series",
  synopsis:
    "A generated vertical fixture series for authorized episode playback tests.",
  category: "vertical",
  language: "Chinese",
  release_year: 2026,
  poster_url: "https://artwork.hyperline.test/vertical.svg",
  backdrop_url: movie.backdrop_url,
  featured: false,
  published: true,
  episode_count: 2,
  season_count: 2,
  seasons: [
    season,
    {
      ...season,
      id: 2,
      season_number: 2,
      episodes: [
        {
          ...episode,
          id: 2,
          season_id: 2,
          season_number: 2,
          episode_number: 1,
          title: "Fixture season two",
        },
      ],
    },
  ],
  episodes: [
    episode,
    {
      ...episode,
      id: 2,
      season_id: 2,
      season_number: 2,
      episode_number: 1,
      title: "Fixture season two",
    },
  ],
};
const pageOf = (data: unknown[]) => ({
  data,
  meta: { current_page: 1, last_page: 1, total: data.length },
});
async function fixtures(page: Page, empty = false) {
  await page.clock.setFixedTime(new Date("2026-01-01T12:00:00Z"));
  await page.route("https://artwork.hyperline.test/**", (route) =>
    route.fulfill({
      contentType: "image/svg+xml",
      body: `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800" viewBox="0 0 1200 800"><defs><linearGradient id="g"><stop stop-color="#252532"/><stop offset="1" stop-color="#751323"/></linearGradient></defs><rect width="1200" height="800" fill="url(#g)"/><circle cx="820" cy="300" r="240" fill="#e50914" opacity=".4"/><path d="M0 800 350 250 700 800M500 800 900 180 1200 800" fill="#08080b" opacity=".6"/><path d="m0 760 1200-80" stroke="#ff959e" opacity=".3"/><text x="60" y="730" fill="#fff" opacity=".6" font-family="Arial" font-size="24" letter-spacing="4">ISOLATED UI FIXTURE</text></svg>`,
    }),
  );
  await page.route("**/api/v1/**", async (route) => {
    const request = route.request(),
      path = new URL(request.url()).pathname.replace("/api/v1", "");
    const headers = {
      "access-control-allow-origin": request.headers().origin || web,
      "access-control-allow-credentials": "true",
      "access-control-allow-headers": "authorization,content-type",
      "access-control-allow-methods": "GET,POST,PATCH,DELETE,OPTIONS",
    };
    if (request.method() === "OPTIONS")
      return route.fulfill({ status: 204, headers });
    const auth = !!request.headers().authorization;
    let body: unknown = { data: [] };
    let status = 200;
    if (path === "/admin/homepage")
      body = {
        data: {
          sections: [
            "continue",
            "trending",
            "latest",
            "genres",
            "series",
            "vertical",
            "featured",
          ],
        },
      };
    else if (path === "/home")
      body = {
        data: {
          hero: empty ? null : movie,
          featured: empty ? [] : [movie],
          trending: empty ? [] : [movie],
          latest: empty ? [] : [movie],
          series: empty ? [] : [series],
          continue_watching: auth
            ? [
                {
                  ...movie,
                  type: "movie",
                  position_seconds: 45,
                  duration_seconds: 480,
                },
              ]
            : [],
        },
      };
    else if (path === "/genres" || path === "/admin/genres")
      body = { data: empty ? [] : [genre] };
    else if (path === "/movies") body = pageOf(empty ? [] : [movie]);
    else if (path === "/movies/local-test-pattern") body = { data: movie };
    else if (path === "/series") body = pageOf(empty ? [] : [series]);
    else if (path === "/series/local-vertical-series") body = { data: series };
    else if (path === "/auth/login" || path === "/admin/login")
      body = {
        data: {
          token: "isolated-ui-fixture",
          user: {
            id: 1,
            name: "Test viewer",
            email: "viewer@hyperline.test",
            roles: [],
          },
        },
      };
    else if (path === "/me")
      body = {
        data: {
          id: 1,
          name: "Test viewer",
          email: "viewer@hyperline.test",
          roles: [],
        },
      };
    else if (path === "/watch-progress")
      body = {
        data: [
          {
            id: 1,
            type: "movie",
            movie,
            position_seconds: 45,
            duration_seconds: 480,
            completed: false,
            last_watched_at: "2026-01-01T00:00:00Z",
          },
        ],
      };
    else if (path === "/watchlist") body = pageOf([{ id: 1, movie }]);
    else if (path === "/admin/movies") body = pageOf([movie]);
    else if (path === "/admin/series") body = pageOf([series]);
    else if (path === "/admin/dashboard")
      body = {
        data: {
          movies: { total: 1, published: 1, draft: 0, featured: 1 },
          genres: 1,
          viewers: { playback_sessions_24h: 0 },
          recent_activity: [],
        },
      };
    else if (path === "/playback/sessions") {
      status = 503;
      body = { message: "Playback authorization is not configured." };
    } else if (path.endsWith("logout")) body = { message: "Signed out." };
    await route.fulfill({ status, headers, json: body });
  });
}
async function login(page: Page, admin = false) {
  await page.goto(admin ? cms : `${web}/login`);
  await page
    .getByLabel("Email", { exact: true })
    .fill(admin ? "admin@hyperline.test" : "viewer@hyperline.test");
  await page.getByLabel("Password", { exact: true }).fill("TestPassword!2026");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  if (admin)
    await expect(
      page.getByRole("heading", { name: "A bigger picture." }),
    ).toBeVisible();
  else
    await expect(page.getByRole("button", { name: "Sign out" })).toBeVisible();
}
async function screenshot(page: Page, name: string) {
  await expect(page.locator(".skeleton")).toHaveCount(0);
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all(
      Array.from(document.images)
        .filter((image) => image.complete || image.loading !== "lazy")
        .map((image) => image.decode().catch(() => {})),
    );
  });
  await expect(page).toHaveScreenshot(name, {
    fullPage: true,
    animations: "disabled",
    maxDiffPixelRatio: 0.005,
  });
}
for (const [device, width, height] of [
  ["desktop", 1366, 900],
  ["mobile", 390, 844],
] as const) {
  test(`visual major pages ${device}`, async ({ page }) => {
    await page.setViewportSize({ width, height });
    await fixtures(page);
    await page.goto(web);
    await expect(
      page.getByRole("heading", { name: movie.title, exact: true }).first(),
    ).toBeVisible();
    await screenshot(page, `after-home-${device}.png`);
    await page.goto(`${web}/movies`);
    await expect(page.locator(".card")).toHaveCount(1);
    await screenshot(page, `after-catalog-${device}.png`);
    await page.goto(`${web}/movies/${movie.slug}`);
    await expect(
      page.getByRole("heading", { name: movie.title, exact: true }),
    ).toBeVisible();
    await screenshot(page, `after-movie-${device}.png`);
    await page.goto(`${web}/series/${series.slug}`);
    await expect(page.locator(".episodes li")).toHaveCount(1);
    await screenshot(page, `after-series-${device}.png`);
    await page.goto(`${web}/login`);
    await screenshot(page, `after-signin-${device}.png`);
    await page.goto(cms);
    await screenshot(page, `after-cms-signin-${device}.png`);
    await login(page, true);
    await screenshot(page, `after-cms-dashboard-${device}.png`);
    if (width < 700)
      await page.getByRole("button", { name: "Menu", exact: true }).click();
    await page
      .getByRole("navigation", { name: "Studio navigation" })
      .getByRole("button", { name: "Movies", exact: true })
      .click();
    await expect(
      page.getByRole("heading", { name: "Movie library" }),
    ).toBeVisible();
    await screenshot(page, `after-cms-movies-${device}.png`);
  });
}
for (const width of [360, 390, 768, 1366, 1920])
  test(`responsive pages fit ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await fixtures(page);
    for (const path of [
      "",
      "/movies",
      "/series/local-vertical-series",
      "/genres",
      "/search",
      "/login",
    ]) {
      await page.goto(web + path);
      await expect(page.locator("main")).toBeVisible();
      await expect(page.locator(".skeleton")).toHaveCount(0);
      expect(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth,
        ),
        path,
      ).toBeTruthy();
    }
    await login(page, true);
    if (width < 700)
      await page.getByRole("button", { name: "Menu", exact: true }).click();
    await page
      .getByRole("navigation", { name: "Studio navigation" })
      .getByRole("button", { name: "Movies", exact: true })
      .click();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
      "CMS editor",
    ).toBeTruthy();
  });
test("branded fallback, keyboard navigation, mobile menu and reduced motion", async ({
  page,
}) => {
  await fixtures(page, true);
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto(web);
  await expect(
    page.getByRole("heading", { name: "Stories that stay with you." }),
  ).toBeVisible();
  await screenshot(page, "after-empty-home-mobile.png");
  await page.keyboard.press("Tab");
  await expect(
    page.getByRole("link", { name: "Skip to content" }),
  ).toBeFocused();
  await page.keyboard.press("Enter");
  await expect(page.locator("#main-content")).toBeFocused();
  await page.getByRole("button", { name: "Menu", exact: true }).click();
  const nav = page.getByRole("navigation", { name: "Main navigation" });
  await expect(nav).toBeVisible();
  await nav.getByRole("link", { name: "Genres", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "A world of genres." }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Menu", exact: true }),
  ).toHaveAttribute("aria-expanded", "false");
});
test("authenticated player fails closed without fetching media; seasons and search navigate", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1366, height: 900 });
  const mediaRequests: string[] = [];
  page.on("request", (request) => {
    if (/\.(m3u8|mpd|mp4|m4s|ts|key)(\?|$)/.test(request.url()))
      mediaRequests.push(request.url());
  });
  await fixtures(page);
  await login(page);
  await expect(page.getByRole("button", { name: "Sign out" })).toBeVisible();
  await page.getByRole("link", { name: "More info", exact: true }).click();
  await page.waitForURL(`${web}/movies/${movie.slug}`);
  await expect(
    page.getByRole("heading", { name: movie.title, exact: true }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Resume", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Playback is unavailable" }),
  ).toBeVisible();
  await expect(page.locator(".error[role=alert]")).toContainText(
    "temporarily unavailable",
  );
  await expect(page.locator("video")).not.toHaveAttribute("src", /.+/);
  await expect(
    page.getByRole("button", { name: "Full screen" }),
  ).toBeDisabled();
  expect(mediaRequests).toEqual([]);
  await screenshot(page, "after-player-unavailable-desktop.png");
  await page.goto(`${web}/series/local-vertical-series`);
  await page.getByLabel("Season", { exact: true }).selectOption("2");
  await expect(page.locator(".episodes li")).toContainText(
    "Fixture season two",
  );
  await expect(
    page
      .locator(".episodes li")
      .getByRole("link", { name: "Sign in", exact: true }),
  ).toHaveAttribute("href", /episode%3D2/);
  await page.goto(`${web}/search`);
  await page
    .getByRole("searchbox", { name: "Search titles", exact: true })
    .fill("Local");
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await expect(page.locator(".card")).toHaveCount(2);
});
test("CMS delete confirmation traps focus, cancels safely, and preserves unsaved changes across sections", async ({
  page,
}) => {
  await fixtures(page);
  await login(page, true);
  await page
    .getByRole("navigation", { name: "Studio navigation" })
    .getByRole("button", { name: "Movies", exact: true })
    .click();
  await page.getByLabel("Title", { exact: true }).fill("Unsaved fixture title");
  await page
    .getByRole("navigation", { name: "Studio navigation" })
    .getByRole("button", { name: "Dashboard", exact: true })
    .click();
  await page
    .getByRole("navigation", { name: "Studio navigation" })
    .getByRole("button", { name: "Movies", exact: true })
    .click();
  await expect(page.getByLabel("Title", { exact: true })).toHaveValue(
    "Unsaved fixture title",
  );
  await page
    .locator(".library article")
    .getByRole("button", { name: "Delete", exact: true })
    .click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole("button", { name: "Cancel" })).toBeFocused();
  await page.keyboard.press("Escape");
  await expect(dialog).toHaveCount(0);
  await expect(
    page
      .locator(".library article")
      .getByRole("button", { name: "Delete", exact: true }),
  ).toBeFocused();
  await expect(page.locator(".library:visible article")).toHaveCount(1);
});

test("failed artwork falls back without an application image proxy", async ({
  page,
}) => {
  await fixtures(page);
  await page.route("https://artwork.hyperline.test/**", (route) =>
    route.fulfill({ status: 404, body: "" }),
  );
  await page.goto(`${web}/movies/${movie.slug}`);
  await expect(page.locator(".artwork-fallback")).toHaveCount(2);
  await expect(page.locator(".artwork img")).toHaveCount(0);
  await expect(
    page.getByRole("heading", { name: movie.title, exact: true }),
  ).toBeVisible();
});
