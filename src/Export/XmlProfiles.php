<?php
declare(strict_types=1);
namespace Customs\Export;

use Brick\Math\BigDecimal as D;
use Brick\Math\RoundingMode as R;
use Customs\Domain\Registry;
use Customs\Http\ApiError;

final class XmlProfiles
{
    public static function profile(string $kind):array { return json_decode(file_get_contents(dirname(__DIR__,2).'/resources/xml-profiles/'.$kind.'.v1.json'),true,512,JSON_THROW_ON_ERROR); }
    public function requireSupported(array $snapshot):void
    {
        $d=$snapshot['data']['draft'];$unsupported=[];
        foreach(['container','specificationsApplicable','referenceApplicable','deferment','relatedParties','useRestrictions','valueConditions'] as $key)if($d['declaration'][$key]===true)$unsupported[]='declaration/_/'.$key;
        foreach($d['goods'] as $g)foreach(['hasQuota','hasPreviousDocument','additionalUnitRequired','hasPreference'] as $key)if($g[$key]===true)$unsupported[]='goods/'.$g['id'].'/'.$key;
        foreach($snapshot['data']['calculation']['items'] as $g)foreach(['deductionValue','indirectValue'] as $key)if(!D::of($g[$key])->isZero())$unsupported[]='goods/'.$g['id'].'/'.$key;
        if($unsupported!==[])throw new ApiError('XML_BRANCH_UNSUPPORTED','Эти данные требуют веток XML, которых нет в предоставленном образце. Расчёт сохранён; формат можно расширить после получения XSD.',422,['addresses'=>$unsupported]);
    }
    public function serialize(array $snapshot):array
    {
        $this->requireSupported($snapshot);
        $files=['declaration.xml'=>$this->declaration($snapshot),'customs-value-declaration.xml'=>$this->valuation($snapshot)];
        (new XmlProfileValidator())->validate($files,$snapshot);
        return $files;
    }
    private function declaration(array $snapshot):string
    {
        $x=new XmlDocument(self::profile('dt'));$d=$snapshot['data']['draft'];$c=$snapshot['data']['calculation'];$v=$d['declaration'];
        $root=$x->root;$gd=$x->add($root,'gdgdc:GoodsDeclaration');
        $x->add($gd,'csdo:EDocCode','R.055');$x->add($gd,'csdo:EDocId',$snapshot['declarationId']);$x->add($gd,'csdo:EDocDateTime',$v['signatureDate'].'T00:00:00+05:00');
        $x->add($gd,'casdo:DeclarationKindCode',$v['kind']);$x->add($gd,'casdo:CustomsProcedureCode',$v['procedure'],['codeListId'=>'2002']);$x->add($gd,'casdo:EDocIndicatorCode',$v['format']);
        $x->add($gd,'csdo:PageQuantity',$c['sheetCount']);$x->add($gd,'casdo:GoodsQuantity',$c['goodsCount']);$x->add($gd,'casdo:CargoQuantity',$c['packageCount']);
        $this->subject($x,$gd,'cacdo:DeclarantDetails',$this->party($d,'declarant'));
        $s=$x->add($gd,'cacdo:GDGoodsShipmentDetails');
        foreach(['DepartureCountryDetails'=>'dispatchCountry','DestinationCountryDetails'=>'destinationCountry','TradeCountryDetails'=>'tradeCountry'] as $name=>$key)$this->country($x,$s,$name,$v[$key]);
        $delivery=$x->add($s,'cacdo:DeliveryTermsDetails');$x->add($delivery,'casdo:DeliveryTermsCode',$v['deliveryCode'],['codeListId'=>'2014']);$x->add($delivery,'casdo:PlaceName',$v['deliveryPlace']);
        $this->amount($x,$s,'CAValueAmount',$c['totals']['invoiceTotal'],$v['invoiceCurrency']);
        foreach(['ConsignorDetails'=>'consignor','ConsigneeDetails'=>'consignee','FinancialSettlementSubjectDetails'=>'financial'] as $name=>$role)$this->subject($x,$s,'cacdo:'.$name,$this->party($d,$role));
        $this->amount($x,$s,'CustomsValueAmount',$c['totals']['customsValue'],'KZT');
        $origins=array_unique(array_column($d['goods'],'originCountry'));if(count($origins)===1)$this->country($x,$s,'OriginCountryDetails',array_values($origins)[0]);
        $nature=$x->add($s,'cacdo:TransactionNatureDetails');$x->add($nature,'casdo:TransactionNatureCode',$v['transactionCode']);$x->add($nature,'casdo:TransactionFeatureCode',$v['transactionFeature']);
        $consignment=$x->add($s,'cacdo:GDConsignmentDetails');$x->add($consignment,'casdo:ContainerIndicator',$v['container']);
        foreach(['BorderTransportDetails'=>'border','ArrivalDepartureTransportDetails'=>'arrival'] as $name=>$stage)foreach($d['transports'] as $t)if($t['stage']===$stage){
            $transport=$x->add($consignment,'cacdo:'.$name);$x->add($transport,'csdo:UnifiedTransportModeCode',$t['mode'],['codeListId'=>'2004']);$x->add($transport,'casdo:RegistrationNationalityCode',$t['country'],['codeListId'=>'2021']);
            $registrations=array_values(array_filter(array_map('trim',preg_split('/[;\n]+/u',$t['registration']))));
            $x->add($transport,'casdo:TransportMeansQuantity',count($registrations));
            foreach($registrations as $i=>$registration){$tr=$x->add($transport,'cacdo:TransportMeansItemDetails');$x->add($tr,'csdo:ObjectOrdinal',$i+1);$x->add($tr,'csdo:TransportMeansRegId',$registration,['countryCode'=>$t['country'],'countryCodeListId'=>'2021']);$x->optional($tr,'casdo:TransportTypeCode',$t['transportType'],['codeListId'=>'2024']);}
        }
        $office=$x->add($consignment,'cacdo:BorderCustomsOfficeDetails');$x->add($office,'csdo:CustomsOfficeCode',$v['borderOfficeCode']);$x->optional($office,'csdo:CustomsOfficeName',$v['borderOfficeName']);$x->add($office,'csdo:UnifiedCountryCode',$v['borderOfficeCountry'],['codeListId'=>'2021']);
        $location=$x->add($s,'cacdo:GoodsLocationDetails');$x->add($location,'casdo:GoodsLocationCode',$v['locationCode'],['codeListId'=>'2013']);$register=$x->add($location,'cacdo:RegisterDocumentIdDetails');$x->add($register,'csdo:UnifiedCountryCode',$v['locationCountry'],['codeListId'=>'2021']);$x->add($register,'casdo:RegistrationNumberId',$v['locationRegistration']);
        foreach($d['goods'] as $i=>$g){
            $calc=$c['items'][$i];$item=$x->add($s,'cacdo:GDGoodsItemDetails');$x->add($item,'casdo:ConsignmentItemOrdinal',$i+1);$x->add($item,'csdo:CommodityCode',$g['commodityCode']);$x->add($item,'casdo:GoodsDescriptionText',$g['description']);
            foreach(['UnifiedGrossMassMeasure'=>'grossMass','UnifiedNetMassMeasure'=>'netMass'] as $name=>$key){$mass=D::of($g[$key]);$x->add($item,'csdo:'.$name,(string)$mass->toScale($mass->isLessThan('0.001')?6:3,R::HalfUp),['measurementUnitCode'=>'166','measurementUnitCodeListId'=>'2016']);}
            $x->add($item,'casdo:PageOrdinal',$calc['sheetOrdinal']);foreach($g['prohibitionCodes'] as $code)$x->add($item,'casdo:ProhibitionCode',$code);$x->add($item,'casdo:IPOCode',$g['intellectualPropertyCode']);
            $group=$x->add($item,'cacdo:GoodsItemGroupDetails');$x->add($group,'casdo:GoodsDescriptionText',$g['description']);$gi=$x->add($group,'cacdo:CommodityGroupItemDetails');
            $description=$x->add($gi,'cacdo:CommodityDescriptionDetails');
            foreach(['casdo:TradeMarkName'=>'tradeMark','csdo:ProductMarkName'=>'brand','csdo:ProductModelName'=>'model','csdo:ProductId'=>'article'] as $name=>$key)$x->optional($description,$name,$g[$key]);
            if($g['manufacturer']!==null){$m=$x->add($gi,'cacdo:ManufacturerDetails');$x->add($m,'csdo:SubjectName',$g['manufacturer']);}
            $measure=$x->add($gi,'cacdo:GoodsMeasureDetails');$x->add($measure,'casdo:GoodsMeasure',$g['netMass'],['measurementUnitCode'=>'166','measurementUnitCodeListId'=>'2016']);
            $package=$x->add($item,'cacdo:CargoPackagePalletDetails');$x->add($package,'casdo:PackageAvailabilityCode','1');$x->add($package,'casdo:CargoQuantity',$g['packageQuantity']);$p=$x->add($package,'cacdo:PackagePalletDetails');$x->add($p,'casdo:InformationKindCode','0');$x->add($p,'csdo:PackageKindCode',$g['packageKind'],['codeListId'=>'2013']);$x->add($p,'csdo:PackageQuantity',$g['packageQuantity']);
            $this->country($x,$item,'OriginCountryDetails',$g['originCountry']);
            $preference=$x->add($item,'cacdo:PreferenceDetails');foreach(['CustomsClearanceChargesPrefCode'=>'preferenceFee','CustomsDutyPrefCode'=>'preferenceDuty','ExcisePrefCode'=>'preferenceExcise','VATPrefCode'=>'preferenceVat'] as $name=>$key)$x->add($preference,'casdo:'.$name,$g[$key],['codeListId'=>'2008']);
            $procedure=$x->add($item,'cacdo:CustomsProcedureDetails');$x->add($procedure,'casdo:CustomsProcedureCode','40',['codeListId'=>'2002']);$x->add($procedure,'casdo:PreviousCustomsProcedureModeCode',$g['previousProcedure'],['codeListId'=>'2002']);$x->add($procedure,'casdo:GoodsMoveFeatureCode',$g['movementFeature'],['codeListId'=>'2003']);
            $this->amount($x,$item,'CAValueAmount',$g['invoiceValue'],$v['invoiceCurrency']);$this->amount($x,$item,'CustomsValueAmount',$calc['customsValue'],'KZT');$this->amount($x,$item,'StatisticValueAmount',$calc['statisticalValue'],'USD');$x->add($item,'casdo:ValuationMethodCode','1',['codeListId'=>'2005']);
            foreach($d['documents'] as $doc)if(in_array($g['id'],$doc['goodsIds'],true))$this->document($x,$item,'cacdo:PresentedDocDetails',$doc,true);
            foreach($calc['payments'] as $payment){$p=$x->add($item,'cacdo:CustomsPaymentDetails');$x->add($p,'casdo:CustomsTaxModeCode',$payment['code'],['codeListId'=>'2010']);
                if($payment['rate']!==null){$x->add($p,'casdo:TaxBaseMeasure',$payment['base']);$x->add($p,'csdo:UnifiedCurrencyN3Code','398',['codeListId'=>'2022']);$rate=$x->add($p,'cacdo:EffectiveCustomsRateDetails');$x->add($rate,'casdo:DutyTaxFeeRateKindCode','%');$x->add($rate,'casdo:DutyTaxFeeRateValue',$payment['rate']);}
                $x->add($p,'casdo:DutyTaxFeeRateDate',$g['ratesDate']);$x->optional($p,'casdo:CustomsTaxPaymentFeatureCode',$g['paymentFeature']??null,['codeListId'=>'2011']);$this->amount($x,$p,'CAPaymentNAmount',$payment['amount'],'398');
            }
        }
        foreach($d['payments'] as $p)if($v['paymentFactsAvailable']===true){$node=$x->add($s,'cacdo:GDFactPaymentDetails');$x->add($node,'casdo:CustomsTaxModeCode',$p['code'],['codeListId'=>'2010']);$x->add($node,'casdo:CAPaymentNAmount',(string)D::of($p['amount'])->toScale(0,R::HalfUp),['currencyCodeListId'=>'2022']);$doc=$x->add($node,'cacdo:GDPaymentDocDetails');$x->optional($doc,'csdo:DocCreationDate',$p['date']);$x->optional($doc,'casdo:PaymentDate',$p['date']);}
        if($v['representativeUsed']===true){$rep=$x->add($gd,'cacdo:SignatoryRepresentativeDetails');$r=$x->add($rep,'cacdo:RegisterDocumentIdDetails');$x->add($r,'casdo:RegistrationNumberId',$v['representativeRegistry']);$x->add($rep,'cacdo:RepresentativeContractDetails');}
        $this->signatory($x,$gd,$v);
        foreach($d['goods'] as $i=>$g){$ki=$x->add($root,'ccecd:KedenGoodsItemDetails');$x->add($ki,'ccecd:GoodsItemOrder',$i+1);$x->optional($ki,'ccecd:Ntincode',$g['ntinCode']);}
        $x->add($root,'ccecd:CustomsOfficeCode',$d['keden']['officeCode']);$x->add($root,'ccecd:RateType',$d['keden']['rateType']);$f=$x->add($root,'ccecd:FillerInformation');foreach(['FillerType'=>'fillerType','FilledBy'=>'filledBy','PersonId'=>'personId'] as $name=>$key)$x->add($f,'ccecd:'.$name,$d['keden'][$key]);
        return $x->bytes();
    }
    private function valuation(array $snapshot):string
    {
        $x=new XmlDocument(self::profile('dts'));$root=$x->root;$d=$snapshot['data']['draft'];$c=$snapshot['data']['calculation'];$v=$d['declaration'];
        $x->add($root,'csdo:EDocCode','R.038');$x->add($root,'csdo:EDocId',$snapshot['valueDeclarationId']);$x->add($root,'csdo:EDocDateTime',$v['signatureDate'].'T00:00:00+05:00');$x->add($root,'casdo:EDocIndicatorCode',$v['format']);$x->add($root,'casdo:ReferenceDocumentId',$snapshot['declarationId']);$x->add($root,'casdo:CVDFormCode','1');$x->add($root,'casdo:ValuationMethodCode','1',['codeListId'=>'2005']);
        foreach(['SellerDetails'=>'seller','BuyerDetails'=>'buyer','DeclarantDetails'=>'declarant'] as $name=>$role)$this->subject($x,$root,'cacdo:'.$name,$this->party($d,$role));
        $delivery=$x->add($root,'cacdo:DeliveryTermsDetails');$x->add($delivery,'casdo:DeliveryTermsCode',$v['deliveryCode'],['codeListId'=>'2014']);$x->add($delivery,'casdo:PlaceName',$v['deliveryPlace']);
        $method=$x->add($root,'cacdo:CVDMethod1Details');
        foreach(['cacdo:PaymentInvoiceDetails'=>['04021','04023'],'cacdo:ContractDetails'=>['03011','03012']] as $name=>$kinds)foreach($d['documents'] as $doc)if(in_array($doc['id'],$v['valuationDocumentIds'],true)&&in_array($doc['kind'],$kinds,true))$this->document($x,$method,$name,$doc,false);
        $relation=$x->add($method,'cacdo:BuyerSellerRelationDetails');$x->add($relation,'casdo:RelationIndicator',$v['relatedParties']);$restriction=$x->add($method,'cacdo:GoodsUseRestrictionDetails');$x->add($restriction,'casdo:RestrictionIndicator',$v['useRestrictions']);$x->add($restriction,'casdo:ValueConditionIndicator',$v['valueConditions']);
        $royalty=$x->add($method,'cacdo:BuyerSellerRoyaltyFeeDetails');foreach(['RoyaltyContractIndicator'=>'royaltyContract','RoyaltyFeeIndicator'=>'royaltyFees','SubsequentResaleIndicator'=>'subsequentProceeds'] as $name=>$key)$x->add($royalty,'casdo:'.$name,$v[$key]);
        $rate=$this->rate($d,$v['invoiceCurrency']);$usd=$this->rate($d,'USD');
        foreach($d['goods'] as $i=>$g){$calc=$c['items'][$i];$item=$x->add($root,'cacdo:CVDGoodsItemDetails');$x->add($item,'casdo:ConsignmentItemOrdinal',$i+1);$x->add($item,'casdo:PageOrdinal',$calc['dtsPageOrdinal']);$x->add($item,'csdo:CommodityCode',$g['commodityCode']);$x->add($item,'casdo:ValuationMethodCode','1',['codeListId'=>'2005']);$this->amount($x,$item,'CustomsValueAmount',$calc['customsValue'],'KZT');$this->amount($x,$item,'CustomsValueAmount',$calc['customsValueUsd'],'USD');$this->amount($x,$item,'ExchangeRate',$usd,'KZT');
            $details=$x->add($item,'cacdo:CVDMethod1CalculationDetails');$basis=$x->add($details,'cacdo:Method1BasisCalculationDetails');$this->amount($x,$basis,'InvoicePriceAmount',$g['invoiceValue'],$v['invoiceCurrency']);$this->amount($x,$basis,'NationalInvoicePriceAmount',$calc['nationalInvoiceValue'],'KZT');$this->amount($x,$basis,'PriceCurrencyRate',$rate,$v['invoiceCurrency']);$this->amount($x,$basis,'TotalAmount',$calc['basisValue'],'KZT');$add=$x->add($details,'cacdo:Method1AddCostsDetails');$x->add($add,'casdo:PlaceName',$v['valuationPlace']);$this->amount($x,$add,'TotalAmount',$calc['additionValue'],'KZT');
        }
        $this->signatory($x,$root,$v);return $x->bytes();
    }
    private function subject(XmlDocument $x,\DOMElement $parent,string $name,array $p):void
    {
        $s=$x->add($parent,$name);$x->add($s,'csdo:SubjectName',$p['name']);$x->optional($s,'csdo:SubjectBriefName',$p['briefName']);$x->optional($s,'csdo:TaxpayerId',$p['taxId']);
        $address=$x->add($s,'ccdo:SubjectAddressDetails');$x->add($address,'csdo:UnifiedCountryCode',$p['country'],['codeListId'=>'2021']);foreach(['RegionName'=>'region','CityName'=>'city','StreetName'=>'street'] as $name=>$key)$x->optional($address,'csdo:'.$name,$p[$key]);
    }
    private function party(array $draft,string $role,array $seen=[]):array { if(isset($seen[$role]))throw new ApiError('PARTY_LINK','Циклическая ссылка участника.');$seen[$role]=true;foreach($draft['parties'] as $p)if($p['role']===$role)return $p['sameAsRole']===null?$p:$this->party($draft,$p['sameAsRole'],$seen);throw new ApiError('PARTY_REQUIRED','Нет участника '.$role); }
    private function country(XmlDocument $x,\DOMElement $p,string $name,string $code):void { $n=$x->add($p,'cacdo:'.$name);$x->add($n,'casdo:CACountryCode',$code,['codeListId'=>'2021']); }
    private function amount(XmlDocument $x,\DOMElement $p,string $name,string $amount,string $currency):void { $x->add($p,'casdo:'.$name,$amount,['currencyCode'=>$currency,'currencyCodeListId'=>'2022']); }
    private function document(XmlDocument $x,\DOMElement $p,string $name,array $d,bool $present):void { $n=$x->add($p,$name);$x->add($n,'csdo:DocKindCode',$d['kind'],['codeListId'=>'2009']);$x->add($n,'csdo:DocName',$d['name']);$x->add($n,'csdo:DocId',$d['number']);$x->optional($n,'csdo:DocCreationDate',$d['date']);if($present){$x->optional($n,'csdo:DocStartDate',$d['validFrom']);$x->optional($n,'csdo:DocValidityDate',$d['validUntil']);$v=$x->add($n,'cacdo:DocumentPresentingDetails');$x->add($v,'casdo:DocPresentKindCode',$d['presentationCode']);} }
    private function signatory(XmlDocument $x,\DOMElement $p,array $v):void { $n=$x->add($p,'cacdo:SignatoryPersonV2Details');$s=$x->add($n,'cacdo:SigningDetails');$f=$x->add($s,'ccdo:FullNameDetails');$x->add($f,'csdo:FirstName',$v['signatoryFirstName']);$x->optional($f,'csdo:MiddleName',$v['signatoryMiddleName']);$x->add($f,'csdo:LastName',$v['signatoryLastName']);$x->add($s,'csdo:PositionName',$v['signatoryPosition']);$comm=$x->add($s,'ccdo:CommunicationDetails');$x->add($comm,'csdo:CommunicationChannelId',$v['signatoryContact']);$x->add($s,'casdo:SigningDate',$v['signatureDate']);if($v['authorityRequired']===true){$a=$x->add($n,'cacdo:PowerOfAttorneyDetails');$x->add($a,'csdo:DocId',$v['authorityNumber']);$x->add($a,'csdo:DocCreationDate',$v['authorityDate']);$x->add($a,'csdo:DocValidityDate',$v['authorityValidUntil']);} }
    private function rate(array $d,string $currency):string { if($currency==='KZT')return '1';foreach($d['rates'] as $r)if($r['currency']===$currency)return (string)D::of($r['value'])->dividedBy($r['nominal'],12,R::HalfUp)->strippedOfTrailingZeros();throw new ApiError('RATE_REQUIRED','Нет курса '.$currency); }
}
