// Extended source formats: DASH playback through dash.js and YouTube embeds.
// Fixtures: seeded movies 'local-dash-pattern' (loopback DASH manifest) and
// 'local-youtube-pattern' (public YouTube link; only the embed is asserted,
// never third-party playback).
import {test,expect} from '../../apps/web/node_modules/@playwright/test';
const web='http://127.0.0.1:18082';
async function signIn(page:import('../../apps/web/node_modules/@playwright/test').Page,email:string){await page.goto(`${web}/login`);await page.getByLabel('Email',{exact:true}).fill(email);await page.getByLabel('Password',{exact:true}).fill('TestPassword!2026');await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL(web+'/');}
async function openMovie(page:import('../../apps/web/node_modules/@playwright/test').Page,search:string,slug:string){
 await page.getByRole('link',{name:'Movies & search'}).click();
 await page.getByLabel('Search movies').fill(search);
 await page.getByRole('button',{name:'Search',exact:true}).click();
 await page.locator('.card').filter({hasText:search}).first().click();
 await page.waitForURL(`${web}/movies/${slug}`);
}
test('DASH sources play through dash.js with the signed-session transport',async({page})=>{
 await signIn(page,'viewer@hyperline.test');
 await openMovie(page,'Local DASH pattern','local-dash-pattern');
 await page.getByRole('link',{name:'Watch now',exact:true}).click();
 await page.waitForURL(/\/watch\?id=\d+/);
 const video=page.locator('video');
 await expect(video).toBeVisible();
 await expect.poll(()=>video.evaluate((v:HTMLVideoElement)=>v.readyState),{timeout:30000}).toBeGreaterThanOrEqual(2);
 await expect.poll(()=>video.evaluate((v:HTMLVideoElement)=>v.videoWidth),{timeout:15000}).toBeGreaterThan(0);
});
test('YouTube sources render the embed player instead of the video element',async({page})=>{
 await signIn(page,'viewer@hyperline.test');
 await openMovie(page,'Local YouTube pattern','local-youtube-pattern');
 await page.getByRole('link',{name:'Watch now',exact:true}).click();
 await page.waitForURL(/\/watch\?id=\d+/);
 const frame=page.locator('iframe[title="YouTube player"]');
 await expect(frame).toBeVisible({timeout:15000});
 await expect(frame).toHaveAttribute('src',/^https:\/\/www\.youtube\.com\/embed\/dQw4w9WgXcQ/);
 await expect(page.locator('video')).toBeHidden();
});
