<?php
declare(strict_types=1);
namespace Customs\Domain;

final class GraphRegistry
{
    private array $data;
    public function __construct() { static $cache=null;$this->data=$cache??=json_decode(file_get_contents(dirname(__DIR__,2).'/resources/registry/graphs.im40.v2.json'),true,512,JSON_THROW_ON_ERROR); }
    public function data(): array { return $this->data; }
    public function graphs(): array { return $this->data['graphs']; }
    public function graph(string $id): array
    {
        foreach($this->graphs() as $graph) if($graph['id']===$id)return $graph;
        throw new \InvalidArgumentException('Unknown graph');
    }
    public function reviewFields(): array
    {
        $ids=[];
        foreach($this->graphs() as $graph) if($graph['calculationStage']==='review' && $graph['fillMode']!=='not_applicable') array_push($ids,...$graph['fieldBindings']);
        return array_values(array_unique($ids));
    }
    public function rows(array $draft, array $graph, array $field): array
    {
        if($field['scope']==='declaration')return [$draft['declaration']];
        $collection=['party'=>'parties','transport'=>'transports','goods'=>'goods','document'=>'documents','rate'=>'rates','payment'=>'payments'][$field['scope']]??null;
        if($collection===null)return [];
        $rows=$draft[$collection];
        if($field['scope']==='party') {
            $byRole=array_column($rows,null,'role');$role=$graph['entityRole']??null;$rows=[];$seen=[];
            while($role!==null&&isset($byRole[$role])&&!isset($seen[$role])){$rows[]=$byRole[$role];$seen[$role]=true;$role=$byRole[$role]['sameAsRole']??null;}
        }
        if($field['scope']==='transport')$rows=array_values(array_filter($rows,fn($r)=>$r['stage']===($graph['entityRole']??null)));
        if($field['scope']==='rate')$rows=array_values(array_filter($rows,fn($r)=>$r['currency']===($draft['declaration']['invoiceCurrency']??null)));
        return $rows;
    }
}
