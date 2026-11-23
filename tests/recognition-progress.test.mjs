import {test} from 'node:test';
import assert from 'node:assert/strict';
import {recognitionKey,recognitionProgress} from '../frontend/recognition.ts';

test('recognition progress counts whole successful files separately from failures and mapping jobs',()=>{
  const s={csrfToken:'session-a',createdAt:1,phase:'recognizing',documents:[{id:'a',status:'recognized',pages:1},{id:'b',status:'error',pages:1},{id:'c',status:'recognizing',pages:12}],jobs:[{kind:'ocr',documentId:'c',status:'done'},{kind:'ocr',documentId:'c',status:'running'}]};
  assert.deepEqual(recognitionProgress(s),{active:true,total:3,recognized:1,failed:1,mapping:false,terminal:false});
  s.documents[2].status='recognized';s.jobs=[{kind:'mapping',status:'running'}];
  assert.deepEqual(recognitionProgress(s),{active:true,total:3,recognized:2,failed:1,mapping:true,terminal:false});
  s.phase='reviewing';s.jobs[0].status='failed';assert.equal(recognitionProgress(s).terminal,true);
});

test('batch identity survives job and revision progress but changes with session or selected file type',()=>{
  const s={csrfToken:'session-a',createdAt:1,dataRevision:2,documents:[{id:'b',documentKind:'02015'},{id:'a',documentKind:'04021'}]};
  const key=recognitionKey(s);s.documents.reverse();s.dataRevision++;assert.equal(recognitionKey(s),key);
  assert.notEqual(recognitionKey({...s,csrfToken:'session-b'}),key);
  s.documents[0].documentKind='04131';assert.notEqual(recognitionKey(s),key);
});
