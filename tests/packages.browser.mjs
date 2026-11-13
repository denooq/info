import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';

const backend=input=>JSON.parse(execFileSync('.local-tools/php/php.exe',['tests/support/package-state.php'],{input:JSON.stringify(input),encoding:'utf8'}));
test('empty package selectors can create and link a shared group inline, preserve failed input and reach calculation',async()=>{
  let state=backend({}); let requests=0; let failSave=true;
  assert.equal(state.validation.count,3);
  const fields=JSON.parse(await readFile('resources/registry/fields.v1.json','utf8'));
  const catalogs=JSON.parse(await readFile('resources/registry/catalogs.v1.json','utf8'));
  const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try {
    const page=await browser.newPage({viewport:{width:1440,height:960}});
    await page.route('http://packages.test/**',async route=>{
      const pathname=new URL(route.request().url()).pathname;
      if(pathname==='/api/session')return route.fulfill({json:state});
      if(pathname==='/api/registry')return route.fulfill({json:{fields,catalogs}});
      if(pathname==='/api/entities') {
        requests++;
        if(failSave){failSave=false;return route.fulfill({status:503,json:{error:{code:'TEMPORARY_ERROR',message:'Не удалось сохранить. Повторите попытку.'}}});}
        state=backend({state,entity:route.request().postDataJSON()});return route.fulfill({json:state});
      }
      if(pathname==='/api/calculate'){state=backend({state,calculate:true});return route.fulfill({json:state});}
      const root=resolve('public/assets-app');
      const path=pathname==='/'?resolve(root,'index.html'):resolve(root,'.'+pathname.replace(/^\/assets-app/,''));
      assert.ok(path.startsWith(root+sep));
      return route.fulfill({contentType:path.endsWith('.js')?'application/javascript':path.endsWith('.css')?'text/css':'text/html',body:await readFile(path)});
    });
    await page.goto('http://packages.test/');
    const first=page.locator('[data-address="goods/goods-1/packageIds"]');
    const second=page.locator('[data-address="goods/goods-2/packageIds"]');
    const shipment=page.locator('[data-address="declaration/_/shipmentPackageIds"]');
    await expect(first).toContainText('Грузовые места ещё не добавлены');
    await expect(first).not.toContainText('Сначала добавьте строки соответствующего раздела');
    const calculate=page.getByRole('button',{name:'Рассчитать',exact:true});await expect(calculate).toBeDisabled();
    await first.getByRole('button',{name:'Добавить грузовые места',exact:true}).click();
    const form=first.getByRole('form',{name:'Новая группа грузовых мест'});
    await form.getByLabel('Название группы',{exact:true}).fill('Паллеты из CMR');
    await form.getByLabel('Количество грузовых мест',{exact:true}).fill('0');
    await form.getByRole('button',{name:'Сохранить грузовые места',exact:true}).click();
    await expect(form.getByRole('alert')).toContainText('целое количество');assert.equal(requests,0);
    await form.getByLabel('Количество грузовых мест',{exact:true}).fill('34');
    await expect(form.getByRole('checkbox').first()).toBeChecked();
    await form.getByRole('checkbox').nth(1).check();
    await form.getByRole('button',{name:'Сохранить грузовые места',exact:true}).click();
    await expect(form.getByRole('alert')).toContainText('Не удалось сохранить');
    await expect(form.getByLabel('Название группы',{exact:true})).toHaveValue('Паллеты из CMR');
    await expect(form.getByLabel('Количество грузовых мест',{exact:true})).toHaveValue('34');
    assert.equal(state.draft.packages.length,0);await expect(calculate).toBeDisabled();
    await form.getByRole('button',{name:'Сохранить грузовые места',exact:true}).click();
    await expect(form).toHaveCount(0);
    for(const field of [first,second,shipment])await expect(field.getByRole('checkbox',{name:'Паллеты из CMR (34)',exact:true})).toBeChecked();
    assert.equal(state.draft.packages.length,1);assert.equal(requests,2);
    await expect(calculate).toBeEnabled();
    await page.setViewportSize({width:390,height:844});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    await calculate.click();
    await expect(page.getByRole('heading',{name:'Проверьте расчёт',exact:true})).toBeVisible();
    assert.equal(state.calculation.packageCount,34);
    await expect(page.locator('.calculation-summary')).toContainText('Грузовых мест 34');
  } finally {await browser.close();}
});
