<?php
declare(strict_types=1);
namespace Customs\Export;

use Closure;
use Customs\Http\ApiError;

final class Bundle
{
    /** The XML callback must serialize and validate both official profiles before returning bytes. */
    public function __construct(private readonly Closure $xml, private readonly ?\Customs\Config $config=null) {}
    public function run(array $job,array $state,string $directory):array
    {
        $snapshot=$state['confirmed'];
        if($snapshot===null || $snapshot['revision']!==$state['dataRevision'])throw new ApiError('SNAPSHOT_STALE','Подтверждённые данные устарели.',409);
        $tag=$snapshot['id'];$staging=$directory.'/artifacts/'.$tag.'.pending';
        if(!is_dir($staging) && !mkdir($staging,0700))throw new ApiError('ARTIFACT_STORAGE','Не удалось подготовить комплект.',503);
        $documents=($this->xml)($snapshot);
        if(array_keys($documents)!==['declaration.xml','customs-value-declaration.xml'])throw new ApiError('XML_SET','Нужны ровно два проверенных XML.',503);
        $files=['declaration.pdf'=>(new PdfDeclaration())->render($snapshot['data'],$directory.'/scratch/mpdf-'.$tag),...$documents];
        foreach($files as $name=>$bytes)if(!is_string($bytes)||@file_put_contents($staging.'/'.$name,$bytes,LOCK_EX)!==strlen($bytes))throw new ApiError('ARTIFACT_STORAGE','Не удалось записать полный комплект.',503);
        $zip=new \ZipArchive();if($zip->open($staging.'/declaration-xml.zip',\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new ApiError('ZIP_FAILED','Не удалось создать ZIP.',503);
        foreach($documents as $name=>$bytes)if(!$zip->addFromString($name,$bytes))throw new ApiError('ZIP_FAILED','Не удалось добавить XML в ZIP.',503);
        if(!$zip->close())throw new ApiError('ZIP_FAILED','Не удалось завершить ZIP.',503);
        $manifest=['snapshotId'=>$tag,'snapshotHash'=>$snapshot['hash'],'revision'=>$state['dataRevision'],'files'=>[]];
        foreach(['declaration.pdf','declaration-xml.zip'] as $name)$manifest['files'][$name]=['sha256'=>hash_file('sha256',$staging.'/'.$name),'bytes'=>filesize($staging.'/'.$name)];
        if($this->config!==null){
            $used=0;foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->config->get('runtime_dir'),\FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile()&&!$file->isLink())$used+=$file->getSize();
            if($used>$this->config->get('max_runtime_bytes'))throw new ApiError('STORAGE_LIMIT','Недостаточно места для публикации полного комплекта.',503);
        }
        return ['manifest'=>$manifest,'directoryName'=>$tag.'.pending'];
    }
    public function apply(array $state,array $payload,array $job):array
    {
        $snapshot=$state['confirmed'];$m=$payload['manifest'];
        if($snapshot===null||$snapshot['hash']!==$m['snapshotHash']||$state['dataRevision']!==$m['revision'])throw new ApiError('SNAPSHOT_STALE','Комплект относится к прежней версии данных.',409);
        $state['artifactSet']=[...$m,'directoryName'=>$payload['directoryName']];$state['phase']='ready';$state['error']=null;return $state;
    }
}
