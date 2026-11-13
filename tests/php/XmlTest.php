<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';
function xmlSnapshot(int $count=1):array {
    $s=completeDraftState($count);$s['confirmed']=$s['artifactSet']=null;$s['calculation']=(new Customs\Domain\Calculator())->calculate($s);
    return (new Customs\Export\Snapshot())->confirm($s,$s['calculation']['hash'])['confirmed'];
}
test('sample XML profiles serialize current goods and preserve separate namespaces and document references',function():void{
    foreach([1,2,5] as $count){$snapshot=xmlSnapshot($count);$files=(new Customs\Export\XmlProfiles())->serialize($snapshot);
        same(['declaration.xml','customs-value-declaration.xml'],array_keys($files));
        truth(str_contains($files['declaration.xml'],'urn:EEC:M:CA:ComplexDataObjects:v1.10.3'));
        truth(str_contains($files['customs-value-declaration.xml'],'urn:EEC:M:CA:ComplexDataObjects:v1.8.1'));
        truth(!str_contains($files['customs-value-declaration.xml'],'S116510021328'));
        truth(str_contains($files['customs-value-declaration.xml'],$snapshot['declarationId']));
    }
});
test('XML local validation rejects changed totals, order, types and entities; unknown branches have explicit errors',function():void{
    $snapshot=xmlSnapshot();$p=new Customs\Export\XmlProfiles();$files=$p->serialize($snapshot);$v=new Customs\Export\XmlProfileValidator();
    $broken=$files;$broken['declaration.xml']=str_replace('17065348.27','1.00',$broken['declaration.xml']);errorCode('XML_PROFILE_INVALID',fn()=>$v->validate($broken,$snapshot));
    $broken=$files;$broken['declaration.xml']=str_replace('<csdo:PageQuantity>1</csdo:PageQuantity>','<csdo:PageQuantity>NaN</csdo:PageQuantity>',$broken['declaration.xml']);errorCode('XML_PROFILE_INVALID',fn()=>$v->validate($broken,$snapshot));
    $broken=$files;$doc=new DOMDocument();$doc->loadXML($broken['declaration.xml']);$xp=new DOMXPath($doc);$xp->registerNamespace('csdo','urn:EEC:M:SimpleDataObjects:v0.4.16');$page=$xp->query('//csdo:PageQuantity')->item(0);$page->parentNode->appendChild($page);$broken['declaration.xml']=$doc->saveXML();errorCode('XML_PROFILE_INVALID',fn()=>$v->validate($broken,$snapshot));
    $broken=$files;$broken['declaration.xml']=str_replace('<casdo:GoodsQuantity>1</casdo:GoodsQuantity>','',$broken['declaration.xml']);errorCode('XML_PROFILE_INVALID',fn()=>$v->validate($broken,$snapshot));
    $broken=$files;$broken['declaration.xml']=preg_replace('/(<\?xml[^?]*\?>)/','$1<!DOCTYPE test [<!ENTITY external SYSTEM "https://example.invalid/secret">]>',$broken['declaration.xml']);errorCode('XML_PROFILE_INVALID',fn()=>$v->validate($broken,$snapshot));
    $unsupported=$snapshot;$unsupported['data']['calculation']['items'][0]['deductionValue']='1';errorCode('XML_BRANCH_UNSUPPORTED',fn()=>$p->serialize($unsupported));
});
