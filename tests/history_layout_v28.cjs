const {chromium}=require('playwright');
const fs=require('fs'), assert=require('assert');
const path=require('path');
const base=path.resolve(__dirname,'../.w13l')+'/';
const fixtures=path.resolve(__dirname,'fixtures/history-v28')+'/';
const native=fs.readFileSync(fixtures+'myRecord.css','utf8')+'\n'+fs.readFileSync(fixtures+'trend.css','utf8');
const bad=fs.readFileSync(fixtures+'legacy-hide.js','utf8');
const limiter=bad.slice(bad.indexOf('  function limitHistory(){'),bad.indexOf('  function fixCopy(){'));
const limiter2=bad.slice(bad.indexOf('  function forceGameHistoryPage10(){'),bad.indexOf("  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',function(){hideBadInjectedImages"));
const oldCSS=fs.readFileSync(fixtures+'old-patch.css','utf8');
function rows(game,seed=10){return Array.from({length:10},(_,i)=>`<div><div class="list-item"><div class="list-item-l"><div class="list-item-l-big">Big</div></div><div class="list-item-m"><div class="list-item-m-top">20260908${game}0${String(seed-i).padStart(3,'0')} <svg width="9" height="8"></svg></div><div class="list-item-m-bottom">2026-09-08 09:30:00</div></div>${i===0?'':`<div class="list-item-r success"><div>Success</div><span>+₹19,600.00</span></div>`}</div></div>`).join('');}
function chart(){return '<div class="t"><div class="t-b1"><div class="t-b1-l">Max consecutive / last 100 Periods</div></div><div class="t-b2">'+Array.from({length:10},(_,i)=>`<div class="t-b2-item"><div class="van-row"><div class="van-col van-col--9"><div class="t-b2-i">20260908100050${String(99-i).padStart(3,'0')}</div></div><div class="van-col van-col--15"><div class="t-b2-Num"><canvas class="line-canvas"></canvas>${Array.from({length:10},(_,j)=>`<div class="t-b2-Num-item ${j===i?'action'+j:''}">${j}</div>`).join('')}<div class="t-b2-Num-BS">B</div></div></div></div></div>`).join('')+'</div></div>';}
const html=`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>html{font-size:min(10vw,40px)}body{margin:0;background:#202329;color:#fff;font-family:Arial}*{box-sizing:border-box}:root{--text_color_L1:#fff;--bg_color_L2:#24262b;--main-color:#dd4545;--norm_secondary-color:#f8b460;--norm_green-color:#13c164;--norm_red-color:#f0484b}.history{margin:12px}.van-row{display:flex}.van-col--9{width:37.5%}.van-col--15{width:62.5%}.my_r-foot{display:flex}.van-col{float:left}</style><style>${native}\n${oldCSS}</style></head><body><main id="app"><div class="history"><div class="my_r"><div class="my_r-body"><div class="list">${rows('10005')}</div></div><div class="my_r-foot">1/2</div></div>${chart()}</div><div class="unrelated" style="display:none">Keep hidden</div></main></body></html>`;
(async()=>{const browser=await chromium.launch({headless:true});const report=[];
for(const width of [320,360,390,430]){
 const page=await browser.newPage({viewport:{width,height:844}});const calls=[];const errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  if(route.request().isNavigationRequest()) return route.fulfill({contentType:'text/html',body:html});
  calls.push(route.request().url());return route.fulfill({contentType:'application/json',body:'{"result":true,"data":true}'});
 });
 await page.goto('https://test.local/WinGo/WinGo_30S');
 await page.evaluate(()=>{document.querySelectorAll('.my_r *').forEach(e=>e.setAttribute('data-v-b6b03d74',''));document.querySelectorAll('.t,.t *').forEach(e=>e.setAttribute('data-v-6f1c18a2',''));});
 await page.addScriptTag({content:limiter+limiter2+';limitHistory();forceGameHistoryPage10();setInterval(limitHistory,1000);setInterval(forceGameHistoryPage10,1200);'});
 const hiddenBefore=await page.locator('.list-item-m-top').evaluateAll(els=>els.filter(e=>!e.getBoundingClientRect().height).length);
 assert(hiddenBefore>0,'must reproduce original hide bug');
 await page.addScriptTag({content:fs.readFileSync(base+'js/13l-hist25.js','utf8')});
 const data=await page.evaluate(async()=>{
  const issues=[...document.querySelectorAll('.list-item-m-top')].map(e=>e.textContent);
  let failures=0,frames=0,maxRight=0;
  const deadline=performance.now()+5300;
  while(performance.now()<deadline){
   await new Promise(requestAnimationFrame);frames++;
   for(const e of document.querySelectorAll('.list-item,.list-item-l,.list-item-l > div,.list-item-m-top,.list-item-m-bottom,.t-b2-item,.t-b2-Num-item')){
    const r=e.getBoundingClientRect();if(r.width<=0||r.height<=0)failures++;maxRight=Math.max(maxRight,r.right);
   }
  }
  return {failures,frames,maxRight,unchanged:JSON.stringify(issues)===JSON.stringify([...document.querySelectorAll('.list-item-m-top')].map(e=>e.textContent)),chart:[...document.querySelectorAll('.t-b2-item')].filter(e=>e.offsetHeight>0).length,unrelated:getComputedStyle(document.querySelector('.unrelated')).display};
 });
 assert.equal(data.failures,0);assert.equal(data.chart,10);assert(data.unchanged);assert.equal(data.unrelated,'none');assert(data.maxRight<=width+1,`overflow ${data.maxRight} > ${width}`);
 assert(!calls.some(u=>u.includes('GetMyGameRecord')),'no all-game filler requests');
 // Native rerender / game tab switch: no stale text restored from a global cache.
 await page.locator('.list').evaluate((el,markup)=>{el.innerHTML=markup;el.querySelectorAll('*').forEach(e=>e.setAttribute('data-v-b6b03d74',''));},rows('10001',20));
 await page.evaluate(()=>history.pushState({},'', '/WinGo/WinGo_1M'));
 await page.waitForTimeout(1300);
 assert(await page.locator('.list-item-m-top').first().textContent().then(s=>s.includes('10001')));
 // Native pending row and conditional detail remain native.
 assert.equal(await page.locator('.list-item').first().locator('.list-item-r').count(),0);
 assert.equal(await page.locator('.list-detail').count(),0);
 await page.screenshot({path:fixtures+`layout-v28-${width}.png`,fullPage:true});
 await page.evaluate(()=>history.pushState({},'', '/'));
 assert.equal(await page.locator('html').getAttribute('data-13l-history'),'0');
 assert.deepEqual(errors,[]);report.push({width,hiddenBefore,...data,errors});await page.close();
}
await browser.close();fs.writeFileSync(fixtures+'results.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));})().catch(e=>{console.error(e);process.exit(1)});
