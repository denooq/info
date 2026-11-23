import {test} from 'node:test';
import assert from 'node:assert/strict';
import {chromium,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {readFile} from 'node:fs/promises';
import {resolve,sep} from 'node:path';

const backend=input=>JSON.parse(execFileSync('.local-tools/php/php.exe',['tests/support/validation-state.php'],{input:JSON.stringify(input),encoding:'utf8'}));
test('all four real-world blockers are visible, navigation reaches the relevant sections, and fixing them enables calculation',async()=>{
  let state=backend({});let uploads=0;let checks=0;assert.equal(state.validation.count,4);
  // Also support navigation when a deployed backend still reports the old cost/kind address.
  const costIssue=state.validation.errors.find(e=>e.code==='COST_APPLICABILITY');costIssue.address='cost/cost-test/kind';costIssue.message='Подтвердите наличие этого компонента в ответах ДТС.';
  const fields=JSON.parse(await readFile('resources/registry/fields.v1.json','utf8'));const catalogs=JSON.parse(await readFile('resources/registry/catalogs.v1.json','utf8'));
  const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try {
    const page=await browser.newPage({viewport:{width:1440,height:960}});
    await page.route('http://validation.test/**',async route=>{
      const pathname=new URL(route.request().url()).pathname;
      if(pathname==='/api/session'){checks++;return route.fulfill({json:state});}
      if(pathname==='/api/registry')return route.fulfill({json:{fields,catalogs}});
      if(pathname==='/api/draft'){const body=route.request().postDataJSON();assert.equal(body.revision,state.dataRevision);state=backend({state,changes:body.changes});return route.fulfill({json:state});}
      if(pathname==='/api/calculate'){state=backend({state,calculate:true});return route.fulfill({json:state});}
      if(pathname==='/api/documents' || pathname==='/api/recognize'){uploads++;return route.fulfill({status:500,json:{error:{message:'Unexpected extraction'}}});}
      const root=resolve('public/assets-app');const path=pathname==='/'?resolve(root,'index.html'):resolve(root,'.'+pathname.replace(/^\/assets-app/,''));assert.ok(path.startsWith(root+sep));
      return route.fulfill({contentType:path.endsWith('.js')?'application/javascript':path.endsWith('.css')?'text/css':'text/html',body:await readFile(path)});
    });
    await page.goto('http://validation.test/');const summary=page.locator('.validation-summary');
    await expect(summary.locator('summary')).toContainText('ошибок — 4');
    for(const text of ['04021','03011','USD','Подтвердите наличие'])await expect(summary).toContainText(text);
    const calculate=page.getByRole('button',{name:'Рассчитать',exact:true});await expect(calculate).toBeDisabled();
    await summary.getByRole('button',{name:'Документы',exact:true}).click();assert.equal(await page.evaluate(()=>document.activeElement.id),'section-document');
    await summary.getByRole('button',{name:'Курсы валют',exact:true}).click();assert.equal(await page.evaluate(()=>document.activeElement.id),'section-rate');
    const freight=fields.fields.find(f=>f.id==='declaration.freightApplicable');
    await summary.getByRole('button',{name:`Поставка и ДТС · ${freight.label}`,exact:true}).click();
    assert.equal(await page.evaluate(()=>document.activeElement.id),'field-declaration-_-freightApplicable');
    for(const [id,code] of [['doc-invoice','04021'],['doc-contract','03011']]){
      const input=page.locator(`[data-address="document/${id}/kind"] input`);await input.fill(code);await input.press('Tab');await expect(input).toBeEnabled();
    }
    const currency=page.locator('[data-address="rate/rate-usd/currency"] input');await currency.fill('USD');await currency.press('Tab');await expect(currency).toBeEnabled();
    // This fixture has no NBK capability and exercises the retained manual fallback.
    for(const [key,value] of [['value','455.63'],['nominal','1'],['source','Подтверждённый тестовый курс']]){
      const input=page.locator(`[data-address="rate/rate-usd/${key}"] input`);await input.fill(value);await input.press('Tab');await expect(input).toBeEnabled();
    }
    await page.locator('[data-address="rate/rate-usd/confirmed"] select').selectOption('yes');
    await page.locator('[data-address="declaration/_/freightApplicable"] select').selectOption('yes');
    await page.getByRole('button',{name:'Проверить заполнение',exact:true}).click();
    await expect(summary).toHaveCount(0);await expect(calculate).toBeEnabled();assert.ok(checks>=2);assert.equal(uploads,0);
    await calculate.click();await expect(page.getByRole('heading',{name:'Проверьте расчёт',exact:true})).toBeVisible();
    assert.equal(uploads,0);
  }finally{await browser.close();}
});
