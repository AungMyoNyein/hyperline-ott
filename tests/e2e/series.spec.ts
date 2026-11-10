// Series discovery, episode ordering, vertical player layout and episode
// navigation against the isolated fixture API; no production endpoints.
import {test,expect} from '../../apps/web/node_modules/@playwright/test';
const web='http://127.0.0.1:18082';const api='http://127.0.0.1:18081/api/v1';
async function signIn(page:import('../../apps/web/node_modules/@playwright/test').Page,email:string){await page.goto(`${web}/login`);await page.getByLabel('Email',{exact:true}).fill(email);await page.getByLabel('Password',{exact:true}).fill('TestPassword!2026');await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL(web+'/');}
test('series are discoverable publicly with ordered published episodes only',async({page,request})=>{
 const home=await request.get(`${api}/home`);const homeJson=await home.json();expect(homeJson.data.series.some((s:{slug:string})=>s.slug==='local-vertical-series')).toBeTruthy();expect(homeJson.data.series.some((s:{slug:string})=>s.slug==='hidden-series-draft')).toBeFalsy();
 await page.goto(web);await expect(page.getByRole('heading',{name:'Series',exact:true})).toBeVisible();await expect(page.locator('main')).toContainText('Local vertical series');await expect(page.locator('main')).not.toContainText('Hidden series draft');
 await page.getByRole('link',{name:'Series',exact:true}).first().click();await page.waitForURL(web+'/series');await expect(page.locator('.card').filter({hasText:'Local vertical series'})).toHaveCount(1);await expect(page.locator('.card').filter({hasText:'3 episodes'})).toHaveCount(1);
 await page.locator('.card').filter({hasText:'Local vertical series'}).click();await page.waitForURL(web+'/series/local-vertical-series');
 await expect(page.getByRole('heading',{name:'Local vertical series',exact:true})).toBeVisible();await expect(page.locator('main')).toContainText('Vertical series');await expect(page.locator('main')).toContainText('Chinese');
 const items=page.locator('.episodes li');await expect(items).toHaveCount(3);await expect(items.nth(0)).toContainText('E1');await expect(items.nth(1)).toContainText('E2');await expect(items.nth(2)).toContainText('E3');await expect(page.locator('main')).not.toContainText('Hidden fixture episode');
 await expect(page.getByRole('link',{name:'Sign in to watch'})).toBeVisible();
 const detail=await request.get(`${api}/series/local-vertical-series`);expect(detail.status()).toBe(200);const detailBody=await detail.text();expect(detailBody).not.toContain('18084');expect(detailBody).not.toContain('m3u8');
 const draft=await request.get(`${api}/series/hidden-series-draft`);expect(draft.status()).toBe(404);
 await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();
});
test('signed-in viewer plays an episode with vertical layout and episode navigation',async({page})=>{
 await signIn(page,'viewer@hyperline.test');
 await page.getByRole('link',{name:'Series',exact:true}).first().click();
 await page.waitForURL(web+'/series');
 await page.locator('.card').filter({hasText:'Local vertical series'}).click();
 await page.waitForURL(web+'/series/local-vertical-series');
 await page.locator('.episodes li').filter({hasText:'E2'}).getByRole('link',{name:/^(Watch|Resume)$/}).click();
 await page.waitForURL(/\/watch\?episode=\d+&series=local-vertical-series&n=2/);
 await expect(page.locator('.player-vertical')).toBeVisible();
 await expect(page.locator('.eyebrow',{hasText:'EPISODE 2'})).toBeVisible();
 const video=page.locator('video');await expect.poll(()=>video.evaluate((v:HTMLVideoElement)=>v.readyState),{timeout:30000}).toBeGreaterThanOrEqual(2);
 await page.getByRole('link',{name:'Next episode →'}).click();
 await page.waitForURL(/n=3/);await expect(page.locator('.eyebrow',{hasText:'EPISODE 3'})).toBeVisible();
 await expect(page.getByRole('link',{name:'Next episode →'})).toHaveClass(/disabled/);
 await page.getByRole('link',{name:'← Previous episode'}).click();
 await page.waitForURL(/n=2/);await expect(page.locator('.eyebrow',{hasText:'EPISODE 2'})).toBeVisible();
 await page.getByRole('link',{name:'All episodes'}).click();
 await page.waitForURL(web+'/series/local-vertical-series');await expect(page.getByRole('heading',{name:'Local vertical series',exact:true})).toBeVisible();
 await page.setViewportSize({width:390,height:844});
 await page.locator('.episodes li').filter({hasText:'E1'}).getByRole('link',{name:/^(Watch|Resume)$/}).click();
 await page.waitForURL(/n=1/);await expect(page.locator('.player-vertical video')).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();
});
