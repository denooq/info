<?php
declare(strict_types=1);
namespace Customs\Domain;

use Customs\Documents\XlsxReader;
use Customs\Http\ApiError;

final class GraphMatrixImporter
{
    public function import(string $path): array
    {
        $workbook=(new XlsxReader())->read($path);
        if(count($workbook['sheets'])!==1)throw new ApiError('GRAPH_MATRIX_SHEETS','Матрица должна содержать один лист.');
        $sheet=$workbook['sheets'][0];$cells=$sheet['cells'];$rows=[];
        foreach($cells as $address=>$cell)if(preg_match('/^([A-E])([0-9]+)$/D',$address,$m))$rows[(int)$m[2]][$m[1]]=trim((string)($cell['value']??''));
        ksort($rows,SORT_NUMERIC);$graphs=[];
        foreach($rows as $rowNumber=>$row) {
            $number=$row['A']??'';
            if($number==='' || $number==='графа в ДТ')continue;
            $id=$number==='графа «В»'?'B':$number;
            if(!preg_match('/^(?:[1-9][0-9]?|15a|17a|B)$/D',$id) || isset($graphs[$id]) || ($row['B']??'')==='')throw new ApiError('GRAPH_MATRIX_ROW','Неизвестная, повторная или неполная строка матрицы.');
            $graphs[$id]=['id'=>$id,'sourceNumber'=>$number,'title'=>$row['B'],'priorities'=>[$row['C']??null,$row['D']??null,$row['E']??null],'sourceCells'=>['number'=>'A'.$rowNumber,'title'=>'B'.$rowNumber,'priorities'=>['C'.$rowNumber,'D'.$rowNumber,'E'.$rowNumber]]];
        }
        $expected=[...array_map('strval',range(1,54)),'15a','17a','B'];
        if(count($graphs)!==57 || array_diff($expected,array_map('strval',array_keys($graphs))))throw new ApiError('GRAPH_MATRIX_COVERAGE','Матрица должна покрывать 1–54, 15a, 17a и графу В.');
        return ['version'=>'2.0.0','profile'=>'im40-kz-graphs','source'=>['file'=>basename($path),'sheet'=>$sheet['name'],'sha256'=>hash_file('sha256',$path)],'graphs'=>array_values($graphs)];
    }
}
