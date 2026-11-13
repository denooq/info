<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';
test('bundle publishes only the complete snapshot and ZIP contains exactly two XML files',function():void{
    $s=completeDraftState();$s['confirmed']=$s['artifactSet']=null;$s['calculation']=(new Customs\Domain\Calculator())->calculate($s);
    $s=(new Customs\Export\Snapshot())->confirm($s,$s['calculation']['hash']);
    $dir=sys_get_temp_dir().'/customs-bundle-test-'.bin2hex(random_bytes(6));mkdir($dir);mkdir($dir.'/artifacts');mkdir($dir.'/scratch');
    // Orchestration fixture only: these bytes are not official DT/DTS serializers or XSD evidence.
    $bundle=new Customs\Export\Bundle(fn($snapshot)=>['declaration.xml'=>'<test-dt/>','customs-value-declaration.xml'=>'<test-dts/>']);
    $payload=$bundle->run([],$s,$dir);same(null,$s['artifactSet']);
    $ready=$bundle->apply($s,$payload,[]);same('ready',$ready['phase']);
    $zip=new ZipArchive();truth($zip->open($dir.'/artifacts/'.$payload['directoryName'].'/declaration-xml.zip')===true);
    same(2,$zip->numFiles);same('declaration.xml',$zip->getNameIndex(0));same('customs-value-declaration.xml',$zip->getNameIndex(1));$zip->close();
    $stale=$s;$stale['dataRevision']++;errorCode('SNAPSHOT_STALE',fn()=>$bundle->apply($stale,$payload,[]));
    $failed=new Customs\Export\Bundle(function(){throw new Customs\Http\ApiError('TEST_DTS_FAILED','DTS failed');});
    errorCode('TEST_DTS_FAILED',fn()=>$failed->run([],$s,$dir));same(null,$s['artifactSet']);
});
