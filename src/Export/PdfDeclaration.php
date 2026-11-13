<?php
declare(strict_types=1);
namespace Customs\Export;

use Customs\Domain\Registry;
use Customs\Http\ApiError;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class PdfDeclaration
{
    private array $continuations=[];
    private array $snapshot;
    public function render(array $snapshot, string $scratch): string
    {
        $this->snapshot=$snapshot; $this->continuations=[];
        if(!is_dir($scratch) && !mkdir($scratch,0700,true))throw new ApiError('PDF_STORAGE','Не удалось подготовить временную печать.',503);
        $pdf=new Mpdf(['tempDir'=>$scratch,'format'=>'A4','default_font'=>'dejavusans','default_font_size'=>7,'margin_left'=>8,'margin_right'=>8,'margin_top'=>8,'margin_bottom'=>10,'shrink_tables_to_fit'=>1]);
        $pdf->SetTitle('Декларация на товары ИМ 40');$pdf->SetAuthor('');
        $pdf->SetHTMLFooter('<div style="text-align:right;font-size:6pt;color:#777">Страница {PAGENO} из {nbpg}</div>');
        $pdf->WriteHTML('table{border-collapse:collapse;width:100%;table-layout:fixed}td{border:0.2mm solid #333;vertical-align:top;padding:0.5mm;font-size:6pt;line-height:1.15} .label{font-size:5.3pt;color:#333;margin-bottom:0.5mm}.title{font-size:10pt;font-weight:bold}.data{font-size:6pt} .muted{font-size:5.5pt;color:#777}.payment td{padding:0.5mm}.appendix{font-size:9pt;line-height:1.5} h2{font-size:11pt}',\Mpdf\HTMLParserMode::HEADER_CSS);
        $pdf->WriteHTML('.compact td,.compact.payment td{font-size:5.5pt;padding:0.35mm}.compact .data{font-size:5.5pt}.compact .label{font-size:5pt;margin-bottom:0.25mm}',\Mpdf\HTMLParserMode::HEADER_CSS);
        $d=$snapshot['draft'];$c=$snapshot['calculation'];
        $html='<div class="title">ДЕКЛАРАЦИЯ НА ТОВАРЫ</div><p class="muted">Подготовленные пользователем сведения · ИМ 40</p><table>';
        $html.=$this->tr($this->box('2','Отправитель / экспортёр',$this->party('consignor'),null,310),$this->box('1','Декларация',$this->graph('1'),null,130));
        $html.=$this->tr($this->box('3','Формы','1 / '.$c['sheetCount']),$this->box('4','Отгрузочные спецификации',$d['declaration']['specificationsApplicable']===true?(string)$d['declaration']['specificationSheets']:''));
        $html.=$this->tr($this->box('5','Всего товаров',(string)$c['goodsCount']),$this->box('6','Всего мест',(string)$c['packageCount']));
        $html.=$this->tr($this->box('8','Получатель',$this->party('consignee'),null,310),$this->box('7','Справочный номер',$d['declaration']['referenceApplicable']===true?$d['declaration']['referenceNumber']:''));
        $html.=$this->tr($this->box('9','Лицо, ответственное за финансовое урегулирование',$this->party('financial'),null,250),$this->box('11','Торгующая страна',$d['declaration']['tradeCountry']));
        $html.=$this->tr($this->box('14','Декларант',$this->party('declarant'),null,310),$this->box('12','Общая таможенная стоимость',$c['totals']['customsValue'].' KZT'));
        foreach([['15','Страна отправления','17','Страна назначения'],['16','Страна происхождения','18','Транспорт при прибытии'],['19','Контейнер','20','Условия поставки'],['21','Транспорт на границе','22','Валюта и общая сумма'],['23','Курс валюты','24','Характер сделки'],['25','Транспорт на границе (вид)','26','Внутренний транспорт (вид)'],['29','Орган въезда','30','Местонахождение товаров']] as [$a,$al,$b,$bl])$html.=$this->tr($this->box($a,$al,$this->graph($a),null,200),$this->box($b,$bl,$this->graph($b),null,200));
        $html.='</table>'.$this->goods($d['goods'][0],$c['items'][0]);
        $html.='<table>'.$this->tr($this->box('48','Отсрочка платежей',$this->graph('48'),null,120),$this->box('B','Подробности подсчёта',$this->paid(),null,180)).$this->tr($this->box('54','Место, дата и сведения о лице, составившем декларацию',$this->graph('54'),null,400),$this->box('A','Регистрация','')).'</table>';
        $pdf->WriteHTML($html);
        for($offset=1;$offset<count($d['goods']);$offset+=3){
            $pdf->AddPage();$sheet=2+intdiv($offset-1,3);
            $html='<div class="title">ДОБАВОЧНЫЙ ЛИСТ ДЕКЛАРАЦИИ НА ТОВАРЫ</div><p>Форма '.$sheet.' / '.$c['sheetCount'].' · ИМ 40</p>';
            foreach(array_slice($d['goods'],$offset,3) as $j=>$g)$html.=$this->goods($g,$c['items'][$offset+$j]);
            $pdf->WriteHTML($html);
        }
        foreach($this->continuations as $continuation){
            $pdf->AddPage();$pdf->WriteHTML('<h2>Продолжение графы '.$this->e($continuation['graph']).($continuation['item']!==null?' · товар '.$continuation['item']:'').'</h2><div class="appendix">'.nl2br($this->e($continuation['text'])).'</div>');
        }
        $bytes=$pdf->Output('',Destination::STRING_RETURN);
        unset($pdf);gc_collect_cycles();return $bytes;
    }
    private function goods(array $g,array $c):string
    {
        $n=$c['ordinal'];$docs=[];
        foreach($this->snapshot['draft']['documents'] as $doc)if(in_array($g['id'],$doc['goodsIds'],true))$docs[]=implode(' ',array_filter([$doc['kind'].'/'.$doc['presentationCode'],$doc['name'],'№ '.$doc['number'],$doc['date']!==null?'от '.$doc['date']:null,$doc['validFrom']!==null?'с '.$doc['validFrom']:null,$doc['validUntil']!==null?'до '.$doc['validUntil']:null]));
        $description=$g['description'];
        foreach(['manufacturer','tradeMark','brand','model','article'] as $key)if(!empty($g[$key]))$description.="\n".(new Registry())->field('goods.'.$key)['label'].': '.$g[$key];
        $description.="\nУпаковка: ".$g['packageKind'].'; количество '.$g['packageQuantity'];
        $description.="\nОграничения: ".implode(', ',$g['prohibitionCodes']).'; интеллектуальная собственность: '.$g['intellectualPropertyCode'];
        if($g['hasPreference']===true)$description.="\nОснование преференции: ".$g['preferenceBasis'];
        $html='<table class="compact" style="margin-top:1mm">'.$this->tr($this->box('31','Грузовые места и описание товаров',$description,$n,240),$this->box('32','Товар',(string)$n,$n));
        foreach([['33','Код товара',$g['commodityCode'],'34','Страна происхождения',$g['originCountry']],['35','Вес брутто, кг',$this->mass($g['grossMass']),'36','Преференции',implode(' ',[$g['preferenceFee'],$g['preferenceDuty'],$g['preferenceExcise'],$g['preferenceVat']])],['37','Процедура','40 '.$g['previousProcedure'].' '.$g['movementFeature'],'38','Вес нетто, кг',$this->mass($g['netMass'])],['39','Квота',$g['hasQuota']===true?$g['quotaQuantity'].' '.$g['quotaUnit']:'','40','Предшествующий документ',$g['hasPreviousDocument']===true?$g['previousDocNumber'].' / '.$g['previousItemNumber']:''],['41','Дополнительные единицы',$g['additionalUnitRequired']===true?$g['additionalQuantity'].' '.$g['additionalUnit']:'','42','Цена товара',$g['invoiceValue']],['43','Метод определения стоимости','1','45','Таможенная стоимость',$c['customsValue'].' KZT']] as [$a,$al,$av,$b,$bl,$bv])$html.=$this->tr($this->box($a,$al,(string)$av,$n,130),$this->box($b,$bl,(string)$bv,$n,130));
        $html.=$this->tr($this->box('44','Дополнительная информация / представленные документы',implode("\n",$docs),$n,340),$this->box('46','Статистическая стоимость',$c['statisticalValue'].' USD',$n));
        $html.='</table><table class="payment compact"><tr><td colspan="5">47 Исчисление платежей · товар '.$n.'</td></tr><tr><td>Вид</td><td>Основа начисления</td><td>Ставка</td><td>Сумма</td><td>Способ уплаты</td></tr>';
        foreach($c['payments'] as $p)$html.='<tr><td>'.$p['code'].'</td><td>'.$this->e($p['base']).'</td><td>'.$this->e($p['rate']===null?'Сбор по формуле':$p['rate'].'%').'</td><td>'.$this->e($p['amount']).'</td><td>'.$this->e($g['paymentFeature']).'</td></tr>';
        return $html.'</table>';
    }
    private function graph(string $graph):string
    {
        $d=$this->snapshot['draft'];$c=$this->snapshot['calculation'];
        if($graph==='16')return implode(', ',array_unique(array_column($d['goods'],'originCountry')));
        if($graph==='18'||$graph==='21')foreach($d['transports'] as $t)if($t['stage']===($graph==='18'?'arrival':'border'))return $t['registration'].' / '.$t['country'];
        if($graph==='22')return $d['declaration']['invoiceCurrency'].' '.$c['totals']['invoiceTotal'];
        if($graph==='23')foreach($d['rates'] as $r)if($r['currency']===$d['declaration']['invoiceCurrency'])return $r['value'].' KZT / '.$r['nominal'].' '.$r['currency'];
        if($graph==='25'||$graph==='26')foreach($d['transports'] as $t)if($t['stage']===($graph==='25'?'border':'arrival'))return $t['mode'];
        $lines=[];
        foreach((new Registry())->fields() as $f)if($f['scope']==='declaration' && in_array($graph,$f['dtGraphs'],true) && !in_array('calculated',$f['acquisition'],true)){
            $v=$d['declaration'][$f['key']]??null;
            if(is_array($f['requiredWhen']) && !\Customs\Domain\Conditions::evaluate($f['requiredWhen'],$d['declaration']))continue;
            if($v===null||$v===[]||$v==='')continue;
            if(is_bool($v))$v=$v?'Да':'Нет';if(is_array($v))$v=implode(', ',$v);
            $lines[]=$f['label'].': '.$v;
        }
        return implode("\n",$lines);
    }
    private function party(string $role,array $seen=[]):string
    {
        if(isset($seen[$role]))throw new ApiError('PARTY_LINK','Циклическая ссылка участника.');$seen[$role]=true;
        foreach($this->snapshot['draft']['parties'] as $p)if($p['role']===$role){
            if($p['sameAsRole']!==null)return $this->party($p['sameAsRole'],$seen);
            return implode("\n",array_filter([$p['name'],implode(', ',array_filter([$p['country'],$p['postalCode'],$p['region'],$p['city'],$p['street']])),$p['taxId']!==null?'Идентификатор: '.$p['taxId']:null]));
        }
        return '';
    }
    private function paid():string { $lines=[];foreach($this->snapshot['calculation']['graphB'] as $code=>$value)$lines[]=$code.' '.$value.' KZT';return implode("\n",$lines); }
    private function box(string $graph,string $label,?string $text,?int $item=null,int $limit=160):string
    {
        $text??='';
        $lineLimit=$item!==null?3:4;
        if(mb_strlen($text)>$limit || count(explode("\n",$text))>$lineLimit){$this->continuations[]=['graph'=>$graph,'item'=>$item,'text'=>$text];$text=implode("\n",array_slice(explode("\n",mb_substr($text,0,$limit)),0,$lineLimit))."…\nСм. продолжение графы ".$graph.($item!==null?' (товар '.$item.')':'');}
        return '<td width="50%"><div class="label">'.$this->e($graph.' '.$label).'</div><div class="data">'.nl2br($this->e($text)).'</div></td>';
    }
    private function mass(string $value):string { $d=\Brick\Math\BigDecimal::of($value);return (string)$d->toScale($d->isLessThan('0.001')?6:3,\Brick\Math\RoundingMode::HalfUp); }
    private function tr(string $a,string $b):string { return '<tr>'.$a.$b.'</tr>'; }
    private function e(string $s):string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
