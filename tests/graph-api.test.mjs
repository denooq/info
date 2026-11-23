import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {createServer} from 'node:net';
import {mkdtemp,readFile,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve,sep} from 'node:path';

test('HTTP official selector groups multiple files, rejects invented codes and assigns legacy files without OCR',async()=>{
  const root=await mkdtemp(join(tmpdir(),'customs-graph-http-'));assert.ok(resolve(root).startsWith(resolve(tmpdir())+sep+'customs-graph-http-'));
  await writeFile(join(root,'clock'),String(Math.floor(Date.now()/1000)));
  const socket=createServer();socket.listen(0,'127.0.0.1');await once(socket,'listening');const port=socket.address().port;await new Promise(r=>socket.close(r));
  const child=spawn('.local-tools/php/php.exe',['-S',`127.0.0.1:${port}`,'tests/support/review-api-router.php'],{env:{...process.env,CUSTOMS_TEST_RUNTIME:root},stdio:['ignore','ignore','pipe']});
  let stderr='';child.stderr.setEncoding('utf8').on('data',s=>{stderr+=s;});const base=`http://127.0.0.1:${port}`;let cookie='',state;
  async function call(path,method='GET',body){
    const form=body instanceof FormData;const response=await fetch(base+path,{method,headers:{Cookie:cookie,...(state?{'X-CSRF-Token':state.csrfToken}:{}),...(!form?{'Content-Type':'application/json'}:{})},body:body===undefined?undefined:form?body:JSON.stringify(body)});
    const payload=await response.json();if(response.headers.has('set-cookie'))cookie=response.headers.get('set-cookie').split(';')[0];return{response,payload};
  }
  try{
    let ready=false;for(let n=0;n<60;n++){try{if((await fetch(base+'/api/registry')).ok){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,50));}assert.ok(ready,stderr);
    let r=await call('/api/classifiers/documents?q=04021');assert.equal(r.payload.items[0].code,'04021');const version=r.payload.version;
    r=await call('/api/session','POST');state=r.payload;
    r=await call('/api/upload-groups','POST',{revision:state.dataRevision,code:'CMR',version});assert.equal(r.response.status,422);
    r=await call('/api/upload-groups','POST',{revision:state.dataRevision,code:'04021',version});assert.equal(r.response.status,200);state=r.payload;assert.equal(state.draft.documents.length,0);
    const bytes=await readFile('reference/source-documents/example2/авиа.pdf');
    for(let i=0;i<2;i++){
      const body=new FormData();body.set('revision',String(state.dataRevision));body.set('groupId','kind-04021');body.set('file',new Blob([bytes],{type:'application/pdf'}),`sample${i}.pdf`);
      r=await call('/api/documents','POST',body);assert.equal(r.response.status,200,JSON.stringify(r.payload));state=r.payload;
    }
    assert.equal(state.documents.length,2);assert.ok(state.documents.every(d=>d.documentKind==='04021'));assert.equal(state.draft.documents.length,0);
    const ids=state.documents.map(d=>d.id);
    r=await call('/api/upload-groups','POST',{revision:state.dataRevision,code:'04021',version});state=r.payload;assert.deepEqual(state.documents.map(d=>d.id),ids);
    r=await call('/api/upload-groups','POST',{revision:state.dataRevision,code:'02017',version});state=r.payload;
    r=await call(`/api/documents/${ids[0]}/type`,'PATCH',{revision:state.dataRevision,groupId:'kind-02017'});assert.equal(r.response.status,200);state=r.payload;assert.equal(state.documents.find(d=>d.id===ids[0]).documentKind,'02017');assert.equal(state.jobs.length,0);
    r=await call(`/api/documents/${ids[1]}`,'DELETE',{revision:state.dataRevision});assert.equal(r.response.status,200);state=r.payload;assert.equal(state.documents.length,1);
    r=await call('/api/upload-groups','POST',{revision:state.dataRevision,id:'kind-04021',action:'remove'});assert.equal(r.response.status,200);assert.equal(r.payload.uploadGroups.length,1);
    assert.equal(r.payload.graphProjection.graphs.length,57);
    state=r.payload;
    r=await call('/api/upload-groups','POST',{revision:state.dataRevision-1,id:'kind-02017',action:'remove'});assert.equal(r.response.status,409);
    r=await call('/api/session');assert.equal(r.payload.documents.length,1);state=r.payload;
    r=await call('/api/upload-groups','POST',{revision:state.dataRevision,id:'kind-02017',action:'remove'});assert.equal(r.response.status,200);assert.equal(r.payload.documents.length,0);assert.equal(r.payload.uploadGroups.length,0);assert.equal(r.payload.dataRevision,state.dataRevision+1);
  }finally{if(child.exitCode===null){child.kill();await once(child,'exit');}await rm(root,{recursive:true,force:true});}
});
