<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);
$sources=json_decode(file_get_contents($root.'/resources/registry/graphs.sources.v2.json'),true,512,JSON_THROW_ON_ERROR);
$bindings=json_decode(file_get_contents($root.'/resources/registry/graph-bindings.v2.json'),true,512,JSON_THROW_ON_ERROR);
$fields=(new Customs\Domain\Registry())->fields();$byId=array_column($bindings['graphs'],null,'id');
foreach($sources['graphs'] as &$graph){
    $graph=array_replace($graph,$byId[$graph['id']]??throw new RuntimeException('Missing graph binding'));
    foreach($graph['fieldBindings'] as $field) if(!isset($fields[$field]))throw new RuntimeException('Unknown binding: '.$field);
}
unset($graph);
$sources['bindingVersion']=$bindings['version'];$sources['profile']=$bindings['profile'];
$sources['instructionSha256']=hash_file('sha256',$root.'/reference/rules/DT_2026.docx');
file_put_contents($root.'/resources/registry/graphs.im40.v2.json',json_encode($sources,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo count($sources['graphs'])." bound graph positions\n";
