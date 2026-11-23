<?php
declare(strict_types=1);
test('graph rules cover 57 source positions with explicit stages and typed visible bindings',function():void {
    $r=new Customs\Domain\GraphRegistry();$fields=(new Customs\Domain\Registry())->fields();$not=[];
    same(57,count($r->graphs()));same(54,count(array_filter($r->graphs(),fn($g)=>$g['parentGraph']===null)));
    foreach($r->graphs() as $g) {
        foreach(['fillMode','scope','applicabilityRule','calculationStage','ruleSource','fieldBindings','requiredWhen'] as $key)truth(array_key_exists($key,$g));
        foreach($g['fieldBindings'] as $field)truth(isset($fields[$field]),$field);
        if($g['fillMode']==='not_applicable'){$not[]=$g['id'];same(false,$g['requiredWhen']);same([],$g['fieldBindings']);}
    }
    same(14,count($not));foreach(['12','45','46','47'] as $id)same('result',$r->graph($id)['calculationStage']);
    same('calculation',$r->graph('B')['calculationStage']);
    same('auto_unavailable',$r->graph('36')['emptyState']);same('auto_unavailable',$r->graph('37')['emptyState']);
    truth(in_array('goods.additionalQuantity',$r->graph('41')['fieldBindings'],true));
    truth(in_array('declaration.signatoryFirstName',$r->graph('54')['fieldBindings'],true));
    same('KZ',$fields['declaration.destinationCountry']['fixedValue']);
    same(hash_file('sha256',dirname(__DIR__,2).'/reference/rules/DT_2026.docx'),$r->data()['instructionSha256']);
});
