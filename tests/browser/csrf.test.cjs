'use strict';
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root,'writable/widget-test-tools/node_modules/playwright'));
let server, browser, origin;
before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(),'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(cache).filter(n=>/^chromium-\d+$/.test(n)).sort((a,b)=>Number(b.split('-')[1])-Number(a.split('-')[1]))
      .map(n=>path.join(cache,n,'chrome-win64/chrome.exe')).find(p=>fs.existsSync(p));
  }
  server = http.createServer((req,res) => {
    if(req.url === '/csrf.js') { res.writeHead(200,{'Content-Type':'text/javascript'}).end(fs.readFileSync(path.join(root,'public/assets/js/csrf.js'))); return; }
    res.writeHead(200,{'Content-Type':'text/html'}).end(`<!doctype html><meta name="csrf-name" content="csrf_test_name"><meta name="csrf-token" content="initial"><meta name="csrf-header" content="X-CSRF-TOKEN">
      <form id="ordinary" method="POST" action="/write"><input type="hidden" name="csrf_test_name" value="initial"><input name="value" value="kept"><button>Save</button></form>
      <form id="upload" method="POST" action="/upload"><input type="hidden" name="csrf_test_name" value="initial"><input type="file" name="photo"></form>
      <form id="search" method="GET" action="/search"><input name="q" value="query"></form><script src="/csrf.js"></script>`);
  });
  server.listen(0,'127.0.0.1'); await once(server,'listening'); origin='http://127.0.0.1:'+server.address().port;
  browser = await chromium.launch({headless:true,executablePath});
}, {timeout:30000});
after(async()=>{ if(browser)await browser.close(); if(server){server.closeAllConnections();await new Promise(r=>server.close(r));} });

test('rotated HTMX token reaches every hidden field and subsequent ordinary/upload FormData',async()=>{
  const page=await browser.newPage();
  try {
    await page.goto(origin);
    const data=await page.evaluate(()=>{
      const xhr={getResponseHeader:n=>({'X-CSRF-TOKEN':'rotated','X-CSRF-NAME':'csrf_test_name','X-CSRF-HEADER':'X-CSRF-TOKEN'}[n]||null)};
      document.dispatchEvent(new CustomEvent('htmx:afterRequest',{detail:{xhr}}));
      // Even a stale dynamically inserted field must be current when submitted.
      document.querySelector('#ordinary input[type=hidden]').value='stale';
      const ordinary=Object.fromEntries(new FormData(document.querySelector('#ordinary')));
      const upload=new FormData(document.querySelector('#upload')).get('csrf_test_name');
      const get=Object.fromEntries(new FormData(document.querySelector('#search')));
      const detail={headers:{}};
      document.dispatchEvent(new CustomEvent('htmx:configRequest',{detail}));
      return{ordinary,upload,get,headers:detail.headers,meta:document.querySelector('meta[name=csrf-token]').content};
    });
    assert.deepEqual(data,{ordinary:{csrf_test_name:'rotated',value:'kept'},upload:'rotated',get:{q:'query'},headers:{'X-CSRF-TOKEN':'rotated'},meta:'rotated'});
  } finally {await page.close();}
});
test('modal fetch/XHR response tokens and error payload sync share one owner without duplicate registration',async()=>{
  const page=await browser.newPage();
  try {
    await page.goto(origin);
    const data=await page.evaluate(async()=>{
      const owner=window.SynapseCsrf;
      const script=document.createElement('script');script.src='/csrf.js';document.body.append(script);await new Promise(r=>script.onload=r);
      SynapseCsrf.fromResponse(new Response('',{headers:{'X-CSRF-TOKEN':'fetch-token','X-CSRF-NAME':'csrf_test_name'}}));
      const fetched=new FormData(document.querySelector('#ordinary')).get('csrf_test_name');
      SynapseCsrf.fromResponse({getResponseHeader:n=>n==='X-CSRF-TOKEN'?'xhr-token':null});
      const xhr=new FormData(document.querySelector('#upload')).get('csrf_test_name');
      SynapseCsrf.sync({name:'csrf_test_name',hash:'failed-validation-token'});
      const retry=new FormData(document.querySelector('#ordinary')).get('csrf_test_name');
      SynapseCsrf.sync({name:null,hash:'malformed'});
      return{same:owner===window.SynapseCsrf,fetched,xhr,retry,headers:SynapseCsrf.headers(),storage:localStorage.length};
    });
    assert.deepEqual(data,{same:true,fetched:'fetch-token',xhr:'xhr-token',retry:'failed-validation-token',headers:{'X-CSRF-TOKEN':'failed-validation-token'},storage:0});
  } finally {await page.close();}
});
