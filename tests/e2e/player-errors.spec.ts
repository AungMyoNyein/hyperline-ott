import {
  test,
  expect,
  Page,
} from "../../apps/web/node_modules/@playwright/test";
const web = "http://127.0.0.1:18082";
async function openMovie(page: Page, title = "Local test pattern") {
  await page.goto(`${web}/login`);
  await page.getByLabel("Email", { exact: true }).fill("viewer@hyperline.test");
  await page.getByLabel("Password", { exact: true }).fill("TestPassword!2026");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page.waitForURL(web + "/");
  await page.getByRole("link", { name: "Movies", exact: true }).click();
  await page.waitForURL(web + "/movies");
  await page.locator(".card").filter({ hasText: title }).click();
  await page.getByRole("link", { name: /^(Watch now|Resume)$/ }).click();
}
async function readyVideo(page: Page) {
  const video = page.locator("video");
  await expect
    .poll(() => video.evaluate((v: HTMLVideoElement) => v.readyState), {
      timeout: 30000,
    })
    .toBeGreaterThanOrEqual(2);
  return video;
}

test("progress 401 expires authentication and removes authorized media", async ({
  page,
}) => {
  await openMovie(page);
  const video = await readyVideo(page);
  await page.route("**/api/v1/watch-progress", async (route) => {
    if (route.request().method() !== "POST") return route.continue();
    return route.fulfill({
      status: 401,
      headers: { "access-control-allow-origin": web },
      json: { message: "Unauthenticated." },
    });
  });
  await video.evaluate((v: HTMLVideoElement) => {
    v.currentTime = 2;
    v.dispatchEvent(new Event("pause"));
  });
  await expect(
    page.getByRole("link", { name: "Sign in to watch" }),
  ).toBeVisible();
  await expect(page.locator("video")).toHaveCount(0);
  await expect(
    page.getByRole("alert").filter({ hasText: "Your session expired" }),
  ).toBeVisible();
});

for (const scenario of [
  { title: "Local test pattern", status: 403 },
  { title: "Local DASH pattern", status: 403 },
  { title: "Local test pattern", status: 410 },
  { title: "Local test pattern", status: 401 },
]) {
  test(`renewal ${scenario.status} stops ${scenario.title} playback`, async ({
    page,
  }) => {
    test.skip(
      !process.env.HYPERLINE_SIGNED_FIXTURE,
      "Requires signed loopback fixture.",
    );
    // Keep the real signed session and transport, shorten only the fixture timer.
    await page.route("**/api/v1/playback/sessions", async (route) => {
      if (route.request().method() !== "POST") return route.continue();
      const response = await route.fetch();
      const body = await response.json();
      body.data.renew_after_seconds = 3;
      await route.fulfill({ response, json: body });
    });
    await page.route("**/api/v1/playback/sessions/*/renew", (route) =>
      route.fulfill({
        status: scenario.status,
        headers: {
          "access-control-allow-origin": web,
          "access-control-allow-credentials": "true",
        },
        json: { message: "Playback authorization expired." },
      }),
    );
    await openMovie(page, scenario.title);
    const video = await readyVideo(page);
    const held = await video.elementHandle();
    await video.evaluate((v: HTMLVideoElement) => v.play());
    if (scenario.status === 401) {
      await expect(
        page.getByRole("link", { name: "Sign in to watch" }),
      ).toBeVisible();
      await expect(video).toHaveCount(0);
    } else {
      await expect(
        page
          .getByRole("alert")
          .filter({ hasText: "Playback authorization could not be renewed" }),
      ).toBeVisible();
      await expect(page.locator(".player-unavailable")).toBeVisible();
      await expect(video).toBeHidden();
      await expect(video).toHaveJSProperty("controls", false);
      await expect(video).not.toHaveAttribute("src");
      await expect(
        page.getByRole("button", { name: "Full screen", exact: true }),
      ).toBeDisabled();
    }
    expect(await held!.evaluate((v: HTMLVideoElement) => v.paused)).toBe(true);
  });
}

test("fatal DASH manifest failure keeps playback unavailable", async ({
  page,
}) => {
  await page.route("https://127.0.0.1:18084/**/manifest.mpd*", (route) =>
    route.fulfill({
      status: 403,
      headers: { "access-control-allow-origin": web },
      body: "Forbidden",
    }),
  );
  await openMovie(page, "Local DASH pattern");
  await expect(
    page.getByRole("alert").filter({ hasText: "DASH playback failed" }),
  ).toBeVisible({ timeout: 30000 });
  await expect(page.locator(".player-unavailable")).toBeVisible();
  await expect(page.locator("video")).toBeHidden();
  await expect(
    page.getByRole("button", { name: "Full screen", exact: true }),
  ).toBeDisabled();
});
