import {test} from 'node:test';
import assert from 'node:assert/strict';
import {pendingInput,rememberInput,forgetInput} from '../frontend/inputCache.ts';

test('unsaved input is scoped to the active temporary session and survives an editor remount',()=>{
  rememberInput('session-a','graph/31/goods-1','Unsaved text');
  assert.equal(pendingInput('session-a','graph/31/goods-2'),undefined);
  assert.equal(pendingInput('session-a','graph/31/goods-1'),'Unsaved text');
  forgetInput('session-a','graph/31/goods-1');assert.equal(pendingInput('session-a','graph/31/goods-1'),undefined);
  rememberInput('session-a','graph/31/goods-1','Private draft');
  assert.equal(pendingInput('session-b','graph/31/goods-1'),undefined);
});
