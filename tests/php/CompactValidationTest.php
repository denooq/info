<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

test('compact readiness accepts a full free text without hidden address details and requires graph 8', function(): void {
    $s=completeDraftState(2);$edit=new Customs\Domain\GraphEdits();$s=$edit->edit($s,'2',null,'Отправитель и полный адрес в свободной форме');
    foreach($s['draft']['parties'] as &$party)if($party['role']==='consignor')foreach(['name','briefName','country','region','city','street','postalCode'] as $key)$party[$key]=null;unset($party);
    foreach($s['draft']['goods'] as &$goods)$goods['groupConfirmed']=false;unset($goods);
    $review=new Customs\Domain\ReviewValidator();$r=$review->validate($s);truth($r['valid'],json_encode($r['errors'],JSON_UNESCAPED_UNICODE));
    $s=$edit->edit($s,'8',null,'');$r=$review->validate($s);truth(!$r['valid']);same('graph/8/_',$r['errors'][0]['address']);
    errorCode('DECLARATION_INVALID',fn()=>$review->requireValid($s));
});

test('calculation readiness uses compact texts and retains real method and cost requirements', function(): void {
    $s=completeDraftState();$s=(new Customs\Domain\GraphEdits())->edit($s,'2',null,'Полный отправитель и адрес');
    foreach($s['draft']['parties'] as &$party)if($party['role']==='consignor')foreach(['country','city','street','briefName'] as $key)$party[$key]=null;unset($party);
    $s['draft']['declaration']['feeBase']=null;$s['draft']['goods'][0]['vatRate']=null;
    $v=new Customs\Domain\Validator(new Customs\Domain\Registry());$result=$v->validate($s);truth($result['valid'],json_encode($result['errors'],JSON_UNESCAPED_UNICODE));
    $calc=(new Customs\Domain\Calculator())->calculate($s);same('25950.00',$calc['totals']['fee']);
    $s['draft']['declaration']['methodOneConfirmed']=null;truth(!$v->validate($s)['valid']);
    $s=completeDraftState();$s=(new Customs\Domain\GraphEdits())->edit($s,'8',null,'');
    errorCode('DECLARATION_INVALID',fn()=>(new Customs\Domain\Calculator())->calculate($s));
});

test('review errors lead to declared compact controls for code conflicts places and documentary records', function(): void {
    $s=completeDraftState();$s['draft']['goods'][0]['previousProcedure']=null;$s['draft']['goods'][0]['packageIds']=[];$s['draft']['documents']=[];
    $r=(new Customs\Domain\ReviewValidator())->validate($s);$codes=array_column($r['errors'],'code');
    truth(in_array('REQUIRED',$codes,true));truth(in_array('DOCUMENT_REQUIRED',$codes,true));
    foreach($r['errors'] as $error)truth(isset($error['graphId']));
    truth((bool)array_filter($r['errors'],fn($e)=>$e['address']==='graph/37/goods-1'));
    truth((bool)array_filter($r['errors'],fn($e)=>$e['address']==='goods/goods-1/packageIds'&&$e['graphId']==='6'));
});
