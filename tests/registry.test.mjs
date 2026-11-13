import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const registry = JSON.parse(readFileSync(new URL('../resources/registry/fields.v1.json', import.meta.url)));
const byId = new Map(registry.fields.map(field => [field.id, field]));

test('field registry covers every DT and DTS matrix family with real fields', () => {
  assert.equal(byId.size, registry.fields.length);
  for (const row of [...registry.matrixCoverage, ...registry.dtsMatrixCoverage]) {
    assert.ok(row.fieldIds.length > 0, row.id);
    for (const fieldId of row.fieldIds) assert.ok(byId.has(fieldId), `${row.id}: ${fieldId}`);
  }
  for (const graph of ['1','2','3','4','5','6','7','8','9','11','12','14','15','16','17','18','19','20','21','22','23','24','25','26','29','30','31','32','33','34','35','36','37','38','39','40','41','42','43','44','45','46','47','48','54','B']) {
    assert.ok(registry.fields.some(field => field.dtGraphs.includes(graph)), graph);
  }
});

test('field types, acquisition and units define a stable machine contract', () => {
  const types = ['string','code','identifier','enum','boolean','date','integer','decimal','string-list','code-list','date-list'];
  for (const field of registry.fields) {
    assert.equal(field.id, `${field.scope}.${field.key}`);
    assert.ok(registry.scopes[field.scope]);
    assert.ok(types.includes(field.type), field.id);
    assert.ok(field.label && field.rule && field.acquisition.length);
    assert.ok(!field.label.includes('???'), field.id);
    assert.ok(Object.hasOwn(field, 'requiredWhen'));
    assert.ok(Array.isArray(field.validWhen));
    assert.ok(field.pdf && field.xml);
    if (field.type === 'decimal') assert.ok(field.unit, field.id);
    if (field.acquisition.includes('extract')) assert.ok(field.sourceHints.length, field.id);
  }
});

test('conditional rules only reference declared fields of their scope', () => {
  function check(condition, field) {
    if (typeof condition === 'boolean') return;
    assert.ok(['eq','all','any','not'].includes(condition.op), field.id);
    if (condition.field) assert.ok(byId.has(`${field.scope}.${condition.field}`), `${field.id}: ${condition.field}`);
    for (const arg of condition.args ?? []) check(arg, field);
    if (condition.arg) check(condition.arg, field);
  }
  for (const field of registry.fields) check(field.requiredWhen, field);
  assert.deepEqual(byId.get('declaration.containerNumbers').requiredWhen, {op:'eq',field:'container',value:true});
  assert.equal(byId.get('declaration.relatedParties').type, 'boolean');
});

test('unknown XML bindings are explicit and cannot be mistaken for validated paths', () => {
  for (const field of registry.fields) for (const binding of Object.values(field.xml)) {
    assert.ok(['sample-observed','sample-family','pending-xsd','model-only','rule-only'].includes(binding.status));
    if (binding.status === 'pending-xsd') {
      assert.equal(binding.path, null);
      assert.ok(binding.reason);
    }
  }
  assert.equal(byId.get('goods.deductionValue').xml.dts.status, 'pending-xsd');
  assert.equal(byId.get('declaration.calculationDate').xml.dt.status, 'pending-xsd');
});

test('official registration, release and signature are not fillable fields', () => {
  const forbidden = /^(registrationNumber|releaseStatus|electronicSignature|kedenRegisteredId)$/;
  assert.ok(registry.fields.every(field => !forbidden.test(field.key)));
  assert.equal(byId.get('declaration.kind').fixedValue, 'ИМ');
  assert.equal(byId.get('goods.valuationMethod').fixedValue, '1');
  assert.ok(registry.nonApplicable.some(row => row.graphs.includes('A')));
});
