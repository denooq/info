<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';
test('confirmation freezes a single consistent data revision; edits and stale calculations invalidate it',function():void{
    $s=completeDraftState();$s['confirmed']=$s['artifactSet']=null;$s['calculation']=(new Customs\Domain\Calculator())->calculate($s);
    $snapshot=new Customs\Export\Snapshot();$confirmed=$snapshot->confirm($s,$s['calculation']['hash']);
    truth(preg_match('/^[a-f0-9-]{36}$/D',$confirmed['confirmed']['declarationId'])===1);
    same('37454.40',$confirmed['confirmed']['data']['draft']['goods'][0]['invoiceValue']);
    $repeat=$snapshot->confirm($confirmed,$s['calculation']['hash']);same($confirmed['confirmed']['id'],$repeat['confirmed']['id']);
    $s['draft']['goods'][0]['invoiceValue']='1';errorCode('CALC_STALE',fn()=>$snapshot->confirm($s,$s['calculation']['hash']));
    $s=completeDraftState();$s['confirmed']=null;$s['calculation']=(new Customs\Domain\Calculator())->calculate($s);
    $s['calculation']=(new Customs\Domain\Calculator())->propose($s['calculation'],'goods-1','duty','1');
    errorCode('CALC_INCONSISTENT',fn()=>$snapshot->confirm($s,$s['calculation']['hash']));
});
test('schema validation diagnoses malformed data and forbids remote dependencies and entities',function():void{
    $dir=sys_get_temp_dir().'/customs-schema-test-'.bin2hex(random_bytes(8));mkdir($dir);
    // Engine unit fixture, deliberately not an official customs schema.
    file_put_contents($dir.'/unit.xsd','<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="probe" type="xs:decimal"/></xs:schema>');
    $v=new Customs\Export\SchemaValidator($dir);$v->validate('<probe>1.23</probe>','unit.xsd');
    errorCode('XML_SCHEMA_INVALID',fn()=>$v->validate('<probe>text</probe>','unit.xsd'));
    errorCode('XML_ENTITIES',fn()=>$v->validate('<!DOCTYPE probe [<!ENTITY ext SYSTEM "https://example.com">]><probe>&ext;</probe>','unit.xsd'));
    file_put_contents($dir.'/remote.xsd','<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:include schemaLocation="https://example.com/remote.xsd"/></xs:schema>');
    errorCode('XSD_EXTERNAL',fn()=>$v->validate('<probe/>','remote.xsd'));
    errorCode('XSD_UNAVAILABLE',fn()=>$v->requireProfiles());
});
