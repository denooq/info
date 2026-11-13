import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';

test('compact review has two desktop columns, single values without choice buttons, sources and mobile reflow', async () => {
  const fixture = JSON.parse(execFileSync('.local-tools/php/php.exe', ['tests/support/create-review-session.php'], { encoding: 'utf8' }));
  const session = fixture.state;
  const fields = JSON.parse(await readFile('resources/registry/fields.v1.json', 'utf8'));
  const catalogs = JSON.parse(await readFile('resources/registry/catalogs.v1.json', 'utf8'));
  session.evidence.country = { documentId: 'source', documentName: 'CMR.pdf', page: 1, quote: 'REPUBLIC OF KAZAKHSTAN' };
  session.evidence.delivery = { documentId: 'source', documentName: 'invoice.pdf', page: 1, quote: 'CPT ALMATY; HS 29153100' };
  for (const [id, fieldId, value, valid, evidence] of [
    ['country', 'declaration.destinationCountry', 'KZ', true, 'country'],
    ['delivery', 'declaration.deliveryCode', 'CPT', true, 'delivery'],
    ['wrong-delivery', 'declaration.deliveryCode', '29153100', false, 'delivery'],
  ]) {
    const key = fieldId.split('.')[1]; const address = `declaration/_/${key}`;
    session.candidates[id] = { id, fieldId, address, entityId: null, value, unit: null, valid, issues: valid ? [] : ['Значение не соответствует условиям поставки.'], evidenceIds: [evidence] };
    if (valid) {
      session.draft.declaration[key] = value;
      session.draft.fieldStates[address] = { status: 'auto', candidateId: id, evidenceIds: [evidence] };
    }
  }
  const browser = await chromium.launch({ channel: 'msedge', headless: true, timeout: 10000 });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 960 } });
    await page.route('http://compact-review.test/**', async route => {
      const pathname = new URL(route.request().url()).pathname;
      if (pathname === '/api/registry') return route.fulfill({ json: { fields, catalogs } });
      if (pathname === '/api/session') return route.fulfill({ json: session });
      if (pathname === '/api/draft') {
        const body = route.request().postDataJSON();
        const change = body.changes[0];
        const field = fields.fields.find(field => field.id === change.fieldId);
        const address = `${field.scope}/${change.entityId ?? '_'}/${field.key}`;
        const candidate = session.candidates[change.candidateId];
        const row = field.scope === 'declaration' ? session.draft.declaration : session.draft.goods.find(row => row.id === change.entityId);
        row[field.key] = candidate ? candidate.value : change.value;
        session.draft.fieldStates[address] = { status: candidate ? 'selected' : 'manual', candidateId: candidate?.id ?? null, evidenceIds: candidate?.evidenceIds ?? [] };
        session.validation.errors = session.validation.errors.filter(error => error.address !== address);
        session.validation.count = session.validation.errors.length; session.validation.valid = session.validation.count === 0;
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
    const layout = await page.locator('#section-declaration .fields-grid').evaluate(grid => {
      const first = grid.children[0].getBoundingClientRect(); const second = grid.children[1].getBoundingClientRect();
      const input = grid.querySelector('input');
      return { columns: getComputedStyle(grid).gridTemplateColumns.split(' ').length, firstTop: first.top, secondTop: second.top, secondLeft: second.left, firstRight: first.right, height: input.getBoundingClientRect().height, font: getComputedStyle(input).fontSize };
    });
    assert.equal(layout.columns, 2); assert.equal(layout.firstTop, layout.secondTop); assert.ok(layout.secondLeft > layout.firstRight);
    assert.ok(layout.height <= 27); assert.equal(layout.font, '12px');
    const country = page.locator('[data-address="declaration/_/destinationCountry"]');
    await expect(country.locator('input')).toHaveValue('KZ'); assert.equal(await country.getByRole('button').count(), 0);
    await country.getByText('Источник', { exact: true }).click(); await expect(country.locator('.source-popover')).toContainText('CMR.pdf, стр. 1');
    const delivery = page.locator('[data-address="declaration/_/deliveryCode"]');
    await expect(delivery.locator('input')).toHaveValue('CPT'); assert.equal(await delivery.getByRole('button').count(), 0);
    assert.equal(await delivery.locator('.candidate-warning').count(), 0);
    const gross = page.locator('[data-address="goods/goods-1/grossMass"]');
    assert.equal(await gross.getByRole('button').count(), 2);
    await gross.getByRole('button', { name: '27860 кг', exact: true }).click(); await expect(gross.locator('input')).toHaveValue('27860');
    await gross.locator('input').fill('28000'); await gross.locator('input').press('Tab');
    await expect(gross.getByText('Введено вручную', { exact: true })).toBeVisible();
    await page.locator('h2').filter({ hasText: 'Товары' }).click();
    await page.screenshot({ path: '.local-tools/compact-review-desktop.png', fullPage: false });
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.equal(await page.locator('#section-declaration .fields-grid').evaluate(grid => getComputedStyle(grid).gridTemplateColumns.split(' ').length), 1);
    await gross.scrollIntoViewIfNeeded(); await page.screenshot({ path: '.local-tools/compact-review-mobile.png' });
  } finally { await browser.close(); }
});
