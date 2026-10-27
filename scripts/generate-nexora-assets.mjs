import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
const dir='public/images/brand';
const mark=(color)=>`<path fill="${color}" d="M10 30Q10 26 14 23L28 13Q32 10 36 14L87 59Q90 62 90 66V82Q90 86 86 88L78 93L28 49V67L13 54Q10 51 10 47Z"/><path fill="#FB8500" d="M58 24L87 6Q90 4 90 8V47ZM10 66L42 91L14 109Q10 111 10 107Z"/>`;
const svg=(body,box)=>`<svg xmlns="http://www.w3.org/2000/svg" viewBox="${box}" role="img" aria-label="Nexora">${body}</svg>`;
for(const [variant,color] of [['light','#023047'],['dark','#FFFFFF']]){
 fs.writeFileSync(`${dir}/nexora-icon-${variant}.svg`,svg(mark(color),'0 0 100 116'));
 fs.writeFileSync(`${dir}/nexora-logo-${variant}.svg`,svg(`<g transform="translate(4 0)">${mark(color)}</g><text x="119" y="87" fill="${color}" font-family="Arial,Helvetica,sans-serif" font-size="87" font-weight="700" letter-spacing="-4">Nexora</text>`,'0 0 420 116'));
}
fs.writeFileSync(`${dir}/nexora-app.svg`,svg(`<rect width="128" height="128" rx="20" fill="#FFFFFF"/><g transform="translate(20 13) scale(.88)">${mark('#023047')}</g>`,'0 0 128 128'));
const browser=await chromium.launch();
const page=await browser.newPage({deviceScaleFactor:2});
for(const variant of ['light','dark']){
 for(const kind of ['logo','icon']){
  const width=kind==='logo'?840:200; const height=232;
  await page.setViewportSize({width,height});
  const src=fs.readFileSync(`${dir}/nexora-${kind}-${variant}.svg`,'utf8');
  await page.setContent(`<style>html,body{margin:0;background:transparent}svg{display:block;width:100%;height:100vh}</style>${src}`);
  await page.screenshot({path:`${dir}/nexora-${kind}-${variant}.png`,omitBackground:true});
 }
}
for(const size of [16,32,48,64,180,192,512]){
 await page.setViewportSize({width:size,height:size});
 const src=fs.readFileSync(`${dir}/nexora-app.svg`,'utf8');
 await page.setContent(`<style>html,body{margin:0;background:transparent}svg{width:100vw;height:100vh;display:block}</style>${src}`);
 await page.screenshot({path:`${dir}/nexora-app-${size}.png`,scale:'css',omitBackground:true});
}
fs.writeFileSync(`${dir}/nexora-browser.svg`,svg(`<rect width="128" height="128" rx="20" fill="#023047"/><g transform="translate(20 13) scale(.88)">${mark('#FFFFFF')}</g>`,'0 0 128 128'));
for(const size of [16,32,48,64,512]){
 await page.setViewportSize({width:size,height:size});
 const src=fs.readFileSync(`${dir}/nexora-browser.svg`,'utf8');
 await page.setContent(`<style>html,body{margin:0;background:transparent}svg{width:100vw;height:100vh;display:block}</style>${src}`);
 await page.screenshot({path:`${dir}/nexora-browser-${size}.png`,scale:'css',omitBackground:true});
}
const png=fs.readFileSync(`${dir}/nexora-browser-32.png`); const header=Buffer.alloc(22);header.writeUInt16LE(1,2);header.writeUInt16LE(1,4);header[6]=32;header[7]=32;header.writeUInt16LE(1,10);header.writeUInt16LE(32,12);header.writeUInt32LE(png.length,14);header.writeUInt32LE(22,18);fs.writeFileSync('public/favicon.ico',Buffer.concat([header,png]));
await browser.close();