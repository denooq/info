import {test} from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {chromium,expect} from '@playwright/test';

test('review candidates preserve sources, manual input clears selection, mandatory fields block API and UI',async()=>{
 const fixture=JSON.parse(execFileSync('.local-tools/php/php.exe',['tests/support/create-review-session.php'],{encoding:'utf8'}));
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:960}});
  await context.addCookies([{name:'customs_session',value:fixture.token,url:'http://127.0.0.1:8080',httpOnly:true,sameSite:'Strict'}]);
  const page=await context.newPage();await page.goto('http://127.0.0.1:8080');
  await page.getByRole('heading',{name:'Проверьте данные декларации',exact:true}).waitFor();
  const row=page.locator('[data-address="goods/goods-1/grossMass"]');
  const button=row.getByRole('button',{name:'27860 кг',exact:true});await button.focus();
  await row.getByRole('tooltip').filter({hasText:'CMR, стр. 1'}).waitFor({state:'visible'});
  await button.click();await page.waitForResponse(r=>r.url().endsWith('/api/draft')&&r.status()===200);
  await expect(row.locator('input')).toHaveValue('27860');
  assert.equal(await button.getAttribute('aria-pressed'),'true');
  await row.locator('input').fill('28000');await row.locator('input').press('Tab');
  await page.waitForResponse(r=>r.url().endsWith('/api/draft')&&r.status()===200);
  await expect(button).toHaveAttribute('aria-pressed','false');await row.getByText('Введено вручную',{exact:true}).waitFor();
  await page.reload();await row.locator('input').waitFor();assert.equal(await row.locator('input').inputValue(),'28000');
  await row.scrollIntoViewIfNeeded();await page.screenshot({path:'.local-tools/review-desktop.png'});
  await page.setViewportSize({width:390,height:844});await row.scrollIntoViewIfNeeded();
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  await page.screenshot({path:'.local-tools/review-mobile.png'});
  const calculate=page.getByRole('button',{name:'Рассчитать',exact:true});await expect(calculate).toBeEnabled();
  const name=page.locator('[data-address="party/party-seller/name"] input');await name.fill('');await name.press('Tab');
  await page.waitForResponse(r=>r.url().endsWith('/api/draft')&&r.status()===200);await expect(calculate).toBeDisabled();
  const result=await page.evaluate(async()=>{const s=await fetch('/api/session').then(r=>r.json());const r=await fetch('/api/calculate',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:JSON.stringify({revision:s.dataRevision})});return {status:r.status,body:await r.json()};});
  assert.equal(result.status,422);assert.equal(result.body.error.code,'DECLARATION_INVALID');
 }finally{await browser.close();}
});
