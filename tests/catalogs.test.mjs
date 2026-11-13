import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = name => JSON.parse(readFileSync(new URL(`../resources/registry/${name}.v1.json`, import.meta.url)));
const catalogs = read('catalogs');
const profile = read('calculation-profile');
const registry = read('fields');

test('partial catalogs carry provenance and resolve only declared field ids', () => {
  const ids = new Set(registry.fields.map(f => f.id));
  for (const [field, catalog] of Object.entries(catalogs.fieldCatalogs)) {
    assert.ok(ids.has(field), field);
    assert.ok(catalogs.lists[catalog], catalog);
  }
  for (const list of Object.values(catalogs.lists)) {
    assert.equal(new Set(list.map(e => e.code)).size, list.length);
    for (const entry of list) assert.ok(entry.source && entry.status && entry.label);
  }
  assert.equal(catalogs.autoAcceptUnknownCodes, false);
});

test('new declarations cannot inherit historical rates or silently assume nominal', () => {
  assert.equal(profile.parameterPolicy.carrySampleRatesToNewDraft, false);
  assert.ok(Object.values(profile.parameterPolicy.defaults).every(value => value === null));
  assert.ok(catalogs.lists.currencies.every(entry => entry.quotationNominal === null));
  assert.equal(profile.exchangeRatePolicy.requireConfirmed, true);
  assert.equal(profile.paymentFacts.deriveFromAccrual, false);
});

test('payment and rounding profiles separate accrual and paid facts', () => {
  assert.deepEqual(profile.formulas.map(f => f.code), ['1010','2010','5060']);
  assert.equal(profile.rounding.moneyScale, 2);
  assert.equal(profile.rounding.paymentFactScale, 0);
  assert.ok(profile.formulas.every(f => f.source));
  assert.ok(profile.unsupported.includes('excise'));
  assert.ok(registry.fields.find(f => f.id === 'goods.dutyRate').requiredWhen);
});
