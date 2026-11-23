<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);$output=$root.'/release';
if(!is_dir($output))mkdir($output,0700);
foreach(['vendor/autoload.php','public/assets-app/index.html','composer.lock','resources/tnved/catalog.v1.json','resources/tnved/codes.v1.json','resources/tnved/tariff-context.v1.json','resources/tnved/tariffs/29.json','resources/registry/graphs.im40.v2.json','resources/registry/graphs.sources.v2.json','resources/registry/classifiers.v2.json','resources/registry/source-roles.v2.json'] as $required)if(!is_file($root.'/'.$required)){fwrite(STDERR,"Build frontend, graph registries and catalogues, and install locked production dependencies first.\n");exit(1);}
$zip=new ZipArchive();$path=$output.'/customs-declaration-mvp.zip';
if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot create release');
$paths=['public','src','resources','vendor'];$manifest=[];$files=[];
foreach($paths as $part){
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$part,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $entry){
        if(!$entry->isFile()||$entry->isLink())continue;
        $relative=str_replace('\\','/',substr($entry->getPathname(),strlen($root)+1));
        if(str_starts_with($relative,'vendor/bin/'))continue;
        $files[$relative]=$entry->getPathname();
    }
}
foreach(['README.md','composer.json','composer.lock','config/local.example.php','bin/worker.php','bin/cleanup.php','bin/check-platform.php','docs/ps-kz-xs.md','docs/sessions-and-uploads.md','docs/xsd-status.md','docs/review-and-calculation.md','docs/exchange-rates.md','docs/xml-profiles.md','docs/pdf-format.md','docs/document-ai-evaluation.md','docs/tnved-selection.md','docs/acceptance.md','docs/graphs-mvp.md','docs/graph-matrix.md','docs/classifiers.md','docs/source-roles.md','docs/acceptance-graphs.md'] as $relative){
    if(!is_file($root.'/'.$relative))throw new RuntimeException('Missing release documentation');
    $files[$relative]=$root.'/'.$relative;
}
ksort($files,SORT_STRING);
$epoch=(new DateTimeImmutable('2026-11-23T00:00:00Z'))->getTimestamp();
foreach($files as $relative=>$absolute){
    if(!$zip->addFile($absolute,$relative))throw new RuntimeException('Cannot add release file');
    $zip->setMtimeName($relative,$epoch);$zip->setExternalAttributesName($relative,ZipArchive::OPSYS_UNIX,0100644<<16);
    $manifest[$relative]=hash_file('sha256',$absolute);
}
ksort($manifest);
$zip->addFromString('release-manifest.json',json_encode(['version'=>'0.1.0','profile'=>'im40-kz-graphs','registryVersion'=>'2.0.0','schemaVersion'=>2,'classifierVersion'=>'378-2026-11-23','status'=>'local-graphs-MVP-hosting-not-tested','export'=>'disabled','xmlValidation'=>'not-applicable-export-disabled','files'=>$manifest],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$zip->setMtimeName('release-manifest.json',$epoch);$zip->setExternalAttributesName('release-manifest.json',ZipArchive::OPSYS_UNIX,0100644<<16);
if(!$zip->close())throw new RuntimeException('Cannot close release');
echo json_encode(['archive'=>'release/customs-declaration-mvp.zip','files'=>count($manifest),'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),'status'=>'local-MVP-hosting-not-tested'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
