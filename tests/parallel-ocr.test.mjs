import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { mkdtemp, writeFile, readdir, rm, access } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { once } from 'node:events';

test('twenty OCR uploads overlap and isolate HTTP, network and oversized-response errors', { timeout: 20000 }, async t => {
  const php = process.env.TEST_PHP_BIN ?? resolve('.local-tools/php/php.exe');
  try { await access(php); } catch { t.skip('Set TEST_PHP_BIN to PHP with ext-curl'); return; }
  const runtime = await mkdtemp(join(tmpdir(), 'customs-parallel-'));
  const bytes = Buffer.alloc(2 * 1024 * 1024 + 1);
  for (let i = 0; i < bytes.length; i++) bytes[i] = i % 256;
  const path = join(runtime, 'document.bin');
  await writeFile(path, bytes);
  const waiting = []; const failures = []; const replies = [];
  let firstFinished = false; let allArrivedBeforeFirstFinished = false;
  const server = createServer(async (request, response) => {
    try {
      assert.equal(request.method, 'POST');
      const chunks = [];
      for await (const chunk of request) chunks.push(chunk);
      const body = JSON.parse(Buffer.concat(chunks));
      assert.equal(body.document.document_url, `data:application/pdf;base64,${bytes.toString('base64')}`);
      waiting.push({ index: body.pages[0], response });
      if (waiting.length === 20) {
        allArrivedBeforeFirstFinished = !firstFinished;
        for (const item of waiting) setTimeout(() => {
          firstFinished = true;
          if (item.index === 9) { item.response.destroy(); return; }
          const status = item.index === 7 ? 429 : 200;
          item.response.writeHead(status, { 'Content-Type': 'application/json', 'Retry-After': '30' });
          item.response.end(item.index === 8 ? Buffer.alloc(16_777_217, 0x20) : JSON.stringify({ pages: [{ index: item.index, markdown: 'Offline OCR' }] }));
        }, item.index === 0 ? 700 : 20);
      }
    } catch (error) { failures.push(error); response.writeHead(500).end('{}'); }
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  const child = spawn(php, ['tests/support/parallel-ocr.php', `http://127.0.0.1:${server.address().port}/ocr`, runtime, path], { windowsHide: true });
  let stdout = ''; let stderr = '';
  child.stdout.setEncoding('utf8').on('data', chunk => {
    stdout += chunk;
    for (const line of chunk.trim().split(/\r?\n/)) {
      try { const item = JSON.parse(line); if (item.id) replies.push(item.id); } catch { /* A split line is checked after exit. */ }
    }
  });
  child.stderr.setEncoding('utf8').on('data', chunk => { stderr += chunk; });
  const timer = setTimeout(() => child.kill(), 12000);
  try {
    const [code] = await once(child, 'exit');
    assert.equal(code, 0, stderr);
    assert.deepEqual(failures, []);
    assert.equal(waiting.length, 20);
    assert.equal(allArrivedBeforeFirstFinished, true, 'Every file must start before the first response');
    const lines = stdout.trim().split(/\r?\n/).map(line => JSON.parse(line));
    const results = lines.filter(line => line.id);
    assert.equal(results.length, 20);
    assert.equal(results.at(-1).id, 'document-0', 'The slow first upload must not hold back other completions');
    assert.equal(results.filter(result => result.status === 200).length, 17);
    assert.equal(results.find(result => result.id === 'document-7').status, 429);
    assert.equal(results.find(result => result.id === 'document-7').retryAfter, '30');
    assert.match(results.find(result => result.id === 'document-8').error, /безопасный объём/);
    assert.match(results.find(result => result.id === 'document-9').error, /Нет ответа Mistral/);
    assert.equal(results.filter(result => result.error !== null).length, 2);
    assert.ok(replies.length > 0, 'Completions are emitted before process exit');
    assert.ok(lines.at(-1).peakMemory < 32 * 1024 * 1024, 'Uploads must be streamed');
    t.diagnostic(`Transport peak memory for twenty 2 MiB uploads: ${lines.at(-1).peakMemory / 1024 / 1024} MiB`);
    assert.deepEqual((await readdir(runtime)).sort(), ['document.bin'], 'Transfer files must be removed');
  } finally {
    clearTimeout(timer); child.kill(); server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
    await rm(runtime, { recursive: true, force: true });
  }
});

test('parallel response storage respects the shared runtime quota and removes partial transfers', { timeout: 15000 }, async t => {
  const php = process.env.TEST_PHP_BIN ?? resolve('.local-tools/php/php.exe');
  try { await access(php); } catch { t.skip('Set TEST_PHP_BIN to PHP with ext-curl'); return; }
  const runtime = await mkdtemp(join(tmpdir(), 'customs-parallel-quota-'));
  const path = join(runtime, 'document.bin');
  await writeFile(path, Buffer.alloc(1024));
  const server = createServer(async (request, response) => {
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    const index = JSON.parse(Buffer.concat(chunks)).pages[0];
    setTimeout(() => {
      response.writeHead(200, { 'Content-Type': 'application/json' });
      response.end(index === 0 ? Buffer.alloc(131072, 0x20) : '{}');
    }, index === 0 ? 5 : 150);
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  const child = spawn(php, ['tests/support/parallel-ocr.php', `http://127.0.0.1:${server.address().port}/ocr`, runtime, path, '65536'], { windowsHide: true });
  let stdout = ''; let stderr = '';
  child.stdout.setEncoding('utf8').on('data', chunk => { stdout += chunk; });
  child.stderr.setEncoding('utf8').on('data', chunk => { stderr += chunk; });
  const timer = setTimeout(() => child.kill(), 10000);
  try {
    const [code] = await once(child, 'exit');
    assert.equal(code, 0, stderr);
    const results = stdout.trim().split(/\r?\n/).map(line => JSON.parse(line)).filter(line => line.id);
    assert.equal(results.length, 20);
    assert.match(results.find(result => result.id === 'document-0').error, /Недостаточно места/);
    assert.equal(results.filter(result => result.status === 200).length, 19);
    assert.deepEqual(await readdir(runtime), ['document.bin']);
  } finally {
    clearTimeout(timer); child.kill(); server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
    await rm(runtime, { recursive: true, force: true });
  }
});
