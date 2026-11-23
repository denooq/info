<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);$source=$root.'/reference/rules/Книга1 (2).xlsx';
$matrix=(new Customs\Domain\GraphMatrixImporter())->import($source);
$target=$root.'/resources/registry/graphs.sources.v2.json';
$json=json_encode($matrix,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
if(file_put_contents($target,$json)===false)throw new RuntimeException('Cannot write graph matrix');
echo json_encode(['graphs'=>count($matrix['graphs']),'sourceSha256'=>$matrix['source']['sha256'],'target'=>'resources/registry/graphs.sources.v2.json'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
