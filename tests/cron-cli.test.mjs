import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtemp,mkdir,writeFile,readdir,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve,sep} from 'node:path';

test('quiet cron still runs cleanup, emits no success mail content and preserves failure diagnostics',async()=>{
  const root=await mkdtemp(join(tmpdir(),'customs-cron-cli-'));assert.ok(resolve(root).startsWith(resolve(tmpdir())+sep+'customs-cron-cli-'));
  const runtime=join(root,'runtime');const config=join(root,'config.php');
  const writeConfig=async values=>writeFile(config,`<?php return json_decode(base64_decode('${Buffer.from(JSON.stringify(values)).toString('base64')}'),true);`);
  const run=args=>spawnSync(resolve('.local-tools/php/php.exe'),args,{env:{...process.env,CUSTOMS_CONFIG_FILE:config,CUSTOMS_RUNTIME_DIR:runtime,MISTRAL_API_KEY:''},encoding:'utf8',windowsHide:true});
  try {
    await writeConfig({runtime_dir:runtime});
    for(const script of ['bin/worker.php','bin/cleanup.php']) {
      const quiet=run(['-f',script,'--','--quiet']);assert.equal(quiet.status,0,quiet.stderr);assert.equal(quiet.stdout,'');assert.equal(quiet.stderr,'');
      const diagnostic=run([script]);assert.equal(diagnostic.status,0,diagnostic.stderr);assert.equal(typeof JSON.parse(diagnostic.stdout),'object');
    }
    const expired='a'.repeat(64);const active='b'.repeat(64);
    for(const [id,expiresAt] of [[expired,Math.floor(Date.now()/1000)-60],[active,Math.floor(Date.now()/1000)+3600]]) {
      const dir=join(runtime,'sessions',id);await mkdir(dir);await writeFile(join(dir,'state.json'),JSON.stringify({expiresAt}));await writeFile(join(dir,'document.pdf'),'private fixture');
    }
    const cleanup=run(['-f','bin/cleanup.php','--','--quiet']);assert.equal(cleanup.status,0,cleanup.stderr);assert.equal(cleanup.stdout,'');
    assert.deepEqual(await readdir(join(runtime,'sessions')),[active],'Quiet must delete only the expired session');
    await writeConfig({runtime_dir:runtime,session_ttl:0});
    for(const script of ['bin/worker.php','bin/cleanup.php']) {
      const failure=run([script,'--quiet']);assert.equal(failure.status,1);assert.equal(failure.stdout,'');assert.match(failure.stderr,/failed; verify private/i);
    }
  }finally{await rm(root,{recursive:true,force:true});}
});
