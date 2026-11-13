import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const base = process.env.CUSTOMS_BASE_URL ?? 'http://127.0.0.1:8080';
async function session() {
  const response = await fetch(`${base}/api/session`, { method: 'POST' });
  assert.equal(response.status, 200);
  const cookie = response.headers.get('set-cookie').split(';')[0];
  return { cookie, state: await response.json() };
}
const a = await session(); const b = await session();
assert.notEqual(a.cookie, b.cookie);
async function upload(owner, csrf) {
  const body = new FormData();
  body.set('revision', '0');
  body.set('file', new Blob([readFileSync(new URL('../reference/source-documents/example2/авиа.pdf', import.meta.url))], { type: 'application/pdf' }), 'AWB.pdf');
  return fetch(`${base}/api/documents`, { method: 'POST', headers: { cookie: owner.cookie, 'X-CSRF-Token': csrf }, body });
}
assert.equal((await upload(a, 'invalid')).status, 403);
const accepted = await upload(a, a.state.csrfToken);
assert.equal(accepted.status, 200);
const updated = await accepted.json();
assert.equal(updated.documents.length, 1);
assert.equal(updated.documents[0].name, 'AWB.pdf');
assert.ok(!('storageName' in updated.documents[0]));
const other = await fetch(`${base}/api/session`, { headers: { cookie: b.cookie } });
assert.equal((await other.json()).documents.length, 0);
const foreignDelete = await fetch(`${base}/api/documents/${updated.documents[0].id}`, { method: 'DELETE', headers: { cookie: b.cookie, 'X-CSRF-Token': b.state.csrfToken, 'Content-Type': 'application/json' }, body: JSON.stringify({ revision: 0 }) });
assert.equal(foreignDelete.status, 404);
for (const path of ['/config/local.php', '/reference/expected-output/S116510021328.xml', '/vendor/autoload.php', '/src/bootstrap.php']) assert.equal((await fetch(base + path)).status, 404);
console.log('HTTP smoke passed: isolated sessions, CSRF, upload, foreign deletion and private paths.');
