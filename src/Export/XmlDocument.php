<?php
declare(strict_types=1);
namespace Customs\Export;

final class XmlDocument
{
    public readonly \DOMDocument $document;
    public readonly \DOMElement $root;
    public function __construct(public readonly array $profile)
    {
        $this->document=new \DOMDocument('1.0','UTF-8');$this->document->formatOutput=true;
        [$prefix]=explode(':',$profile['root']);
        $this->root=$this->document->createElementNS($profile['namespaces'][$prefix],$profile['root']);$this->document->appendChild($this->root);
        foreach($profile['namespaces'] as $p=>$uri)$this->root->setAttributeNS('http://www.w3.org/2000/xmlns/','xmlns:'.$p,$uri);
    }
    public function add(\DOMElement $parent,string $name,string|int|bool|null $value=null,array $attributes=[]):\DOMElement
    {
        [$prefix]=explode(':',$name);$node=$this->document->createElementNS($this->profile['namespaces'][$prefix],$name);
        if($value!==null)$node->appendChild($this->document->createTextNode(is_bool($value)?($value?'1':'0'):(string)$value));
        foreach($attributes as $key=>$v)$node->setAttribute($key,(string)$v);
        $parent->appendChild($node);return $node;
    }
    public function optional(\DOMElement $parent,string $name,mixed $value,array $attributes=[]):void { if($value!==null && $value!=='')$this->add($parent,$name,$value,$attributes); }
    public function bytes():string { return $this->document->saveXML(); }
}
