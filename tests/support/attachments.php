<?php
declare(strict_types=1);

function attachmentFixture():array
{
    $config=new Customs\Config(['runtime_dir'=>sys_get_temp_dir().'/customs-delete-'.bin2hex(random_bytes(8))]);
    $store=new Customs\Storage\SessionStore($config);$uploads=new Customs\Documents\Uploads($config,$store);$s=$store->create()['state'];$version=(new Customs\Domain\Classifiers())->version();
    foreach(['04021','02015'] as $code)$s=$uploads->changeGroup($s['id'],['code'=>$code,'version'=>$version],$s['dataRevision']);
    foreach(['kind-04021','kind-04021','kind-02015'] as $i=>$group)$s=$uploads->addFromPath($s['id'],dirname(__DIR__,2).'/reference/source-documents/example2/авиа.pdf','sample'.$i.'.pdf',$s['dataRevision'],false,$group);
    return [$config,$store,$uploads,$s,array_keys($s['documents'])];
}
