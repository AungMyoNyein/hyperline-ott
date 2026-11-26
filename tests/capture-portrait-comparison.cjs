const { chromium } = require('../apps/web/node_modules/@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const phase = process.argv[2];
const base = process.argv[3];
const evidence = path.resolve(__dirname, '../docs/ui/portrait-compact');
const screenshots = path.join(evidence, 'comparisons');
const movies = Array.from({length:12}, (_,index)=>({id:1000+index,slug:`fixture-${index}`,title:index%2?'မြန်မာ ရုပ်ရှင် ဇာတ်လမ်း':`Isolated movie ${index+1}`,poster_url:`https://artwork.hyperline.test/${index%3===0?'portrait':index%3===1?'landscape':'missing'}.svg`,backdrop_url:'https://artwork.hyperline.test/landscape.svg',synopsis:'A short story for the isolated layout fixture.',genres:[],published:true,playable:true,featured:true,release_year:2026,duration_minutes:90}));
(async()=>{
  fs.mkdirSync(screenshots,{recursive:true});
  const browser=await chromium.launch({headless:true,executablePath:'/root/.cache/ms-playwright/chromium-1208/chrome-linux64/chrome'});
  const page=await browser.newPage();
  const metrics=[];
  await page.route('https://artwork.hyperline.test/**',route=>{
    const url=route.request().url();
    if(url.includes('missing'))return route.fulfill({status:404,body:''});
    const portrait=url.includes('portrait');
    return route.fulfill({contentType:'image/svg+xml',body:`<svg xmlns="http://www.w3.org/2000/svg" width="${portrait?600:1600}" height="900"><rect width="100%" height="100%" fill="#302028"/><circle cx="300" cy="300" r="180" fill="#762030"/></svg>`});
  });
  await page.route('**/api/v1/**',route=>{
    if(!['GET','HEAD','OPTIONS'].includes(route.request().method()))return route.abort();
    const pathname=new URL(route.request().url()).pathname.split('/api/v1')[1];
    if(pathname==='/home')return route.fulfill({json:{data:{hero:movies[0],trending:movies,latest:movies,featured:movies,series:[],continue_watching:[]}}});
    if(pathname==='/movies')return route.fulfill({json:{data:movies,meta:{total:12,current_page:1,last_page:1}}});
    return route.fulfill({json:{data:[]}});
  });
  try{
    for(const width of [360,390,768,1366,1920]){
      await page.setViewportSize({width,height:width<600?844:1080});
      await page.goto(base,{waitUntil:'domcontentloaded'});
      await page.locator('.hero').waitFor();
      await page.evaluate(()=>document.fonts.ready);
      const hero=await page.locator('.hero').boundingBox();
      await page.screenshot({path:path.join(screenshots,`${phase}-home-${width}.png`),fullPage:true});
      await page.goto(base+'/movies',{waitUntil:'domcontentloaded'});
      await page.locator('.movie-card').first().waitFor();
      for(const artwork of await page.locator('.movie-card .artwork').all())await artwork.scrollIntoViewIfNeeded({timeout:1000});
      await page.evaluate(()=>window.scrollTo(0,0));
      const frame=await page.locator('.card-image').first().boundingBox();
      await page.screenshot({path:path.join(screenshots,`${phase}-catalog-${width}.png`),fullPage:true});
      metrics.push({width,hero_height:hero.height,poster_ratio:frame.width/frame.height,columns:await page.locator('.grid').evaluate(element=>getComputedStyle(element).gridTemplateColumns.split(' ').length),overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)});
    }
    fs.writeFileSync(path.join(evidence,`${phase}-fixture-measurements.json`),JSON.stringify(metrics,null,2)+'\n');
    console.log(JSON.stringify(metrics));
  }finally{await browser.close();}
})().catch(error=>{console.error(error.message.split('\n')[0]);process.exit(1)});
