<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

test('compact code strings retain leading zeroes and reject malformed current input atomically', function(): void {
    $s=completeDraftState();$edit=new Customs\Domain\GraphEdits();
    $s=$edit->edit($s,'37','goods-1','40; 00; 000');same('00',$s['draft']['goods'][0]['previousProcedure']);same('000',$s['draft']['goods'][0]['movementFeature']);
    $s=$edit->edit($s,'36','goods-1','Нет; ОО; ОО; ОО; ОО');same(false,$s['draft']['goods'][0]['hasPreference']);same(null,$s['draft']['graphTexts']['graph/36/goods-1']['error']);
    $s=$edit->edit($s,'20',null,'FCA; HUAIBEI');same('FCA',$s['draft']['declaration']['deliveryCode']);
    $s=$edit->edit($s,'20',null,'Неясный текст');truth($s['draft']['graphTexts']['graph/20/_']['error']!==null);same('FCA',$s['draft']['declaration']['deliveryCode']);
    errorCode('DECLARATION_INVALID',fn()=>(new Customs\Domain\Calculator())->calculate($s,false));
    errorCode('GRAPH_EDIT_REQUIRED',fn()=>(new Customs\Domain\Draft(new Customs\Domain\Registry()))->edit($s,'party.city','party-consignor','Скрытая правка'));
});

test('several conflicting documentary records can be chosen independently and remap preserves the choices', function(): void {
    $s=completeDraftState();
    foreach(['doc-invoice','doc-contract'] as $recordId) {
        $address='document/'.$recordId.'/number';$s['draft']['fieldStates'][$address]['needsReview']=true;
        foreach(['A','B'] as $suffix) {$id=$recordId.$suffix;$s['evidence'][$id]=['documentId'=>$id,'documentName'=>$id,'page'=>1,'quote'=>$id];$s['candidates'][$id]=['id'=>$id,'fieldId'=>'document.number','entityId'=>$recordId,'address'=>$address,'valid'=>true,'value'=>$id,'evidenceIds'=>[$id]];}
    }
    $project=new Customs\Domain\CompactGraphs();$edit=new Customs\Domain\GraphEdits();
    foreach(['doc-invoice','doc-contract'] as $recordId) {
        $item=$project->item($s,'44','goods-1');$choice=array_values(array_filter($item['choices'],fn($c)=>($c['recordId']??null)===$recordId&&$c['recordValues']['number']===$recordId.'A'))[0];
        $s=$edit->edit($s,'44','goods-1','',$choice['id']);same($recordId.'A',array_column($s['draft']['documents'],null,'id')[$recordId]['number']);
    }
    $r=(new Customs\Domain\ReviewValidator())->validate($s);truth($r['valid'],json_encode($r['errors'],JSON_UNESCAPED_UNICODE));
    $s=(new Customs\AI\CandidateMerger(new Customs\Domain\Registry()))->reconcile($s);
    same('doc-invoiceA',$s['draft']['documents'][0]['number']);same('doc-contractA',$s['draft']['documents'][1]['number']);
});

test('document list reorder duplicate scans and edits preserve stable legal records', function(): void {
    $s=completeDraftState();$edit=new Customs\Domain\GraphEdits();$old=$s['draft']['documents'];
    $text=Customs\Domain\GraphCodecs::documentText(array_reverse($old),'goods-1');
    $s=$edit->edit($s,'44','goods-1',$text);same(array_reverse(array_column($old,'id')),array_column($s['draft']['documents'],'id'));
    $s=$edit->edit($s,'44','goods-1',$text."\n".explode("\n",$text)[0]);same(2,count($s['draft']['documents']));
    $oldId=$s['draft']['documents'][1]['id'];$newText=str_replace($old[0]['number'],'NEW-NUMBER',$text);
    $s=$edit->edit($s,'44','goods-1',$newText);same($oldId,$s['draft']['documents'][1]['id']);same(null,$s['draft']['graphTexts']['graph/44/goods-1']['error']);
    $before=$s['draft']['documents'];$s=$edit->edit($s,'44','goods-1','foreign-id | 12 | 2026-10-28 | 0');same($before,$s['draft']['documents']);truth($s['draft']['graphTexts']['graph/44/goods-1']['error']!==null);
});
