<?php
declare(strict_types=1);
test('public classifier endpoint returns literal paginated official data before session creation',function():void {
    $config=new Customs\Config(['runtime_dir'=>sys_get_temp_dir().'/customs-classifier-api-'.bin2hex(random_bytes(6))]);
    $app=new Customs\Http\Application($config);$previous=$_GET;
    try {
        $_GET=['q'=>'04021','limit'=>'2'];$reply=$app->handle('GET','/api/classifiers/documents');same(1,$reply['total']);same('04021',$reply['items'][0]['code']);
        $_GET=['q'=>'CMR'];same(0,$app->handle('GET','/api/classifiers/documents')['total']);
        $_GET=['limit'=>10,'offset'=>320];truth(count($app->handle('GET','/api/classifiers/documents')['items'])>0);
        $registry=$app->handle('GET','/api/registry');same(249,count($registry['catalogs']['lists']['countries']));same(57,count($registry['graphs']['graphs']));
    } finally {$_GET=$previous;}
});
