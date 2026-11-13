<?php
declare(strict_types=1);

test('expired session is deleted without browser activity and late response cannot resurrect it', function (): void {
    $now=1000; $store=sessionFixture(function()use(&$now):int{return $now;});
    $expired=$store->create(); $dir=$store->directory($expired['state']['id']);
    file_put_contents($dir.'/documents/dummy.pdf','test-document');
    file_put_contents($dir.'/ocr/dummy.json','test-ocr');
    file_put_contents($dir.'/artifacts/dummy.xml','test-artifact');
    $now=8190; $active=$store->create();
    $now=8200;
    $result=$store->cleanupExpired();
    same(1,$result['removed']); same(0,$result['errors']);
    truth(!is_dir($dir));
    truth(is_dir($store->directory($active['state']['id'])));
    errorCode('SESSION_EXPIRED',fn()=>$store->mutate($expired['state']['id'],null,fn(array $s):array=>$s,false));
    truth(!is_dir($dir));
    same(0,$store->cleanupExpired()['removed']);
});

test('cleanup only targets validated session directories inside private runtime', function (): void {
    $now=1000; $store=sessionFixture(function()use(&$now):int{return $now;});
    $session=$store->create();
    $parent=dirname($store->directory($session['state']['id']));
    mkdir($parent.'/keep-folder'); file_put_contents($parent.'/keep-folder/sentinel','keep');
    errorCode('SESSION_REQUIRED',fn()=>$store->directory('../keep-folder'));
    $now=10000; same(0,$store->cleanupExpired()['errors']);
    same('keep',file_get_contents($parent.'/keep-folder/sentinel'));
});
