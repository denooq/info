<?php
declare(strict_types=1);
function typedWeightSource(string $id,string $code,string $text):array {
    return ['documentId'=>$id,'name'=>$code.'.pdf','documentKind'=>$code,'classifierVersion'=>'378-2026-11-23','sourceRole'=>Customs\Domain\SourceRoles::forCode($code),'pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','text'=>$text]]]]];
}
function typedWeightPayload(string $id,string $weight):array {
    $source=['documentId'=>$id,'page'=>1,'blockId'=>'p1-b0','sheet'=>null,'cell'=>null,'quote'=>'Gross '.$weight.' kg'];
    return ['entities'=>[['scope'=>'goods','key'=>'goods-1','role'=>null,'source'=>$source]],'candidates'=>[['fieldId'=>'goods.grossMass','entityKey'=>'goods-1','rawValue'=>$weight,'unit'=>'kg','source'=>$source]],'warnings'=>[]];
}
test('ranked sources retain inter-rank conflicts, deduplicate equal values and preserve manual edits',function():void {
    $registry=new Customs\Domain\Registry();$merge=new Customs\AI\CandidateMerger($registry);$s=['draft'=>(new Customs\Domain\Draft($registry))->empty(),'candidates'=>[],'evidence'=>[]];
    $s['draft']['goods'][0]['description']='Подтверждённый товар';
    $a=str_repeat('a',32);$b=str_repeat('b',32);$sourceA=typedWeightSource($a,'02015','Gross 27860 kg');$sourceB=typedWeightSource($b,'04131','Gross 26792 kg');
    $s=$merge->merge($s,typedWeightPayload($b,'26792'),[$sourceB]);same('26792',$s['draft']['goods'][0]['grossMass']);same(2,array_values($s['candidates'])[0]['rank']);
    $s=$merge->merge($s,typedWeightPayload($a,'27860'),[$sourceA]);same(null,$s['draft']['goods'][0]['grossMass']);same(2,count($s['candidates']));truth($s['draft']['fieldStates']['goods/goods-1/grossMass']['conflict']);
    $s=(new Customs\Domain\Draft($registry))->edit($s,'goods.grossMass','goods-1','28000');$s=$merge->merge($s,typedWeightPayload($a,'27860'),[$sourceA]);same('28000',$s['draft']['goods'][0]['grossMass']);
    $equal=['draft'=>(new Customs\Domain\Draft($registry))->empty(),'candidates'=>[],'evidence'=>[]];$equal['draft']['goods'][0]['description']='Подтверждённый товар';$equal=$merge->merge($equal,typedWeightPayload($a,'27860'),[$sourceA]);$equal=$merge->merge($equal,typedWeightPayload($b,'27860'),[typedWeightSource($b,'04131','Gross 27860 kg')]);same(1,count($equal['candidates']));same(2,count(array_values($equal['candidates'])[0]['evidenceIds']));same('27860',$equal['draft']['goods'][0]['grossMass']);
    $fallback=['draft'=>(new Customs\Domain\Draft($registry))->empty(),'candidates'=>[],'evidence'=>[]];$fallback['draft']['goods'][0]['description']='Known shipment item';$fallback=$merge->merge($fallback,typedWeightPayload($a,'27860'),[typedWeightSource($a,'03011','Gross 27860 kg')]);same(1,count($fallback['candidates']));same('27860',$fallback['draft']['goods'][0]['grossMass']);same(null,array_values($fallback['candidates'])[0]['rank']);same([],$fallback['mappingIssues']);
});

test('invoice and contract party values remain eligible when their entity role differs from graph labels',function():void{
    $registry=new Customs\Domain\Registry();$merge=new Customs\AI\CandidateMerger($registry);$state=['draft'=>(new Customs\Domain\Draft($registry))->empty(),'candidates'=>[],'evidence'=>[]];
    $id=str_repeat('d',32);$source=typedWeightSource($id,'04021','Seller: ANHUI RUIBAI NEW MATERIAL CO. LTD.; Buyer: CUSTOMS IMPORT LLP');
    $entities=[];$candidates=[];
    foreach(['seller'=>'ANHUI RUIBAI NEW MATERIAL CO. LTD.','buyer'=>'CUSTOMS IMPORT LLP'] as $role=>$name){
        $key=$role;$citation=['documentId'=>$id,'page'=>1,'blockId'=>'p1-b0','sheet'=>null,'cell'=>null,'quote'=>$name];
        $entities[]=['scope'=>'party','key'=>$key,'role'=>$role,'source'=>array_replace($citation,['quote'=>$role==='seller'?'Seller: '.$name:'Buyer: '.$name])];
        $candidates[]=['fieldId'=>'party.name','entityKey'=>$key,'rawValue'=>$name,'unit'=>null,'source'=>$citation];
    }
    $state=$merge->merge($state,['entities'=>$entities,'candidates'=>$candidates,'warnings'=>[]],[$source]);
    $parties=array_column($state['draft']['parties'],null,'role');
    same('ANHUI RUIBAI NEW MATERIAL CO. LTD.',$parties['seller']['name']);same('CUSTOMS IMPORT LLP',$parties['buyer']['name']);
    same([],array_values(array_filter($state['mappingIssues'],fn(array $issue):bool=>($issue['code']??'')==='SOURCE_ROLE_UNCONFIRMED')));
});

test('non-priority shipment sources are not blocked from establishing a documented item',function():void{
    $id=str_repeat('f',32);$source=typedWeightSource($id,'09013','Transit declaration: Ethyl acetate, 27860 kg');
    truth((new Customs\AI\SourceIndex([$source]))->supportsNewGoods($id));
});

test('uploaded document kind remains protected from a conflicting AI document-type proposal',function():void{
    $registry=new Customs\Domain\Registry();$model=new Customs\Domain\Draft($registry);$merge=new Customs\AI\CandidateMerger($registry);$state=['draft'=>$model->empty(),'candidates'=>[],'evidence'=>[]];
    $id=str_repeat('e',32);$source=typedWeightSource($id,'04021','Invoice INV-1');$citation=['documentId'=>$id,'page'=>1,'blockId'=>'p1-b0','sheet'=>null,'cell'=>null,'quote'=>'Invoice INV-1'];
    $response=['entities'=>[['scope'=>'document','key'=>'invoice','role'=>null,'source'=>$citation]],'candidates'=>[['fieldId'=>'document.number','entityKey'=>'invoice','rawValue'=>'INV-1','unit'=>null,'source'=>$citation]],'warnings'=>[]];
    $state=$merge->merge($state,$response,[$source]);$documentId=$state['draft']['documents'][0]['id'];
    $state['draft']['documents'][0]['kind']='03011';$state['draft']['fieldStates'][Customs\Domain\Draft::address('document',$documentId,'kind')]=['status'=>'manual','candidateId'=>null,'evidenceIds'=>[],'conflict'=>false,'needsReview'=>false];
    $state=$merge->merge($state,$response,[$source]);
    same('03011',$state['draft']['documents'][0]['kind']);
});
test('contract and amendment in one PDF retain separate record numbers and a manual legal type correction',function():void {
    $registry=new Customs\Domain\Registry();$merge=new Customs\AI\CandidateMerger($registry);$s=['draft'=>(new Customs\Domain\Draft($registry))->empty(),'candidates'=>[],'evidence'=>[]];$id=str_repeat('a',32);$source=typedWeightSource($id,'03011','Contract CON-1; Appendix ADD-25');
    $response=['entities'=>[],'candidates'=>[],'warnings'=>[]];foreach(['contract'=>'CON-1','appendix'=>'ADD-25'] as $key=>$number){$citation=['documentId'=>$id,'page'=>1,'blockId'=>'p1-b0','sheet'=>null,'cell'=>null,'quote'=>$number];$response['entities'][]=['scope'=>'document','key'=>$key,'role'=>null,'source'=>$citation];$response['candidates'][]=['fieldId'=>'document.number','entityKey'=>$key,'rawValue'=>$number,'unit'=>null,'source'=>$citation];}
    $s=$merge->merge($s,$response,[$source]);same(2,count($s['draft']['documents']));same(['CON-1','ADD-25'],array_column($s['draft']['documents'],'number'));
    foreach($s['draft']['documents'] as &$document){$document['date']='2026-10-28';$document['presentationCode']='0';}unset($document);
    $appendix=$s['draft']['documents'][1]['id'];
    $s=(new Customs\Domain\GraphEdits())->edit($s,'44','goods-1',"03011 | CON-1 | 2026-10-28 | 0\n03012 | ADD-25 | 2026-10-28 | 0");
    same(null,$s['draft']['graphTexts']['graph/44/goods-1']['error']);
    $s=$merge->merge($s,$response,[$source]);$corrected=array_column($s['draft']['documents'],null,'id')[$appendix];same('03012',$corrected['kind']);same([$id],$corrected['documentIds']);
});
test('document entity keys are local to a file and transport roles select the correct existing stage',function():void {
    $registry=new Customs\Domain\Registry();$merge=new Customs\AI\CandidateMerger($registry);$s=['draft'=>(new Customs\Domain\Draft($registry))->empty(),'candidates'=>[],'evidence'=>[]];
    foreach(['a'=>'INV-1','b'=>'INV-2'] as $letter=>$number) {
        $id=str_repeat($letter,32);$source=typedWeightSource($id,'04021','Invoice '.$number);$citation=['documentId'=>$id,'page'=>1,'blockId'=>'p1-b0','sheet'=>null,'cell'=>null,'quote'=>'Invoice '.$number];
        $s=$merge->merge($s,['entities'=>[['scope'=>'document','key'=>'invoice','role'=>null,'source'=>$citation]],'candidates'=>[['fieldId'=>'document.number','entityKey'=>'invoice','rawValue'=>$number,'unit'=>null,'source'=>$citation]],'warnings'=>[]],[$source]);
    }
    same(2,count($s['draft']['documents']));same(['INV-1','INV-2'],array_column($s['draft']['documents'],'number'));
    $id=str_repeat('c',32);$source=typedWeightSource($id,'02017','Aircraft REG-42');$citation=['documentId'=>$id,'page'=>1,'blockId'=>'p1-b0','sheet'=>null,'cell'=>null,'quote'=>'Aircraft REG-42'];
    $s=$merge->merge($s,['entities'=>[['scope'=>'transport','key'=>'plane','role'=>'border','source'=>$citation]],'candidates'=>[['fieldId'=>'transport.registration','entityKey'=>'plane','rawValue'=>'REG-42','unit'=>null,'source'=>$citation]],'warnings'=>[]],[$source]);
    $transport=array_column($s['draft']['transports'],null,'stage');same('REG-42',$transport['border']['registration']);same(null,$transport['arrival']['registration']);
});
