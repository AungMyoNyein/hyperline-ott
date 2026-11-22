// Real HTTP calls to the isolated Laravel API; no production API mocks.
import { test, expect } from "../../apps/web/node_modules/@playwright/test";
import { writeFileSync, unlinkSync } from "node:fs";
const web = "http://127.0.0.1:18082";
const cms = "http://127.0.0.1:18083";
const api = "http://127.0.0.1:18081/api/v1";
async function signIn(
  page: import("../../apps/web/node_modules/@playwright/test").Page,
  base: string,
  email: string,
) {
  await page.goto(base == cms ? cms : `${web}/login`);
  await page.getByLabel("Email", { exact: true }).fill(email);
  await page.getByLabel("Password", { exact: true }).fill("TestPassword!2026");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  if (base === web) await page.waitForURL(web + "/");
}
test("CMS authenticates, validates, creates, edits, publishes, deletes and revokes logout", async ({
  page,
  request,
}) => {
  await signIn(page, cms, "admin@hyperline.test");
  await expect(page.getByLabel("Dashboard")).toContainText("total movies");
  await page
    .getByRole("navigation", { name: "Studio navigation" })
    .getByRole("button", { name: "Movies", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Create movie" }),
  ).toBeVisible();
  const title = `Browser movie ${Date.now()}`;
  await page.getByLabel("Title", { exact: true }).fill(title);
  await page
    .getByLabel("Synopsis", { exact: true })
    .fill("A browser-created test record.");
  await page
    .getByLabel("HTTPS stream URL", { exact: true })
    .fill("https://cdn.example.test/browser.m3u8");
  await page.getByLabel("Test patterns", { exact: true }).check();
  await page.getByRole("button", { name: "Save movie", exact: true }).click();
  await expect(
    page.getByRole("status").filter({ hasText: "Movie saved" }),
  ).toContainText("Movie saved");
  let row = page
    .locator(".library article")
    .filter({ has: page.getByRole("heading", { name: title, exact: true }) });
  await expect(row).toContainText("Draft");
  await row.getByRole("button", { name: "Edit", exact: true }).click();
  await expect(page.getByLabel("Synopsis", { exact: true })).toHaveValue(
    "A browser-created test record.",
  );
  await expect(
    page.getByLabel("HTTPS stream URL", { exact: true }),
  ).toHaveValue("https://cdn.example.test/browser.m3u8");
  await page
    .getByLabel("HTTPS stream URL", { exact: true })
    .fill("http://127.0.0.1/private.m3u8");
  await page.getByRole("button", { name: "Save movie", exact: true }).click();
  await expect(page.locator(".error[role=alert]")).toContainText(
    "sources.0.url",
  );
  await page
    .getByLabel("HTTPS stream URL", { exact: true })
    .fill("https://cdn.example.test/edited.m3u8");
  await page.getByRole("button", { name: "Save movie", exact: true }).click();
  await expect(
    page.getByRole("status").filter({ hasText: "Movie saved" }),
  ).toContainText("Movie saved");
  await row.getByRole("button", { name: "Publish", exact: true }).click();
  await expect(row).toContainText("Published");
  let catalog = await request.get(
    `${api}/movies?q=${encodeURIComponent(title)}`,
  );
  expect((await catalog.json()).data).toHaveLength(1);
  await row.getByRole("button", { name: "Unpublish", exact: true }).click();
  await expect(row).toContainText("Draft");
  catalog = await request.get(`${api}/movies?q=${encodeURIComponent(title)}`);
  expect((await catalog.json()).data).toHaveLength(0);
  await row.getByRole("button", { name: "Delete", exact: true }).click();
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Delete movie", exact: true })
    .click();
  await expect(row).toHaveCount(0);
  const logout = page.waitForRequest((r) => r.url().endsWith("/admin/logout"));
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  const sent = await logout;
  await expect(
    page.getByRole("heading", { name: "Administrator sign in" }),
  ).toBeVisible();
  const revoked = await request.get(`${api}/admin/dashboard`, {
    headers: { Authorization: sent.headers().authorization },
  });
  expect(revoked.status()).toBe(401);
});
test("public catalog filters and detail show published content, and mobile layout fits", async ({
  page,
}) => {
  await page.goto(web);
  await expect(
    page.getByRole("heading", { name: "Trending now" }),
  ).toBeVisible();
  await expect(page.locator("main")).toContainText("Local test pattern");
  await expect(page.locator("main")).not.toContainText("Hidden test draft");
  await page.getByRole("link", { name: "Movies", exact: true }).click();
  await page.waitForURL(web + "/movies");
  await page.getByLabel("Search movies").fill("Local test");
  await page.getByLabel("Genre", { exact: true }).selectOption("test-patterns");
  await page.getByLabel("Featured only").check();
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await expect(page.locator(".card")).toHaveCount(1);
  await page.locator(".card").click();
  await expect(
    page.getByRole("heading", { name: "Local test pattern", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Sign in to watch" }),
  ).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
});
test("authorized generated HLS loads variants, advances playback, supports quality and fullscreen", async ({
  page,
}) => {
  await signIn(page, web, "viewer@hyperline.test");
  await page.getByRole("link", { name: "Movies", exact: true }).click();
  await page.waitForURL(web + "/movies");
  await expect(
    page.getByRole("heading", { name: "Find your next story" }),
  ).toBeVisible();
  await page.locator(".card").filter({ hasText: "Local test pattern" }).click();
  await page.getByRole("link", { name: /^(Watch now|Resume)$/ }).click();
  const video = page.locator("video");
  await expect
    .poll(() => video.evaluate((v: HTMLVideoElement) => v.readyState), {
      timeout: 30000,
    })
    .toBeGreaterThanOrEqual(2);
  await expect(page.getByLabel("Quality")).toBeVisible();
  await expect(page.getByLabel("Quality").locator("option")).toHaveCount(3);
  await expect(page.getByLabel("Quality")).toHaveValue("-1");
  await video.evaluate((v: HTMLVideoElement) => v.play());
  await expect
    .poll(() => video.evaluate((v: HTMLVideoElement) => v.currentTime))
    .toBeGreaterThan(0.5);
  await page.getByLabel("Quality").selectOption("0");
  await expect(page.getByLabel("Quality")).toHaveValue("0");
  await page.getByRole("button", { name: "Full screen", exact: true }).click();
  await expect
    .poll(() => page.evaluate(() => !!document.fullscreenElement))
    .toBeTruthy();
  await page.evaluate(() => document.exitFullscreen());
  await page.locator(".player").evaluate((element) => {
    element.requestFullscreen = () =>
      Promise.reject(new DOMException("Fullscreen denied", "NotAllowedError"));
  });
  await page.getByRole("button", { name: "Full screen", exact: true }).click();
  await expect(page.getByRole("status")).toContainText(
    "Full screen is unavailable",
  );
  await expect(video).toBeVisible();
  await expect(video).toHaveJSProperty("controls", true);
  await expect(page.locator(".player-unavailable")).toHaveCount(0);
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await expect(
    page.getByRole("link", { name: "Sign in to watch" }),
  ).toBeVisible();
});
test("browser blocks missing CDN CORS and player reports an actionable error", async ({
  page,
}) => {
  const errors: string[] = [];
  page.on("console", (m) => {
    if (m.type() === "error") errors.push(m.text());
  });
  writeFileSync("/tmp/hyperline-test-media/block-cors", "blocked");
  try {
    await signIn(page, web, "viewer@hyperline.test");
    await page.getByRole("link", { name: "Movies", exact: true }).click();
    await page.waitForURL(web + "/movies");
    await expect(
      page.getByRole("heading", { name: "Find your next story" }),
    ).toBeVisible();
    await page
      .locator(".card")
      .filter({ hasText: "Local test pattern" })
      .click();
    await page.getByRole("link", { name: /^(Watch now|Resume)$/ }).click();
    await expect(page.locator(".error[role=alert]")).toContainText(
      /CORS|too long/,
      { timeout: 40000 },
    );
    expect(
      errors.some((e) => /CORS|Access-Control-Allow-Origin/i.test(e)),
    ).toBeTruthy();
  } finally {
    unlinkSync("/tmp/hyperline-test-media/block-cors");
  }
});

// Progress availability must not change an already authorized media session.
for (const failure of [503, 403, "network"] as const) {
  test(`progress ${failure} read and save failures keep authorized video and controls available`, async ({
    page,
  }) => {
    await signIn(page, web, "viewer@hyperline.test");
    await page.route("**/api/v1/watch-progress**", (route) => {
      const headers = {
        "access-control-allow-origin": web,
        "access-control-allow-headers": "authorization,content-type",
        "access-control-allow-methods": "GET,POST,OPTIONS",
      };
      if (route.request().method() === "OPTIONS")
        return route.fulfill({ status: 204, headers });
      return failure === "network"
        ? route.abort("failed")
        : route.fulfill({
            status: failure,
            headers,
            json: { message: "Isolated progress service unavailable." },
          });
    });
    await page.getByRole("link", { name: "Movies", exact: true }).click();
    await page.waitForURL(web + "/movies");
    await page
      .locator(".card")
      .filter({ hasText: "Local test pattern" })
      .click();
    await page.getByRole("link", { name: /^(Watch now|Resume)$/ }).click();
    const video = page.locator("video");
    await expect(
      page
        .getByRole("status")
        .filter({ hasText: "Resume position could not be loaded" }),
    ).toBeVisible();
    await expect
      .poll(() => video.evaluate((v: HTMLVideoElement) => v.readyState), {
        timeout: 30000,
      })
      .toBeGreaterThanOrEqual(2);
    await video.evaluate((v: HTMLVideoElement) => v.play());
    await expect
      .poll(() => video.evaluate((v: HTMLVideoElement) => v.currentTime))
      .toBeGreaterThan(1.25);
    await video.evaluate((v: HTMLVideoElement) => v.pause());
    await expect(page.getByRole("status")).toContainText(
      "Watch progress could not be saved",
    );
    await expect(video).toBeVisible();
    await expect(video).toHaveJSProperty("controls", true);
    await expect(page.locator(".player-unavailable")).toHaveCount(0);
    await expect(
      page.getByRole("button", { name: "Full screen", exact: true }),
    ).toBeEnabled();
  });
}
