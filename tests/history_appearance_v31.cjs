const {chromium}=require('playwright');const fs=require('fs'),path=require('path'),assert=require('assert');
const base=path.resolve(__dirname,'../.w13l'),fixtures=path.resolve(__dirname,'fixtures/history-v31');
const css=fs.readFileSync(base+'/css/13l-patch25.css','utf8');const reference=fs.readFileSync(fixtures+'/dhani-record.css','utf8');
const faces=(css.match(/@font-face\{[^}]+\}/g)||[]).join('\n');assert.equal((faces.match(/@font-face/g)||[]).length,4);
const sample=()=>Array.from({length:10},(_,i)=>`<div><div class="list-item"><div class="list-item-l"><div class="list-item-l-${i<2?['big','small'][i]:i%10}">${i<2?['Big','Small'][i]:i%10}</div></div><div class="list-item-m"><div class="list-item-m-top">20260908100010542<svg width="9" height="8"></svg></div><div class="list-item-m-bottom">2026-09-08 14:31:39</div></div><div class="list-item-r ${i%2?'success':''}"><div>${i%2?'success':'Lose'}</div><span>${i%2?'+₹196.00':'-₹100.00'}</span></div></div></div>`).join('');
const palettes=':root{--text_color_L1:#fff;--bg_color_L2:#252525;--norm_secondary-color:#ffb35c;--norm_bule-color:#6ba5ec;--norm_green-color:#13c164;--norm_red-color:#f0484b;--norm_Purple-color:#ad4dff}';
const markup=`<main class="history"><div class="my_r"><div class="my_r-body"><div class="list">${sample()}</div></div></div></main>`;
(async()=>{const b=await chromium.launch();const report=[];
for(const width of [320,360,390,411,430]){
 const measurements=[];
 for(const mode of ['reference','patched']){
  const p=await b.newPage({viewport:{width,height:850}});
  const basics=`html{font-size:${Math.min(width/10,40)}px}body{margin:0;background:#161616;font-family:${mode==='reference'?'Poppins':'Times New Roman'}}*{box-sizing:border-box}.history{margin:12px}${palettes}`;
  const localFaces=mode==='reference'?faces.replaceAll('13L History Poppins','Poppins'):faces;
  await p.setContent(`<html data-13l-history="1"><head><style>${basics}\n${reference}\n${localFaces}\n${mode==='patched'?css:''}</style></head><body>${markup}</body></html>`);
  await p.evaluate(()=>document.querySelectorAll('.my_r,.my_r *').forEach(e=>e.setAttribute('data-v-b6b03d74','')));
  await p.evaluate(async()=>{await document.fonts.load('400 14px "'+(document.body.style.fontFamily|| (getComputedStyle(document.querySelector('.list-item-m-top')).fontFamily.split(',')[0].replaceAll('"','')))+'"','Big ₹');await document.fonts.ready;});
  const m=await p.evaluate(()=>{
   const props=['fontFamily','fontSize','fontWeight','lineHeight','borderRadius'];const out={};
   for(const [key,sel] of Object.entries({chip:'.list-item-l',chipInner:'.list-item-l > div',period:'.list-item-m-top',time:'.list-item-m-bottom',badge:'.list-item-r > div',amount:'.list-item-r > span',row:'.list-item'})){
    const el=document.querySelector(sel),r=el.getBoundingClientRect(),cs=getComputedStyle(el);out[key]={width:r.width,height:r.height};for(const p of props)out[key][p]=cs[p];
   }
   out.rows=[...document.querySelectorAll('.list-item')].filter(e=>e.offsetHeight>0).length;
   out.maxRight=Math.max(...[...document.querySelectorAll('.list-item,.list-item-l,.list-item-m,.list-item-r')].map(e=>e.getBoundingClientRect().right));
   return out;
  });
  const client=await p.context().newCDPSession(p);await client.send('DOM.enable');await client.send('CSS.enable');const doc=await client.send('DOM.getDocument');
  const node=await client.send('DOM.querySelector',{nodeId:doc.root.nodeId,selector:'.list-item-m-top'});
  m.actualFonts=(await client.send('CSS.getPlatformFontsForNode',{nodeId:node.nodeId})).fonts;
  const amountNode=await client.send('DOM.querySelector',{nodeId:doc.root.nodeId,selector:'.list-item-r > span'});
  m.amountFonts=(await client.send('CSS.getPlatformFontsForNode',{nodeId:amountNode.nodeId})).fonts;
  if(mode==='patched')await p.screenshot({path:path.resolve('/home/user/inspection/css-match',`poppins31-${width}.png`),fullPage:true});
  measurements.push(m);await p.close();
 }
 const [ref,got]=measurements;
 for(const key of ['chip','period','time','badge','amount','row']){
  for(const prop of ['fontSize','fontWeight','lineHeight'])assert.equal(got[key][prop],ref[key][prop],`${width} ${key} ${prop}`);
 }
 assert.equal(got.chip.width,ref.chip.width);assert.equal(got.chip.height,ref.chip.height);assert.equal(got.chip.borderRadius,ref.chip.borderRadius);assert.equal(got.chipInner.borderRadius,got.chip.borderRadius);
 assert.equal(got.rows,10);assert(got.maxRight<=width);assert(got.actualFonts.some(f=>f.isCustomFont&&/poppins/i.test(f.familyName)),'Poppins must really render (not CSS-name-only)');assert(got.amountFonts.every(f=>/poppins/i.test(f.familyName)),'including rupee glyph');
 report.push({width,match:true,chip:got.chip,period:got.period,actualFonts:got.actualFonts,amountFonts:got.amountFonts,rows:got.rows});
}
await b.close();fs.writeFileSync(fixtures+'/appearance-results.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));})().catch(e=>{console.error(e);process.exit(1)});
