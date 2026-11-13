import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';

test('upload page accepts several documents and removes only the selected one', async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 960 } });
    await page.goto('http://127.0.0.1:8080/');
    await page.getByRole('heading', { name: /Подготовьте декларацию/ }).waitFor();
    assert.equal(await page.locator('input[type=file]').getAttribute('multiple'), '');
    await page.screenshot({ path: '.local-tools/upload-desktop.png', fullPage: true });
    const pdf = readFileSync(new URL('../reference/source-documents/example2/авиа.pdf', import.meta.url));
    await page.locator('input[type=file]').setInputFiles([
      { name: 'invoice-1.pdf', mimeType: 'application/pdf', buffer: pdf },
      { name: 'invoice-2.pdf', mimeType: 'application/pdf', buffer: pdf },
    ]);
    await page.getByText('Документов: 2', { exact: true }).waitFor();
    assert.equal(await page.locator('.file-row').count(), 2);
    await page.getByRole('button', { name: 'Удалить invoice-1.pdf', exact: true }).click();
    await page.getByText('Документов: 1', { exact: true }).waitFor();
    assert.equal(await page.locator('.file-name').textContent(), 'invoice-2.pdf');
    assert.equal(await page.getByRole('button', { name: 'Распознать документы', exact: true }).isEnabled(), true);
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    await page.screenshot({ path: '.local-tools/upload-mobile.png', fullPage: true });
  } finally { await browser.close(); }
});
