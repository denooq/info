import {test} from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFile} from 'node:fs/promises';
import {chromium,expect} from '@playwright/test';
import {resolve,sep} from 'node:path';

test('mapping failure is distinct from missing fields and retry reuses OCR without creating OCR jobs',async()=>{
  const fixture=JSON.parse(execFileSync('.local-tools/php/php.exe',['tests/support/create-review-session.php'],{encoding:'utf8'}));
  const session=fixture.state;
  session.phase='reviewing';
  session.documents=[{id:'file-invoice',name:'инвойс.pdf',mime:'application/pdf',size:1024,pages:1,status:'recognized',documentKind:'04021'}];
  session.error={code:'MAPPING_CONTEXT_LIMIT',message:'Полный запрос сопоставления превышает безопасный лимит контекста.',details:{measuredBytes:194000,limitBytes:180000}};
  session.mappingWindowProgress={completed:0};
  session.jobs=session.documents.map(document=>({id:`ocr-${document.id}`,kind:'ocr',documentId:document.id,status:'done'}));
  session.jobs.push({id:'mapping-failed',kind:'mapping',status:'failed',error:session.error});
  const fields=JSON.parse(await readFile('resources/registry/fields.v1.json','utf8'));
  const catalogs=JSON.parse(await readFile('resources/registry/catalogs.v1.json','utf8'));
  const graphs=JSON.parse(await readFile('resources/registry/graphs.im40.v2.json','utf8'));
  const editors=JSON.parse(await readFile('resources/registry/graph-editors.im40.v3.json','utf8'));
  const browser=await chromium.launch({channel:'msedge',headless:true,timeout:10000});
  let retryBody=null;let pollAfterRetry=0;
  try{
    const page=await browser.newPage({viewport:{width:1440,height:960}});
    await page.route('http://mapping-retry.test/**',async route=>{
      const url=new URL(route.request().url());
      if(url.pathname==='/api/registry')return route.fulfill({json:{fields,catalogs,graphs,editors}});
      if(url.pathname==='/api/session'){
        if(session.phase==='recognizing'){
          pollAfterRetry++;
          if(pollAfterRetry>1){session.phase='reviewing';session.error=null;session.jobs=session.jobs.map(job=>job.kind==='mapping'?{...job,status:'done',error:null}:job);session.mappingWindowProgress={completed:3,calls:3,inputBytes:420000};}
        }
        return route.fulfill({json:session});
      }
      if(url.pathname==='/api/recognize'&&route.request().method()==='POST'){
        retryBody=route.request().postDataJSON();
        session.phase='recognizing';session.error=null;session.mappingWindowProgress={completed:0};
        session.jobs=session.jobs.filter(job=>job.kind==='ocr');
        session.jobs.push({id:'mapping-retry',kind:'mapping',status:'queued',error:null});
        return route.fulfill({json:session});
      }
      if(url.pathname==='/api/rates')return route.fulfill({json:session});
      const root=resolve('public/assets-app');
      const path=url.pathname==='/'?resolve(root,'index.html'):resolve(root,'.'+url.pathname.replace(/^\/assets-app/,''));
      assert.ok(path.startsWith(root+sep));
      return route.fulfill({contentType:path.endsWith('.js')?'application/javascript':path.endsWith('.css')?'text/css':'text/html',body:await readFile(path)});
    });
    await page.goto('http://mapping-retry.test/');
    await page.getByRole('heading',{name:'Проверьте данные декларации',exact:true}).waitFor();
    await expect(page.locator('.mapping-failure')).toContainText('Сопоставление документов не завершилось');
    await expect(page.locator('.mapping-failure')).toContainText(/194.?000 байт/);
    await page.getByRole('button',{name:'Повторить сопоставление',exact:true}).click();
    await expect(page.locator('.mapping-progress')).toContainText('Сопоставляем распознанные документы');
    assert.deepEqual(retryBody,{retry:true,mappingOnly:true});
    await expect(page.locator('.mapping-progress')).toHaveCount(0,{timeout:8000});
    await expect(page.getByRole('heading',{name:'Проверьте данные декларации',exact:true})).toBeVisible();
    assert.equal(session.jobs.filter(job=>job.kind==='ocr').length,session.documents.length,'Retry must keep existing OCR jobs without adding new ones.');
    assert.equal(session.jobs.filter(job=>job.kind==='mapping').at(-1).status,'done');
  }finally{await browser.close();}
});
