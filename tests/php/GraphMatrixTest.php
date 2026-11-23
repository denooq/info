<?php
declare(strict_types=1);

test('workbook graph matrix preserves all 57 positions, priorities, labels and exact source identity',function():void {
    $matrix=(new Customs\Domain\GraphMatrixImporter())->import(dirname(__DIR__,2).'/reference/rules/Книга1 (2).xlsx');
    same('a013d4f4f2ad62c5ee29ad23108eb58ef9e5b67b0c1b566698623ab46ef071aa',$matrix['source']['sha256']);
    same(57,count($matrix['graphs']));$graphs=array_column($matrix['graphs'],null,'id');
    foreach([...array_map('strval',range(1,54)),'15a','17a','B'] as $id)truth(isset($graphs[$id]),$id);
    same('ПРОЦЕДУРА',$graphs['37']['title']);same('Вес брутто (кг)',$graphs['35']['title']);
    same(['товаротранспортный документ','упаковочный лист','инвойс'],$graphs['35']['priorities']);
    same('графа «В»',$graphs['B']['sourceNumber']);same('398 (всегда)',$graphs['17a']['priorities'][0]);
    same(14,count(array_filter($graphs,fn($g)=>$g['priorities'][0]==='не заполняется')));
    $stored=json_decode(file_get_contents(dirname(__DIR__,2).'/resources/registry/graphs.sources.v2.json'),true,512,JSON_THROW_ON_ERROR);
    same($matrix,$stored);
});
