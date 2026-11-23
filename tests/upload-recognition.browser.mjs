import {test} from 'node:test';
import assert from 'node:assert/strict';
import {chromium,expect} from '@playwright/test';
import {graphServer} from './support/graph-server.mjs';

async function attach(page){
  const search=page.getByRole('combobox',{name:'Вид документа',exact:true});
  await search.fill('04021');await page.getByRole('option').filter({hasText:'04021'}).click();
  await expect(page.getByRole('button',{name:'Распознать документы',exact:true})).toBeDisabled();
  const chooser=page.waitForEvent('filechooser');await page.getByRole('button',{name:'Прикрепить файлы',exact:true}).click();
  await(await chooser).setFiles(['reference/source-documents/example2/авиа.pdf','reference/source-documents/смр.pdf']);
  await expect(page.locator('.file-row')).toHaveCount(2);await expect(page.getByRole('button',{name:'Распознать документы',exact:true})).toBeEnabled();
}
async function stage(page,stage){
  await page.evaluate(async stage=>{const s=await(await fetch('/api/session')).json();const r=await fetch('/fixture/recognition',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({stage})});if(!r.ok)throw new Error(await r.text());},stage);
}
async function poll(page){await page.clock.runFor(2600);}

test('manual batch shows file progress, survives refresh, waits for mapping and navigates even for an immediate cache hit',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try {
    const page=await browser.newPage();let starts=0;page.on('request',r=>{if(r.url().endsWith('/api/recognize'))starts++;});
    await page.goto(server.base);await attach(page);assert.equal(starts,0);await page.clock.install();
    await page.getByRole('button',{name:'Распознать документы',exact:true}).click();await expect(page.locator('.notice.progress')).toContainText('0 из 2');assert.equal(starts,1);
    await expect(page.getByRole('button',{name:'Прикрепить файлы',exact:true})).toBeDisabled();await expect(page.getByRole('button',{name:'Распознавание…',exact:true})).toBeDisabled();
    await stage(page,'partial');await poll(page);await expect(page.locator('.notice.progress')).toContainText('1 из 2');
    await page.reload();await expect(page.locator('.notice.progress')).toContainText('1 из 2');assert.equal(starts,1);
    await stage(page,'mapping');await poll(page);await expect(page.locator('.notice.progress')).toContainText('2 из 2');await expect(page.locator('.notice.progress')).toContainText('Заполняем графы декларации');
    await expect(page.getByRole('heading',{name:'Проверьте данные декларации',exact:true})).toHaveCount(0);
    const expiry=await page.evaluate(async()=>(await(await fetch('/api/session')).json()).expiresAt);
    await stage(page,'success');await poll(page);await page.getByRole('heading',{name:'Проверьте данные декларации',exact:true}).waitFor();await expect(page.locator('.graphs-grid > .graph-card')).toHaveCount(54);
    assert.equal(await page.evaluate(async()=>(await(await fetch('/api/session')).json()).expiresAt),expiry);
    await page.getByRole('button',{name:'К документам',exact:true}).click();await expect(page.getByRole('heading',{name:'Подготовьте декларацию ИМ 40',exact:true})).toBeVisible();assert.equal(starts,1);
    await page.getByRole('button',{name:'Распознать документы',exact:true}).click();await page.getByRole('heading',{name:'Проверьте данные декларации',exact:true}).waitFor();assert.equal(starts,2);
    const current=await page.evaluate(async()=>(await(await fetch('/api/session')).json()));assert.ok(current.jobs.every(j=>!['queued','running'].includes(j.status)));
  } finally {await browser.close();await server.stop();}
});

for(const outcome of ['partial-error','all-error','mapping-error'])test(`terminal ${outcome} opens manual review with diagnostics and retains the calculation gate`,async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try {
    const page=await browser.newPage();await page.goto(server.base);await attach(page);await page.clock.install();
    await page.getByRole('button',{name:'Распознать документы',exact:true}).click();await expect(page.locator('.notice.progress')).toBeVisible();
    if(outcome==='partial-error') {
      await stage(page,'mapping-partial');await poll(page);await expect(page.locator('.notice.progress')).toContainText('1 из 2. Ошибок: 1.');await expect(page.locator('.notice.progress')).toContainText('Заполняем графы декларации');
    }
    await stage(page,outcome);await poll(page);await page.getByRole('heading',{name:'Проверьте данные декларации',exact:true}).waitFor();
    await expect(page.getByText('Проверочная конечная ошибка обработки.',{exact:true})).toBeVisible();await expect(page.getByRole('button',{name:'К расчёту',exact:true})).toBeDisabled();
    const field=page.locator('[data-address="graph/8/_"] textarea');await field.fill('Ручной получатель после ошибки');await field.blur();await expect(field).toHaveValue('Ручной получатель после ошибки');
    await expect(page.locator('.notice.progress')).toHaveCount(0);
  } finally {await browser.close();await server.stop();}
});

test('connection recovery preserves the batch and a late poll cannot replace its completed result',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});let release;
  try {
    const page=await browser.newPage();let starts=0;page.on('request',r=>{if(r.url().endsWith('/api/recognize'))starts++;});
    await page.goto(server.base);await attach(page);await page.clock.install();await page.getByRole('button',{name:'Распознать документы',exact:true}).click();await expect(page.locator('.notice.progress')).toBeVisible();
    await page.route('**/api/session',r=>r.abort('failed'),{times:1});await poll(page);await expect(page.locator('.session-connection')).toBeVisible();await expect(page.locator('.file-row')).toHaveCount(2);assert.equal(starts,1);
    const held=new Promise(r=>{release=r;});let captured;const capturedResponse=new Promise(r=>{captured=r;});
    await page.route('**/api/session',async route=>{const response=await route.fetch();const old=await response.json();captured();await held;try{await route.fulfill({json:old});}catch{/* The completed batch aborts this old polling request. */}},{times:1});
    await poll(page);await capturedResponse;await stage(page,'success');await page.getByRole('button',{name:'Проверить сессию',exact:true}).click();await page.getByRole('heading',{name:'Проверьте данные декларации',exact:true}).waitFor();
    release();await page.clock.runFor(3000);await expect(page.getByRole('heading',{name:'Проверьте данные декларации',exact:true})).toBeVisible();await expect(page.locator('.session-connection')).toHaveCount(0);assert.equal(starts,1);
  } finally {release?.();await browser.close();await server.stop();}
});
