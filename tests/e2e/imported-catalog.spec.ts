import { test, expect } from "../../apps/web/node_modules/@playwright/test";
import { mockYouTube } from "./youtube-fixture";

test("imported Myanmar draft is CMS-only; isolated publication exposes Unicode, attribution and official player contract", async ({
  page,
  request,
}) => {
  test.skip(
    !process.env.HYPERLINE_CATALOG_CSV,
    "Real CSV fixture is opt-in and isolated.",
  );
  const api = "http://127.0.0.1:18081/api/v1";
  const title = "အဝိဇ္ဇာကမ်းပါး";
  const login = await request.post(`${api}/auth/login`, {
    data: { email: "admin@hyperline.test", password: "TestPassword!2026" },
  });
  expect(login.ok()).toBe(true);
  const token = (await login.json()).data.token;
  const headers = {
    Authorization: `Bearer ${token}`,
    Accept: "application/json",
  };
  const library = await request.get(
    `${api}/admin/movies?q=${encodeURIComponent(title)}`,
    { headers },
  );
  const movie = (await library.json()).data.find(
    (m: { title: string }) => m.title === title,
  );
  expect(movie.published).toBe(false);
  expect(movie.youtube.channel_name).toBe("Mahar");
  const hidden = await request.get(`${api}/movies/${movie.slug}`);
  expect(hidden.status()).toBe(404);

  await page.goto("http://127.0.0.1:18083");
  await page.getByLabel("Email", { exact: true }).fill("admin@hyperline.test");
  await page.getByLabel("Password", { exact: true }).fill("TestPassword!2026");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page
    .getByRole("navigation", { name: "Studio navigation" })
    .getByRole("button", { name: "Movies", exact: true })
    .click();
  const row = page.locator(".library-row").filter({ hasText: title });
  await page.getByLabel("Search movies", { exact: true }).fill(title);
  await page
    .getByRole("button", { name: "Apply filters", exact: true })
    .click();
  await expect(row.getByText("Draft", { exact: true })).toBeVisible();
  await expect(row).toContainText("YouTube · Mahar");

  try {
    expect(
      (
        await request.patch(`${api}/admin/movies/${movie.id}`, {
          headers,
          data: { published: true },
        })
      ).ok(),
    ).toBe(true);
    await mockYouTube(page);
    await page.goto("http://127.0.0.1:18082/login");
    await page
      .getByLabel("Email", { exact: true })
      .fill("viewer@hyperline.test");
    await page
      .getByLabel("Password", { exact: true })
      .fill("TestPassword!2026");
    await page.getByRole("button", { name: "Sign in", exact: true }).click();
    await page.waitForURL("http://127.0.0.1:18082/");
    await page.getByRole("link", { name: "Movies", exact: true }).click();
    await page
      .locator(".card")
      .filter({ hasText: title })
      .getByRole("link", { name: /details$/ })
      .click();
    await expect(
      page.getByRole("heading", { name: title, exact: true }),
    ).toBeVisible();
    await expect(page.getByLabel("YouTube source attribution")).toContainText(
      movie.youtube.youtube_title,
    );
    await expect(
      page
        .getByLabel("YouTube source attribution")
        .getByRole("link", { name: "Mahar" }),
    ).toHaveAttribute("href", "https://www.youtube.com/@MaharOfficialMovies");
    await page.getByRole("link", { name: /^(Watch now|Resume)$/ }).click();
    await expect(page.locator('iframe[title="YouTube player"]')).toBeVisible();
    await expect(page.getByLabel("YouTube source attribution")).toContainText(
      "Mahar",
    );
    // Contract mock only; no claim that this CSV film is embeddable in every region.
    await expect(
      page.locator('iframe[title="YouTube player"]'),
    ).toHaveAttribute("src", /ubS9j_8REko/);
  } finally {
    expect(
      (
        await request.patch(`${api}/admin/movies/${movie.id}`, {
          headers,
          data: { published: false },
        })
      ).ok(),
    ).toBe(true);
  }
  expect((await request.get(`${api}/movies/${movie.slug}`)).status()).toBe(404);
});
