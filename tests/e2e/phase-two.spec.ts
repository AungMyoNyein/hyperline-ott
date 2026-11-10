// Phase 2 vertical slice: CMS series/season/episode management → publish →
// web browse → episode playback with progress, resume and continue watching.
// Runs against the isolated fixture stack only; no production endpoints.
import {test,expect} from '../../apps/web/node_modules/@playwright/test';
const web='http://127.0.0.1:18082';const cms='http://127.0.0.1:18083';const api='http://127.0.0.1:18081/api/v1';
async function signIn(page:import('../../apps/web/node_modules/@playwright/test').Page,base:string,email:string){await page.goto(base===cms?cms:`${web}/login`);await page.getByLabel('Email',{exact:true}).fill(email);await page.getByLabel('Password',{exact:true}).fill('TestPassword!2026');await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL(base===cms?cms:web+'/');}
test('admin creates a series with a season and episode in the CMS, publishes it, and it becomes publicly browsable',async({page,request})=>{
 const slug=`phase-two-series-${Date.now()}`;const title=`Phase two series ${Date.now()}`;
 await signIn(page,cms,'admin@hyperline.test');
 await expect(page.getByRole('heading',{name:'Series library',exact:true})).toBeVisible();
 // Create the series through the UI.
 await page.getByRole('button',{name:'New series',exact:true}).click();
 const createForm=page.getByRole('form',{name:'Create series'});
 await expect(createForm).toBeVisible();
 await createForm.getByLabel('Title',{exact:true}).fill(title);
 await createForm.getByLabel('Slug (optional on create)',{exact:true}).fill(slug);
 await createForm.getByLabel('Category (e.g. vertical)',{exact:true}).fill('vertical');
 await createForm.getByRole('button',{name:'Save series',exact:true}).click();
 await expect(page.getByRole('status').filter({hasText:'Series saved'})).toBeVisible();
 await expect(page.locator('.library article').filter({hasText:title}).first()).toContainText('Draft');
 await expect(page.locator('.library article').filter({hasText:title}).first()).toContainText('1 season(s) · 0 episode(s)');
 // Validation: duplicate slug is rejected inline.
 await page.getByRole('button',{name:'New series',exact:true}).click();
 await page.getByRole('form',{name:'Create series'}).getByLabel('Title',{exact:true}).fill('Dup');
 await page.getByRole('form',{name:'Create series'}).getByLabel('Slug (optional on create)',{exact:true}).fill(slug);
 await page.getByRole('form',{name:'Create series'}).getByRole('button',{name:'Save series',exact:true}).click();
 await expect(page.locator('.field-error').filter({hasText:'slug'})).toBeVisible();
 // Cancel on a dirty form asks for confirmation; accept it.
 page.once('dialog',d=>d.accept());
 await page.getByRole('form',{name:'Create series'}).getByRole('button',{name:'Cancel',exact:true}).click();
 // Manage: reopen the manager (the "New series" click collapsed it), then add
 // season 2 and an episode in season 1.
 const seriesRow=page.locator('.library article').filter({hasText:title}).first();
 const manager=seriesRow.locator('.season-manager');
 await seriesRow.getByRole('button',{name:'Manage',exact:true}).click();
 await expect(manager).toBeVisible();
 await expect(manager.locator('.season-block h4').filter({hasText:'Season 1'})).toBeVisible();
 await manager.getByRole('button',{name:'Add season',exact:true}).click();
 const seasonForm=manager.getByRole('form',{name:'Add season'});
 await seasonForm.getByLabel('Season number',{exact:true}).fill('2');
 await seasonForm.getByLabel('Season title',{exact:true}).fill('Second run');
 await seasonForm.getByRole('button',{name:'Save season',exact:true}).click();
 await expect(page.getByRole('status').filter({hasText:'Season saved'})).toBeVisible();
 await expect(manager.locator('.season-block h4').filter({hasText:'Season 2 — Second run'})).toBeVisible();
 const season1=manager.locator('.season-block').filter({hasText:'Season 1'}).first();
 await season1.getByRole('button',{name:'Add episode',exact:true}).click();
 const epForm=season1.getByRole('form',{name:'Add episode'});
 await epForm.getByLabel('Episode number',{exact:true}).fill('1');
 await epForm.getByLabel('Episode title',{exact:true}).fill('Phase two pilot');
 await epForm.getByLabel('HTTPS stream URL',{exact:true}).fill('https://cdn.example.test/phase-two/pilot.m3u8');
 await epForm.getByRole('button',{name:'Save episode',exact:true}).click();
 await expect(page.getByRole('status').filter({hasText:'Episode saved'})).toBeVisible();
 await expect(season1.locator('.episode-list')).toContainText('E1');
 await expect(season1.locator('.episode-list')).toContainText('Phase two pilot');
 await expect(season1.locator('.episode-list')).toContainText('Draft');
 // Publish the episode, then the series.
 await season1.locator('.episode-list').getByRole('button',{name:'Publish',exact:true}).click();
 await expect(page.getByRole('status').filter({hasText:'Episode published'})).toBeVisible();
 await seriesRow.getByRole('button',{name:'Publish',exact:true}).click();
 await expect(page.getByRole('status').filter({hasText:'Series published'})).toBeVisible();
 await expect(seriesRow).toContainText('Published');
 // Destructive-action confirmation is required (dismiss it, nothing is deleted).
 page.once('dialog',d=>d.dismiss());
 await season1.getByRole('button',{name:'Delete season',exact:true}).click();
 await expect(manager.locator('.season-block h4').filter({hasText:'Season 2 — Second run'})).toBeVisible();
 // Public API now exposes the hierarchy; stream URLs never leak.
 const detail=await request.get(`${api}/series/${slug}`);
 expect(detail.status()).toBe(200);
 const body=await detail.json();
 expect(body.data.seasons.map((s:{season_number:number})=>s.season_number)).toEqual([1,2]);
 expect(body.data.episodes).toHaveLength(1);
 expect(body.data.episodes[0].season_number).toBe(1);
 expect(await detail.text()).not.toContain('cdn.example.test');
 // Web browse: the new series is publicly visible.
 await page.goto(`${web}/series/${slug}`);
 await expect(page.getByRole('heading',{name:title,exact:true})).toBeVisible();
 const picker=page.getByLabel('Season',{exact:true});
 await expect(picker).toBeVisible();
 await expect(picker).toHaveValue('1');
 await expect(page.locator('.episodes li')).toHaveCount(1);
 await expect(page.locator('.episodes li').first()).toContainText('Phase two pilot');
 await picker.selectOption('2');
 await expect(page.locator('.episodes li')).toHaveCount(0);
 await expect(page.locator('main')).toContainText('No episodes published in this season yet.');
});
test('viewer episode playback records progress, resumes and feeds continue watching',async({page,request})=>{
 await signIn(page,web,'viewer@hyperline.test');
 // In-app navigation only: the session token lives in memory, so a full page
 // load (page.goto) would silently discard the signed-in session.
 await page.getByRole('link',{name:'Series',exact:true}).first().click();
 await page.waitForURL(web+'/series');
 await page.locator('.card').filter({hasText:'Local vertical series'}).click();
 await page.waitForURL(web+'/series/local-vertical-series');
 await expect(page.locator('.episodes li')).toHaveCount(3);
 // Play episode 2 and move the playhead so a progress write is due.
 await page.locator('.episodes li').filter({hasText:'E2'}).getByRole('link',{name:/^(Watch|Resume)$/}).click();
 await page.waitForURL(/\/watch\?episode=\d+&series=local-vertical-series&n=2/);
 const video=page.locator('video');
 await expect.poll(()=>video.evaluate((v:HTMLVideoElement)=>v.readyState),{timeout:30000}).toBeGreaterThanOrEqual(2);
 const progressPost=page.waitForRequest(r=>r.url().endsWith('/watch-progress')&&r.method()==='POST',{timeout:30000});
 // Seek relative to the current position: an absolute target could coincide
 // with a restored resume point, and a no-op seek fires no timeupdate.
 await video.evaluate((v:HTMLVideoElement)=>{v.currentTime=Math.max(1,Math.min((v.duration||8)-0.5,v.currentTime+2));});
 const req=await progressPost;
 const payload=JSON.parse(req.postData()||'{}');
 expect(payload.episode_id).toBeGreaterThan(0);
 expect(payload.movie_id).toBeUndefined();
 // Back to the episode list through the in-app link: it now offers Resume
 // with a progress indicator for E2.
 await page.getByRole('link',{name:'All episodes'}).click();
 await page.waitForURL(web+'/series/local-vertical-series');
 await expect(page.locator('.episodes li').filter({hasText:'E2'}).getByRole('link',{name:'Resume',exact:true})).toBeVisible();
 await expect(page.locator('.episodes li').filter({hasText:'E2'}).locator('.progress-track')).toBeVisible();
 // Continue watching on home distinguishes the episode row.
 await page.getByRole('link',{name:'Home',exact:true}).click();
 await page.waitForURL(web+'/');
 const rail=page.locator('section[aria-label="Continue watching"]');
 await expect(rail).toContainText('Local vertical series');
 await expect(rail).toContainText('E2');
 const episodeLink=rail.getByRole('link').filter({hasText:'Local vertical series'}).first();
 await expect(episodeLink).toHaveAttribute('href',/\/watch\?episode=\d+&series=local-vertical-series&n=2/);
 // The progress API refuses anonymous callers (401), so viewer rows stay private.
 const list=await request.get(`${api}/watch-progress`);
 expect(list.status()).toBe(401);
});
