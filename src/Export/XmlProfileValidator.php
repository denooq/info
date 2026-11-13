<?php
declare(strict_types=1);
namespace Customs\Export;

use Brick\Math\BigDecimal;
use Customs\Http\ApiError;

final class XmlProfileValidator
{
    private array $children=[];
    public function __construct()
    {
        foreach(['dt','dts'] as $kind)foreach(XmlProfiles::profile($kind)['nodes'] as $node){
            $name=$node['name'];$this->children[$name]??=[];
            foreach($node['children'] as $child)if(!in_array($child,$this->children[$name],true))$this->children[$name][]=$child;
        }
        // Reuse the observed common subject type across roles; no new element names.
        $subject=['csdo:SubjectName','csdo:SubjectBriefName','casdo:CAUniqueCustomsNumberId','csdo:TaxpayerId','ccdo:SubjectAddressDetails'];
        foreach(['DeclarantDetails','SellerDetails','BuyerDetails','ConsignorDetails','ConsigneeDetails','FinancialSettlementSubjectDetails'] as $name)$this->children['cacdo:'.$name]=[...$subject,'casdo:EqualIndicator'];
        $this->children['ccdo:SubjectAddressDetails']=['csdo:UnifiedCountryCode','csdo:AddressKindCode','csdo:RegionName','csdo:CityName','csdo:StreetName'];
    }
    public function validate(array $files,array $snapshot):array
    {
        $documents=[];
        foreach(['dt'=>'declaration.xml','dts'=>'customs-value-declaration.xml'] as $kind=>$name){
            $xml=$files[$name]??'';
            if(preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))$this->fail('/','Внешние сущности недопустимы.');
            $old=libxml_use_internal_errors(true);libxml_clear_errors();
            try{$doc=new \DOMDocument();$doc->resolveExternals=false;$doc->substituteEntities=false;if(!$doc->loadXML($xml,LIBXML_NONET))$this->fail('/','XML не читается.');}
            finally{libxml_clear_errors();libxml_use_internal_errors($old);}
            $profile=XmlProfiles::profile($kind);$root=$doc->documentElement;
            if($root->nodeName!==$profile['root'])$this->fail('/','Неверный корневой элемент.');
            $this->structure($root,$profile['namespaces'],'');
            $xp=new \DOMXPath($doc);foreach($profile['namespaces'] as $prefix=>$uri)$xp->registerNamespace($prefix,$uri);
            $documents[$kind]=[$doc,$xp];
        }
        [$dt,$dx]=$documents['dt'];[$dts,$vx]=$documents['dts'];$c=$snapshot['data']['calculation'];$d=$snapshot['data']['draft'];
        $this->equal($dx,'//gdgdc:GoodsDeclaration/csdo:EDocId',$snapshot['declarationId']);
        $this->equal($vx,'/cvd:CustomsValueDeclaration/csdo:EDocId',$snapshot['valueDeclarationId']);
        $this->equal($vx,'/cvd:CustomsValueDeclaration/casdo:ReferenceDocumentId',$snapshot['declarationId']);
        foreach(['declarationId','valueDeclarationId'] as $key)if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$snapshot[$key]))$this->fail('/','Неверный технический UUID.');
        $di=$dx->query('//cacdo:GDGoodsItemDetails');$vi=$vx->query('/cvd:CustomsValueDeclaration/cacdo:CVDGoodsItemDetails');
        if($di->length!==count($d['goods'])||$vi->length!==count($d['goods']))$this->fail('/','Различается количество товаров.');
        foreach($d['goods'] as $i=>$g){$ordinal=$i+1;$calc=$c['items'][$i];$dp='//cacdo:GDGoodsItemDetails[casdo:ConsignmentItemOrdinal='.$ordinal.']';$vp='/cvd:CustomsValueDeclaration/cacdo:CVDGoodsItemDetails[casdo:ConsignmentItemOrdinal='.$ordinal.']';
            foreach([[$dx,$dp],[$vx,$vp]] as [$xp,$path]){$this->equal($xp,$path.'/csdo:CommodityCode',$g['commodityCode']);$this->equal($xp,$path.'/casdo:CustomsValueAmount[@currencyCode="KZT"]',$calc['customsValue'],true);}
            $this->equal($dx,$dp.'/csdo:UnifiedGrossMassMeasure',$g['grossMass'],true);$this->equal($dx,$dp.'/csdo:UnifiedNetMassMeasure',$g['netMass'],true);
            $this->equal($dx,$dp.'/casdo:StatisticValueAmount',$calc['statisticalValue'],true);
            $this->equal($vx,$vp.'/cacdo:CVDMethod1CalculationDetails/cacdo:Method1BasisCalculationDetails/casdo:TotalAmount',$calc['basisValue'],true);
            $this->equal($vx,$vp.'/cacdo:CVDMethod1CalculationDetails/cacdo:Method1AddCostsDetails/casdo:TotalAmount',$calc['additionValue'],true);
            foreach($d['documents'] as $document)if(in_array($g['id'],$document['goodsIds'],true)){
                $found=false;foreach($dx->query($dp.'/cacdo:PresentedDocDetails') as $node)if($dx->evaluate('string(csdo:DocId)',$node)===$document['number'] && $dx->evaluate('string(csdo:DocKindCode)',$node)===$document['kind']){
                    if($dx->evaluate('string(cacdo:DocumentPresentingDetails/casdo:DocPresentKindCode)',$node)===$document['presentationCode'])$found=true;
                }
                if(!$found)$this->fail($dp.'/cacdo:PresentedDocDetails','Не совпадают документ или код представления.');
            }
            foreach($calc['payments'] as $p)$this->equal($dx,$dp.'/cacdo:CustomsPaymentDetails[casdo:CustomsTaxModeCode="'.$p['code'].'"]/casdo:CAPaymentNAmount',$p['amount'],true);
            $this->equal($dx,'/ccecd:KedenGoodsDeclaration/ccecd:KedenGoodsItemDetails[ccecd:GoodsItemOrder='.$ordinal.']/ccecd:GoodsItemOrder',(string)$ordinal);
        }
        $this->equal($dx,'//gdgdc:GoodsDeclaration/csdo:PageQuantity',(string)$c['sheetCount']);$this->equal($dx,'//gdgdc:GoodsDeclaration/casdo:GoodsQuantity',(string)$c['goodsCount']);$this->equal($dx,'//gdgdc:GoodsDeclaration/casdo:CargoQuantity',(string)$c['packageCount']);
        $this->equal($dx,'//cacdo:GDGoodsShipmentDetails/casdo:CustomsValueAmount',$c['totals']['customsValue'],true);
        foreach(['officeCode'=>'CustomsOfficeCode','rateType'=>'RateType'] as $key=>$name)$this->equal($dx,'/ccecd:KedenGoodsDeclaration/ccecd:'.$name,$d['keden'][$key]);
        return ['profile'=>'sample-dt-dts-v1','business'=>'passed','sampleStructure'=>'passed','crossFile'=>'passed','officialXsd'=>'not-performed','kedenImport'=>'not-tested'];
    }
    private function structure(\DOMElement $node,array $ns,string $parent):void
    {
        $path=$parent.'/'.$node->nodeName;$prefix=$node->prefix;
        if(!isset($ns[$prefix])||$node->namespaceURI!==$ns[$prefix])$this->fail($path,'Пространство имён отличается от закреплённого профиля.');
        if(!array_key_exists($node->nodeName,$this->children))$this->fail($path,'Элемент отсутствует в профилях образцов.');
        $order=-1;
        foreach($node->childNodes as $child)if($child instanceof \DOMElement){
            $rank=array_search($child->nodeName,$this->children[$node->nodeName],true);
            if($rank===false||$rank<$order)$this->fail($path,'Элемент находится вне наблюдаемого порядка/структуры.');
            $order=$rank;$this->structure($child,$ns,$path);
        }
        if($this->children[$node->nodeName]===[]){
            $value=$node->textContent;$name=$node->localName;
            if(preg_match('/(?:Amount|Measure|RateValue|CurrencyRate|ExchangeRate)$/',$name)&&!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',$value))$this->fail($path,'Ожидается десятичное число.');
            if(preg_match('/(?:Ordinal|Quantity)$/',$name)&&!preg_match('/^[0-9]+$/D',$value))$this->fail($path,'Ожидается неотрицательное целое число.');
            if(str_ends_with($name,'Indicator')&&!in_array($value,['0','1'],true))$this->fail($path,'Ожидается 0 или 1.');
            if($name==='CommodityCode'&&!preg_match('/^[0-9]{10}$/D',$value))$this->fail($path,'Код товара должен содержать 10 цифр.');
            if($name==='DocPresentKindCode'&&!in_array($value,['0','1','2','3'],true))$this->fail($path,'Неизвестный код представления.');
            if(str_ends_with($name,'Date')&&(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)||!\DateTimeImmutable::createFromFormat('!Y-m-d',$value)))$this->fail($path,'Неверная дата.');
        }
    }
    private function equal(\DOMXPath $xp,string $path,string $expected,bool $decimal=false):void
    {
        $nodes=$xp->query($path);if($nodes->length!==1)$this->fail($path,'Обязательное значение отсутствует либо повторено.');$value=$nodes->item(0)->textContent;
        $valid=$decimal?BigDecimal::of($value)->isEqualTo($expected):$value===$expected;
        if(!$valid)$this->fail($path,'Значение не совпадает с подтверждённой версией.');
    }
    private function fail(string $path,string $message):never { throw new ApiError('XML_PROFILE_INVALID','XML не прошёл локальную проверку профиля.',422,['path'=>$path,'message'=>$message,'officialXsd'=>'not-performed']); }
}
