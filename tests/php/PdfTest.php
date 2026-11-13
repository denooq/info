<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';
test('PDF includes current Unicode data, document codes and full overflow, without registered statuses',function():void{
    $s=completeDraftState(5);$s['calculation']=(new Customs\Domain\Calculator())->calculate($s);
    $s['draft']['goods'][0]['description']='Қазақстан құжаты '.str_repeat('Длинное описание товара; ',70).'КОНЕЦ_ПОЛНОГО_ТЕКСТА';
    $s['draft']['documents'][0]['number']='10-18/332';$s['draft']['documents'][0]['presentationCode']='1';
    $bytes=(new Customs\Export\PdfDeclaration())->render($s,sys_get_temp_dir().'/customs-pdf-test-'.bin2hex(random_bytes(6)));
    $pdf=(new Smalot\PdfParser\Parser())->parseContent($bytes);$text=$pdf->getText();
    foreach(['Қазақстан','КОНЕЦ_ПОЛНОГО_ТЕКСТА','10-18/332','04021/1','17065348.27','Товар 5'] as $expected)truth(mb_stripos($text,$expected)!==false,$expected);
    foreach(['55411/281026/0028206','Выпуск разрешён','Электронная подпись'] as $forbidden)truth(!str_contains($text,$forbidden),$forbidden);
    same(3,$s['calculation']['sheetCount']);truth(count($pdf->getPages())>=4);
});
