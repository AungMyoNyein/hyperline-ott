// Production smoke only: existing-account sign-in, reads and sign-out.
// No fixtures, content changes, playback, traces, screenshots or token logging.
const {chromium, expect} = require('../../apps/web/node_modules/@playwright/test');

(async () => {
  const checkBrowser = process.argv.includes('--check-browser');
  const keys = ['HYPERLINE_CMS_EMAIL', 'HYPERLINE_CMS_PASSWORD', 'HYPERLINE_VIEWER_EMAIL', 'HYPERLINE_VIEWER_PASSWORD'];
  if (!checkBrowser && keys.some(k => !process.env[k])) throw new Error('Missing existing-account inputs');
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  try {
    if (checkBrowser) {
      const page = await browser.newPage();
      await page.goto('about:blank');
      console.log('PASS Chromium launch; production TLS validation remains enabled');
      return;
    }
    for (const role of ['CMS', 'VIEWER']) {
      const host = role === 'CMS' ? 'https://cms.zentunnel.net' : 'https://ott.zentunnel.net';
      const context = await browser.newContext({ignoreHTTPSErrors: false});
      // No CDN/origin requests are needed for this smoke test.
      await context.route('**/*', route => {
        const url = new URL(route.request().url());
        return ['cms.zentunnel.net', 'ott.zentunnel.net', 'api.zentunnel.net'].includes(url.hostname)
          ? route.continue() : route.abort();
      });
      const page = await context.newPage();
      try {
        const response = await page.goto(host + (role === 'CMS' ? '/' : '/login'));
        if (!response || response.status() !== 200) throw new Error('Page readiness');
        await page.getByLabel('Email', {exact: true}).fill(process.env[`HYPERLINE_${role}_EMAIL`]);
        await page.getByLabel('Password', {exact: true}).fill(process.env[`HYPERLINE_${role}_PASSWORD`]);
        const api = 'https://api.zentunnel.net/api/v1';
        const loginPath = role === 'CMS' ? '/admin/login' : '/auth/login';
        const loggedIn = page.waitForResponse(r => r.url() === api + loginPath && r.request().method() === 'POST');
        const dashboard = role === 'CMS' ? page.waitForResponse(r => r.url() === api + '/admin/dashboard') : null;
        const movies = role === 'CMS' ? page.waitForResponse(r => r.url().startsWith(api + '/admin/movies?')) : null;
        await page.getByRole('button', {name: 'Sign in', exact: true}).click();
        const login = await loggedIn;
        if (login.status() !== 200) throw new Error('Login readiness');
        const originHeaders = await login.allHeaders();
        if (originHeaders['access-control-allow-origin'] !== host || originHeaders['access-control-allow-credentials'] !== 'true') throw new Error('Credentialed CORS');
        await expect(page.getByRole('button', {name: 'Sign out', exact: true})).toBeVisible();
        if (role === 'CMS') {
          if ((await dashboard).status() !== 200 || (await movies).status() !== 200) throw new Error('CMS authorization');
          await expect(page.getByRole('heading', {name: 'Movie library', exact: true})).toBeVisible();
        } else {
          await page.getByRole('link', {name: 'Movies & search', exact: true}).click();
          await expect(page.getByRole('heading', {name: 'Find your next story', exact: true})).toBeVisible();
        }
        const logoutPath = role === 'CMS' ? '/admin/logout' : '/auth/logout';
        const loggedOut = page.waitForResponse(r => r.url() === api + logoutPath && r.request().method() === 'POST');
        await page.getByRole('button', {name: 'Sign out', exact: true}).click();
        if (!(await loggedOut).ok()) throw new Error('Logout readiness');
        if (role === 'CMS') await expect(page.getByRole('heading', {name: 'Administrator sign in', exact: true})).toBeVisible();
        else await expect(page.getByRole('link', {name: 'Sign in', exact: true})).toBeVisible();
        console.log('PASS ' + role + ' trusted HTTPS, existing-account login, authorized reads, credentialed CORS and logout');
      } finally { await context.close(); }
    }
  } finally { await browser.close(); }
})().catch(() => {
  console.error('FAIL browser smoke incomplete; sensitive error and response details suppressed');
  process.exitCode = 1;
});
