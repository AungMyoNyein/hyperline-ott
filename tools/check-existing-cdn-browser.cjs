// Metadata-only browser diagnostic. Does not navigate to the real OTT site or
// download video/playlist bodies. A local intercepted document supplies Origin.
const {chromium}=require('../apps/web/node_modules/playwright');
const fs=require('node:fs');
(async()=>{
 const url=process.argv[2]?fs.readFileSync(process.argv[2],'utf8').trim():'https://cdn.goldendragon2000.com/';
 const parsed=new URL(url);
 if(parsed.protocol!=='https:'||parsed.hostname!=='cdn.goldendragon2000.com'||(parsed.port&&parsed.port!=='443')||parsed.username||parsed.password||parsed.hash)throw new Error('Invalid diagnostic target');
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const page=await browser.newPage();
  const origin='https://ott.zentunnel.net/__hyperline_cdn_diagnostic__';
  await page.route(origin,r=>r.fulfill({status:200,contentType:'text/html',body:'<!doctype html><title>Local CDN metadata diagnostic</title>'}));
  await page.goto(origin);
  const result=await page.evaluate(async(url)=>{
   const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),15000);
   try{const response=await fetch(url,{method:'HEAD',mode:'cors',credentials:'omit',redirect:'error',cache:'no-store',signal:controller.signal});return {browser_cors_allowed:true,status:response.status,content_type:response.headers.get('Content-Type')};}
   catch(e){return {browser_cors_allowed:false,error_type:e.name};}finally{clearTimeout(timer);}
  },url);
  console.log(JSON.stringify({origin:'https://ott.zentunnel.net',target_host:parsed.hostname,scope:'HEAD only; anonymous; no redirects/media/body fetched',...result},null,2));
 }finally{await browser.close();}
})().catch(()=>{console.error('Browser diagnostic failed; no URL or credentials printed.');process.exitCode=1;});
