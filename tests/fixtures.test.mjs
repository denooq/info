import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
const read = file => JSON.parse(readFileSync(new URL(`./fixtures/${file}.json`, import.meta.url)));
const projectFile = file => new URL(`../${file}`, import.meta.url);

test('reference inputs exist and never include registered output documents', () => {
  for (const name of ['reference-cpt','reference-fca']) {
    const fixture = read(name);
    for (const file of [...fixture.inputs, ...fixture.comparisonOnly]) assert.ok(existsSync(projectFile(file)), file);
    assert.ok(fixture.comparisonOnly.every(file => !fixture.inputs.includes(file)));
    assert.ok(fixture.inputs.every(file => !file.includes('expected-output') && !file.includes('Выпуск')));
    for (const fact of fixture.facts) {
      assert.ok(fixture.inputs.includes(fact.evidence.file));
      assert.ok(fact.evidence.page || (fact.evidence.sheet && fact.evidence.cell));
    }
    assert.equal(fixture.calculationScenario.testOnly, true);
  }
});

test('known contradictions and shared packaging remain explicit test cases', () => {
  const first = read('reference-cpt');
  assert.deepEqual(first.facts.filter(f => f.conflictGroup === 'gross').map(f => f.value), ['26792','27860']);
  assert.ok(first.discrepancies.some(d => d.id === 'letter-presentation' && d.pdf !== d.xml));
  const second = read('reference-fca');
  assert.ok(second.discrepancies.some(d => d.id === 'quantity' && d.value === '2975'));
  const multi = read('multi-goods');
  assert.equal(multi.cases[0].expected.packageCount, 1);
  assert.equal(multi.cases[1].expected.dtSheetCount, 3);
  assert.equal(new Set(multi.cases[1].goods.flatMap(g => g.sourceLineIds)).size, 5);
});
