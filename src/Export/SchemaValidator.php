<?php
declare(strict_types=1);
namespace Customs\Export;

use Customs\Config;
use Customs\Http\ApiError;

final class SchemaValidator
{
    public function __construct(private readonly string $root) {}
    public function requireProfiles():array
    {
        $path=$this->root.'/manifest.json';
        if(!is_file($path))throw new ApiError('XSD_UNAVAILABLE','Официальные XSD выбранных версий пока не установлены. Комплект не создан.',503);
        $manifest=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        foreach(['dt'=>'urn:EEC:R:055:GoodsDeclaration:v1.2.0','dts'=>'urn:EEC:R:038:CustomsValueDeclaration:v1.5.0'] as $key=>$ns){
            $profile=$manifest['profiles'][$key]??null;
            if(!is_array($profile)||($profile['namespace']??'')!==$ns||empty($profile['source']))throw new ApiError('XSD_PROFILE','Не указан проверенный источник и точная версия XSD.',503);
            $this->checkedPath($profile['entry']);
        }
        foreach($manifest['files']??[] as $relative=>$checksum)if(!hash_equals($checksum,hash_file('sha256',$this->checkedPath($relative))))throw new ApiError('XSD_CHECKSUM','Контрольная сумма схемы не совпадает.',503);
        if(empty($manifest['files']))throw new ApiError('XSD_PROFILE','Нет контрольных сумм схем.',503);
        return $manifest;
    }
    public function validate(string $xml,string $entry):void
    {
        $path=$this->checkedPath($entry);$this->dependencies($path,[]);
        if(preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))throw new ApiError('XML_ENTITIES','В XML недопустимы внешние сущности.',422);
        $previous=libxml_use_internal_errors(true);libxml_clear_errors();
        try{
            $doc=new \DOMDocument();$doc->resolveExternals=false;$doc->substituteEntities=false;
            if(!$doc->loadXML($xml,LIBXML_NONET)||!$doc->schemaValidate($path)){
                $errors=[];foreach(libxml_get_errors() as $error)$errors[]=['line'=>$error->line,'column'=>$error->column,'message'=>trim(preg_replace('~(?:[A-Za-z]:)?[/\\\\][^\s\'\"]+\.xsd~','[schema]',$error->message))];
                throw new ApiError('XML_SCHEMA_INVALID','XML не прошёл проверку схемы.',422,['errors'=>$errors]);
            }
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
    private function dependencies(string $path,array $seen):void
    {
        if(isset($seen[$path]))return;$seen[$path]=true;
        $xml=file_get_contents($path);if(preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))throw new ApiError('XSD_ENTITIES','Недопустимая схема с сущностями.',503);
        $doc=new \DOMDocument();if(!$doc->loadXML($xml,LIBXML_NONET))throw new ApiError('XSD_INVALID','Схема не читается.',503);
        $xp=new \DOMXPath($doc);$xp->registerNamespace('xs','http://www.w3.org/2001/XMLSchema');
        foreach($xp->query('//xs:import|//xs:include|//xs:redefine') as $node){
            $relative=$node->getAttribute('schemaLocation');
            if($relative===''||preg_match('~^(?:[a-z][a-z0-9+.-]*:|[/\\\\])~i',$relative))throw new ApiError('XSD_EXTERNAL','Зависимость XSD должна быть закреплена локально.',503);
            $dependency=realpath(dirname($path).'/'.$relative);
            if($dependency===false||!Config::inside($dependency,realpath($this->root)))throw new ApiError('XSD_DEPENDENCY','Зависимость схемы отсутствует или вне комплекта.',503);
            $this->dependencies($dependency,$seen);
        }
    }
    private function checkedPath(string $relative):string
    {
        if(preg_match('~^(?:[a-z][a-z0-9+.-]*:|[/\\\\])~i',$relative))throw new ApiError('XSD_PATH','Недопустимый путь схемы.',503);
        $path=realpath($this->root.'/'.$relative);$root=realpath($this->root);
        if($path===false||$root===false||!Config::inside($path,$root)||!is_file($path))throw new ApiError('XSD_UNAVAILABLE','Нет локальной схемы выбранной версии.',503);
        return $path;
    }
}
