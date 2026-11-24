<?php
declare(strict_types=1);
namespace Customs\Domain;

use Brick\Math\BigDecimal;

final class GraphDefaults
{
    public static function apply(array $draft): array
    {
        self::set($draft,'declaration',null,'destinationCountry','KZ','profile');
        self::set($draft,'declaration',null,'goodsCount',count($draft['goods']),'rule');
        $sourceCount=$draft['declaration']['sourcePackageCount']??null;$sourceMeta=$draft['fieldStates']['declaration/_/sourcePackageCount']??[];
        $sourceId='package-recognized-count';$autoIndex=null;
        foreach($draft['packages'] as $i=>$package)if($package['id']===$sourceId&&($package['_fromCount']??false))$autoIndex=$i;
        if(is_int($sourceCount)&&$sourceCount>0&&!($sourceMeta['conflict']??false)&&!($sourceMeta['needsReview']??false)&&($draft['packages']===[]||$autoIndex!==null)) {
            $package=['id'=>$sourceId,'label'=>'Места поставки','quantity'=>$sourceCount,'kind'=>null,'_fromCount'=>true,'evidenceIds'=>$sourceMeta['evidenceIds']??[]];
            if($autoIndex===null)$draft['packages'][]=$package;else $draft['packages'][$autoIndex]=$package;
            $draft['declaration']['shipmentPackageIds']=array_values(array_unique([...($draft['declaration']['shipmentPackageIds']??[]),$sourceId]));
            if(count($draft['goods'])===1&&($draft['goods'][0]['packageIds']??[])===[])$draft['goods'][0]['packageIds']=[$sourceId];
        } elseif($autoIndex!==null) $draft['packages'][$autoIndex]['quantity']=null;
        $total=BigDecimal::zero();$complete=$draft['goods']!==[];
        foreach($draft['goods'] as $i=>$goods) {
            self::set($draft,'goods',$goods['id'],'ordinal',$i+1,'rule');
            $preferenceKeys=['preferenceFee','preferenceDuty','preferenceExcise','preferenceVat'];
            $preferenceMeta=$draft['fieldStates']['goods/'.$goods['id'].'/hasPreference']??[];
            $hasPreferenceEvidence=($preferenceMeta['evidenceIds']??[])!==[]||in_array($preferenceMeta['status']??'', ['manual','selected','auto'], true);
            $medical=str_starts_with((string)($goods['commodityCode']??''),'30')||preg_match('/медицин|лекарствен|фармацев|протез|\\bmedical\\b|\\bmedicin|\\bpharmaceu/ui',(string)($goods['description']??''));
            $preferencesEmpty=($goods['hasPreference']??null)===null&&array_reduce($preferenceKeys,fn(bool $empty,string $key):bool=>$empty&&($goods[$key]??null)===null,true);
            if($medical&&($preferenceMeta['status']??null)==='profile'&&($goods['hasPreference']??null)===false) {
                $goodsIndex=array_search($goods['id'],array_column($draft['goods'],'id'),true);
                if($goodsIndex!==false) {
                    $draft['goods'][$goodsIndex]['hasPreference']=null;$draft['fieldStates']['goods/'.$goods['id'].'/hasPreference']=DeclarationDate::meta('unknown');
                    foreach($preferenceKeys as $key)if(($draft['goods'][$goodsIndex][$key]??null)==='ОО'){$draft['goods'][$goodsIndex][$key]=null;$draft['fieldStates']['goods/'.$goods['id'].'/'.$key]=DeclarationDate::meta('unknown');}
                    $draft['fieldStates']['goods/'.$goods['id'].'/preferenceBasis']=DeclarationDate::meta('unknown');
                }
            }
            if($preferencesEmpty&&!$hasPreferenceEvidence&&!$medical) {
                $goodsIndex=array_search($goods['id'],array_column($draft['goods'],'id'),true);
                if($goodsIndex!==false) {
                    $draft['goods'][$goodsIndex]['hasPreference']=false;
                    $draft['fieldStates']['goods/'.$goods['id'].'/hasPreference']=DeclarationDate::meta('profile');
                    foreach($preferenceKeys as $key) {$draft['goods'][$goodsIndex][$key]='ОО';$draft['fieldStates']['goods/'.$goods['id'].'/'.$key]=DeclarationDate::meta('profile');}
                    $draft['fieldStates']['goods/'.$goods['id'].'/preferenceBasis']=DeclarationDate::meta('profile');
                }
            }
            $linkedQuantity=($draft['fieldStates']['goods/'.$goods['id'].'/packageQuantity']['status']??'')==='linked';
            if(count($draft['goods'])===1&&is_int($sourceCount)&&$sourceCount>0&&(($draft['goods'][0]['packageQuantity']??null)===null||$linkedQuantity)&&($draft['goods'][0]['packageIds']??[])===[$sourceId])self::set($draft,'goods',$goods['id'],'packageQuantity',$sourceCount,'linked');
            if(($goods['invoiceValue']??null)===null)$complete=false;
            else try{$total=$total->plus($goods['invoiceValue']);}catch(\Throwable){$complete=false;}
        }
        self::set($draft,'declaration',null,'invoiceTotal',$complete?(string)$total:null,'rule');
        $byId=array_column($draft['packages'],null,'id');$count=0;$complete=($draft['declaration']['shipmentPackageIds']??[])!==[];
        foreach(array_unique($draft['declaration']['shipmentPackageIds']??[]) as $id) {
            if(!isset($byId[$id])||!is_int($byId[$id]['quantity'])||$byId[$id]['quantity']<1){$complete=false;continue;}
            $count+=$byId[$id]['quantity'];
        }
        self::set($draft,'declaration',null,'packageCount',$complete?$count:null,'rule');
        return $draft;
    }
    private static function set(array &$draft,string $scope,?string $id,string $key,mixed $value,string $status):void
    {
        if($scope==='declaration')$draft['declaration'][$key]=$value;
        else foreach($draft['goods'] as &$row)if($row['id']===$id)$row[$key]=$value;
        unset($row);
        $draft['fieldStates'][Draft::address($scope,$id,$key)]=DeclarationDate::meta($status);
    }
}
