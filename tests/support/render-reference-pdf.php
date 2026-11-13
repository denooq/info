<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/reference-state.php';
foreach(['reference-cpt','reference-fca'] as $case){$s=referenceState($case);$s['calculation']=(new Customs\Domain\Calculator())->calculate($s);$bytes=(new Customs\Export\PdfDeclaration())->render($s,dirname(__DIR__,2).'/.local-tools/pdf-scratch');file_put_contents(dirname(__DIR__,2).'/.local-tools/'.$case.'.pdf',$bytes);echo $case.' '.strlen($bytes).PHP_EOL;}
