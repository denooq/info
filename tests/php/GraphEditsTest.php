<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

test('graph edits persist one manual text and invalid numeric input cannot reuse its old value', function(): void {
    $s=completeDraftState();$edits=new Customs\Domain\GraphEdits();$s=$edits->edit($s,'2',null,'Отправитель, полный адрес');
    same('Отправитель, полный адрес',(new Customs\Domain\CompactGraphs())->item($s,'2',null)['value']);
    $model=new Customs\Domain\Draft(new Customs\Domain\Registry());$old=$s['draft']['goods'][0]['netMass'];
    $s=$model->edit($s,'goods.netMass','goods-1','не число');same($old,$s['draft']['goods'][0]['netMass']);
    same('не число',(new Customs\Domain\CompactGraphs())->item($s,'38','goods-1')['inputs'][0]['value']);
    truth(!(new Customs\Domain\ReviewValidator())->validate($s)['valid']);
    errorCode('DECLARATION_INVALID',fn()=>(new Customs\Domain\Calculator())->calculate($s,false));
    $s=$model->edit($s,'goods.netMass','goods-1','123');truth(($s['draft']['invalidInputs']??[])===[]);
    errorCode('GRAPH_ENTITY',fn()=>$edits->edit($s,'31','other-goods','Товар'));
});

test('graph changes use the existing atomic revision guard', function(): void {
    $store=sessionFixture();$created=$store->create();$id=$created['state']['id'];$revision=$created['state']['dataRevision'];
    $next=$store->mutate($id,$revision,fn($s)=>(new Customs\Domain\GraphEdits())->edit($s,'2',null,'Первый текст'));
    same($revision+1,$next['dataRevision']);
    errorCode('REVISION_CONFLICT',fn()=>$store->mutate($id,$revision,fn($s)=>(new Customs\Domain\GraphEdits())->edit($s,'8',null,'Устаревший ответ')));
    $again=$store->read($id);same('Первый текст',$again['draft']['graphTexts']['graph/2/_']['value']);
    same($next['dataRevision'],$again['dataRevision']);
});
