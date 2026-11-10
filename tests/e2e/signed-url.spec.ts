import {test,expect} from '../../apps/web/node_modules/@playwright/test';
test.skip(!process.env.HYPERLINE_SIGNED_FIXTURE,'Run with HYPERLINE_SIGNED_FIXTURE=1; mock transport, not a real CDN protocol.');
test('direct signed-URL transport refreshes through Laravel and preserves playback position',async({page})=>{
 const web='http://127.0.0.1:18082';
 await page.goto(`${web}/login`);await page.getByLabel('Email',{exact:true}).fill('viewer@hyperline.test');await page.getByLabel('Password',{exact:true}).fill('TestPassword!2026');await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL(web+'/');
 await page.getByRole('link',{name:'Movies & search'}).click();await expect(page.getByRole('heading',{name:'Find your next story'})).toBeVisible();await page.locator('.card').filter({hasText:'Local test pattern'}).click();
 const created=page.waitForResponse(r=>r.url().endsWith('/playback/sessions')&&r.request().method()==='POST');
 await page.getByRole('link',{name:'Watch now',exact:true}).click();const playback=(await (await created).json()).data;
 expect(playback.signature_required).toBe(true);expect(playback.credentials_required).toBe(false);expect(new URL(playback.url).origin).toBe('https://127.0.0.1:18084');
 const renewed=page.waitForResponse(r=>r.url().endsWith(`/playback/sessions/${playback.session_id}/renew`));
 const reloaded=page.waitForRequest(r=>r.url().startsWith('https://127.0.0.1:18084/master.m3u8?')&&r.url()!==playback.url);
 const video=page.locator('video');await expect.poll(()=>video.evaluate((v:HTMLVideoElement)=>v.readyState)).toBeGreaterThanOrEqual(2);await video.evaluate((v:HTMLVideoElement)=>v.play());await expect.poll(()=>video.evaluate((v:HTMLVideoElement)=>v.currentTime)).toBeGreaterThan(.5);
 const refresh=await renewed;expect(refresh.status()).toBe(200);const next=(await refresh.json()).data;expect(next.url).not.toBe(playback.url);expect(new URL(next.url).pathname).toBe(new URL(playback.url).pathname);await reloaded;
 await expect.poll(()=>video.evaluate((v:HTMLVideoElement)=>v.currentTime)).toBeGreaterThan(1);await expect.poll(()=>video.evaluate((v:HTMLVideoElement)=>!v.paused)).toBeTruthy();
});
