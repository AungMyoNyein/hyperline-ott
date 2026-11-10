import {
  test,
  expect,
  Page,
} from "../../apps/web/node_modules/@playwright/test";
const web = "http://127.0.0.1:18082",
  cms = "http://127.0.0.1:18083",
  api = "http://127.0.0.1:18081/api/v1";
async function login(page: Page, admin = false) {
  await page.goto(admin ? cms : `${web}/login`);
  await page
    .getByLabel("Email", { exact: true })
    .fill(admin ? "admin@hyperline.test" : "viewer@hyperline.test");
  await page.getByLabel("Password", { exact: true }).fill("TestPassword!2026");
  const response = page.waitForResponse(
    (r) => r.url() === `${api}/${admin ? "admin/login" : "auth/login"}`,
  );
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  const data = (await (await response).json()).data;
  if (admin)
    await expect(
      page.getByRole("heading", { name: "Create movie" }),
    ).toBeVisible();
  else await page.waitForURL(web + "/");
  return data.token as string;
}
test("My List adds, lists, removes and expires a revoked in-memory session", async ({
  page,
  request,
}) => {
  const token = await login(page);
  await page.getByRole("link", { name: "Movies & search" }).click();
  await page.locator(".card").filter({ hasText: "Local test pattern" }).click();
  await page.getByRole("button", { name: "Add to watchlist" }).click();
  await expect(page.getByRole("status")).toContainText("Added");
  await page.getByRole("link", { name: "My List", exact: true }).focus();
  await page.keyboard.press("Enter");
  await expect(
    page.getByRole("heading", { name: "Local test pattern" }),
  ).toBeVisible();
  await page.screenshot({
    path: "/tmp/hyperline-phase1-my-list-desktop.png",
    fullPage: true,
  });
  await page.setViewportSize({ width: 390, height: 844 });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
  await page.screenshot({
    path: "/tmp/hyperline-phase1-my-list-mobile.png",
    fullPage: true,
  });
  await page
    .getByRole("button", { name: "Remove Local test pattern from My List" })
    .click();
  await expect(page.locator("main")).toContainText("Your list is empty");
  expect(
    (
      await request.post(`${api}/auth/logout`, {
        headers: { Authorization: `Bearer ${token}` },
      })
    ).status(),
  ).toBe(200);
  await page.getByRole("link", { name: "Home", exact: true }).click();
  await page.getByRole("link", { name: "My List", exact: true }).click();
  await expect(
    page.getByRole("link", { name: "Sign in to view My List" }),
  ).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Sign in", exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(() => [localStorage.length, sessionStorage.length]),
  ).toEqual([0, 0]);
});
test("movie progress resumes, throttles event bursts, saves on pause, and completion leaves home", async ({
  page,
  request,
}) => {
  const token = await login(page);
  const headers = { Authorization: `Bearer ${token}` };
  const catalog = await (
    await request.get(`${api}/movies?q=Local%20test`)
  ).json();
  const id = catalog.data[0].id;
  await request.post(`${api}/watch-progress`, {
    headers,
    data: {
      movie_id: id,
      position_seconds: 2,
      duration_seconds: 8,
      completed: false,
    },
  });
  await page.getByRole("link", { name: "Home", exact: true }).click();
  // A real client navigation remounts home after the fixture progress write.
  await page.getByRole("link", { name: "Movies & search" }).click();
  await page.getByRole("link", { name: "Home", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Continue watching", exact: true }),
  ).toBeVisible();
  await page
    .locator("section")
    .filter({
      has: page.getByRole("heading", {
        name: "Continue watching",
        exact: true,
      }),
    })
    .locator(".card")
    .first()
    .click();
  // Phase 2: continue-watching cards deep-link straight into the player so a
  // title always resumes where the viewer stopped; no details-page detour.
  await page.waitForURL(/\/watch\?id=\d+/);
  const video = page.locator("video");
  await expect
    .poll(() => video.evaluate((v: HTMLVideoElement) => v.readyState))
    .toBeGreaterThanOrEqual(2);
  await expect
    .poll(() => video.evaluate((v: HTMLVideoElement) => v.currentTime))
    .toBeGreaterThanOrEqual(1.9);
  let writes = 0;
  page.on("request", (r) => {
    if (r.url().endsWith("/watch-progress") && r.method() === "POST") writes++;
  });
  await video.evaluate((v: HTMLVideoElement) => {
    v.currentTime = 3;
    for (let i = 0; i < 100; i++) v.dispatchEvent(new Event("timeupdate"));
  });
  await expect
    .poll(async () => {
      const j = await (
        await request.get(`${api}/watch-progress?movie_id=${id}`, { headers })
      ).json();
      return j.data[0].position_seconds;
    })
    .toBeGreaterThanOrEqual(2);
  // Restoring the saved position can itself trigger the first periodic save;
  // a seek within the interval must not require another immediate write.
  const afterBurst = writes;
  expect(afterBurst).toBeLessThanOrEqual(1);
  await video.evaluate((v: HTMLVideoElement) => {
    v.currentTime = 4;
    for (let i = 0; i < 100; i++) v.dispatchEvent(new Event("timeupdate"));
  });
  // The queued events cannot bypass the 15 second gate.
  await page.getByRole("button", { name: "Full screen", exact: true }).focus();
  expect(writes).toBe(afterBurst);
  await video.evaluate((v: HTMLVideoElement) => v.play());
  await expect
    .poll(() => video.evaluate((v: HTMLVideoElement) => v.currentTime))
    .toBeGreaterThan(4.2);
  await video.evaluate((v: HTMLVideoElement) => v.pause());
  await expect
    .poll(async () => {
      const j = await (
        await request.get(`${api}/watch-progress?movie_id=${id}`, { headers })
      ).json();
      return j.data[0].position_seconds;
    })
    .toBeGreaterThanOrEqual(4);
  await video.evaluate((v: HTMLVideoElement) => {
    v.currentTime = v.duration - 0.4;
    void v.play();
  });
  await expect
    .poll(async () => {
      const j = await (
        await request.get(`${api}/watch-progress?movie_id=${id}`, { headers })
      ).json();
      return j.data[0].completed;
    })
    .toBe(true);
  await page.getByRole("link", { name: "Home", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Recently added" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Continue watching", exact: true }),
  ).toHaveCount(0);
});
test("CMS protects unsaved edits, applies API filters, exposes audit history and handles revocation", async ({
  page,
  request,
}) => {
  const token = await login(page, true);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
  await page.screenshot({
    path: "/tmp/hyperline-phase1-cms-desktop.png",
    fullPage: true,
  });
  await page.getByLabel("Title", { exact: true }).fill("Unsaved title");
  page.once("dialog", (d) => d.dismiss());
  await page.getByRole("button", { name: "Clear editor", exact: true }).click();
  await expect(page.getByLabel("Title", { exact: true })).toHaveValue(
    "Unsaved title",
  );
  page.once("dialog", (d) => d.accept());
  await page.getByRole("button", { name: "Clear editor", exact: true }).click();
  await expect(page.getByLabel("Title", { exact: true })).toHaveValue("");
  await page.getByLabel("Search movies", { exact: true }).fill("Local test");
  await page.getByLabel("Publication", { exact: true }).selectOption("1");
  await page.getByLabel("Genre", { exact: true }).selectOption("test-patterns");
  await page.getByRole("combobox", { name: "Featured", exact: true }).selectOption("1");
  await page.getByRole("button", { name: "Apply filters" }).click();
  await expect(
    page.locator(".library article").filter({ hasText: "Local test pattern" }),
  ).toHaveCount(1);
  await expect(
    page.locator(".library article").filter({ hasText: "Hidden test draft" }),
  ).toHaveCount(0);
  await page.getByRole("button", { name: "View audit log" }).click();
  await page.getByLabel("Action", { exact: true }).fill("admin.login");
  await page.getByRole("button", { name: "Filter audit log" }).click();
  await expect(
    page.getByRole("heading", { name: "admin.login", exact: true }).first(),
  ).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
  await page.screenshot({
    path: "/tmp/hyperline-phase1-cms-mobile.png",
    fullPage: true,
  });
  await page.getByRole("button", { name: "Apply filters" }).focus();
  await page.keyboard.press("Enter");
  expect(
    (
      await request.post(`${api}/admin/logout`, {
        headers: { Authorization: `Bearer ${token}` },
      })
    ).status(),
  ).toBe(200);
  await page.getByLabel("Search movies", { exact: true }).fill("Hidden");
  await page.getByRole("button", { name: "Apply filters" }).click();
  await expect(
    page.getByRole("heading", { name: "Administrator sign in" }),
  ).toBeVisible();
  expect(
    await page.evaluate(() => [localStorage.length, sessionStorage.length]),
  ).toEqual([0, 0]);
});
