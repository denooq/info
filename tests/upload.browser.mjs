import {test} from 'node:test';
import assert from 'node:assert/strict';
import {chromium,expect} from '@playwright/test';
import {graphServer} from './support/graph-server.mjs';
import {readFile} from 'node:fs/promises';
import {join} from 'node:path';

test('attachment button opens the real chooser and saves multipart files in the selected group',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try {
    const page=await browser.newPage({viewport:{width:1366,height:900}});await page.goto(server.base);
    const search=page.getByRole('combobox',{name:'Вид документа',exact:true});
    await search.fill('04021');await page.getByRole('option').filter({hasText:'04021'}).click();
    const row=page.locator('.upload-group').filter({hasText:'04021'});await row.waitFor();
    const before=await page.evaluate(async()=>(await(await fetch('/api/session')).json()));
    const pendingChooser=page.waitForEvent('filechooser');await row.getByRole('button',{name:'Прикрепить файлы',exact:true}).click();
    const chooser=await pendingChooser;assert.equal(chooser.isMultiple(),true);
    const pendingUpload=page.waitForRequest(r=>r.url().endsWith('/api/documents')&&r.method()==='POST');
    await chooser.setFiles('reference/source-documents/example2/авиа.pdf');
    const upload=await pendingUpload;
    assert.match(upload.headers()['content-type'],/^multipart\/form-data; boundary=/);
    await expect(row.locator('.file-row')).toHaveCount(1);await expect(row).toContainText('авиа.pdf');
    assert.deepEqual(JSON.parse(await readFile(join(server.root,'last-upload.json'),'utf8')),{groupId:'kind-04021',revision:String(before.dataRevision),name:'авиа.pdf',uploaded:true});
    // An empty selection is a cancelled dialog: no request, data change or OCR.
    const again=page.waitForEvent('filechooser');await row.getByRole('button',{name:'Прикрепить файлы',exact:true}).click();await(await again).setFiles([]);
    await expect(row.locator('.file-row')).toHaveCount(1);
    const repeat=page.waitForEvent('filechooser');await row.getByRole('button',{name:'Прикрепить файлы',exact:true}).click();await(await repeat).setFiles('reference/source-documents/example2/авиа.pdf');
    await expect(row.locator('.file-row')).toHaveCount(2);
    await page.reload();await expect(page.locator('.file-row')).toHaveCount(2);
    const state=await page.evaluate(async()=>(await(await fetch('/api/session')).json()));
    assert.equal(state.jobs.length,0);assert.equal(state.phase,'uploading');assert.equal(state.draft.documents.length,0);
    const mixed=page.waitForEvent('filechooser');await page.getByRole('button',{name:'Прикрепить файлы',exact:true}).click();
    await(await mixed).setFiles([{name:'broken.pdf',mimeType:'application/pdf',buffer:Buffer.from('not a PDF')},{name:'valid.pdf',mimeType:'application/pdf',buffer:await readFile('reference/source-documents/смр.pdf')}]);
    await expect(page.locator('.file-row')).toHaveCount(3);await expect(page.locator('.upload-group [role=alert]')).toContainText('broken.pdf');
    await page.getByRole('button',{name:'Удалить valid.pdf',exact:true}).click();await expect(page.locator('.file-row')).toHaveCount(2);
    await page.getByRole('button',{name:'Удалить вид 04021 со всеми файлами',exact:true}).click();await expect(page.locator('.upload-group')).toHaveCount(0);await page.reload();await expect(page.locator('.file-row')).toHaveCount(0);
  } finally {await browser.close();await server.stop();}
});

test('selecting an official result immediately creates one row, with retry and a separate graph 44 contract',async()=>{
  const server=await graphServer();const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  try {
    const page=await browser.newPage();await page.goto(server.base);
    const search=page.getByRole('combobox',{name:'Вид документа',exact:true});
    await search.fill('04021');await page.getByRole('option').filter({hasText:'04021'}).waitFor();
    await expect(page.locator('.upload-group')).toHaveCount(0);await expect(page.getByRole('button',{name:'+ Добавить',exact:true})).toHaveCount(0);
    await search.press('Enter');await expect(page.locator('.upload-group')).toHaveCount(1);
    await search.fill('04021');await page.getByRole('option').filter({hasText:'04021'}).click();await expect(search).toHaveValue('');await expect(page.locator('.upload-group')).toHaveCount(1);
    await page.route('**/api/upload-groups',route=>route.fulfill({status:503,json:{error:{code:'STORAGE_ERROR',message:'Проверочная ошибка сохранения вида'}}}),{times:1});
    await search.fill('04131');await page.getByRole('option').filter({hasText:'04131'}).click();
    await expect(page.getByRole('button',{name:'Повторить добавление',exact:true})).toBeVisible();await expect(page.locator('.upload-group')).toHaveCount(1);
    await page.getByRole('button',{name:'Повторить добавление',exact:true}).click();await expect(page.locator('.upload-group')).toHaveCount(2);
    await page.reload();await expect(page.locator('.upload-group')).toHaveCount(2);
    await page.evaluate(async()=>{const s=await(await fetch('/api/session')).json();await fetch('/fixture/seed',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrfToken},body:'{}'});});await page.reload();
    const graph=page.locator('#graph-44');await graph.waitFor();
    const before=await page.evaluate(async()=>(await(await fetch('/api/session')).json()).draft.documents.length);
    await graph.getByRole('combobox',{name:'Добавить представленный документ',exact:true}).fill('04031');await graph.getByRole('option').filter({hasText:'04031'}).click();
    assert.equal(await page.evaluate(async()=>(await(await fetch('/api/session')).json()).draft.documents.length),before);
    await expect(graph.getByRole('button',{name:'+ Добавить',exact:true})).toBeEnabled();await graph.getByRole('button',{name:'+ Добавить',exact:true}).click();
    await expect(graph.locator('.document-record[data-document-kind="04031"]')).toHaveCount(1);
    await page.getByRole('button',{name:'К документам',exact:true}).click();await page.getByRole('button',{name:'Удалить invoice-fixture.pdf',exact:true}).click();await expect(page.locator('.file-row')).toHaveCount(0);
    await page.getByRole('button',{name:'К проверке данных',exact:true}).click();await expect(page.locator('#graph-44 .document-record[data-document-kind="04031"]')).toHaveCount(1);
  } finally {await browser.close();await server.stop();}
});
