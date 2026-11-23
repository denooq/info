<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);
$amendments=json_decode(file_get_contents($root.'/reference/classifiers/amendments.json'),true,512,JSON_THROW_ON_ERROR);
foreach($amendments['sources'] as $source) if(isset($source['file']) && hash_file('sha256',$root.'/reference/classifiers/'.$source['file'])!==$source['sha256']) throw new RuntimeException('Classifier source changed');
$data=(new Customs\Domain\ClassifierImporter())->import($root.'/reference/classifiers/decision-378-adilet-2026-11-23.html',$amendments);
file_put_contents($root.'/resources/registry/classifiers.v2.json',json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo count($data['documents']).' document positions, '.count($data['countries'])." countries\n";
