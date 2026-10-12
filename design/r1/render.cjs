const {chromium}=require('/tmp/zmtools/node_modules/playwright');const fs=require('fs');
(async()=>{const b=await chromium.launch({executablePath:'/home/box/.cache/ms-playwright/chromium-1243/chrome-linux64/chrome'});const files=fs.readdirSync('.').filter(f=>/^(dashboard|project).*\.html$/.test(f));
for(const f of files)for(const w of [1440,390]){const p=await b.newPage({viewport:{width:w,height:900},deviceScaleFactor:w==390?2:1});
await p.goto('file://'+process.cwd()+'/'+f);await p.evaluate(()=>document.fonts.ready);
const ok=await p.evaluate(()=>document.fonts.check('16px "Atkinson Hyperlegible"'));
const ovf=await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
const n=f.replace('.html','')+'-'+w;await p.screenshot({path:'png/'+n+'.png',fullPage:true});await p.screenshot({path:'share/'+n+'.jpg',fullPage:true,type:'jpeg',quality:88});
console.log(n,'font',ok,'hOverflow',ovf);await p.close();}await b.close();})();
