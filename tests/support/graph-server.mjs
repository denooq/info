import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {createServer} from 'node:net';
import {mkdtemp,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve,sep} from 'node:path';

export async function graphServer(){
  const root=await mkdtemp(join(tmpdir(),'customs-graph-browser-'));assert.ok(resolve(root).startsWith(resolve(tmpdir())+sep+'customs-graph-browser-'));
  await writeFile(join(root,'clock'),String(Math.floor(Date.now()/1000)));
  const socket=createServer();socket.listen(0,'127.0.0.1');await once(socket,'listening');const port=socket.address().port;await new Promise(r=>socket.close(r));
  const child=spawn('.local-tools/php/php.exe',['-S',`127.0.0.1:${port}`,'tests/support/review-api-router.php'],{env:{...process.env,CUSTOMS_TEST_RUNTIME:root},stdio:['ignore','ignore','pipe']});let stderr='';child.stderr.setEncoding('utf8').on('data',s=>{stderr+=s;});
  const base=`http://127.0.0.1:${port}`;
  let ready=false;for(let n=0;n<60;n++){try{if((await fetch(base+'/api/registry')).ok){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,50));}assert.ok(ready,stderr);
  return {base,root,async stop(){if(child.exitCode===null){child.kill();await once(child,'exit');}await rm(root,{recursive:true,force:true});}};
}
