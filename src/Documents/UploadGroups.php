<?php
declare(strict_types=1);
namespace Customs\Documents;

use Customs\Domain\Classifiers;
use Customs\Http\ApiError;

final class UploadGroups
{
    public function change(array $state, array $request): array
    {
        $state['uploadGroups']??=[];$action=$request['action']??'add';$id=$request['id']??null;
        if($action==='remove') {
            if(!is_string($id)||!isset($state['uploadGroups'][$id]))throw new ApiError('UPLOAD_GROUP_UNKNOWN','Вид не добавлен в эту сессию.',404);
            foreach($state['documents'] as $doc)if(($doc['groupId']??null)===$id)throw new ApiError('UPLOAD_GROUP_NOT_EMPTY','Сначала удалите или перенесите вложения этого вида.',422);
            unset($state['uploadGroups'][$id]);return $state;
        }
        if(!in_array($action,['add','type'],true)||!is_string($request['code']??null)||!is_string($request['version']??null))throw new ApiError('UPLOAD_GROUP_REQUEST','Выберите официальный вид документа.',422);
        $catalog=new Classifiers();$kind=$catalog->requireDocument($request['code'],$request['version']);$target='kind-'.$kind['code'];
        if(!isset($state['uploadGroups'][$target]))$state['uploadGroups'][$target]=['id'=>$target,'code'=>$kind['code'],'version'=>$catalog->version(),'name'=>$kind['name']];
        if($action==='type') {
            if(!is_string($id)||!isset($state['uploadGroups'][$id]))throw new ApiError('UPLOAD_GROUP_UNKNOWN','Вид не добавлен в эту сессию.',404);
            foreach($state['documents'] as &$doc)if(($doc['groupId']??null)===$id)$doc=array_replace($doc,self::identity($state,$target));unset($doc);
            if($id!==$target)unset($state['uploadGroups'][$id]);
        }
        return $state;
    }
    public function assign(array $state,string $documentId,string $groupId):array
    {
        if(!isset($state['documents'][$documentId]))throw new ApiError('DOCUMENT_NOT_FOUND','Файл не найден.',404);
        $state['documents'][$documentId]=array_replace($state['documents'][$documentId],self::identity($state,$groupId));
        return $state;
    }
    public static function identity(array $state,string $groupId):array
    {
        $group=$state['uploadGroups'][$groupId]??throw new ApiError('UPLOAD_GROUP_UNKNOWN','Сначала добавьте вид документа из классификатора.',422);
        (new Classifiers())->requireDocument($group['code'],$group['version']);
        return ['groupId'=>$groupId,'documentKind'=>$group['code'],'classifierVersion'=>$group['version']];
    }
}
