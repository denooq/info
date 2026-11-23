<?php
declare(strict_types=1);

namespace Customs\Documents;

use Customs\Config;
use Customs\Http\ApiError;
use Customs\Storage\SessionStore;

final class Uploads
{
    public function __construct(private readonly Config $config, private readonly SessionStore $store) {}

    public function receive(string $sessionId, array $upload, int $revision, bool $userActivity = false, ?string $groupId = null): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $limit = in_array($upload['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new ApiError($limit ? 'FILE_LIMIT' : 'UPLOAD_FAILED', $limit ? 'Превышен лимит загрузки PHP/приложения.' : 'Не удалось загрузить файл целиком.');
        }
        if (!is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name']) || !is_string($upload['name'] ?? null)) throw new ApiError('UPLOAD_FAILED', 'Неверная загрузка файла.');
        return $this->addFromPath($sessionId, $upload['tmp_name'], $upload['name'], $revision, $userActivity, $groupId);
    }

    /** Local tests and HTTP receive() supply a local path; never expose this method with a client path. */
    public function addFromPath(string $sessionId, string $sourcePath, string $name, int $revision, bool $userActivity = false, ?string $groupId = null): array
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))) ?: 'document';
        if (mb_strlen($name) > 240) throw new ApiError('FILE_NAME', 'Слишком длинное имя файла.');
        $this->store->read($sessionId);
        $lock = fopen($this->config->get('runtime_dir') . '/heavy.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new ApiError('PROCESSING_BUSY', 'Обработка занята. Повторите загрузку через несколько секунд.', 503);
        }
        $destination = null;
        $committed = false;
        try {
            $info = (new Inspector($this->config))->inspect($sourcePath, $name);
            $id = bin2hex(random_bytes(16));
            $destination = $this->store->documentPath($sessionId, $id, $info['extension']);
            $state = $this->store->mutate($sessionId, $revision, function (array $state) use ($id, $sourcePath, $name, $info, $destination, $groupId): array {
                UploadGroups::requireMutable($state);
                $identity=$groupId===null?['groupId'=>null,'documentKind'=>null,'classifierVersion'=>null]:UploadGroups::identity($state,$groupId);
                if (count($state['documents']) >= $this->config->get('max_files')) throw new ApiError('FILE_COUNT_LIMIT', 'Превышен лимит количества файлов.');
                $total = array_sum(array_column($state['documents'], 'size'));
                $pages = array_sum(array_column($state['documents'], 'pages'));
                if ($total + $info['size'] > $this->config->get('max_session_bytes')) throw new ApiError('SESSION_SIZE_LIMIT', 'Превышен общий размер документов сессии.');
                if ($pages + $info['pages'] > $this->config->get('max_pages')) throw new ApiError('PAGE_LIMIT', 'Превышен общий лимит страниц сессии.');
                $used = $this->runtimeSize();
                $free = disk_free_space($this->config->get('runtime_dir'));
                if ($used + $info['size'] > $this->config->get('max_runtime_bytes') || ($free !== false && $free < $info['size'] + 10_485_760)) throw new ApiError('STORAGE_LIMIT', 'Недостаточно места во временном хранилище.', 503);
                if (!copy($sourcePath, $destination)) throw new ApiError('STORAGE_ERROR', 'Не удалось сохранить загруженный файл.', 503);
                @chmod($destination, 0600);
                $state['documents'][$id] = ['id' => $id, 'name' => $name, 'mime' => $info['mime'], 'size' => $info['size'], 'pages' => $info['pages'], 'hash' => $info['hash'], 'storageName' => basename($destination), 'extension' => $info['extension'], 'status' => 'pending', 'error' => null,...$identity];
                $state['phase'] = 'uploading';
                return $state;
            },true,$userActivity);
            $committed = true;
            return $state;
        } finally {
            if (!$committed && $destination !== null && is_file($destination)) @unlink($destination);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function remove(string $sessionId, string $documentId, int $revision, bool $userActivity = false): array
    {
        $directory=$this->store->directory($sessionId);
        $this->store->mutate($sessionId, $revision, function (array $state) use ($documentId,$directory): array {
            UploadGroups::requireMutable($state);
            if (!isset($state['documents'][$documentId])) throw new ApiError('DOCUMENT_NOT_FOUND', 'Документ не найден.', 404);
            return $this->withoutDocuments($state,[$documentId],$directory);
        },true,$userActivity);
        $this->store->cleanupDeletedFiles($sessionId);
        return $this->store->read($sessionId);
    }

    public function changeGroup(string $sessionId,array $request,int $revision,bool $userActivity=false):array
    {
        $directory=$this->store->directory($sessionId);
        $this->store->mutate($sessionId,$revision,function(array $state)use($request,$directory):array {
            UploadGroups::requireMutable($state);
            if(($request['action']??'add')==='remove') {
                $id=$request['id']??null;
                if(!is_string($id)||!isset($state['uploadGroups'][$id]))throw new ApiError('UPLOAD_GROUP_UNKNOWN','Вид не добавлен в эту сессию.',404);
                $ids=array_keys(array_filter($state['documents'],fn($d)=>($d['groupId']??null)===$id));
                $state=$this->withoutDocuments($state,$ids,$directory);
            }
            return (new UploadGroups())->change($state,$request);
        },true,$userActivity);
        $this->store->cleanupDeletedFiles($sessionId);
        return $this->store->read($sessionId);
    }

    private function withoutDocuments(array $state,array $ids,string $directory):array
    {
        if($ids===[])return $state;
        $pending=$state['pendingFileCleanup']??[];
        foreach($ids as $id) {
            $name=$state['documents'][$id]['storageName']??'';
            if(is_string($name)&&preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|xlsx)$/D',$name))$pending[]='documents/'.$name;
            if(preg_match('/^[a-f0-9]{32}$/D',$id))foreach(glob($directory.'/ocr/'.$id.'-*') as $path)$pending[]='ocr/'.basename($path);
            unset($state['documents'][$id]);
        }
        foreach(glob($directory.'/ocr/mapping-*') as $path)$pending[]='ocr/'.basename($path);
        $state['pendingFileCleanup']=array_values(array_unique($pending));
        $removed=[];
        foreach($state['evidence'] as $key=>$evidence)if(in_array($evidence['documentId']??null,$ids,true)){$removed[]=$key;unset($state['evidence'][$key]);}
        $model=new \Customs\Domain\Draft(new \Customs\Domain\Registry());
        foreach($state['candidates'] as $key=>&$candidate) {
            $affected=array_intersect($candidate['documentIds']??[],$ids)!==[]||array_intersect($candidate['evidenceIds']??[],$removed)!==[];
            if(!$affected)continue;
            $candidate['documentIds']=array_values(array_diff($candidate['documentIds']??[],$ids));
            $candidate['evidenceIds']=array_values(array_diff($candidate['evidenceIds']??[],$removed));
            $candidate['rawValues']=array_values(array_filter($candidate['rawValues']??[],fn($r)=>!in_array($r['evidenceId']??null,$removed,true)));
            if($candidate['evidenceIds']===[]||(($candidate['origin']??'')==='tnved'&&$candidate['documentIds']===[])){unset($state['candidates'][$key]);continue;}
            [$scope,$entityId]=explode('/',$candidate['address'],3);$row=&$model->entity($state['draft'],$scope,$entityId==='_'?null:$entityId);$ranks=[];
            foreach($candidate['evidenceIds'] as $evidenceId){$kind=$state['evidence'][$evidenceId]['documentKind']??null;if($kind!==null){$rank=\Customs\Domain\GraphSources::rank($candidate['fieldId'],$row,$kind);if($rank!==null)$ranks[]=$rank;}}
            if($ranks!==[])$candidate['rank']=min($ranks);unset($row);
        }
        unset($candidate);
        foreach($state['draft']['fieldStates'] as $address=>&$field) {
            $affected=array_intersect($field['evidenceIds']??[],$removed)!==[]||(($field['candidateId']??null)!==null&&!isset($state['candidates'][$field['candidateId']]));
            $field['evidenceIds']=array_values(array_diff($field['evidenceIds']??[],$removed));
            $field['reviewedCandidateIds']=array_values(array_filter($field['reviewedCandidateIds']??[],fn($id)=>isset($state['candidates'][$id])));
            if(!$affected)continue;
            if(in_array($field['status']??'',['manual','selected'],true)) {
                if(!isset($state['candidates'][$field['candidateId']??''])){$field['status']='manual';$field['candidateId']=null;}
            } elseif($field['evidenceIds']===[]) {
                [$scope,$entityId,$key]=explode('/',$address,3);$row=&$model->entity($state['draft'],$scope,$entityId==='_'?null:$entityId);$row[$key]=null;unset($row);
                $field=array_replace($field,['status'=>'unknown','candidateId'=>null,'conflict'=>false,'needsReview'=>true]);
            }
        }
        unset($field);
        foreach($state['draft']['documents'] as &$record)$record['documentIds']=array_values(array_diff($record['documentIds']??[],$ids));unset($record);
        foreach($state['draft']['packages'] as &$package)if(isset($package['evidenceIds']))$package['evidenceIds']=array_values(array_diff($package['evidenceIds'],$removed));unset($package);
        foreach($state['draft']['sourceLines'] as $key=>$line)if(in_array($line['evidenceId']??null,$removed,true))unset($state['draft']['sourceLines'][$key]);
        foreach($state['draft']['goods'] as &$goods) {
            $goods['sourceLineIds']=array_values(array_intersect($goods['sourceLineIds']??[],array_keys($state['draft']['sourceLines'])));
            if(($state['draft']['declaration']['sourcePackageCount']??null)===null&&($state['draft']['fieldStates']['goods/'.$goods['id'].'/packageQuantity']['status']??'')==='linked')$goods['packageQuantity']=null;
        }
        unset($goods);
        foreach($state['draft']['entityAliases'] as $key=>$alias)foreach($ids as $id)if(str_contains($key,':'.$id.':')){unset($state['draft']['entityAliases'][$key]);break;}
        foreach($state['tnvedQueries']??[] as $goodsId=>$queries)foreach($queries as $key=>$query)if(array_intersect($query['evidenceIds']??[],$removed)!==[])unset($state['tnvedQueries'][$goodsId][$key]);
        foreach($state['jobs'] as $key=>$job)if($job['kind']==='mapping'||in_array($job['documentId']??null,$ids,true))unset($state['jobs'][$key]);
        unset($state['mappingProgress']);$state['mappingIssues']=[];$state['error']=null;
        // Removing the last scan must not strand an already reviewed manual draft.
        $wasReviewed=in_array($state['phase'],['reviewing','calculation','calculated'],true);
        $pending=array_filter($state['documents'],fn($d)=>!in_array($d['status'],['recognized','error'],true));
        $state['phase']=$wasReviewed&&$pending===[]?'reviewing':'uploading';
        $state=(new \Customs\AI\CandidateMerger(new \Customs\Domain\Registry()))->reconcile($state);
        return (new \Customs\Domain\TariffDefaults())->apply($state);
    }

    private function runtimeSize(): int
    {
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->config->get('runtime_dir'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && !$file->isLink()) $size += $file->getSize();
        }
        return $size;
    }
}
