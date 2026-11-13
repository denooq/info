import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {createServer} from 'node:net';
import {mkdtemp,readFile,readdir,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve,sep} from 'node:path';

test('real HTTP adds review rows, renews activity cookies and retains documents beyond initial expiry',async()=>{
  const root=await mkdtemp(join(tmpdir(),'customs-http-review-'));
  assert.ok(resolve(root).startsWith(resolve(tmpdir())+sep+'customs-http-review-'));
  const initial=Math.floor(Date.now()/1000);await writeFile(join(root,'clock'),String(initial));
  const socket=createServer();socket.listen(0,'127.0.0.1');await once(socket,'listening');const port=socket.address().port;
  await new Promise(resolve=>socket.close(resolve));
  const child=spawn('.local-tools/php/php.exe',['-S',`127.0.0.1:${port}`,'tests/support/review-api-router.php'],{env:{...process.env,CUSTOMS_TEST_RUNTIME:root},stdio:['ignore','ignore','pipe']});
  let stderr='';child.stderr.setEncoding('utf8').on('data',data=>{stderr+=data;});
  const base=`http://127.0.0.1:${port}`;let cookie='';let state;
  async function call(path,method='GET',body,authenticated=true) {
    const response=await fetch(base+path,{method,headers:{...(authenticated?{Cookie:cookie}:{}),'Content-Type':'application/json',...(state?{'X-CSRF-Token':state.csrfToken}:{})},body:body===undefined?undefined:JSON.stringify(body)});
    const payload=await response.json();const header=response.headers.get('set-cookie');if(header)cookie=header.split(';',1)[0];
    return {response,payload,header};
  }
  try {
    let ready=false;
    for(let n=0;n<60;n++) {
      try{const response=await fetch(base+'/api/registry');if(response.ok){ready=true;break;}}catch{/* Server starting. */}
      await new Promise(r=>setTimeout(r,50));
    }
    assert.ok(ready,stderr);
    let result=await call('/api/session','POST');assert.equal(result.response.status,200);state=result.payload;
    assert.match(result.header,/HttpOnly/i);assert.match(result.header,/SameSite=Strict/i);assert.match(result.header,/path=\//i);
    result=await call('/fixture/seed','POST');assert.equal(result.response.status,200);state=result.payload;
    const sessionRoot=join(root,'sessions',(await readdir(join(root,'sessions')))[0]);
    const document=join(sessionRoot,'documents','a'.repeat(32)+'.pdf');const ocr=join(sessionRoot,'ocr','source.json');
    const originalDocument=await readFile(document);const originalOcr=await readFile(ocr);
    assert.equal(state.expiresAt,initial+120);
    await writeFile(join(root,'clock'),String(initial+110));
    result=await call('/api/session');assert.equal(result.payload.expiresAt,initial+120,'Polling must not renew server TTL');
    result=await call('/api/draft','PATCH',{revision:state.dataRevision,changes:[{fieldId:'party.name',entityId:'party-seller',value:'Ручная правка без перезагрузки'}]});
    assert.equal(result.response.status,200,JSON.stringify(result.payload));state=result.payload;
    assert.equal(state.expiresAt,initial+230);assert.equal(state.draft.parties[0].name,'Ручная правка без перезагрузки');
    const expires=/expires=([^;]+)/i.exec(result.header);assert.ok(expires);assert.equal(Date.parse(expires[1])/1000,state.expiresAt);
    await writeFile(join(root,'clock'),String(initial+130));
    result=await call('/api/session');assert.equal(result.response.status,200,'The original two-minute deadline must not end an active session');state=result.payload;
    for(const [scope,collection] of [['rate','rates'],['cost','costs'],['payment','payments']]) {
      const count=state.draft[collection].length;
      result=await call('/api/entities','POST',{revision:state.dataRevision,scope,action:'add'});
      assert.equal(result.response.status,200,JSON.stringify(result.payload));state=result.payload;
      assert.equal(state.draft[collection].length,count+1);assert.equal(state.documents.length,1);
    }
    assert.deepEqual(await readFile(document),originalDocument);assert.deepEqual(await readFile(ocr),originalOcr);
    assert.equal((await readdir(join(root,'sessions'))).length,1);
    const expiry=state.expiresAt;await writeFile(join(root,'clock'),String(expiry+1));
    result=await call('/api/session');assert.equal(result.response.status,410);assert.equal(result.payload.error.code,'SESSION_EXPIRED');
    result=await call('/api/session','GET',undefined,false);assert.equal(result.response.status,401);assert.equal(result.payload.error.code,'SESSION_REQUIRED');
    assert.equal((await readdir(join(root,'sessions'))).length,1,'An authentication error must not create a new session');
  }finally {
    if(child.exitCode===null){child.kill();await once(child,'exit');}
    await rm(root,{recursive:true,force:true});
  }
});
