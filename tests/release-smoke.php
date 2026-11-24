<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$archive=dirname(__DIR__).'/release/customs-declaration-mvp.zip';
$zip=new ZipArchive();if($zip->open($archive)!==true)throw new RuntimeException('Release archive missing');
$manifest=json_decode($zip->getFromName('release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(($manifest['profile']??'')!=='im40-kz-graphs'||($manifest['export']??'')!=='disabled')throw new RuntimeException('Wrong release profile');
if(($manifest['editorVersion']??'')!=='3.1.0'||($manifest['statutoryRulesVersion']??'')!=='kz-im40-2026.1')throw new RuntimeException('Missing compact contract');
if(($manifest['statutoryRulesSha256']??null)!==($manifest['files']['resources/registry/statutory-rules.kz.v1.json']??null))throw new RuntimeException('Wrong statutory resource hash');
foreach(['resources/registry/graph-mapping.v2.json','resources/schemas/graph-mapping.v2.json','resources/prompts/graph-mapping.v2.txt','docs/autofill-acceptance.md','docs/compact-graph-review.md','docs/graph-mapping-contract.md','docs/graph-mapping-results.md','docs/graph-mapping-verification.md','docs/mapping-limits.md','docs/source-manifest.md'] as $required)if(!isset($manifest['files'][$required]))throw new RuntimeException('Release is missing '.$required);
foreach($manifest['files'] as $name=>$hash){$bytes=$zip->getFromName($name);if($bytes===false||hash('sha256',$bytes)!==$hash)throw new RuntimeException('Release hash mismatch: '.$name);}
$forbidden=['reference/','tests/','.local-tools/','runtime/','.git/'];
for($i=0;$i<$zip->numFiles;$i++){
    $name=$zip->getNameIndex($i);
    if(preg_match('#(?:^|/)\.\.(?:/|$)|^[\\/]|^[A-Za-z]:#D',$name)||str_contains($name,"\0"))throw new RuntimeException('Unsafe archive entry');
    foreach($forbidden as $prefix)if(str_starts_with($name,$prefix))throw new RuntimeException('Private/development data in release');
    if(in_array($name,['config/local.php','.env','sesssion.json'],true))throw new RuntimeException('Private configuration in release');
}
$base=realpath(sys_get_temp_dir());$directory=$base.'/customs-release-smoke-'.bin2hex(random_bytes(8));
if(!mkdir($directory,0700))throw new RuntimeException('Cannot create smoke directory');
$target=realpath($directory);if($target===false||dirname($target)!==$base||!str_starts_with(basename($target),'customs-release-smoke-'))throw new RuntimeException('Unsafe smoke target');
try {
    if(!$zip->extractTo($target))throw new RuntimeException('Cannot extract release');$zip->close();
    if(is_dir($target.'/reference'))throw new RuntimeException('Reference is not a production dependency');
    require $target.'/src/bootstrap.php';
    $registry=(new Customs\Domain\Registry())->data();$graphs=(new Customs\Domain\GraphRegistry())->data();$classifiers=new Customs\Domain\Classifiers();$mappingContract=new Customs\Domain\GraphMappingContract();
    if(count($graphs['graphs'])!==57||count($classifiers->data()['documents'])!==331||count($classifiers->data()['countries'])!==249)throw new RuntimeException('Incomplete runtime resources');
    $mappingSchema=json_decode(file_get_contents($target.'/resources/schemas/graph-mapping.v2.json'),true,128,JSON_THROW_ON_ERROR);
    if(count($mappingContract->positions())!==57||($mappingSchema['properties']['schemaVersion']['const']??null)!=='graph-mapping-v2'||count((new Customs\Domain\GraphEditors())->data()['graphs'])!==54)throw new RuntimeException('Graph mapping/editor contract unavailable in release');
    if($classifiers->country('KZ')['name']!=='КАЗАХСТАН'||(new Customs\Domain\TariffCatalogue())->get('2915310000')===null)throw new RuntimeException('Runtime catalog read failed');
    $config=new Customs\Config(['runtime_dir'=>$target.'/private-runtime','secure_cookie'=>false]);$store=new Customs\Storage\SessionStore($config);$session=$store->create();
    $view=$store->publicView($session['state']);if(count($view['graphProjection']['graphs'])!==57||count($view['graphProjection']['editors'])!==54||$view['capabilities']['export']!==false||$view['ready'])throw new RuntimeException('Wrong session profile');
    $changed=$store->mutate($session['state']['id'],$session['state']['dataRevision'],fn($state)=>(new Customs\Domain\GraphEdits())->edit($state,'8',null,'Ручной получатель без reference'));
    if((new Customs\Domain\CompactGraphs())->item($changed,'8',null)['value']!=='Ручной получатель без reference')throw new RuntimeException('Compact editor save failed');
    $old=$changed;unset($old['draft']['compactVersion'],$old['draft']['ruleResourceHash'],$old['draft']['statutoryRules']);$old['draft']['declaration']['feeBase']='100';$old['calculation']=['dataRevision'=>$old['dataRevision']];
    $migrated=Customs\Storage\SessionMigration::upgrade($old);
    if($migrated['calculation']!==null||$migrated['dataRevision']!==$old['dataRevision']+1||$migrated['draft']['legacyStatutory']['declaration/_/feeBase']['value']!=='100'||$migrated['expiresAt']!==$old['expiresAt']||(new Customs\Domain\CompactGraphs())->item($migrated,'8',null)['value']!=='Ручной получатель без reference')throw new RuntimeException('Compact migration failed');
    if(Customs\Storage\SessionMigration::upgrade($migrated)!==$migrated)throw new RuntimeException('Migration is not idempotent');
    $uploads=new Customs\Documents\Uploads($config,$store);$state=$uploads->changeGroup($changed['id'],['code'=>'04021','version'=>$classifiers->version()],$changed['dataRevision']);
    $png=$store->directory($state['id']).'/scratch/smoke.png';$image=imagecreatetruecolor(1,1);imagepng($image,$png);imagedestroy($image);
    $state=$uploads->addFromPath($state['id'],$png,'smoke.png',$state['dataRevision'],false,'kind-04021');$file=reset($state['documents']);$revision=$state['dataRevision'];
    $state=$uploads->changeGroup($state['id'],['action'=>'remove','id'=>'kind-04021'],$revision);
    if($state['documents']!==[]||$state['uploadGroups']!==[]||$state['dataRevision']!==$revision+1||is_file($store->directory($state['id']).'/documents/'.$file['storageName']))throw new RuntimeException('Attachment row removal failed in release');
    $_COOKIE['customs_session']=$session['token'];$app=new Customs\Http\Application($config,$store);$reply=$app->handle('GET','/api/registry');if(count($reply['graphs']['graphs'])!==57||count($reply['editors']['graphs'])!==54)throw new RuntimeException('Registry API unavailable');
    try{$app->handle('GET','/api/download/pdf');throw new RuntimeException('Export enabled');}catch(Customs\Http\ApiError $error){if($error->errorCode!=='EXPORT_DISABLED')throw $error;}
    $index=file_get_contents($target.'/public/assets-app/index.html');preg_match_all('#(?:src|href)="(/assets-app/[^\"]+)"#',$index,$assets);
    if(count($assets[1])<2)throw new RuntimeException('Frontend entry missing');foreach($assets[1] as $asset)if(!is_file($target.'/public'.$asset))throw new RuntimeException('Frontend asset missing');
    echo json_encode(['profile'=>$manifest['profile'],'editorVersion'=>$manifest['editorVersion'],'statutoryRulesVersion'=>$manifest['statutoryRulesVersion'],'manifestFiles'=>count($manifest['files']),'allHashesMatch'=>true,'editors'=>54,'graphs'=>57,'documentPositions'=>331,'countries'=>249,'runtimeWithoutReference'=>true,'compactSaveAndMigration'=>true,'attachmentRowDeletion'=>true,'exportDisabled'=>true,'frontendAssets'=>count($assets[1])],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    // Only the exact verified temporary extraction directory is removed; never follow links.
    if(realpath($directory)!==$target||dirname($target)!==$base)throw new RuntimeException('Unsafe smoke cleanup');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){$path=$entry->getPathname();if($entry->isLink()||$entry->isFile())unlink($path);else rmdir($path);}rmdir($target);
}
