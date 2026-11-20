import {
  test,
  expect,
  Page,
} from "../../apps/web/node_modules/@playwright/test";
import { mockYouTube, youtubeAction } from "./youtube-fixture";
const web = "http://127.0.0.1:18082";
const cms = "http://127.0.0.1:18083";
async function signIn(page: Page, admin = false) {
  await page.goto(admin ? cms : `${web}/login`);
  await page
    .getByLabel("Email", { exact: true })
    .fill(admin ? "admin@hyperline.test" : "viewer@hyperline.test");
  await page.getByLabel("Password", { exact: true }).fill("TestPassword!2026");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  if (!admin) await page.waitForURL(web + "/");
}
async function openYouTube(page: Page) {
  await page.getByRole("link", { name: "Movies", exact: true }).click();
  await page.waitForURL(web + "/movies");
  await page
    .locator(".card")
    .filter({ hasText: "Local YouTube pattern" })
    .click();
  const session = page.waitForResponse(
    (r) =>
      r.request().method() === "POST" && r.url().endsWith("/playback/sessions"),
  );
  const progress = page.waitForResponse(
    (r) =>
      r.request().method() === "GET" &&
      r.url().includes("/watch-progress?movie_id="),
  );
  await page.getByRole("link", { name: /^(Watch now|Resume)$/ }).click();
  await expect(page.locator('iframe[title="YouTube player"]')).toBeVisible();
  await (await progress).finished();
  return (await (await session).json()).data;
}
function progressPost(page: Page) {
  return page.waitForResponse(
    (r) =>
      r.request().method() === "POST" && r.url().endsWith("/watch-progress"),
  );
}

for (const source of [
  {
    kind: "youtube",
    url: "https://youtu.be/M7lc1UVf-VE?si=fixture",
    stored: "https://www.youtube.com/watch?v=M7lc1UVf-VE",
  },
  {
    kind: "hls",
    url: "https://cdn.example.test/authorized/master.m3u8",
    stored: "https://cdn.example.test/authorized/master.m3u8",
  },
  {
    kind: "dash",
    url: "https://cdn.example.test/authorized/manifest.mpd",
    stored: "https://cdn.example.test/authorized/manifest.mpd",
  },
]) {
  test(`CMS stores an explicit ${source.kind} source with the approved representation`, async ({
    page,
  }) => {
    await signIn(page, true);
    await page
      .getByRole("navigation", { name: "Studio navigation" })
      .getByRole("button", { name: "Movies", exact: true })
      .click();
    await page
      .getByLabel("Title", { exact: true })
      .fill(`Source contract ${source.kind} ${Date.now()}`);
    await page.getByLabel("Format", { exact: true }).selectOption(source.kind);
    await page.getByLabel("HTTPS stream URL", { exact: true }).fill(source.url);
    const saved = page.waitForResponse(
      (r) =>
        r.request().method() === "POST" && r.url().endsWith("/admin/movies"),
    );
    await page.getByRole("button", { name: "Save movie", exact: true }).click();
    const response = await saved;
    expect(response.status()).toBe(201);
    const body = (await response.json()).data;
    expect(body.sources[0].kind).toBe(source.kind);
    expect(body.sources[0].url).toBe(source.stored);
    expect(body.published).toBe(false);
  });
}

test("YouTube movie API events save progress, resume, seek and complete through the shared engine", async ({
  page,
}) => {
  await mockYouTube(page);
  await signIn(page);
  const session = await openYouTube(page);
  expect(session.source.video_id).toBe("M7lc1UVf-VE");
  expect(session.signature_required).toBe(false);
  expect(session.renew_after_seconds).toBeNull();
  await youtubeAction(page, "playVideo");
  await youtubeAction(page, "seekTo", 35);
  const saved = progressPost(page);
  await youtubeAction(page, "pauseVideo");
  expect((await saved).ok()).toBe(true);
  await page.getByRole("link", { name: "Movies", exact: true }).click();
  await page.waitForURL(web + "/movies");
  // openYouTube starts with in-app navigation to preserve the bearer session.
  await openYouTube(page);
  await expect
    .poll(() =>
      page.evaluate(() =>
        (
          window as unknown as {
            __youtubePlayers: { getCurrentTime(): number }[];
          }
        ).__youtubePlayers
          .at(-1)!
          .getCurrentTime(),
      ),
    )
    .toBeGreaterThanOrEqual(35);
  const completed = progressPost(page);
  await youtubeAction(page, "finish");
  const result = await completed;
  expect(result.ok()).toBe(true);
  expect(result.request().postDataJSON().completed).toBe(true);
  await page.getByRole("link", { name: "Movies", exact: true }).click();
  await openYouTube(page);
  await expect
    .poll(() =>
      page.evaluate(() =>
        (
          window as unknown as {
            __youtubePlayers: { getCurrentTime(): number }[];
          }
        ).__youtubePlayers
          .at(-1)!
          .getCurrentTime(),
      ),
    )
    .toBe(0);
});

test("YouTube progress failures remain nonfatal and progress 401 removes the embed", async ({
  page,
}) => {
  await mockYouTube(page);
  await signIn(page);
  await page.route("**/api/v1/watch-progress**", (route) =>
    route.request().method() === "OPTIONS"
      ? route.continue()
      : route.fulfill({
          status: 503,
          headers: { "access-control-allow-origin": web },
          json: { message: "Fixture progress unavailable" },
        }),
  );
  await openYouTube(page);
  await expect(
    page
      .getByRole("status")
      .filter({ hasText: "Resume position could not be loaded" }),
  ).toBeVisible();
  await youtubeAction(page, "playVideo");
  await youtubeAction(page, "seekTo", 20);
  await youtubeAction(page, "pauseVideo");
  await expect(
    page
      .getByRole("status")
      .filter({ hasText: "Watch progress could not be saved" }),
  ).toBeVisible();
  await expect(page.locator('iframe[title="YouTube player"]')).toBeVisible();
  await page.unroute("**/api/v1/watch-progress**");
  await page.route("**/api/v1/watch-progress", (route) =>
    route.request().method() === "POST"
      ? route.fulfill({
          status: 401,
          headers: { "access-control-allow-origin": web },
          json: { message: "Unauthenticated" },
        })
      : route.continue(),
  );
  await youtubeAction(page, "seekTo", 25);
  await youtubeAction(page, "pauseVideo");
  await expect(
    page.getByRole("link", { name: "Sign in to watch" }),
  ).toBeVisible();
  await expect(page.locator("iframe")).toHaveCount(0);
  expect(
    await page.evaluate(
      () =>
        (
          window as unknown as { __youtubePlayers: { destroyed: boolean }[] }
        ).__youtubePlayers.at(-1)!.destroyed,
    ),
  ).toBe(true);
});

for (const [code, message] of [
  [100, "private or no longer available"],
  [101, "does not allow embedded playback"],
  [150, "does not allow embedded playback"],
  [153, "could not verify"],
  [5, "could not play"],
] as const) {
  test(`YouTube error ${code} explains the failure and destroys the embed`, async ({
    page,
  }) => {
    await mockYouTube(page);
    await signIn(page);
    await openYouTube(page);
    await youtubeAction(page, "fail", code);
    await expect(
      page.getByRole("alert").filter({ hasText: message }),
    ).toBeVisible();
    await expect(page.locator("iframe")).toHaveCount(0);
  });
}

test("YouTube script network failure reports a useful error", async ({
  page,
}) => {
  await page.route("https://www.youtube.com/iframe_api", (route) =>
    route.abort("failed"),
  );
  await signIn(page);
  await page.getByRole("link", { name: "Movies", exact: true }).click();
  await page.waitForURL(web + "/movies");
  await page
    .locator(".card")
    .filter({ hasText: "Local YouTube pattern" })
    .click();
  await page.getByRole("link", { name: /^(Watch now|Resume)$/ }).click();
  await expect(
    page.getByRole("alert").filter({ hasText: "YouTube could not be reached" }),
  ).toBeVisible();
  await expect(page.locator("iframe")).toHaveCount(0);
});

test("YouTube episode switching cleans players, scopes progress, fits mobile and preserves DASH/HLS selection", async ({
  page,
}) => {
  await mockYouTube(page);
  await signIn(page);
  await page.getByRole("link", { name: "Series", exact: true }).first().click();
  await page.waitForURL(web + "/series");
  await page
    .locator(".card")
    .filter({ hasText: "YouTube sample series" })
    .click();
  await page
    .locator(".episodes li")
    .first()
    .getByRole("link", { name: /^(Watch|Resume)$/ })
    .click();
  const frame = page.locator('iframe[title="YouTube player"]');
  await expect(frame).toBeVisible();
  const episode1 = new URL(page.url()).searchParams.get("episode");
  const saved = progressPost(page);
  await youtubeAction(page, "seekTo", 42);
  await youtubeAction(page, "pauseVideo");
  expect((await saved).request().postDataJSON().episode_id).toBe(
    Number(episode1),
  );
  const nextProgress = page.waitForResponse(
    (r) =>
      r.request().method() === "GET" &&
      r.url().includes("/watch-progress?episode_id="),
  );
  await page.getByRole("link", { name: "Next episode →" }).click();
  await page.waitForURL(/&n=2$/);
  await expect(frame).toBeVisible();
  await (await nextProgress).finished();
  await expect
    .poll(() =>
      page.evaluate(
        () =>
          (
            window as unknown as { __youtubePlayers: { destroyed: boolean }[] }
          ).__youtubePlayers.filter((p) => !p.destroyed).length,
      ),
    )
    .toBe(1);
  const saved2 = progressPost(page);
  await youtubeAction(page, "seekTo", 12);
  await youtubeAction(page, "pauseVideo");
  expect((await saved2).request().postDataJSON().episode_id).not.toBe(
    Number(episode1),
  );
  for (const width of [360, 390, 1366]) {
    await page.setViewportSize({ width, height: 900 });
    const box = await frame.boundingBox();
    expect(box!.width).toBeGreaterThanOrEqual(200);
    expect(box!.height).toBeGreaterThanOrEqual(200);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
  }
  await page.getByRole("link", { name: "← Previous episode" }).click();
  await page.waitForURL(/&n=1$/);
  await expect(frame).toBeVisible();
  await expect
    .poll(() =>
      page.evaluate(() =>
        (
          window as unknown as {
            __youtubePlayers: { getCurrentTime(): number }[];
          }
        ).__youtubePlayers
          .at(-1)!
          .getCurrentTime(),
      ),
    )
    .toBe(42);
  await page.getByRole("link", { name: "Next episode →" }).click();
  await page.waitForURL(/&n=2$/);
  await page.getByRole("link", { name: "Next episode →" }).click();
  await page.waitForURL(/&n=3$/);
  await expect(frame).toHaveCount(0);
  await expect
    .poll(
      () =>
        page.locator("video").evaluate((v: HTMLVideoElement) => v.readyState),
      { timeout: 30000 },
    )
    .toBeGreaterThanOrEqual(2);
  await page.getByRole("link", { name: "Next episode →" }).click();
  await page.waitForURL(/&n=4$/);
  await expect
    .poll(
      () =>
        page.locator("video").evaluate((v: HTMLVideoElement) => v.readyState),
      { timeout: 30000 },
    )
    .toBeGreaterThanOrEqual(2);
});
