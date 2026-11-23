<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

use Customs\Domain\{Draft,Entities,Registry,Validator};

test('required document edits create one record and successive edits reuse it without inventing facts',function():void {
    $s=completeDraftState();$s['draft']['documents']=[];$s['draft']['declaration']['valuationDocumentIds']=[];
    $entities=new Entities(new Registry());
    $edit=['scope'=>'document','action'=>'required','kind'=>'04021','fieldId'=>'document.number','value'=>'INV-42'];
    $s=$entities->change($s,$edit);same(1,count($s['draft']['documents']));$document=$s['draft']['documents'][0];
    same('04021',$document['kind']);same('INV-42',$document['number']);same(true,$document['dateRequired']);
    same('Счет-фактура (инвойс) к договору',$document['name']);same(null,$document['date']);same(null,$document['presentationCode']);same(['goods-1'],$document['goodsIds']);same([],$document['documentIds']);
    $s=$entities->change($s,array_replace($edit,['fieldId'=>'document.date','value'=>'2026-10-28']));
    same(1,count($s['draft']['documents']));same($document['id'],$s['draft']['documents'][0]['id']);same('2026-10-28',$s['draft']['documents'][0]['date']);
    same('manual',$s['draft']['fieldStates'][Draft::address('document',$document['id'],'number')]['status']);
    $errors=(new Validator(new Registry()))->validate($s)['errors'];truth((bool)array_filter($errors,fn($e)=>$e['code']==='REQUIRED'&&$e['address']==='document/'.$document['id'].'/presentationCode'));
    truth(count(array_filter($errors,fn($e)=>$e['code']==='DOCUMENT_REQUIRED' && str_contains($e['message'],'03011')))===1);
});

test('late required-document edits reuse an existing recognized record and retain its sources and links',function():void {
    $s=completeDraftState(2);$invoice=$s['draft']['documents'][0];$address=Draft::address('document',$invoice['id'],'date');
    $s['draft']['fieldStates'][$address]=['status'=>'auto','candidateId'=>'candidate-date','evidenceIds'=>['source-date']];
    $s['draft']['documents'][]=array_replace($invoice,['id'=>'second-invoice','number'=>'INV-SECOND']);
    $s=(new Entities(new Registry()))->change($s,['scope'=>'document','action'=>'required','kind'=>'04021','fieldId'=>'document.number','value'=>'INV-EDITED']);
    same(3,count($s['draft']['documents']));same($invoice['id'],$s['draft']['documents'][0]['id']);same('INV-EDITED',$s['draft']['documents'][0]['number']);
    same($invoice['goodsIds'],$s['draft']['documents'][0]['goodsIds']);same('INV-SECOND',$s['draft']['documents'][2]['number']);same(['source-date'],$s['draft']['fieldStates'][$address]['evidenceIds']);
});

test('required-document endpoint restricts kinds and fields and failed input does not persist a blank record',function():void {
    $store=sessionFixture();$s=$store->create()['state'];$entities=new Entities(new Registry());
    $request=['scope'=>'document','action'=>'required','kind'=>'04021','fieldId'=>'document.number','value'=>'INV'];
    errorCode('DOCUMENT_KIND_UNKNOWN',fn()=>$entities->change($s,array_replace($request,['kind'=>'99999'])));
    foreach(['party.name','document.kind','document.dateRequired'] as $field)errorCode('REQUIRED_DOCUMENT_FIELD',fn()=>$entities->change($s,array_replace($request,['fieldId'=>$field])));
    errorCode('FIELD_VALUE',fn()=>$store->mutate($s['id'],$s['dataRevision'],fn($state)=>$entities->change($state,array_replace($request,['value'=>42]))));
    same([],$store->read($s['id'])['draft']['documents']);same($s['dataRevision'],$store->read($s['id'])['dataRevision']);
});
