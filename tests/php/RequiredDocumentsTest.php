<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

use Customs\Domain\{Draft,Entities,GraphCodecs,GraphEdits,Registry,ReviewValidator};

test('graph 44 creates required records without uploaded files and preserves identities on successive edits',function():void {
    $s=completeDraftState();$s['draft']['documents']=[];$s['draft']['declaration']['valuationDocumentIds']=[];
    $edit=new GraphEdits();$text='04021 | INV-42 | 2026-10-28 | 0';
    $s=$edit->edit($s,'44','goods-1',$text);same(null,$s['draft']['graphTexts']['graph/44/goods-1']['error']);
    same(1,count($s['draft']['documents']));$document=$s['draft']['documents'][0];
    same('04021',$document['kind']);same('INV-42',$document['number']);same(true,$document['dateRequired']);
    same('Счет-фактура (инвойс) к договору',$document['name']);same(['goods-1'],$document['goodsIds']);same([],$document['documentIds']);
    $s=$edit->edit($s,'44','goods-1',$text."\n03011 | CON-1 | 2026-10-01 | 0");
    same(2,count($s['draft']['documents']));same($document['id'],$s['draft']['documents'][0]['id']);
    same('manual',$s['draft']['fieldStates'][Draft::address('document',$document['id'],'number')]['status']);
    same([],array_values(array_filter((new ReviewValidator())->validate($s)['errors'],fn($e)=>$e['code']==='DOCUMENT_REQUIRED')));
});

test('graph 44 record edits address one of several invoices and new records stay linked to one uploaded file',function():void {
    $s=completeDraftState(1);$first=$s['draft']['documents'][0];$second=array_replace($first,['id'=>'invoice-second','number'=>'SECOND','documentIds'=>['file-b']]);$s['draft']['documents'][0]['documentIds']=['file-a'];$s['draft']['documents'][]=$second;
    $s['documents']=['file-a'=>['id'=>'file-a','name'=>'invoice-a.pdf','documentKind'=>'04021'],'file-b'=>['id'=>'file-b','name'=>'invoice-b.pdf','documentKind'=>'04021'],'file-c'=>['id'=>'file-c','name'=>'invoice-c.pdf','documentKind'=>'04021']];
    $entities=new Entities(new Registry());$s=$entities->change($s,['scope'=>'document','action'=>'record','kind'=>'04021','recordId'=>$second['id'],'fieldId'=>'document.number','value'=>'SECOND-EDITED']);
    $records=array_column($s['draft']['documents'],null,'id');same($first['number'],$records[$first['id']]['number']);same('SECOND-EDITED',$records[$second['id']]['number']);
    $s=$entities->change($s,['scope'=>'document','action'=>'record','kind'=>'04021','fileId'=>'file-c','fieldId'=>'document.number','value'=>'THIRD']);
    $third=array_values(array_filter($s['draft']['documents'],fn($row)=>in_array('file-c',$row['documentIds']??[],true)))[0];same('THIRD',$third['number']);same(['file-c'],$third['documentIds']);
    errorCode('DOCUMENT_RECORD_UNKNOWN',fn()=>$entities->change($s,['scope'=>'document','action'=>'record','kind'=>'04021','recordId'=>'invoice-missing','fieldId'=>'document.number','value'=>'WRONG']));
});

test('editing graph 44 retains unchanged sources links and other legal records of the same kind',function():void {
    $s=completeDraftState(2);$invoice=$s['draft']['documents'][0];$address=Draft::address('document',$invoice['id'],'date');
    $s['draft']['fieldStates'][$address]=['status'=>'auto','candidateId'=>'candidate-date','evidenceIds'=>['source-date']];
    $s['draft']['documents'][]=array_replace($invoice,['id'=>'second-invoice','number'=>'INV-SECOND']);
    $text=GraphCodecs::documentText($s['draft']['documents'],'goods-1');
    $s=(new GraphEdits())->edit($s,'44','goods-1',str_replace($invoice['number'],'INV-EDITED',$text));
    same(null,$s['draft']['graphTexts']['graph/44/goods-1']['error']);same(3,count($s['draft']['documents']));
    $records=array_column($s['draft']['documents'],null,'id');same('INV-EDITED',$records[$invoice['id']]['number']);
    same($invoice['goodsIds'],$records[$invoice['id']]['goodsIds']);same('INV-SECOND',$records['second-invoice']['number']);same(['source-date'],$s['draft']['fieldStates'][$address]['evidenceIds']);
});

test('invalid graph 44 input remains visible without persisting partial records and legacy hidden patches are rejected',function():void {
    $store=sessionFixture();$s=$store->create()['state'];$before=$s['draft']['documents'];$edit=new GraphEdits();
    foreach(['99999 | INV | 2026-10-28 | 0','04021 | INV | 2026-02-30 | 0','04021 | INV | 2026-10-28 | 9'] as $text){
        $changed=$store->mutate($s['id'],$s['dataRevision'],fn($state)=>$edit->edit($state,'44','goods-1',$text));
        same($before,$changed['draft']['documents']);same($text,$changed['draft']['graphTexts']['graph/44/goods-1']['value']);
        truth($changed['draft']['graphTexts']['graph/44/goods-1']['error']!==null);$s=$changed;
    }
    $complete=completeDraftState();errorCode('GRAPH_EDIT_REQUIRED',fn()=>(new Draft(new Registry()))->edit($complete,'document.number',$complete['draft']['documents'][0]['id'],'INV'));
    errorCode('GRAPH_EDIT_REQUIRED',fn()=>$store->mutate($s['id'],$s['dataRevision'],fn($state)=>(new Entities(new Registry()))->change($state,['scope'=>'document','action'=>'required','kind'=>'04021','fieldId'=>'document.number','value'=>'INV'])));
    same($before,$store->read($s['id'])['draft']['documents']);same($s['dataRevision'],$store->read($s['id'])['dataRevision']);
});
