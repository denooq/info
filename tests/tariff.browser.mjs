import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';

const backend = input => JSON.parse(execFileSync('.local-tools/php/php.exe', ['tests/support/tariff-state.php'], { input: JSON.stringify(input), encoding: 'utf8' }));
test('tariff form shows base provenance, requires applicability confirmation and recalculates edited specific operands', async () => {
  let state = backend({code:'0808101000',specific:true});
  const fields=JSON.parse(await readFile('resources/registry/fields.v1.json','utf8'));
  const catalogs=JSON.parse(await readFile('resources/registry/catalogs.v1.json','utf8'));
  const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try {
    const page=await browser.newPage({viewport:{width:1440,height:960}});
    await page.route('http://tariff.test/**',async route=>{
      const pathname=new URL(route.request().url()).pathname;
      if(pathname==='/api/session') return route.fulfill({json:state});
      if(pathname==='/api/registry') return route.fulfill({json:{fields,catalogs}});
      if(pathname==='/api/draft') {state=backend({state,changes:route.request().postDataJSON().changes}); return route.fulfill({json:state});}
      if(pathname==='/api/calculate') {state=backend({state,calculate:true}); return route.fulfill({json:state});}
      const root=resolve('public/assets-app');
      const path=pathname==='/'?resolve(root,'index.html'):resolve(root,'.'+pathname.replace(/^\/assets-app/,''));
      assert.ok(path.startsWith(root+sep));
      return route.fulfill({contentType:path.endsWith('.js')?'application/javascript':path.endsWith('.css')?'text/css':'text/html',body:await readFile(path)});
    });
    await page.goto('http://tariff.test/');
    const field=key=>page.locator(`[data-address="goods/goods-1/${key}"]`);
    await expect(field('dutySpecificRate').locator('input')).toHaveValue('0.06');
    await expect(field('dutyQuantity').locator('input')).toHaveValue('24480');
    await expect(field('dutyQuantity').locator('input')).toHaveAttribute('readonly','');
    assert.equal(await field('dutySpecificRate').getByRole('button').count(),0);
    await expect(field('dutyRate')).toHaveCount(0);
    await expect(page.locator('.tariff-info')).toContainText('действующая ставка в нём не определена');
    const calculate=page.getByRole('button',{name:'Рассчитать',exact:true});
    await expect(calculate).toBeDisabled();
    await field('tariffConfirmed').locator('select').selectOption('yes');
    await expect(calculate).toBeEnabled(); await calculate.click();
    await expect(page.getByRole('heading',{name:'Проверьте расчёт',exact:true})).toBeVisible();
    await expect(page.locator('.formula-note')).toContainText('0.06 EUR за 1 кг');
    await expect(page.locator('.tariff-info')).toContainText('Создание комплекта файлов');
    await page.getByRole('button',{name:'Изменить курсы и ставки',exact:true}).click();
    await field('dutySpecificRate').locator('input').fill('0.1');
    await field('dutySpecificRate').locator('input').press('Tab');
    await expect(field('tariffConfirmed').locator('select')).toHaveValue('no');
    await expect(page.getByRole('button',{name:'Создать',exact:true})).toBeDisabled();
    await field('rateBasis').locator('input').fill('Проверенное основание новой ставки');
    await field('rateBasis').locator('input').press('Tab');
    await expect(field('rateBasis').getByText('Введено вручную',{exact:true})).toBeVisible();
    await field('tariffConfirmed').locator('select').selectOption('yes');
    await page.getByRole('button',{name:'Пересчитать',exact:true}).first().click();
    await expect(page.locator('.formula-note')).toContainText('0.1 EUR за 1 кг');
    assert.equal(state.calculation.items[0].duty,'1224000.00');
    await page.locator('.tariff-trace summary').click();
    await expect(page.locator('.tariff-trace')).toContainText('24480 кг × 0.1 EUR');
    await page.setViewportSize({width:390,height:844});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  } finally {await browser.close();}
});
