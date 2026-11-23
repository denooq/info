<?php
declare(strict_types=1);
namespace Customs\Storage;

use Customs\Domain\GraphDefaults;
use Customs\Http\ApiError;

final class SessionMigration
{
    public static function upgrade(array $state): array
    {
        $version=$state['schemaVersion']??1;
        if(!in_array($version,[1,2],true))throw new ApiError('SESSION_VERSION','Версия сессии не поддерживается этой сборкой. Данные сохранены; используйте совместимую версию приложения.',409);
        if($version===1) {
            $state['schemaVersion']=2;$state['registryVersion']='2.0.0';$state['profile']='im40-kz-graphs';$state['uploadGroups']??=[];
            if(in_array($state['phase'],['ready','generating','confirmed','calculated'],true))$state['phase']='reviewing';
            $state['confirmed']=$state['artifactSet']=$state['calculation']=null;
            foreach($state['jobs'] as &$job)if($job['kind']==='generate')$job['status']='cancelled';unset($job);
            foreach($state['documents'] as &$doc){$doc['groupId']??=null;$doc['documentKind']??=null;$doc['classifierVersion']??=null;}unset($doc);
        }
        $state['confirmed']=$state['artifactSet']=null;
        if(in_array($state['phase'],['ready','generating','confirmed'],true))$state['phase']='reviewing';
        foreach($state['jobs'] as &$job)if($job['kind']==='generate')$job['status']='cancelled';unset($job);
        return $state;
    }
}
