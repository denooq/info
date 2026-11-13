<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/fixtures.php';
foreach([1,2,5] as $count){
    $s=completeDraftState($count);
    $s['draft']['goods'][0]['description'].=' · Қазақша: Қазақстан, құжат, өнім, өлшем';
    $s['calculation']=(new Customs\Domain\Calculator())->calculate($s);
    $s['draft']['documents'][]=array_replace($s['draft']['documents'][0],['id'=>'letter','kind'=>'09999','name'=>'Письмо по СЭС','number'=>'10-18/332','presentationCode'=>'1']);
    $scratch=dirname(__DIR__,2).'/.local-tools/pdf-scratch';
    $bytes=(new Customs\Export\PdfDeclaration())->render($s,$scratch);
    $path=dirname(__DIR__,2).'/.local-tools/declaration-'.$count.'.pdf';file_put_contents($path,$bytes);
    $pdf=(new Smalot\PdfParser\Parser())->parseContent($bytes);
    echo json_encode(['goods'=>$count,'pages'=>count($pdf->getPages()),'bytes'=>strlen($bytes),'peak'=>memory_get_peak_usage(true)]).PHP_EOL;
}
