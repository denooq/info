<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/reference-state.php';
test('both reference scenarios keep PDF, DT and DTS values and document presentation codes consistent',function():void{
    foreach(['reference-cpt','reference-fca'] as $case){
        $s=referenceState($case);$s['confirmed']=$s['artifactSet']=null;$s['calculation']=(new Customs\Domain\Calculator())->calculate($s);
        $snapshot=(new Customs\Export\Snapshot())->confirm($s,$s['calculation']['hash'])['confirmed'];
        $files=(new Customs\Export\XmlProfiles())->serialize($snapshot);
        $bytes=(new Customs\Export\PdfDeclaration())->render($snapshot['data'],sys_get_temp_dir().'/customs-reference-pdf-'.bin2hex(random_bytes(6)));
        $text=(new Smalot\PdfParser\Parser())->parseContent($bytes)->getText();
        $expected=$case==='reference-cpt'?'17065348.27':'3509649.02';
        truth(str_contains($text,$expected));truth(str_contains($files['declaration.xml'],$expected));truth(str_contains($files['customs-value-declaration.xml'],$expected));
        if($case==='reference-cpt'){truth(str_contains($text,'01999/1'));truth(str_contains($files['declaration.xml'],'<casdo:DocPresentKindCode>1</casdo:DocPresentKindCode>'));}
    }
});
