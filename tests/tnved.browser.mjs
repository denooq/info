import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';

test('TN VED field shows automatic reference provenance and independent ambiguous alternatives', async () => {
  const { state } = JSON.parse(execFileSync('.local-tools/php/php.exe', ['tests/support/create-tnved-session.php'], { encoding: 'utf8' }));
  const fields = JSON.parse(await readFile('resources/registry/fields.v1.json', 'utf8'));
  const catalogs = JSON.parse(await readFile('resources/registry/catalogs.v1.json', 'utf8'));
  const browser = await chromium.launch({ channel: 'msedge', headless: true, timeout: 10000 });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 960 } });
    await page.route('http://tnved.test/**', async route => {
      const pathname = new URL(route.request().url()).pathname;
      if (pathname === '/api/session') return route.fulfill({ json: state });
      if (pathname === '/api/registry') return route.fulfill({ json: { fields, catalogs } });
      if (pathname === '/api/draft') {
        const change = route.request().postDataJSON().changes[0];
        const candidate = state.candidates[change.candidateId];
        const row = state.draft.goods.find(row => row.id === change.entityId);
        row.commodityCode = candidate ? candidate.value : change.value;
        const address = `goods/${row.id}/commodityCode`;
        state.draft.fieldStates[address] = { status: candidate ? 'selected' : 'manual', candidateId: candidate?.id ?? null, evidenceIds: candidate?.evidenceIds ?? [] };
        state.dataRevision++;
        return route.fulfill({ json: state });
      }
      const root = resolve('public/assets-app');
      const path = pathname === '/' ? resolve(root, 'index.html') : resolve(root, '.' + pathname.replace(/^\/assets-app/, ''));
      assert.ok(path.startsWith(root + sep));
      return route.fulfill({ contentType: path.endsWith('.js') ? 'application/javascript' : path.endsWith('.css') ? 'text/css' : 'text/html', body: await readFile(path) });
    });
    await page.goto('http://tnved.test/');
    const first = page.locator('[data-address="goods/goods-1/commodityCode"]');
    const second = page.locator('[data-address="goods/goods-2/commodityCode"]');
    await expect(first.locator('input')).toHaveValue('2915310000');
    await expect(first.getByText('Подобрано по справочнику', { exact: true })).toBeVisible();
    assert.equal(await first.getByRole('button').count(), 0);
    await first.getByText('Источник', { exact: true }).click();
    await expect(first.locator('.source-popover')).toContainText('этилацетат');
    await expect(first.getByRole('link', { name: 'Первоисточник ЕЭК' })).toHaveAttribute('href', /^https:\/\/eec\.eaeunion\.org\//);
    await expect(second.locator('input')).toHaveValue('');
    assert.ok(await second.getByRole('button').count() > 1);
    const choice = second.getByRole('button', { name: '3506910000', exact: true });
    await choice.focus();
    await expect(second.getByRole('tooltip').filter({ hasText: 'адгезивы на основе полимеров' })).toBeVisible();
    await choice.click(); await expect(second.locator('input')).toHaveValue('3506910000');
    await expect(second.getByText('Выбран источник', { exact: true })).toBeVisible();
    await expect(first.locator('input')).toHaveValue('2915310000');
    await second.locator('input').fill('3506990000'); await second.locator('input').press('Tab');
    await expect(second.getByText('Введено вручную', { exact: true })).toBeVisible();
  } finally { await browser.close(); }
});
