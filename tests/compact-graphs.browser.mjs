import {test} from 'node:test';
import assert from 'node:assert/strict';
import {chromium,expect} from '@playwright/test';
import {graphServer} from './support/graph-server.mjs';

async function seed(page,count=2){await page.goto('/');await page.getByRole('heading',{name:'Подготовьте декларацию ИМ 40',exact:true}).waitFor();await page.evaluate(async n=>{const s=await(await fetch('/api/session')).json();const response=await fetch('/fixture/seed',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({goodsCount:n})});if(!response.ok)throw Error(await response.text());},count);await page.reload();await page.locator('#graph-2').waitFor();}

test('empty and unmatched goods and legacy source metadata do not crash review',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try {
    const page=await browser.newPage({baseURL:server.base,viewport:{width:1440,height:960}});const errors=[];page.on('pageerror',error=>errors.push(error.message));await seed(page,1);
    await page.getByRole('button',{name:'Добавить товар',exact:true}).click();
    const selector=page.getByRole('combobox',{name:'Товар для граф 31–47'});await expect(selector.locator('option')).toHaveCount(2);
    const id=await selector.locator('option').last().getAttribute('value');await selector.selectOption(id);
    const code=page.locator(`[data-address="goods/${id}/commodityCode"] input`);await expect(code).toHaveValue('');await expect(page.locator('#graph-33')).toContainText('Укажите описание товара');
    const description=page.locator('#graph-31 textarea');await description.fill('неттакоготовара xyz123');await description.blur();
    await expect(page.locator('#graph-33')).toContainText('Код по этому наименованию не найден');await expect(page.locator('.graphs-grid > .graph-card:not(.graph-child-card)')).toHaveCount(54);
    await page.reload();await selector.selectOption(id);await expect(code).toHaveValue('');await expect(description).toHaveValue('неттакоготовара xyz123');
    // Older sessions can carry TN VED lookup information without a list of citations.
    for(const evidenceIds of [undefined,null]) {
      await page.route('**/api/session',async route=>{const response=await route.fetch();const s=await response.json();s.capabilities.nbkRates=false;for(const meta of Object.values(s.draft.fieldStates))meta.evidenceIds=evidenceIds;await route.fulfill({response,json:s});},{times:1});
      await page.reload();await selector.selectOption(id);await expect(code).toHaveValue('');await expect(description).toHaveValue('неттакоготовара xyz123');
    }
    assert.deepEqual(errors,[]);
  }finally{await browser.close();await server.stop();}
});

test('every graph uses one column and content-sized frames at desktop mobile and enlarged zoom',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try{
    const page=await browser.newPage({baseURL:server.base,viewport:{width:1440,height:960}});await seed(page);
    async function geometry(){const result=await page.locator('.graphs-grid').evaluate(el=>({columns:getComputedStyle(el).gridTemplateColumns.split(' ').length,boxes:[...el.querySelectorAll(':scope > .graph-card')].map(e=>{const r=e.getBoundingClientRect();return [r.x,r.y,r.width,r.height];})}));assert.equal(result.columns,1);assert.equal(result.boxes.length,56);assert.ok(Math.max(...result.boxes.map(b=>b[2]))-Math.min(...result.boxes.map(b=>b[2]))<1);for(let i=1;i<result.boxes.length;i++)assert.ok(result.boxes[i][1]>=result.boxes[i-1][1]+result.boxes[i-1][3]-1);}
    await geometry();await expect(page.locator('#graph-1 > legend')).toHaveText('Графа 1');
    await page.evaluate(async()=>{const s=await(await fetch('/api/session')).json();await fetch('/fixture/candidates',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({count:1})});});await page.reload();
    await expect(page.locator('#graph-35 .candidate')).toHaveCount(0);await expect(page.locator('#graph-35 input')).toHaveValue('27860');
    await page.evaluate(async()=>{const s=await(await fetch('/api/session')).json();await fetch('/fixture/candidates',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({count:2})});});await page.reload();
    const choice=page.getByRole('button',{name:'27860 кг',exact:true});await choice.focus();await expect(page.locator('.floating-source')).toBeVisible();
    const popup=await page.locator('.floating-source').boundingBox();assert.ok(popup.x>=0&&popup.x+popup.width<=1440);await choice.click();await expect(page.locator('#graph-35 input')).toHaveValue('27860');await geometry();
    await page.setViewportSize({width:1366,height:900});await geometry();
    await page.locator('#graph-2').evaluate(el=>{const title=el.querySelector('h2');title.textContent='Очень длинное название графы '.repeat(15);const content=el.querySelector('.graph-editor-scroll');const p=document.createElement('p');p.textContent='Длинный текст и ошибка '.repeat(100);content.append(p);});await geometry();
    await page.setViewportSize({width:390,height:844});await geometry();assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    await page.evaluate(()=>document.documentElement.style.zoom='2');await geometry();assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    await page.keyboard.press('Tab');assert.ok(await page.evaluate(()=>document.activeElement!==document.body));
  }finally{await browser.close();await server.stop();}
});

test('compact review uses one descriptive editor and controlled operands with a complete mandatory gate',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try{
    const page=await browser.newPage({baseURL:server.base,viewport:{width:1440,height:960}});await seed(page,5);
    await expect(page.locator('.graphs-grid > .graph-card:not(.graph-child-card)')).toHaveCount(54);await expect(page.locator('.graphs-grid > .graph-child-card')).toHaveCount(2);await expect(page.locator('[data-graph-id]')).toHaveCount(57);
    await expect(page.locator('#graph-2 textarea')).toHaveCount(1);await expect(page.locator('#graph-2 input, #graph-2 select')).toHaveCount(0);
    await expect(page.locator('#graph-1 input')).toHaveAttribute('readonly','');await expect(page.locator('#graph-1 input')).toHaveValue(/ИМ.*40/);
    await expect(page.locator('#graph-22 [data-address="declaration/_/invoiceCurrency"] input')).toBeVisible();
    await expect(page.locator('#graph-23 input[aria-label="Курс USD к KZT"]')).toBeVisible();await expect(page.locator('#graph-23').getByText('Кратность',{exact:true})).toHaveCount(0);
    await expect(page.locator('#graph-54 [data-address="declaration/_/signatureDate"] input')).toBeVisible();await expect(page.locator('#graph-54 textarea')).toHaveCount(1);
    const sender=page.locator('#graph-2 textarea');await sender.fill('Ручной отправитель и полный адрес');await sender.blur();await page.reload();await expect(page.locator('#graph-2 textarea')).toHaveValue('Ручной отправитель и полный адрес');
    await page.getByRole('combobox',{name:'Товар для граф 31–47'}).selectOption('goods-2');const net=page.locator('#graph-38 input');await net.fill('24000');await net.blur();await expect(net).toHaveValue('24000');
    await page.getByRole('combobox',{name:'Товар для граф 31–47'}).selectOption('goods-1');await expect(page.locator('#graph-38 input')).toHaveValue('24480');
    const code=page.locator('[data-address="goods/goods-1/commodityCode"] input');await code.fill('3702319100');await code.blur();
    await expect(page.locator('#graph-41 [data-address$="/additionalQuantity"] input')).toBeVisible();await expect(page.locator('#graph-41 [data-address$="/secondaryAdditionalQuantity"] input')).toBeVisible();
    await expect(page.locator('#graph-41 [data-address$="/secondaryAdditionalUnit"] input')).toHaveValue('006');
    await code.fill('2915310000');await code.blur();await expect(page.locator('#graph-41 [data-address$="/additionalQuantity"] input')).toHaveCount(0);
    const consignee=page.locator('#graph-8 textarea');await consignee.fill('');await consignee.blur();await expect(page.getByRole('button',{name:'К расчёту',exact:true})).toBeDisabled();
    const blocked=await page.evaluate(async()=>{const s=await(await fetch('/api/session')).json();return(await fetch('/api/calculate',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({revision:s.dataRevision})})).status;});assert.equal(blocked,422);
    await consignee.fill('Ручной получатель и адрес');await consignee.blur();await expect(page.getByRole('button',{name:'К расчёту',exact:true})).toBeEnabled();
    await page.getByRole('button',{name:'К расчёту',exact:true}).click();await page.getByRole('heading',{name:'Расчёт декларации',exact:true}).waitFor();
  }finally{await browser.close();await server.stop();}
});

test('calculation shows sourced statutory values without manual rate fields and rejects forged rates',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try{
    const page=await browser.newPage({baseURL:server.base,viewport:{width:1440,height:960}});await seed(page,2);
    await page.getByRole('button',{name:'К расчёту',exact:true}).click();await page.getByRole('heading',{name:'Расчёт декларации',exact:true}).waitFor();
    await expect(page.locator('.statutory-summary')).toContainText('25950.00 KZT');await expect(page.locator('.statutory-vat').first()).toContainText('16%');
    await expect(page.locator('[data-address$="/vatRate"], [data-address$="/feeBase"], [data-address$="/feeAdditional"], [data-address$="/dutyRate"], [data-address$="/dutyFormula"]')).toHaveCount(0);
    await page.getByRole('button',{name:'Источники и период действия',exact:true}).focus();await expect(page.getByRole('link',{name:'Ставка НДС',exact:true})).toHaveAttribute('href',/gov\.kz/);
    const forged=await page.evaluate(async()=>{const s=await(await fetch('/api/session')).json();const response=await fetch('/api/draft',{method:'PATCH',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({revision:s.dataRevision,changes:[{fieldId:'goods.vatRate',entityId:'goods-1',value:'1'}]})});return{status:response.status,json:await response.json()};});assert.ok(forged.status>=400);assert.equal(forged.json.error.code,'FIELD_READ_ONLY');
    await page.getByRole('button',{name:'Рассчитать',exact:true}).click();await page.getByRole('heading',{name:'Итоги',exact:true}).waitFor();
    const result=await page.evaluate(async()=>(await(await fetch('/api/session')).json()).calculation);assert.equal(result.totals.fee,'25950.00');assert.deepEqual(result.graphB,[]);assert.ok(result.ruleHash);
    await expect(page.locator('#section-cost button').first()).toBeEnabled();await expect(page.locator('#section-rate button').first()).toBeEnabled();
  }finally{await browser.close();await server.stop();}
});

test('review calculation and return keep one revision and reject an invalid new price',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try{
    const page=await browser.newPage({baseURL:server.base,viewport:{width:1440,height:960}});await seed(page,2);
    const sender=page.locator('#graph-2 textarea');await sender.fill('Сохранённый полный отправитель');await sender.blur();await page.getByRole('button',{name:'К расчёту',exact:true}).click();
    await page.getByRole('button',{name:'Рассчитать',exact:true}).click();await page.getByRole('heading',{name:'Итоги',exact:true}).waitFor();
    const before=await page.evaluate(async()=>(await(await fetch('/api/session')).json()).calculation.hash);
    await page.getByRole('button',{name:'К проверке данных',exact:true}).click();await expect(page.locator('#graph-2 textarea')).toHaveValue('Сохранённый полный отправитель');
    const price=page.locator('#graph-42 input');await price.fill('100');await price.blur();await expect(page.locator('#graph-12 input')).toHaveValue('Будет рассчитано на следующем шаге');
    await page.getByRole('button',{name:'К расчёту',exact:true}).click();await page.getByRole('button',{name:'Рассчитать',exact:true}).click();await page.getByRole('heading',{name:'Итоги',exact:true}).waitFor();
    const after=await page.evaluate(async()=>(await(await fetch('/api/session')).json()).calculation);assert.notEqual(after.hash,before);assert.equal(after.items[0].nationalInvoiceValue,'45563.00');assert.equal(after.totals.fee,'25950.00');assert.ok(after.ruleHash);
    await page.getByRole('button',{name:'К проверке данных',exact:true}).click();await price.fill('не число');await price.blur();await expect(page.getByRole('button',{name:'К расчёту',exact:true})).toBeDisabled();await expect(price).toHaveValue('не число');
    const status=await page.evaluate(async()=>{const s=await(await fetch('/api/session')).json();return(await fetch('/api/calculate',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({revision:s.dataRevision})})).status;});assert.equal(status,422);
    await price.fill('123');await price.blur();await expect(page.getByRole('button',{name:'К расчёту',exact:true})).toBeEnabled();
  }finally{await browser.close();await server.stop();}
});

test('failed and delayed saves preserve neighbouring text and unsaved goods input',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try{
    const page=await browser.newPage({baseURL:server.base,viewport:{width:1440,height:960}});await seed(page,2);
    await page.route('**/api/draft',route=>route.fulfill({status:401,json:{error:{code:'SESSION_REQUIRED',message:'Проверка текущей сессии'}}}),{times:1});
    const description=page.locator('#graph-31 textarea');await description.fill('Несохранённое описание товара');await description.blur();await expect(page.locator('.session-connection')).toBeVisible();
    await page.getByRole('combobox',{name:'Товар для граф 31–47'}).selectOption('goods-2');await page.getByRole('combobox',{name:'Товар для граф 31–47'}).selectOption('goods-1');await expect(description).toHaveValue('Несохранённое описание товара');
    await page.route('**/api/draft',async route=>{await new Promise(resolve=>setTimeout(resolve,250));await route.continue();},{times:1});
    const sender=page.locator('#graph-2 textarea'),consignee=page.locator('#graph-8 textarea');await sender.fill('Отправитель после очереди');await consignee.focus();await consignee.fill('Соседний несохранённый получатель');
    await expect.poll(()=>page.evaluate(async()=>(await(await fetch('/api/session')).json()).draft.graphTexts?.['graph/2/_']?.value)).toBe('Отправитель после очереди');
    await expect(consignee).toHaveValue('Соседний несохранённый получатель');await consignee.blur();
    await expect.poll(()=>page.evaluate(async()=>(await(await fetch('/api/session')).json()).draft.graphTexts?.['graph/8/_']?.value)).toBe('Соседний несохранённый получатель');
  }finally{await browser.close();await server.stop();}
});
