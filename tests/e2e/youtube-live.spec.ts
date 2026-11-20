import { test, expect } from "../../apps/web/node_modules/@playwright/test";

test("official YouTube sample live embed play and pause", async ({
  page,
}, info) => {
  test.skip(
    !process.env.HYPERLINE_YOUTUBE_LIVE,
    "External YouTube playback is opt-in; deterministic API contract tests run separately.",
  );
  const failedOrigins: string[] = [];
  page.on("requestfailed", (r) => {
    if (/youtube|googlevideo|ytimg/.test(r.url()))
      failedOrigins.push(new URL(r.url()).origin);
  });
  const web = "http://127.0.0.1:18082";
  await page.goto(`${web}/login`);
  await page.getByLabel("Email", { exact: true }).fill("viewer@hyperline.test");
  await page.getByLabel("Password", { exact: true }).fill("TestPassword!2026");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page.waitForURL(web + "/");
  await page.getByRole("link", { name: "Movies", exact: true }).click();
  await page.waitForURL(web + "/movies");
  await page
    .locator(".card")
    .filter({ hasText: "Local YouTube pattern" })
    .click();
  await page.getByRole("link", { name: /^(Watch now|Resume)$/ }).click();
  try {
    const frame = page.locator('iframe[title="YouTube player"]');
    await expect(frame).toBeVisible({ timeout: 25000 });
    await expect(frame).toHaveAttribute(
      "src",
      /youtube\.com\/embed\/M7lc1UVf-VE/,
    );
    const video = page
      .frameLocator('iframe[title="YouTube player"]')
      .locator("video");
    await video.waitFor({ timeout: 10000 });
    const play = page
      .frameLocator('iframe[title="YouTube player"]')
      .getByRole("button", { name: /^play(?: video)?$/i })
      .first();
    await play.click({ timeout: 10000 });
    await expect
      .poll(
        () =>
          video.evaluate(
            (v: HTMLVideoElement) => !v.paused && v.currentTime > 0.5,
          ),
        { timeout: 15000 },
      )
      .toBe(true);
    await video.evaluate((v: HTMLVideoElement) => v.pause());
    await expect(video).toHaveJSProperty("paused", true);
    await info.attach("live-youtube-result", {
      body: JSON.stringify({
        result: "PASS",
        video_id: "M7lc1UVf-VE",
        scope: "isolated fixture app; official external playback",
      }),
      contentType: "application/json",
    });
  } catch (error) {
    const alert = await page.getByRole("alert").allTextContents();
    let externalText = "";
    for (const frame of page.frames()) {
      if (/youtube\.com/.test(frame.url()))
        externalText += await frame
          .locator("body")
          .innerText()
          .catch(() => "");
    }
    const externalReason =
      alert.find((s) =>
        /YouTube.*(could not be reached|too long|private|does not allow|could not verify)/.test(
          s,
        ),
      ) ||
      (/Video unavailable|Sign in|not available|Error 15[03]/i.test(
        externalText,
      )
        ? "Official YouTube embed unavailable or restricted in this environment"
        : "");
    if (externalReason || failedOrigins.length) {
      await info.attach("live-youtube-result", {
        body: JSON.stringify({
          result: "NOT TESTED",
          reason: externalReason || "Third-party network requests failed",
          failed_origins: [...new Set(failedOrigins)],
        }),
        contentType: "application/json",
      });
      test.skip(
        true,
        externalReason ||
          "External YouTube requests failed; no actual playback claim.",
      );
    }
    throw error;
  }
});
