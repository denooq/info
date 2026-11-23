<?php
declare(strict_types=1);

test('complete official classifiers reproduce source rows and explicit amendments',function():void {
    $root=dirname(__DIR__,2);$patch=json_decode(file_get_contents($root.'/reference/classifiers/amendments.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($patch['sources'] as $source) if(isset($source['file'])) same($source['sha256'],hash_file('sha256',$root.'/reference/classifiers/'.$source['file']));
    $built=(new Customs\Domain\ClassifierImporter())->import($root.'/reference/classifiers/decision-378-adilet-2026-11-23.html',$patch);
    $catalog=new Customs\Domain\Classifiers();same($built,$catalog->data());
    same(339,$built['coverage']['documentSourceRows']);same(250,$built['coverage']['countrySourceRows']);
    same(331,count($built['documents']));same(249,count($built['countries']));same(6,count($built['retired']));
    same('КАЗАХСТАН',$catalog->country('KZ')['name']);same(null,$catalog->country('398'));
    same('Отгрузочный (упаковочный) лист',$catalog->document('04131')['name']);
    truth($catalog->document('03031')['selectable'],'multi-country restriction includes Kazakhstan');
    truth(!$catalog->document('04026')['selectable']);truth(!$catalog->document('07013','2026-11-23')['selectable']);
    truth($catalog->document('07013','2026-07-04')['selectable']);
    truth(!$catalog->document('10048','2026-11-23')['selectable']);truth($catalog->document('10048','2027-04-01')['selectable']);
    truth(!str_contains($catalog->document('10042','2026-11-23')['name'],'10048'));truth(str_contains($catalog->document('10042','2027-04-01')['name'],'10048'));
    truth($catalog->document('07053','2026-11-23')['selectable']);truth(!$catalog->document('06023','2026-11-23')['selectable']);
    foreach($built['documents'] as $row) truth(is_string($row['code'])&&preg_match('/^\d{5}$/D',$row['code'])===1);
});

test('document search is literal and pagination covers the entire official catalog',function():void {
    $catalog=new Customs\Domain\Classifiers();$all=[];$offset=0;
    do{$page=$catalog->documents('',$offset,17,'2026-11-23');array_push($all,...array_column($page['items'],'code'));$offset=$page['nextOffset'];}while($offset!==null);
    same(331,count(array_unique($all)));same(331,$page['total']);
    same('04021',$catalog->documents('04021')['items'][0]['code']);
    same('04131',$catalog->documents('  УПАКОВОЧНЫЙ  ')['items'][0]['code']);
    same(0,$catalog->documents('CMR')['total']);same(0,$catalog->documents('packing list')['total']);same(0,$catalog->documents('накладная CMR')['total']);
    truth($catalog->documents('02026')['retired']!==[]);
});
