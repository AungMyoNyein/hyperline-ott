import type { Page } from "../../apps/web/node_modules/@playwright/test";
import { readFileSync } from "node:fs";
import { join } from "node:path";

export async function mockYouTube(page: Page) {
  await page.route("https://www.youtube.com/iframe_api", (route) =>
    route.fulfill({
      contentType: "text/javascript",
      body: readFileSync(join(__dirname, "../fixtures/youtube-api.js"), "utf8"),
    }),
  );
  await page.route("https://www.youtube.com/embed/**", (route) =>
    route.fulfill({
      contentType: "text/html",
      body: "<!doctype html><title>Isolated YouTube API contract fixture</title>",
    }),
  );
}

export async function youtubeAction(
  page: Page,
  action: "playVideo" | "pauseVideo" | "finish" | "seekTo" | "fail",
  value = 0,
) {
  await page.evaluate(
    ({ action, value }) => {
      const fixture = window as unknown as {
        __youtubePlayers: Record<string, (value: number) => void>[];
      };
      fixture.__youtubePlayers.at(-1)![action](value);
    },
    { action, value },
  );
}
