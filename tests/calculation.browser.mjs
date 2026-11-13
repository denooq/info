import {test} from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {chromium,expect} from '@playwright/test';

test('calculation review rejects inconsistent overrides and recalculates after an exchange rate change',async()=>{
 const fixture=JSON.parse(execFileSync('.local-tools/php/php.exe',['tests/support/create-review-session.php'],{encoding:'utf8'}));
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:960}});
  await context.addCookies([{name:'customs_session',value:fixture.token,url:'http://127.0.0.1:8080',httpOnly:true,sameSite:'Strict'}]);
  const page=await context.newPage();await page.goto('http://127.0.0.1:8080');
  await page.locator('[data-address="goods/goods-1/grossMass"]').getByRole('button',{name:'27860 кг'}).click();
  await expect(page.getByRole('button',{name:'Рассчитать',exact:true})).toBeEnabled();
  await page.getByRole('button',{name:'Рассчитать',exact:true}).click();
  await page.getByRole('heading',{name:'Проверьте расчёт',exact:true}).waitFor();
  const duty=page.locator('.result-row').filter({hasText:'Пошлина 2010, KZT'}).locator('input');
  await expect(duty).toHaveValue('853267.41');await duty.fill('999');await duty.press('Tab');
  await expect(page.getByRole('button',{name:'Создать',exact:true})).toBeDisabled();
  await page.getByRole('button',{name:'Принять рассчитанное',exact:true}).click();
  await expect(page.getByRole('button',{name:'Создать',exact:true})).toBeEnabled();
  await page.getByRole('button',{name:'Изменить курсы и ставки',exact:true}).click();
  const rate=page.locator('[data-address="rate/rate-usd/value"] input');await rate.fill('460');await rate.press('Tab');
  await expect(page.getByRole('button',{name:'Создать',exact:true})).toBeDisabled();
  await page.getByRole('button',{name:'Пересчитать',exact:true}).first().click();
  const newDuty=page.locator('.result-row').filter({hasText:'Пошлина 2010, KZT'}).locator('input');
  await expect(newDuty).toHaveValue('861451.20');await expect(page.getByRole('button',{name:'Создать',exact:true})).toBeEnabled();
  await page.screenshot({path:'.local-tools/calculation-desktop.png'});
  await page.reload();await page.getByRole('heading',{name:'Проверьте расчёт',exact:true}).waitFor();
  await expect(page.locator('.result-row').filter({hasText:'Пошлина 2010, KZT'}).locator('input')).toHaveValue('861451.20');
 }finally{await browser.close();}
});
