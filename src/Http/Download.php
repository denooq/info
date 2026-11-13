<?php
declare(strict_types=1);
namespace Customs\Http;

use Customs\Storage\SessionStore;

final class Download
{
    private function __construct(private readonly string $path,private readonly string $name,private readonly string $mime) {}
    public static function fromState(SessionStore $store,array $state,string $kind):self
    {
        $set=$state['artifactSet'];$confirmed=$state['confirmed'];
        if($set===null||$confirmed===null||$set['revision']!==$state['dataRevision']||$set['snapshotHash']!==$confirmed['hash'])throw new ApiError('ARTIFACTS_NOT_READY','Создайте актуальный полный комплект.',409);
        $name=$kind==='pdf'?'declaration.pdf':'declaration-xml.zip';$dir=$set['directoryName'];
        if(!preg_match('/^[a-f0-9-]{36}\.pending$/D',$dir))throw new ApiError('ARTIFACT_PATH','Недопустимый комплект.',503);
        $path=$store->directory($state['id']).'/artifacts/'.$dir.'/'.$name;
        if(!is_file($path)||is_link($path)||!hash_equals($set['files'][$name]['sha256'],hash_file('sha256',$path)))throw new ApiError('ARTIFACT_INTEGRITY','Комплект повреждён. Создайте его повторно.',503);
        return new self($path,$name,$kind==='pdf'?'application/pdf':'application/zip');
    }
    public function send():void
    {
        header('Content-Type: '.$this->mime);header('Content-Disposition: attachment; filename="'.$this->name.'"');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Content-Length: '.filesize($this->path));
        $handle=fopen($this->path,'rb');if($handle===false)throw new ApiError('ARTIFACT_UNAVAILABLE','Комплект недоступен.',410);
        try{fpassthru($handle);}finally{fclose($handle);}
    }
}
