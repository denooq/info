import {test} from 'node:test';
import assert from 'node:assert/strict';
import {chromium,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {readFile} from 'node:fs/promises';
import {resolve,sep} from 'node:path';

const backend=input=>JSON.parse(execFileSync('.local-tools/php/php.exe',['tests/support/required-documents-state.php'],{input:JSON.stringify(input),encoding:'utf8'}));
test('required invoice and contract fields are immediately editable, preserve adjacent input and reach calculation without Add or OCR',async()=>{
  let state=backend({});let writes=0;let extraction=0;let failOnce=true;
  const fields=JSON.parse(await readFile('resources/registry/fields.v1.json','utf8'));const catalogs=JSON.parse(await readFile('resources/registry/catalogs.v1.json','utf8'));
  const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try {
    const page=await browser.newPage({viewport:{width:1440,height:960}});
    await page.route('http://required-documents.test/**',async route=>{
      const path=new URL(route.request().url()).pathname;
      if(path==='/api/session')return route.fulfill({json:state});
      if(path==='/api/registry')return route.fulfill({json:{fields,catalogs}});
      if(path==='/api/entities') {
        const body=route.request().postDataJSON();assert.equal(body.action,'required');assert.equal(body.revision,state.dataRevision);writes++;
        if(failOnce){failOnce=false;return route.fulfill({status:503,json:{error:{code:'TEMPORARY',message:'Тестовая ошибка сохранения'}}});}
        await new Promise(r=>setTimeout(r,200));state=backend({state,entity:body});return route.fulfill({json:state});
      }
      if(path==='/api/draft'){const body=route.request().postDataJSON();assert.equal(body.revision,state.dataRevision);state=backend({state,changes:body.changes});return route.fulfill({json:state});}
      if(path==='/api/calculate'){state=backend({state,calculate:true});return route.fulfill({json:state});}
      if(path==='/api/documents' || path==='/api/recognize'){extraction++;return route.fulfill({status:500,json:{error:{message:'Unexpected extraction'}}});}
      const root=resolve('public/assets-app');const file=path==='/'?resolve(root,'index.html'):resolve(root,'.'+path.replace(/^\/assets-app/,''));assert.ok(file.startsWith(root+sep));
      return route.fulfill({contentType:file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':'text/html',body:await readFile(file)});
    });
    await page.goto('http://required-documents.test/');
    const section=page.locator('#section-document');await expect(section.locator('.entity-block')).toHaveCount(2);
    assert.equal(writes,0);assert.equal(state.draft.documents.length,0);
    const calculate=page.getByRole('button',{name:'Рассчитать',exact:true});await expect(calculate).toBeDisabled();
    const card=kind=>section.locator(`[data-document-kind="${kind}"]`);
    const input=(kind,key)=>card(kind).locator(`[data-address$="/${key}"] input`);
    await expect(card('04021').getByRole('heading',{name:'Инвойс · 04021',exact:true})).toBeVisible();
    await expect(card('03011').getByRole('heading',{name:'Контракт · 03011',exact:true})).toBeVisible();
    await expect(input('04021','kind')).toHaveAttribute('readonly','');
    await input('04021','number').fill('INV-42');await page.getByRole('heading',{name:'Документы',exact:true}).click();
    await expect(card('04021')).toContainText('Тестовая ошибка сохранения');await expect(input('04021','number')).toHaveValue('INV-42');assert.equal(state.draft.documents.length,0);
    // Retry the same local input, then type into another field while the first save is in flight.
    await input('04021','number').focus();const response=page.waitForResponse(r=>r.url().endsWith('/api/entities'));
    await input('04021','name').click();await input('04021','name').fill('Коммерческий инвойс');await response;
    await expect(input('04021','number')).toHaveValue('INV-42');await expect(input('04021','name')).toHaveValue('Коммерческий инвойс');
    await expect(input('04021','name')).toBeFocused();await page.getByRole('heading',{name:'Документы',exact:true}).click();
    for(const [kind,name,number] of [['04021','Коммерческий инвойс','INV-42'],['03011','Контракт поставки','CTR-17']]) {
      for(const [key,value] of [['name',name],['number',number],['date','2026-10-28'],['presentationCode','0']]) {
        const field=input(kind,key);await field.fill(value);await page.getByRole('heading',{name:'Документы',exact:true}).click();await expect(field).toBeEnabled();
      }
      const goods=card(kind).locator('[data-address$="/goodsIds"] input[type="checkbox"]');await goods.click();await expect(goods).toBeChecked();await expect(goods).toBeEnabled();
    }
    assert.equal(state.draft.documents.length,2);await expect(section.locator('.entity-block')).toHaveCount(2);
    for(const checkbox of await page.locator('[data-address="declaration/_/valuationDocumentIds"] input[type="checkbox"]').all()) {await checkbox.click();await expect(checkbox).toBeChecked();await expect(checkbox).toBeEnabled();}
    await expect(calculate).toBeEnabled();await page.reload();await expect(section.locator('.entity-block')).toHaveCount(2);
    await expect(input('04021','number')).toHaveValue('INV-42');await expect(input('03011','number')).toHaveValue('CTR-17');
    await calculate.click();await expect(page.getByRole('heading',{name:'Проверьте расчёт',exact:true})).toBeVisible();assert.equal(extraction,0);
  }finally{await browser.close();}
});
