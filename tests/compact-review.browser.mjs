import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';

test('compact review uses one desktop column, single values without choice buttons, sources and mobile reflow', async () => {
  const fixture = JSON.parse(process.env.COMPACT_REVIEW_STATE_PATH
    ? await readFile(process.env.COMPACT_REVIEW_STATE_PATH, 'utf8')
    : execFileSync('.local-tools/php/php.exe', ['tests/support/create-review-session.php'], { encoding: 'utf8' }));
  const session = fixture.state;
  const fields = JSON.parse(await readFile('resources/registry/fields.v1.json', 'utf8'));
  const catalogs = JSON.parse(await readFile('resources/registry/catalogs.v1.json', 'utf8'));
  const graphs = JSON.parse(await readFile('resources/registry/graphs.im40.v2.json', 'utf8'));
  const editors = JSON.parse(await readFile('resources/registry/graph-editors.im40.v3.json', 'utf8'));
  session.draft.declaration.invoiceCurrency = 'USD';
  session.draft.rates = [{ id: 'rate-usd', currency: 'USD', value: '34.32', nominal: '100', date: '2026-11-23', source: 'https://nationalbank.kz', confirmed: true }];
  session.draft.fieldStates['rate/rate-usd/value'] = { status: 'nbk', candidateId: null, evidenceIds: [] };
  session.uploadGroups = [{ id: 'upload-group-invoice', code: '04021', version: 'fixture-classifier', name: 'Счет-фактура (инвойс) к договору' }];
  session.documents = [{ id: 'file-invoice', name: 'инвойс.pdf', mime: 'application/pdf', size: 1024, pages: 1, status: 'recognized', groupId: 'upload-group-invoice', documentKind: '04021' }];
  session.draft.documents = [{ id: 'record-invoice', kind: '04021', name: 'Счет-фактура (инвойс) к договору', number: 'INV-42', date: '2026-10-28', presentationCode: '0', dateRequired: true, documentIds: ['file-invoice'], goodsIds: ['goods-1'] }];
  session.evidence.country = { documentId: 'source', documentName: 'Экспортная декларация.pdf', page: 1, quote: 'CHINA' };
  session.evidence.delivery = { documentId: 'source', documentName: 'invoice.pdf', page: 1, quote: 'CPT ALMATY; HS 29153100' };
  for (const [id, fieldId, value, valid, evidence] of [
    ['country', 'declaration.dispatchCountry', 'CN', true, 'country'],
    ['wrong-delivery', 'declaration.deliveryCode', '29153100', false, 'delivery'],
  ]) {
    const key = fieldId.split('.')[1]; const address = `declaration/_/${key}`;
    session.candidates[id] = { id, fieldId, address, entityId: null, value, unit: null, valid, issues: valid ? [] : ['Значение не соответствует условиям поставки.'], evidenceIds: [evidence] };
    if (valid) {
      session.draft.declaration[key] = value;
      session.draft.fieldStates[address] = { status: 'auto', candidateId: id, evidenceIds: [evidence] };
    }
  }
  const deliveryEditor = session.graphProjection.editors.find(item => item.graphId === '20' && item.entityId === null);
  deliveryEditor.value = 'CPT; Алматы'; deliveryEditor.status = 'auto'; deliveryEditor.evidenceIds = ['delivery'];
  deliveryEditor.choices = [{ id: 'delivery', value: 'CPT; Алматы', evidenceIds: ['delivery'] }];
  const browser = await chromium.launch({ channel: 'msedge', headless: true, timeout: 10000 });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 960 } });
    await page.route('http://compact-review.test/**', async route => {
      const pathname = new URL(route.request().url()).pathname;
      if (pathname === '/api/registry') return route.fulfill({ json: { fields, catalogs, graphs, editors } });
      if (pathname === '/api/session') return route.fulfill({ json: session });
      if (pathname === '/api/classifiers/tnved') return route.fulfill({ json: { version: 'fixture-tnved', items: [{ code: '2915310000', name: 'этилацетат', fullName: '2915310000 — Этилацетат', sourceUrl: 'https://eec.eaeunion.org/', page: 20 }] } });
      if (pathname === '/api/rates') return route.fulfill({ json: session });
      if (pathname === '/api/rates/manual') {
        const body = route.request().postDataJSON();
        const quote = session.draft.rates.find(rate => rate.currency === body.currency);
        Object.assign(quote, { value: body.value, nominal: '1', source: 'manual', confirmed: true });
        session.draft.fieldStates[`rate/${quote.id}/value`] = { status: 'manual', candidateId: null, evidenceIds: [] };
        session.dataRevision++; session.graphProjection.dataRevision = session.dataRevision;
        return route.fulfill({ json: session });
      }
      if (pathname === '/api/entities') {
        const body = route.request().postDataJSON();
        const field = fields.fields.find(item => item.id === body.fieldId);
        const record = session.draft.documents.find(item => item.id === body.recordId);
        assert.ok(field && record);
        record[field.key] = body.value; session.dataRevision++;
        session.graphProjection.dataRevision = session.dataRevision;
        return route.fulfill({ json: session });
      }
      if (pathname === '/api/draft') {
        const body = route.request().postDataJSON();
        const change = body.changes[0];
        session.testLastDraftChange = change;
        const field = fields.fields.find(field => field.id === change.fieldId);
        const address = `${field.scope}/${change.entityId ?? '_'}/${field.key}`;
        const candidate = session.candidates[change.candidateId];
        const row = field.scope === 'declaration' ? session.draft.declaration : session.draft.goods.find(row => row.id === change.entityId);
        row[field.key] = candidate ? candidate.value : change.value;
        session.draft.fieldStates[address] = { status: candidate ? 'selected' : 'manual', candidateId: candidate?.id ?? null, evidenceIds: candidate?.evidenceIds ?? [] };
        for (const editor of session.graphProjection.editors) for (const input of editor.inputs) if (input.address === address) input.value = row[field.key];
        session.validation.errors = session.validation.errors.filter(error => error.address !== address);
        session.validation.count = session.validation.errors.length; session.validation.valid = session.validation.count === 0; session.graphProjection.dataRevision = session.dataRevision + 1;
        session.dataRevision++;
        return route.fulfill({ json: session });
      }
      const root = resolve('public/assets-app');
      const path = pathname === '/' ? resolve(root, 'index.html') : resolve(root, '.' + pathname.replace(/^\/assets-app/, ''));
      assert.ok(path.startsWith(root + sep));
      return route.fulfill({ contentType: path.endsWith('.js') ? 'application/javascript' : path.endsWith('.css') ? 'text/css' : 'text/html', body: await readFile(path) });
    });
    await page.goto('http://compact-review.test/');
    await page.getByRole('heading', { name: 'Проверьте данные декларации', exact: true }).waitFor();
    const layout = await page.locator('.graphs-grid').evaluate(grid => {
      const first = grid.children[0].getBoundingClientRect(); const second = grid.children[1].getBoundingClientRect();
      const input = grid.querySelector('#graph-1 input');
      return { columns: getComputedStyle(grid).gridTemplateColumns.split(' ').length, firstTop: first.top, secondTop: second.top, firstLeft: first.left, secondLeft: second.left, height: input.getBoundingClientRect().height, font: getComputedStyle(input).fontSize };
    });
    assert.equal(layout.columns, 1); assert.ok(layout.secondTop > layout.firstTop); assert.ok(Math.abs(layout.secondLeft - layout.firstLeft) < 1);
    assert.ok(layout.height <= 27); assert.equal(layout.font, '12px');
    assert.equal(await page.locator('.validation-summary').count(), 0);
    assert.equal(await page.locator('.review-page details.notice').count(), 0);
    const graphOne = await page.locator('#graph-1').evaluate(card => ({height:card.getBoundingClientRect().height, body:card.querySelector('.graph-card-body')?.getBoundingClientRect().height, editor:card.querySelector('.graph-editor-scroll')?.getBoundingClientRect().height, input:card.querySelector('input')?.getBoundingClientRect().height, meta:card.querySelector('small')?.getBoundingClientRect().height}));
    assert.ok(graphOne.height <= 64, JSON.stringify(graphOne));
    assert.ok(await page.locator('#graph-3').evaluate(card => card.getBoundingClientRect().height <= 40));
    const country = page.locator('[data-address="declaration/_/dispatchCountry"]');
    await expect(country.locator('input')).toHaveValue('CN'); assert.equal(await country.locator('.candidate-list button').count(), 0);
    await country.getByText('Источник', { exact: true }).focus(); await expect(page.locator('.floating-source')).toContainText('Экспортная декларация.pdf, стр. 1');
    await expect(page.locator('#graph-17a')).toContainText('KZ — КАЗАХСТАН');
    const delivery = page.locator('#graph-20 .graph-text-editor');
    await expect(delivery.locator('textarea')).toHaveValue('CPT; Алматы'); assert.equal(await delivery.locator('.compact-alternatives button').count(), 0);
    assert.equal(await delivery.locator('.compact-error').textContent(), '');
    const graph44 = page.locator('#graph-44');
    await expect(graph44).toContainText('04021 — Счет-фактура (инвойс) к договору'); await expect(graph44).toContainText('инвойс.pdf');
    const invoiceNumber = graph44.locator('[data-address="document/record-invoice/number"] input');
    await invoiceNumber.fill('INV-43'); await invoiceNumber.press('Tab'); await expect(invoiceNumber).toHaveValue('INV-43'); assert.equal(session.draft.documents[0].number, 'INV-43');
    const tnved = page.locator('#tnved-search-goods-1');
    await expect(page.locator('.tnved-results')).toHaveCount(0); await tnved.fill('этилацетат'); await page.getByRole('button', { name: 'Найти' }).click();
    await expect(page.locator('.tnved-results')).toContainText('2915310000'); await expect(page.getByRole('link', { name: 'Источник, стр. 20' })).toHaveAttribute('href', 'https://eec.eaeunion.org/');
    await page.locator('.tnved-result').click(); await expect(page.locator('[data-address="goods/goods-1/commodityCode"] input')).toHaveValue('2915310000');
    const usdRate = page.getByLabel('Курс USD к KZT');
    await expect(usdRate).toHaveValue('0.3432'); assert.equal(await page.locator('#graph-23').getByText('Кратность', { exact: true }).count(), 0);
    await usdRate.fill('450.123'); await usdRate.press('Tab'); await expect(page.locator('#graph-23 .graph-rate-row')).toContainText('Введено вручную'); await expect(usdRate).toHaveValue('450.123');
    const gross = page.locator('[data-address="goods/goods-1/grossMass"]');
    assert.equal(await gross.getByRole('button').count(), 2);
    await gross.getByRole('button', { name: '27860 кг', exact: true }).click(); assert.equal(session.draft.goods[0].grossMass, '27860', JSON.stringify(session.testLastDraftChange)); await expect(gross.locator('input')).toHaveValue('27860');
    await gross.locator('input').fill('28000'); await gross.locator('input').press('Tab');
    await expect(gross.getByText('Введено вручную', { exact: true })).toBeVisible();
    await page.screenshot({ path: '.local-tools/compact-review-desktop.png', fullPage: false });
    await page.setViewportSize({ width: 390, height: 844 });
    const narrowLayout = await page.evaluate(() => ({scrollWidth:document.documentElement.scrollWidth,width:innerWidth,offenders:[...document.querySelectorAll('body *')].map(node=>({tag:node.tagName,cls:String(node.className),id:node.id,left:Math.round(node.getBoundingClientRect().left),right:Math.round(node.getBoundingClientRect().right),width:Math.round(node.getBoundingClientRect().width)})).filter(item=>item.right>innerWidth+1).slice(0,12)}));
    assert.ok(narrowLayout.scrollWidth<=narrowLayout.width,JSON.stringify(narrowLayout));
    assert.equal(await page.locator('.graphs-grid').evaluate(grid => getComputedStyle(grid).gridTemplateColumns.split(' ').length), 1);
    await gross.scrollIntoViewIfNeeded(); await page.screenshot({ path: '.local-tools/compact-review-mobile.png' });
    await page.setViewportSize({ width: 195, height: 700 });
    const zoomLayout = await page.evaluate(() => ({scrollWidth:document.documentElement.scrollWidth,width:innerWidth,bodyGrid:getComputedStyle(document.querySelector('#graph-15a .graph-card-body')).gridTemplateColumns,bodyWidth:document.querySelector('#graph-15a .graph-card-body').getBoundingClientRect().width,offenders:[...document.querySelectorAll('body *')].map(node=>({tag:node.tagName,cls:String(node.className),id:node.id,left:Math.round(node.getBoundingClientRect().left),right:Math.round(node.getBoundingClientRect().right),width:Math.round(node.getBoundingClientRect().width),parent:node.parentElement?.className,parentWidth:Math.round(node.parentElement?.getBoundingClientRect().width??0),grand:node.parentElement?.parentElement?.className,card:node.closest('.graph-card')?.id})).filter(item=>item.right>innerWidth+1).slice(0,12)}));
    assert.ok(zoomLayout.scrollWidth<=zoomLayout.width,JSON.stringify(zoomLayout));
    const narrowInput = await page.locator('[data-address="goods/goods-1/grossMass"] input').evaluate(input => { const r=input.getBoundingClientRect();return r.width>0&&r.left>=0&&r.right<=innerWidth; });
    assert.equal(narrowInput,true);
  } finally { await browser.close(); }
});
