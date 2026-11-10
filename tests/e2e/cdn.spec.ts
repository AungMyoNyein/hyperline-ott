import {test,expect} from '../../apps/web/node_modules/@playwright/test';
const base='https://ott.hyperline.test:18443';
test.skip(!process.env.HYPERLINE_CDN_E2E,'Run with tests/cdn/run.py and its isolated Nginx fixture.');
test('signed HttpOnly cookies authorize nested HLS and renew without exposing tokens',async({page,context})=>{
 await page.goto(`${base}/login`);
 await page.getByLabel('Email',{exact:true}).fill('viewer@hyperline.test');
 await page.getByLabel('Password',{exact:true}).fill('TestPassword!2026');
 await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL(base+'/');
 await page.getByRole('link',{name:'Movies & search'}).click();await expect(page.getByRole('heading',{name:'Find your next story'})).toBeVisible();
 await page.locator('.card').filter({hasText:'Protected test 1'}).click();
 const playbackResponse=page.waitForResponse(r=>r.url().endsWith('/playback/sessions')&&r.request().method()==='POST');
 await page.getByRole('link',{name:'Watch now',exact:true}).click();
 const response=await playbackResponse;expect(response.status()).toBe(201);const playback=(await response.json()).data;
 expect(playback.credentials_required).toBe(true);expect(playback.url).toMatch(/^https:\/\/cdn\.hyperline\.test:18443\/hls\/\d+\/\d+\/master.m3u8$/);
 const cookies=(await context.cookies(playback.url)).filter(c=>c.name==='__Secure-hl_playback');expect(cookies).toHaveLength(1);
 expect(cookies[0].secure).toBe(true);expect(cookies[0].httpOnly).toBe(true);expect(cookies[0].sameSite).toBe('Lax');expect(cookies[0].domain).toBe('.hyperline.test');
 expect(await page.evaluate(()=>document.cookie)).not.toContain('__Secure-hl_playback');expect(JSON.stringify(playback)).not.toContain(cookies[0].value);
 const video=page.locator('video');await expect.poll(()=>video.evaluate((v:HTMLVideoElement)=>v.readyState),{timeout:30000}).toBeGreaterThanOrEqual(2);
 await video.evaluate(async(v:HTMLVideoElement)=>{v.loop=true;await v.play();});await expect.poll(()=>video.evaluate((v:HTMLVideoElement)=>v.currentTime)).toBeGreaterThan(.5);
 const statuses=await page.evaluate(async(url:string)=>{
  const noCookie=await fetch(url,{credentials:'omit'});const withCookie=await fetch(url,{credentials:'include'});return [noCookie.status,withCookie.status];
 },playback.url);expect(statuses).toEqual([403,200]);
 const renewal=await page.waitForResponse(r=>r.url().endsWith(`/playback/sessions/${playback.session_id}/renew`),{timeout:25000});expect(renewal.status()).toBe(200);
 const renewed=(await context.cookies(playback.url)).find(c=>c.name==='__Secure-hl_playback')!;expect(renewed.expires).toBeGreaterThan(cookies[0].expires);expect(renewed.value).not.toBe(cookies[0].value);
 await page.getByRole('link',{name:'Home',exact:true}).click();
});
