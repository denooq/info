import {test} from 'node:test';
import assert from 'node:assert/strict';
import {chromium,expect} from '@playwright/test';
import {graphServer} from './support/graph-server.mjs';

test('official document selector supports code/name search, keyboard and multiple compact attachments',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try{
    const page=await browser.newPage({viewport:{width:1366,height:900}});await page.goto(server.base);
    const search=page.getByRole('combobox',{name:'Вид документа',exact:true});await search.fill('04021');await page.getByRole('option').filter({hasText:'04021'}).waitFor();await search.press('Enter');
    await page.locator('.upload-group').waitFor();await expect(page.locator('.upload-group')).toHaveCount(1);
    await page.locator('input[type=file]').setInputFiles(['reference/source-documents/example2/авиа.pdf','reference/source-documents/смр.pdf']);await expect(page.locator('.file-row')).toHaveCount(2);
    const geometry=await page.locator('.file-row').first().evaluate(el=>({height:el.getBoundingClientRect().height,font:getComputedStyle(el.querySelector('.file-name')).fontSize}));assert.ok(geometry.height<65);assert.equal(geometry.font,'12px');
    await expect(page.getByRole('button',{name:'Распознать документы',exact:true})).toBeEnabled();
    await page.locator('input[type=file]').setInputFiles({name:'broken.pdf',mimeType:'application/pdf',buffer:Buffer.from('not a PDF')});await expect(page.getByRole('alert')).toBeVisible();await expect(page.locator('.file-row')).toHaveCount(2);
    await search.fill('CMR');await expect(page.getByText('Совпадений нет.',{exact:true})).toBeVisible();
    await search.fill('упаковочный');await page.getByRole('option').filter({hasText:'04131'}).click();await expect(page.locator('.upload-group')).toHaveCount(2);
    await expect(page.locator('.file-row')).toHaveCount(2);await expect(page.getByRole('button',{name:'Распознать документы',exact:true})).toBeEnabled();
    await page.getByRole('button',{name:'Удалить смр.pdf',exact:true}).click();await expect(page.locator('.file-row')).toHaveCount(1);
    await search.fill('');await page.getByRole('button',{name:/Показать ещё/}).waitFor();await page.getByRole('button',{name:/Показать ещё/}).click();await expect(page.getByRole('option')).toHaveCount(80);
  }finally{await browser.close();await server.stop();}
});

test('candidate buttons appear only for alternatives and an authentication failure retains edited inputs',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try{
    const page=await browser.newPage({viewport:{width:1440,height:960}});await page.goto(server.base);await page.getByRole('heading',{name:'Подготовьте декларацию ИМ 40',exact:true}).waitFor();
    async function fixture(count){await page.evaluate(async n=>{let s=await(await fetch('/api/session')).json();if(s.phase==='uploading')s=await(await fetch('/fixture/seed',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:'{}'})).json();await fetch('/fixture/candidates',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({count:n})});},count);await page.reload();await page.locator('#graph-35').waitFor();}
    await fixture(1);await expect(page.locator('#graph-35 .candidate')).toHaveCount(0);await expect(page.locator('#graph-35 input')).toHaveValue('27860');
    await fixture(2);await expect(page.locator('#graph-35 .candidate')).toHaveCount(2);const choice=page.getByRole('button',{name:'27860 кг',exact:true});await choice.hover();await expect(page.locator('.source-tooltip').filter({hasText:'Транспортная накладная'})).toBeVisible();await choice.click();await expect(page.locator('#graph-35 input')).toHaveValue('27860');
    const description=page.locator('[data-address="goods/goods-1/description"] input');await page.route('**/api/draft',route=>route.fulfill({status:401,json:{error:{code:'SESSION_REQUIRED',message:'Проверка cookie'}}}),{times:1});
    await description.fill('Несохранённый ввод при ошибке сессии');await description.blur();await expect(page.locator('.session-connection')).toBeVisible();await expect(description).toHaveValue('Несохранённый ввод при ошибке сессии');await expect(page.locator('#graph-35 input')).toHaveValue('27860');
  }finally{await browser.close();await server.stop();}
});

test('graph 44 exposes missing document fields and first blur does not erase the next input',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try{
    const page=await browser.newPage({viewport:{width:1440,height:960}});await page.goto(server.base);await page.getByRole('heading',{name:'Подготовьте декларацию ИМ 40',exact:true}).waitFor();
    await page.evaluate(async()=>{const s=await(await fetch('/api/session')).json();await fetch('/fixture/seed',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({emptyDocuments:true,recordType:'04031'})});});await page.reload();
    const record=page.locator('.document-record[data-document-kind="04031"]');await record.waitFor();const number=record.locator('[data-address$="/number"] input');const date=record.locator('[data-address$="/date"] input');await expect(number).toHaveValue('');
    await page.route('**/api/entities',async route=>{await new Promise(r=>setTimeout(r,200));await route.continue();},{times:1});
    await number.fill('FREIGHT-1');await date.focus();await date.fill('2026-10-28');await page.waitForTimeout(400);await expect(date).toHaveValue('2026-10-28');await date.blur();
    await expect(record.locator('[data-address$="/number"] input')).toHaveValue('FREIGHT-1');
    await expect.poll(async()=>page.evaluate(async()=>{const s=await(await fetch('/api/session')).json();return s.draft.documents.find(d=>d.kind==='04031')?.date;})).toBe('2026-10-28');
    const documents=await page.evaluate(async()=>(await(await fetch('/api/session')).json()).draft.documents);assert.equal(documents.filter(d=>d.kind==='04031').length,1);assert.equal(documents[0].number,'FREIGHT-1');
    await expect(page.locator('.document-record[data-document-kind="04021"]')).toHaveCount(1);await expect(page.locator('.document-record[data-document-kind="03011"]')).toHaveCount(1);
  }finally{await browser.close();await server.stop();}
});

test('54 compact graph cards retain 57 positions, strict review gate, five goods and a third calculation page',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try{
    const page=await browser.newPage({viewport:{width:1440,height:960}});const runtimeErrors=[];page.on('pageerror',e=>runtimeErrors.push(e.message));await page.goto(server.base);await page.getByRole('heading',{name:'Подготовьте декларацию ИМ 40',exact:true}).waitFor();
    const seeded=await page.evaluate(async()=>{const s=await(await fetch('/api/session')).json();return(await fetch('/fixture/seed',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({goodsCount:5})})).json();});assert.equal(seeded.reviewValidation.valid,true,JSON.stringify(seeded.reviewValidation));
    await page.reload();await page.getByRole('heading',{name:'Проверьте данные декларации',exact:true}).waitFor();
    await expect(page.locator('.graphs-grid > .graph-card')).toHaveCount(54);await expect(page.locator('[data-graph-id]')).toHaveCount(57);assert.equal(await page.locator('nav span').count(),3);
    const columns=await page.locator('.graphs-grid').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length);assert.equal(columns,2);
    await page.getByRole('combobox',{name:'Товар для граф 31–47'}).selectOption('goods-2');const mass=page.locator('[data-address="goods/goods-2/grossMass"] input');await mass.fill('28100');await mass.blur();await expect(mass).toHaveValue('28100');
    await page.getByRole('combobox',{name:'Товар для граф 31–47'}).selectOption('goods-1');await expect(page.locator('[data-address="goods/goods-1/grossMass"] input')).toHaveValue('27860');
    const consignee=page.locator('[data-address="party/party-consignee/name"] input');await consignee.fill('');await consignee.blur();await expect(page.getByRole('button',{name:'К расчёту',exact:true})).toBeDisabled();
    let blocked=await page.evaluate(async()=>{const s=await(await fetch('/api/session')).json();const r=await fetch('/api/calculate',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({revision:s.dataRevision})});return{status:r.status,json:await r.json()};});assert.equal(blocked.status,422);assert.equal(blocked.json.error.code,'DECLARATION_INVALID');
    await consignee.fill('Проверочный получатель');await consignee.blur();await expect(page.getByRole('button',{name:'К расчёту',exact:true})).toBeEnabled();await page.getByRole('button',{name:'К расчёту',exact:true}).click();await page.getByRole('heading',{name:'Расчёт декларации',exact:true}).waitFor();
    await expect(page.getByRole('button',{name:'Создать',exact:true})).toHaveCount(0);await page.getByRole('button',{name:'Рассчитать',exact:true}).click();await page.getByRole('heading',{name:'Итоги',exact:true}).waitFor();
    const calculatedValue=page.getByRole('textbox',{name:'Таможенная стоимость, KZT',exact:true}).first();const formulaValue=await calculatedValue.inputValue();await calculatedValue.fill('1');await calculatedValue.blur();await expect(page.getByText('Ручные итоги расходятся с формулой.',{exact:false})).toBeVisible();await page.getByRole('button',{name:'Пересчитать',exact:true}).click();await expect(calculatedValue).toHaveValue(formulaValue);
    await page.getByRole('button',{name:'К проверке данных',exact:true}).click();await expect(page.locator('#graph-12')).not.toContainText('Будет рассчитано');
    await expect(page.locator('#graph-47 .graph-payments tbody tr')).toHaveCount(3);
    await page.setViewportSize({width:390,height:844});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    assert.deepEqual(runtimeErrors,[]);
  }finally{await browser.close();await server.stop();}
});
