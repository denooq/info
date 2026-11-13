import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';

// Uses the production bundle and mocked HTTP only: no PHP server or Mistral calls.
test('recognition keeps polling after one file fails and shows completed files immediately', async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true, timeout: 10000 });
  try {
    const page = await browser.newPage();
    await page.clock.install();
    let polls = 0;
    const failure = { code: 'MISTRAL_REQUEST', message: 'Файл требует проверки.' };
    await page.route('http://recognition.test/**', async route => {
      const pathname = new URL(route.request().url()).pathname;
      if (pathname === '/api/registry') return route.fulfill({ json: { fields: { fields: [], scopes: {} }, catalogs: { lists: {}, fieldCatalogs: {} } } });
      if (pathname === '/api/session') {
        const completed = Math.min(polls++, 2);
        const documents = [0, 1, 2].map(index => ({ id: `doc-${index}`, name: `file-${index}.pdf`, mime: 'application/pdf', size: 1024, pages: 1, status: index === 0 ? 'error' : index <= completed ? 'recognized' : 'recognizing' }));
        return route.fulfill({ json: {
          dataRevision: 0, phase: 'recognizing', error: failure, documents,
          jobs: documents.map(document => ({ id: document.id, kind: 'ocr', status: document.status === 'error' ? 'failed' : document.status === 'recognized' ? 'done' : 'running' })),
          limits: { max_file_bytes: 10485760, max_files: 20 },
        } });
      }
      const root = resolve('public/assets-app');
      const path = pathname === '/' ? resolve(root, 'index.html') : resolve(root, '.' + pathname.replace(/^\/assets-app/, ''));
      assert.ok(path.startsWith(root + sep));
      const contentType = path.endsWith('.js') ? 'application/javascript' : path.endsWith('.css') ? 'text/css' : 'text/html';
      return route.fulfill({ contentType, body: await readFile(path) });
    });
    await page.goto('http://recognition.test/');
    await expect(page.getByRole('status')).toContainText('Готово: 0 из 3');
    await expect(page.getByRole('button', { name: 'Выбрать документы', exact: true })).toBeDisabled();
    await page.clock.runFor(2600);
    await expect(page.getByRole('status')).toContainText('Готово: 1 из 3');
    await expect(page.locator('.file-row').nth(1)).toContainText('Распознан');
    await page.clock.runFor(2600);
    await expect(page.getByRole('button', { name: 'Повторить распознавание', exact: true })).toBeEnabled();
    assert.equal(polls, 3);
    await page.clock.runFor(6000);
    assert.equal(polls, 3, 'Polling stops after every active request has finished');
  } finally { await browser.close(); }
});
