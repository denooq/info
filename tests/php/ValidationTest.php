<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

test('all required initial data is checked; zero, false and inapplicability are separate from unknown',function():void{
    $v=new Customs\Domain\Validator(new Customs\Domain\Registry());$s=completeDraftState();$valid=$v->validate($s);
    truth($valid['valid'],json_encode($valid['errors'],JSON_UNESCAPED_UNICODE));
    foreach(['relatedParties','royaltiesApplicable','paymentFactsAvailable'] as $key){$copy=$s;$copy['draft']['declaration'][$key]=null;truth(!$v->validate($copy)['valid']);}
    $s['draft']['declaration']['container']=true;truth(!$v->validate($s)['valid']);
    $s['draft']['declaration']['container']=false;same(true,$v->validate($s)['valid']);
    $s['draft']['parties'][0]['name']=null;errorCode('DECLARATION_INVALID',fn()=>$v->requireValid($s));
});
test('conflicts, party cycles, missing rates and unknown source lines block the server',function():void{
    $v=new Customs\Domain\Validator(new Customs\Domain\Registry());
    foreach(['conflict','cycle','rate','group','method'] as $case){
        $s=completeDraftState();
        if($case==='conflict')$s['draft']['fieldStates']['goods/goods-1/grossMass']['conflict']=true;
        if($case==='cycle')$s['draft']['parties'][0]['sameAsRole']='seller';
        if($case==='rate')$s['draft']['rates']=[];
        if($case==='group')$s['draft']['goods'][0]['sourceLineIds']=['unknown-source-line'];
        if($case==='method'){$s['draft']['declaration']['relatedParties']=true;$s['draft']['declaration']['relationshipAffectedPrice']=true;}
        truth(!$v->validate($s)['valid'],$case);
    }
});
test('shared invoice and pallets do not duplicate identity; grouping rejects inconsistent classification',function():void{
    $s=completeDraftState(2);$model=new Customs\Domain\Entities(new Customs\Domain\Registry());
    same(true,(new Customs\Domain\Validator(new Customs\Domain\Registry()))->validate($s)['valid']);
    $calc=(new Customs\Domain\Calculator())->calculate($s);same(34,$calc['packageCount']);same(2,$calc['goodsCount']);
    $merged=$model->change($s,['scope'=>'goods','action'=>'merge','ids'=>['goods-1','goods-2'],'mode'=>'sum','confirmed'=>true]);
    same('74908.80',$merged['draft']['goods'][0]['invoiceValue']);same(2,count($merged['draft']['goods'][0]['sourceLineIds']));
    $s['draft']['goods'][1]['commodityCode']='3506910000';errorCode('GROUP_INCOMPATIBLE',fn()=>$model->change($s,['scope'=>'goods','action'=>'merge','ids'=>['goods-1','goods-2'],'mode'=>'sum','confirmed'=>true]));
    $split=$model->change($merged,['scope'=>'goods','action'=>'split','id'=>'goods-1','sourceLineIds'=>['manual-line-2']]);
    same(2,count($split['draft']['goods']));same(null,$split['draft']['goods'][0]['invoiceValue']);
});

test('missing document types and an unconfirmed cost identify the declaration records and actual DTS answer',function():void {
    $s=completeDraftState();foreach($s['draft']['documents'] as &$document)$document['kind']='99999';unset($document);
    $s['draft']['costs'][]=['id'=>'test-cost','kind'=>'freight','direction'=>'addition','amount'=>'1','currency'=>'KZT','includedInPrice'=>false,'basis'=>'Проверочный участок','evidenceIds'=>[],'allocationBasis'=>'value','allocationConfirmed'=>true,'goodsIds'=>['goods-1']];
    $errors=(new Customs\Domain\Validator(new Customs\Domain\Registry()))->validate($s)['errors'];
    $documents=array_values(array_filter($errors,fn($e)=>$e['code']==='DOCUMENT_REQUIRED'));same(2,count($documents));
    truth(str_contains($documents[0]['message'],'инвойса'));truth(str_contains($documents[1]['message'],'контракта'));
    $cost=array_values(array_filter($errors,fn($e)=>$e['code']==='COST_APPLICABILITY'))[0];same('declaration/_/freightApplicable',$cost['address']);
    truth(str_contains($cost['message'],'«Да»'));
});
