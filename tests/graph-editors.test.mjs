import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const read=name=>JSON.parse(readFileSync(`resources/registry/${name}`,'utf8'));
const manifest=read('graph-editors.im40.v3.json');
const source=read('graphs.im40.v2.json');
test('explicit editors cover all 54 graphs and 57 source positions',()=>{
  assert.equal(manifest.graphs.length,54);
  assert.equal(new Set(manifest.graphs.map(g=>g.graphId)).size,54);
  const positions=manifest.graphs.flatMap(g=>[g.graphId,...g.childPositions]);
  assert.deepEqual(positions.sort(),source.graphs.map(g=>g.id).sort());
  assert.equal(manifest.graphs.filter(g=>g.editorKind==='inactive').length,14);
  for(const id of ['2','8','9','14']){
    const g=manifest.graphs.find(g=>g.graphId===id);
    assert.equal(g.editorKind,'text');assert.deepEqual(g.visibleInputs,[]);
  }
  for(const id of ['12','45','46','47']){
    const g=manifest.graphs.find(g=>g.graphId===id);
    assert.equal(g.readOnlyWhen,true);assert.deepEqual(g.visibleInputs,[]);
  }
});
test('money mass date and quantities have declared typed controls',()=>{
  const field=id=>manifest.graphs.find(g=>g.graphId===id).visibleInputs;
  assert.deepEqual(field('42'),['goods.invoiceValue']);
  assert.deepEqual(field('35'),['goods.grossMass']);
  assert.deepEqual(field('38'),['goods.netMass']);
  assert.ok(field('41').includes('goods.secondaryAdditionalQuantity'));
  assert.ok(field('54').includes('declaration.signatureDate'));
  assert.ok(!manifest.graphs.flatMap(g=>g.visibleInputs).some(id=>/vatRate|feeBase|feeAdditional/.test(id)));
});
