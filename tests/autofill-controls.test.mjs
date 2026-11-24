import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync,existsSync} from 'node:fs';
test('all 57 source positions have reviewed expectations for both complete corpora',()=>{
  const fixture=JSON.parse(readFileSync('tests/fixtures/autofill-controls.v1.json','utf8'));
  const ids=JSON.parse(readFileSync('resources/registry/graphs.sources.v2.json','utf8')).graphs.map(g=>g.id).sort();
  for(const scenario of Object.values(fixture.cases)){
    assert.deepEqual(scenario.positions.map(p=>p.graphId).sort(),ids);
    assert.equal(new Set(scenario.positions.map(p=>p.graphId)).size,57);
    assert.ok(scenario.inputs.every(existsSync));
    assert.ok(scenario.inputs.every(p=>!scenario.comparisonOnly.includes(p)));
    for(const p of scenario.positions){assert.ok(p.expected);assert.ok(Array.isArray(p.values));assert.ok(p.sources.every(s=>scenario.inputs.includes(s)));if(['found','conflict'].includes(p.expected)){assert.ok(p.sources.length);assert.ok(p.values.length);}}
  }
  assert.equal(fixture.reportedRegression.files.length,4);
  assert.ok(fixture.reportedRegression.forbiddenDocumentNumbers.includes('USD37454.40'));
  assert.doesNotMatch(JSON.stringify(fixture),/csrfToken|mstrl_|Authorization/);
});
