import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { resolve,sep } from 'node:path';

const backend=input=>JSON.parse(execFileSync('.local-tools/php/php.exe',['tests/support/review-state.php'],{input:JSON.stringify(input),encoding:'utf8'}));
test('blur saves do not swallow add clicks; all review sections work and session errors preserve the current draft',async()=>{
  let state=backend({});let newSessions=0;let uploads=0;let failCostOnce=true;let expire=false;const actions=[];
  const fields=JSON.parse(await readFile('resources/registry/fields.v1.json','utf8'));
  const catalogs=JSON.parse(await readFile('resources/registry/catalogs.v1.json','utf8'));
  const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try {
    const page=await browser.newPage({viewport:{width:1440,height:960}});
    await page.route('http://review-actions.test/**',async route=>{
      const pathname=new URL(route.request().url()).pathname;const method=route.request().method();
      if(pathname==='/api/session') {
        if(method==='POST'){newSessions++;return route.fulfill({status:500,json:{error:{code:'UNEXPECTED_RESET',message:'Unexpected new session'}}});}
        if(expire)return route.fulfill({status:410,json:{error:{code:'SESSION_EXPIRED',message:'Срок сессии истёк. Загрузите документы заново.'}}});
        return route.fulfill({json:state});
      }
      if(pathname==='/api/registry')return route.fulfill({json:{fields,catalogs}});
      if(pathname==='/api/documents'){uploads++;return route.fulfill({status:500,json:{error:{message:'Unexpected upload'}}});}
      if(pathname==='/api/draft') {
        if(expire)return route.fulfill({status:410,json:{error:{code:'SESSION_EXPIRED',message:'Загрузите документы заново.'}}});
        const body=route.request().postDataJSON();await new Promise(r=>setTimeout(r,350));
        assert.equal(body.revision,state.dataRevision);actions.push('save');
        state=backend({state,changes:body.changes});return route.fulfill({json:state});
      }
      if(pathname==='/api/entities') {
        const body=route.request().postDataJSON();assert.equal(body.revision,state.dataRevision);
        if(body.scope==='cost' && failCostOnce){failCostOnce=false;return route.fulfill({status:401,json:{error:{code:'SESSION_REQUIRED',message:'Начните новую загрузку документов.'}}});}
        actions.push(body.scope);state=backend({state,entity:body});return route.fulfill({json:state});
      }
      const root=resolve('public/assets-app');const path=pathname==='/'?resolve(root,'index.html'):resolve(root,'.'+pathname.replace(/^\/assets-app/,''));
      assert.ok(path.startsWith(root+sep));return route.fulfill({contentType:path.endsWith('.js')?'application/javascript':path.endsWith('.css')?'text/css':'text/html',body:await readFile(path)});
    });
    await page.goto('http://review-actions.test/');
    const name=page.locator('[data-address="party/party-seller/name"] input');
    await name.fill('Название после ручной правки');
    // Click directly from the edited input, without an extra blur or second click.
    await page.locator('#section-rate').getByRole('button',{name:'Добавить',exact:true}).click();
    await expect(page.locator('#section-rate .entity-block')).toHaveCount(2);
    assert.deepEqual(actions.slice(0,2),['save','rate']);
    assert.equal(state.draft.parties[0].name,'Название после ручной правки');assert.equal(state.documents.length,1);
    await name.fill('Дополнительная правка продавца');
    const sellerSaved=page.waitForResponse(response=>response.url().endsWith('/api/draft'));
    const buyer=page.locator('[data-address="party/party-buyer/name"] input');
    await buyer.fill('Незавершённый ввод покупателя');
    await sellerSaved;await expect(name).toBeEnabled();
    await expect(buyer).toHaveValue('Незавершённый ввод покупателя');
    await page.locator('#section-cost').getByRole('button',{name:'Добавить',exact:true}).click();
    await expect(page.locator('.session-connection')).toContainText('cookie текущей сессии');
    await expect(page.getByRole('heading',{name:'Проверьте данные декларации',exact:true})).toBeVisible();
    await expect(name).toHaveValue('Дополнительная правка продавца');assert.equal(newSessions,0);assert.equal(uploads,0);
    await page.getByRole('button',{name:'Проверить сессию',exact:true}).click();
    await expect(page.locator('.session-connection')).toHaveCount(0);
    await page.locator('#section-cost').getByRole('button',{name:'Добавить',exact:true}).click();
    await expect(page.locator('#section-cost .entity-block')).toHaveCount(1);
    const costId=state.draft.costs[0].id;
    await page.locator(`[data-address="cost/${costId}/basis"] input`).fill('Подтверждённый участок перевозки');
    await page.locator('#section-payment').getByRole('button',{name:'Добавить',exact:true}).click();
    await expect(page.locator('#section-payment .entity-block')).toHaveCount(1);
    assert.deepEqual(actions,['save','rate','save','save','cost','save','payment']);
    assert.equal(state.draft.parties[1].name,'Незавершённый ввод покупателя');
    assert.equal(state.draft.costs[0].basis,'Подтверждённый участок перевозки');
    expire=true;await name.fill('Несохранённый ввод после истечения');await name.press('Tab');
    await expect(page.locator('.session-connection')).toContainText('Срок бездействия');
    await expect(name).toHaveValue('Несохранённый ввод после истечения');
    await page.getByRole('button',{name:'Проверить сессию',exact:true}).click();
    await expect(name).toHaveValue('Несохранённый ввод после истечения');
    await expect(page.locator('#section-rate .entity-block')).toHaveCount(2);
    assert.equal(newSessions,0);assert.equal(uploads,0);assert.equal(state.documents.length,1);
  } finally {await browser.close();}
});
