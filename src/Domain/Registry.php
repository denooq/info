<?php
declare(strict_types=1);

namespace Customs\Domain;

use Customs\Http\ApiError;

final class Registry
{
    private array $data;
    private array $fields;
    public function __construct()
    {
        $this->data = self::resource('fields');
        $this->data['version']='2.0.0';
        foreach($this->data['fields'] as &$field) if($field['id']==='declaration.destinationCountry')$field['fixedValue']='KZ';
        unset($field);
        $this->data['fields'][]=['id'=>'declaration.sourcePackageCount','key'=>'sourcePackageCount','scope'=>'declaration','label'=>'Количество мест по документам','type'=>'integer','acquisition'=>['extract','manual'],'dtGraphs'=>['6'],'rule'=>'DT:15(6)','requiredWhen'=>false,'validWhen'=>[['op'=>'decimal','min'=>'1']],'sourceHints'=>['number of packages','pallets','количество мест']];
        $this->fields = array_column($this->data['fields'], null, 'id');
    }
    public static function resource(string $name): array
    {
        static $cache=[];
        if (!in_array($name, ['fields','rules','catalogs','calculation-profile'], true)) throw new \InvalidArgumentException('Unknown registry resource');
        if(isset($cache[$name]))return $cache[$name];
        $data=json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/registry/' . $name . '.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        if($name==='catalogs') {
            $official=new Classifiers();$old=array_column($data['lists']['countries'],null,'code');
            $data['lists']['countries']=array_map(fn($r)=>['code'=>$r['code'],'label'=>$r['name'],'aliases'=>$old[$r['code']]['aliases']??[]],$official->data()['countries']);
            $dateRules=array_column($data['lists']['documentKinds'],null,'code');
            $data['lists']['documentKinds']=array_map(fn($r)=>['code'=>$r['code'],'label'=>$r['name'],'dateRequired'=>$dateRules[$r['code']]['dateRequired']??true,'restriction'=>$r['restriction']],$official->data()['documents']);
            $data['classifierVersion']=$official->version();
        }
        return $cache[$name]=$data;
    }
    public function field(string $id): array
    {
        return $this->fields[$id] ?? throw new ApiError('FIELD_UNKNOWN', 'Неизвестное поле декларации.');
    }
    public function fields(): array { return $this->fields; }
    public function data(): array { return $this->data; }
    public function extractable(): array
    {
        $inactive=['3','4','7','10','13','27','28','40','48','49','50','51','52','53'];
        return array_filter($this->fields,fn($f)=>in_array('extract',$f['acquisition'],true)&&!isset($f['fixedValue'])&&$f['scope']!=='keden'&&($f['dtGraphs']===[]||array_diff($f['dtGraphs'],$inactive)!==[]));
    }
}
