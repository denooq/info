import {test} from 'node:test';
import assert from 'node:assert/strict';
import {graphServer} from './support/graph-server.mjs';

test('HTTP gates all input graphs, opens calculation parameters separately and never publishes exports',async()=>{
  const server=await graphServer();let cookie='',state;
  async function call(path,method='GET',body){const response=await fetch(server.base+path,{method,headers:{Cookie:cookie,'Content-Type':'application/json',...(state?{'X-CSRF-Token':state.csrfToken}:{})},body:body===undefined?undefined:JSON.stringify(body)});const payload=await response.json();if(response.headers.has('set-cookie'))cookie=response.headers.get('set-cookie').split(';')[0];if(response.ok&&payload.draft)state=payload;return{response,payload};}
  async function patch(changes){const r=await call('/api/draft','PATCH',{revision:state.dataRevision,changes});assert.equal(r.response.status,200,JSON.stringify(r.payload));return r;}
  try{
    await call('/api/session','POST');await call('/fixture/seed','POST',{goodsCount:2});assert.equal(state.reviewValidation.valid,true);
    await patch([{fieldId:'declaration.methodOneConfirmed',value:null}]);assert.equal(state.reviewValidation.valid,true);assert.equal(state.calculationValidation.valid,false);
    let r=await call('/api/review/complete','POST',{revision:state.dataRevision});assert.equal(r.response.status,200);assert.equal(state.phase,'calculation');assert.equal(state.calculation,null);
    r=await call('/api/calculate','POST',{revision:state.dataRevision});assert.equal(r.response.status,422);assert.equal(r.payload.error.code,'DECLARATION_INVALID');
    await patch([{fieldId:'declaration.methodOneConfirmed',value:true},{fieldId:'party.name',entityId:'party-consignee',value:null}]);
    for(const path of ['/api/review/complete','/api/calculate']){r=await call(path,'POST',{revision:state.dataRevision});assert.equal(r.response.status,422);assert.ok(r.payload.error.details.errors.some(e=>e.graphId==='8'));}
    await patch([{fieldId:'party.name',entityId:'party-consignee',value:'Получатель'}]);r=await call('/api/calculate','POST',{revision:state.dataRevision});assert.equal(r.response.status,200,JSON.stringify(r.payload));
    const oldRevision=state.dataRevision;assert.equal(state.calculation.dataRevision,oldRevision);const graph12=state.graphProjection.graphs.find(g=>g.id==='12');assert.equal(graph12.entries[0].status,'calculated');assert.equal(graph12.entries[0].value,state.calculation.totals.customsValue);
    r=await call('/api/calculation','PATCH',{revision:state.dataRevision,entityId:'goods-1',key:'customsValue',value:'1'});assert.equal(r.response.status,200);assert.equal(state.calculation.consistent,false);
    await patch([{fieldId:'goods.invoiceValue',entityId:'goods-1',value:'40000'}]);assert.equal(state.calculation,null);assert.equal(state.graphProjection.graphs.find(g=>g.id==='45').entries[0].status,'pending_calculation');
    r=await call('/api/calculation','PATCH',{revision:oldRevision,entityId:'goods-1',key:'customsValue',value:'1'});assert.equal(r.response.status,409);
    r=await call('/api/calculate','POST',{revision:state.dataRevision});assert.equal(r.response.status,200);assert.equal(state.calculation.consistent,true);
    for(const path of ['/api/confirm','/api/create','/api/generate']){r=await call(path,'POST',{revision:state.dataRevision});assert.equal(r.response.status,409);assert.equal(r.payload.error.code,'EXPORT_DISABLED');}
    for(const path of ['/api/download/pdf','/api/download/xml']){r=await call(path);assert.equal(r.response.status,409);assert.equal(r.payload.error.code,'EXPORT_DISABLED');}
    r=await call('/api/session');assert.equal(state.ready,false);assert.ok(!state.jobs.some(j=>j.kind==='generate'));assert.equal(state.capabilities.export,false);
  }finally{await server.stop();}
});
