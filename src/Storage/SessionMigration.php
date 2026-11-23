<?php
declare(strict_types=1);
namespace Customs\Storage;

use Customs\Domain\GraphDefaults;
use Customs\Http\ApiError;

final class SessionMigration
{
    public static function upgrade(array $state, bool $new = false): array
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
        $resourceHash = hash('sha256', json_encode(\Customs\Domain\StatutoryRules::resource(), JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $first = ($state['draft']['compactVersion'] ?? null) !== \Customs\Domain\GraphEditors::VERSION;
        $oldRulesHash = $state['draft']['statutoryRules']['hash'] ?? null;
        $changedVersion = ($state['draft']['ruleResourceHash'] ?? null) !== $resourceHash;
        $legacy = [];
        if ($first && !$new) {
            foreach ((new \Customs\Domain\Registry())->fields() as $field) {
                if (!\Customs\Domain\StatutoryRules::owns($field['id'])) continue;
                $rows = $field['scope'] === 'declaration' ? [$state['draft']['declaration']] : $state['draft']['goods'];
                foreach ($rows as $row) {
                    $address = \Customs\Domain\Draft::address($field['scope'],$row['id'] ?? null,$field['key']);
                    if (($row[$field['key']] ?? null) !== null) $legacy[$address] = ['value'=>$row[$field['key']],'meta'=>$state['draft']['fieldStates'][$address] ?? []];
                }
            }
            $state['draft']['legacyStatutory'] = $legacy;
        }
        $state['draft']['compactVersion'] = \Customs\Domain\GraphEditors::VERSION;
        $state['draft']['ruleResourceHash'] = $resourceHash;
        $state = (new \Customs\Domain\StatutoryRules())->apply($state);
        if (!$new && ($first || $changedVersion || $oldRulesHash !== $state['draft']['statutoryRules']['hash'])) {
            $state['dataRevision']++;
            $state['calculation'] = $state['confirmed'] = $state['artifactSet'] = null;
            if ($state['phase'] === 'calculated') $state['phase'] = 'calculation';
            $changes = [];
            foreach ($legacy as $address=>$old) {
                [$scope,$id,$key] = explode('/',$address,3);
                $row = $scope === 'declaration' ? $state['draft']['declaration'] : (array_column($state['draft']['goods'],null,'id')[$id] ?? []);
                if (($row[$key] ?? null) !== $old['value']) $changes[] = ['address'=>$address,'previous'=>$old['value'],'current'=>$row[$key] ?? null];
            }
            $state['migrationNotices'] = [['id'=>'compact-'.\Customs\Domain\GraphEditors::VERSION.'-'.$resourceHash,'message'=>'Форма обновлена. Нормативные ставки определяет сервер; прежний расчёт требует пересчёта. Документы и введённые сведения сохранены.','changes'=>$changes,'sources'=>$state['draft']['statutoryRules']['sources']]];
            $state['_migrationPending'] = true;
        }
        return $state;
    }
}
