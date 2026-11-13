<?php
declare(strict_types=1);
namespace Customs\Export;

use Customs\Domain\Calculator;
use Customs\Domain\Registry;
use Customs\Domain\Validator;
use Customs\Http\ApiError;

final class Snapshot
{
    public function confirm(array $state, string $calculationHash): array
    {
        (new Validator(new Registry()))->requireValid($state);
        $calculation=$state['calculation'];
        if($calculation===null || $calculation['dataRevision']!==$state['dataRevision'])throw new ApiError('CALC_STALE','Пересчитайте текущие данные.',409);
        if(!$calculation['consistent'])throw new ApiError('CALC_INCONSISTENT','Ручные результаты расходятся с формулой.',422);
        $fresh=(new Calculator())->calculate($state);
        if(!hash_equals($fresh['hash'],$calculationHash) || !hash_equals($fresh['hash'],$calculation['hash']))throw new ApiError('CALC_STALE','Изменилась версия расчёта или правил. Выполните пересчёт.',409);
        if($state['confirmed']!==null && $state['confirmed']['revision']===$state['dataRevision'] && $state['confirmed']['calculationHash']===$fresh['hash'])return $state;
        $content=['draft'=>$state['draft'],'calculation'=>$fresh,'registryVersion'=>$state['registryVersion'],'rules'=>Registry::resource('rules'),'profile'=>Registry::resource('calculation-profile')];
        $snapshot=['id'=>self::uuid(),'declarationId'=>self::uuid(),'valueDeclarationId'=>self::uuid(),'revision'=>$state['dataRevision'],'calculationHash'=>$fresh['hash'],'data'=>$content];
        $snapshot['hash']=hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $state['confirmed']=$snapshot;$state['artifactSet']=null;
        return $state;
    }
    private static function uuid():string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);
        $hex=bin2hex($bytes);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
