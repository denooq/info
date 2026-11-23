import {test} from 'node:test';
import assert from 'node:assert/strict';
import {chromium,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {readFile} from 'node:fs/promises';
import {resolve,sep} from 'node:path';

const backend=input=>JSON.parse(execFileSync('.local-tools/php/php.exe',['tests/support/nbk-state.php'],{input:JSON.stringify(input),encoding:'utf8'}));
test('review auto-loads NBK rates, preserves nominal and manual edits, reloads for a changed date and handles bank failure',async()=>{
  let state=backend({});let requests=0;let failNext=false;let extraction=0;
  const fields=JSON.parse(await readFile('resources/registry/fields.v1.json','utf8'));const catalogs=JSON.parse(await readFile('resources/registry/catalogs.v1.json','utf8'));
  const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try {
    const page=await browser.newPage({viewport:{width:1440,height:960}});
    await page.route('http://exchange-rates.test/**',async route=>{
      const pathname=new URL(route.request().url()).pathname;
      if(pathname==='/api/session')return route.fulfill({json:state});
      if(pathname==='/api/registry')return route.fulfill({json:{fields,catalogs}});
      if(pathname==='/api/rates') {
        const body=route.request().postDataJSON();assert.equal(body.revision,state.dataRevision);requests++;
        state=backend({state,bank:failNext?'error':'success',force:body.force});failNext=false;return route.fulfill({json:state});
      }
      if(pathname==='/api/draft'){const body=route.request().postDataJSON();assert.equal(body.revision,state.dataRevision);state=backend({state,changes:body.changes});return route.fulfill({json:state});}
      if(pathname==='/api/calculate'){state=backend({state,calculate:true});return route.fulfill({json:state});}
      if(pathname==='/api/recognize' || pathname==='/api/documents'){extraction++;return route.fulfill({status:500,json:{error:{message:'Unexpected extraction'}}});}
      const root=resolve('public/assets-app');const path=pathname==='/'?resolve(root,'index.html'):resolve(root,'.'+pathname.replace(/^\/assets-app/,''));assert.ok(path.startsWith(root+sep));
      return route.fulfill({contentType:path.endsWith('.js')?'application/javascript':path.endsWith('.css')?'text/css':'text/html',body:await readFile(path)});
    });
    await page.goto('http://exchange-rates.test/');const panel=page.getByRole('complementary',{name:'Курсы Национального Банка'});
    await expect(panel).toContainText('28.10.2026');await expect(page.locator('#section-rate .entity-block')).toHaveCount(2);
    const field=(id,key)=>page.locator(`[data-address="rate/${id}/${key}"]`);
    let won=state.draft.rates.find(r=>r.currency==='KRW');let usd=state.draft.rates.find(r=>r.currency==='USD');
    await expect(field(won.id,'value').locator('input')).toHaveValue('34.32');await expect(field(won.id,'nominal').locator('input')).toHaveValue('100');
    await expect(field(won.id,'value')).toContainText('Получено из НБК');assert.equal(requests,1);
    const signature=page.locator('[data-address="declaration/_/signatureDate"] input');
    await signature.fill('2026-10-29');await page.getByRole('heading',{name:'Проверьте данные декларации',exact:true}).click();
    await expect(field(usd.id,'value').locator('input')).toHaveValue('460');assert.equal(requests,2);
    await expect(page.locator('[data-address="declaration/_/calculationDate"] input')).toHaveValue('2026-10-29');
    await expect(panel.getByRole('link')).toHaveAttribute('href',/fdate=29\.10\.2026$/);
    await field(usd.id,'value').locator('input').fill('461');await field(usd.id,'value').locator('input').press('Tab');
    await expect(field(usd.id,'confirmed').locator('select')).toHaveValue('no');assert.equal(requests,2);
    await field(usd.id,'source').locator('input').fill('Подтверждённая ручная корректировка');await field(usd.id,'source').locator('input').press('Tab');
    await expect(field(usd.id,'source').locator('input')).toBeEnabled();await field(usd.id,'confirmed').locator('select').selectOption('yes');
    failNext=true;await panel.getByRole('button',{name:'Загрузить курсы НБК',exact:true}).click();
    await expect(panel.getByRole('alert')).toContainText('временно недоступен');await expect(field(usd.id,'value').locator('input')).toHaveValue('461');
    await panel.getByRole('button',{name:'Загрузить курсы НБК',exact:true}).click();
    await expect(field(usd.id,'value').locator('input')).toHaveValue('460');assert.equal(requests,4);
    const calculate=page.getByRole('button',{name:'Рассчитать',exact:true});await expect(calculate).toBeEnabled();await calculate.click();
    await expect(page.getByRole('heading',{name:'Проверьте расчёт',exact:true})).toBeVisible();
    await expect(panel).toBeVisible();await page.getByRole('button',{name:'Изменить курсы и ставки',exact:true}).click();
    await signature.fill('2026-10-28');await page.getByRole('heading',{name:'Проверьте расчёт',exact:true}).click();
    await expect(field(usd.id,'value').locator('input')).toHaveValue('455.63');assert.equal(requests,5);
    await expect(panel.getByRole('link')).toHaveAttribute('href',/fdate=28\.10\.2026$/);
    await expect(page.getByText('Данные изменены. Пересчитайте значения перед созданием файлов.',{exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Создать',exact:true})).toBeDisabled();
    await page.getByRole('button',{name:'Пересчитать',exact:true}).last().click();
    await expect(page.getByRole('button',{name:'Создать',exact:true})).toBeEnabled();assert.equal(extraction,0);
  }finally{await browser.close();}
});
