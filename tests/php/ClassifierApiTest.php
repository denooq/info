<?php
declare(strict_types=1);
test('public classifier endpoint returns literal paginated official data before session creation',function():void {
    $config=new Customs\Config(['runtime_dir'=>sys_get_temp_dir().'/customs-classifier-api-'.bin2hex(random_bytes(6))]);
    $app=new Customs\Http\Application($config);$previous=$_GET;
    try {
        $_GET=['q'=>'04021','limit'=>'2'];$reply=$app->handle('GET','/api/classifiers/documents');same(1,$reply['total']);same('04021',$reply['items'][0]['code']);
        $_GET=['q'=>'CMR'];same(0,$app->handle('GET','/api/classifiers/documents')['total']);
        $_GET=['limit'=>10,'offset'=>320];truth(count($app->handle('GET','/api/classifiers/documents')['items'])>0);
        $_GET=['q'=>'010121','limit'=>'2'];$codes=$app->handle('GET','/api/classifiers/tnved');same('0101210000',$codes['items'][0]['code']);same(10,strlen($codes['items'][0]['code']));truth($codes['total']>=1);
        $_GET=['q'=>'этилацетат'];$name=$app->handle('GET','/api/classifiers/tnved');same('2915310000',$name['items'][0]['code']);same(20,$name['items'][0]['page']);truth(str_starts_with($name['items'][0]['sourceUrl'],'https://eec.eaeunion.org/'));
        $_GET=['q'=>'01','limit'=>'2'];$first=$app->handle('GET','/api/classifiers/tnved');same(2,count($first['items']));truth($first['nextOffset']!==null);truth($first['total']>2);
        $_GET=['q'=>'01','offset'=>(string)$first['nextOffset'],'limit'=>'2'];$next=$app->handle('GET','/api/classifiers/tnved');same($first['nextOffset'],$next['offset']);truth($first['items'][0]['code']!==$next['items'][0]['code']);
        $_GET=['q'=>'no-such-catalog-entry'];same([],$app->handle('GET','/api/classifiers/tnved')['items']);
        $_GET=['q'=>'x'];errorCode('CLASSIFIER_QUERY',fn()=>$app->handle('GET','/api/classifiers/tnved'));
        $registry=$app->handle('GET','/api/registry');same(249,count($registry['catalogs']['lists']['countries']));same(57,count($registry['graphs']['graphs']));
    } finally {$_GET=$previous;}
});
