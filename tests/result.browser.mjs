import {test} from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {chromium,expect} from '@playwright/test';
import {createHash} from 'node:crypto';
test('four-step bundle creates PDF and two XML, repeated downloads stay identical and edits revoke downloads',async()=>{
 const fixture=JSON.parse(execFileSync('.local-tools/php/php.exe',['tests/support/create-review-session.php'],{encoding:'utf8'}));
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:960}});await context.addCookies([{name:'customs_session',value:fixture.token,url:'http://127.0.0.1:8080',httpOnly:true,sameSite:'Strict'}]);
  const page=await context.newPage();await page.goto('http://127.0.0.1:8080');
  await page.locator('[data-address="goods/goods-1/grossMass"]').getByRole('button',{name:'27860 кг'}).click();await expect(page.getByRole('button',{name:'Рассчитать',exact:true})).toBeEnabled();await page.getByRole('button',{name:'Рассчитать',exact:true}).click();
  await page.getByRole('heading',{name:'Проверьте расчёт',exact:true}).waitFor();await page.getByRole('button',{name:'Создать',exact:true}).click();
  await page.getByRole('heading',{name:'Формируем файлы декларации',exact:true}).waitFor();
  const result=JSON.parse(execFileSync('.local-tools/php/php.exe',['tests/support/tick-session.php',fixture.token],{encoding:'utf8'}));assert.equal(result.error,null);assert.equal(result.phase,'ready');
  await page.getByRole('heading',{name:'Декларация подготовлена',exact:true}).waitFor();
  await expect(page.getByRole('link',{name:/Скачать PDF/})).toHaveAttribute('href','/api/download/pdf');
  await expect(page.getByRole('link',{name:/Скачать XML/})).toHaveAttribute('href','/api/download/xml');
  await page.screenshot({path:'.local-tools/result-desktop.png'});
  const hash=bytes=>createHash('sha256').update(bytes).digest('hex');
  for(const kind of ['pdf','xml']){const a=await context.request.get(`http://127.0.0.1:8080/api/download/${kind}`);const b=await context.request.get(`http://127.0.0.1:8080/api/download/${kind}`);assert.equal(a.status(),200);assert.equal(hash(await a.body()),hash(await b.body()));}
  const foreign=await browser.newContext();await foreign.request.post('http://127.0.0.1:8080/api/session');assert.equal((await foreign.request.get('http://127.0.0.1:8080/api/download/pdf')).status(),409);await foreign.close();
  await page.getByRole('button',{name:'Вернуться к проверке данных'}).click();const input=page.locator('[data-address="goods/goods-1/invoiceValue"] input');await input.fill('1');await input.press('Tab');await page.waitForResponse(r=>r.url().endsWith('/api/draft')&&r.status()===200);
  assert.equal((await context.request.get('http://127.0.0.1:8080/api/download/pdf')).status(),409);
 }finally{await browser.close();}
});
