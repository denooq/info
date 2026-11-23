<?php
declare(strict_types=1);
namespace Customs\Domain;

use Customs\Http\ApiError;

/** Compact presentation is additive: it never destroys the extracted facts. */
final class CompactGraphs
{
    public function project(array $state): array
    {
        $state['draft'] = GraphDefaults::apply($state['draft']);
        $draft = $state['draft']; $result = []; $registry = new Registry();
        foreach ((new GraphEditors())->data()['graphs'] as $editor) {
            $graph = (new GraphRegistry())->graph($editor['graphId']);
            $rows = $editor['scope'] === 'goods' ? $draft['goods'] : [null];
            foreach ($rows as $goods) {
                $id = $goods['id'] ?? null; $address = self::address($graph['id'], $id);
                $saved = $draft['graphTexts'][$address] ?? null;
                $value = $saved['value'] ?? $this->value($draft, $graph, $state, $goods);
                $evidence = $saved['evidenceIds'] ?? $this->evidence($draft, $graph, $id);
                $evidence = array_values(array_filter($evidence, fn($key) => isset($state['evidence'][$key])));
                $choices = $editor['editorKind']==='documents'?$this->documentChoices($state,$id):(in_array($editor['editorKind'], ['text','code-text','signature'], true) ? $this->choices($state, $graph, $id) : []);
                if($saved===null&&in_array($editor['editorKind'],['text','code-text','signature'],true)&&!Conditions::missing($value)) {
                    [$manual,$newConflict]=$this->legacyManual($draft,$graph,$id);
                    if($manual)$saved=['value'=>$value,'status'=>'manual','evidenceIds'=>$evidence,'reviewedCandidateIds'=>$newConflict?[]:array_column($choices,'id')];
                }
                if ($saved === null && $editor['editorKind']!=='documents' && count($choices) === 1) { $value = $choices[0]['value']; $evidence = $choices[0]['evidenceIds']; }
                if ($saved === null && $editor['editorKind']!=='documents' && count($choices) > 1) $value = null;
                $inputs = [];
                foreach ($editor['visibleInputs'] as $fieldId) {
                    $field = $registry->field($fieldId);
                    $sourceGraph = $graph;
                    if ($graph['id'] === '15') $sourceGraph = (new GraphRegistry())->graph('15a');
                    foreach ((new GraphRegistry())->rows($draft, $sourceGraph, $field) as $row) {
                        if ($field['scope'] === 'goods' && $editor['scope'] === 'goods' && $row['id'] !== $id) continue;
                        $entityId = $field['scope'] === 'declaration' ? null : $row['id'];
                        $inputAddress = Draft::address($field['scope'], $entityId, $field['key']);
                        $inputs[] = ['fieldId'=>$fieldId,'entityId'=>$entityId,'address'=>$inputAddress,'value'=>$draft['invalidInputs'][$inputAddress]['raw'] ?? $row[$field['key']] ?? null,'required'=>Conditions::evaluate($field['requiredWhen'], $row)];
                    }
                }
                $pending = $graph['calculationStage'] === 'result' && !$this->current($state);
                $status = $editor['editorKind'] === 'inactive' ? 'not_applicable' : ($pending ? 'pending_calculation' : (Conditions::missing($value) && $inputs === [] ? 'missing' : ($saved['status'] ?? 'auto')));
                if ($saved === null && count($choices) > 1) $status = 'conflict';
                if ($saved !== null) foreach ($choices as $choice) if (!in_array($choice['id'], $saved['reviewedCandidateIds'] ?? [], true) && $choice['value'] !== $saved['value']) $status = 'conflict';
                if($editor['editorKind']==='documents'&&$choices!==[])$status='conflict';
                $currentValue=$saved['value']??$value;
                if(($saved!==null||$editor['editorKind']==='documents')&&$status==='conflict'&&$currentValue!==''&&$currentValue!==null&&!in_array($currentValue,array_column($choices,'value'),true))$choices[]=['id'=>'graph-manual-'.hash('sha256',$address.$currentValue),'value'=>$currentValue,'label'=>'Сохранённое значение','evidenceIds'=>$evidence,'kind'=>'manual'];
                if($saved!==null&&($saved['status']??'')==='selected'&&$evidence===[]&&$status!=='conflict')$status='manual';
                $result[] = ['graphId'=>$graph['id'],'entityId'=>$id,'address'=>$address,'kind'=>$editor['editorKind'],'value'=>$value,'status'=>$status,'evidenceIds'=>$evidence,'inputs'=>$inputs,'choices'=>$choices,'error'=>$saved['error'] ?? null];
            }
        }
        return $result;
    }

    public static function address(string $graphId, ?string $entityId): string { return 'graph/'.$graphId.'/'.($entityId ?? '_'); }

    public function item(array $state, string $graphId, ?string $entityId): array
    {
        foreach ($this->project($state) as $item) if ($item['graphId'] === $graphId && $item['entityId'] === $entityId) return $item;
        throw new ApiError('GRAPH_ENTITY', 'Графа или товар отсутствуют в текущей форме.', 422);
    }

    private function current(array $state): bool { return isset($state['calculation']) && ($state['calculation']['dataRevision'] ?? -1) === ($state['dataRevision'] ?? 0); }

    private function parts(array $row, array $keys): string
    {
        $values = [];
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_array($value)) $value = implode(', ', $value);
            if ($value === null || $value === '' || $value === GraphProjection::UNAVAILABLE) continue;
            $value = is_bool($value) ? ($value ? 'Да' : 'Нет') : (string)$value;
            if (!in_array(mb_strtolower(trim($value)), array_map(fn($s)=>mb_strtolower(trim($s)), $values), true)) $values[] = $value;
        }
        return implode('; ', $values);
    }

    private function compound(array $row, array $keys): string
    {
        return implode('; ', array_map(function($key) use($row) { $v=$row[$key]??'';return is_bool($v)?($v?'Да':'Нет'):(is_array($v)?implode(', ',$v):(string)$v); },$keys));
    }

    private function value(array $draft, array $graph, array $state, ?array $goods): ?string
    {
        $d = $draft['declaration']; $id = $graph['id'];
        if ($graph['fillMode'] === 'not_applicable') return 'Не заполняется';
        if (in_array($id, ['2','8','9','14'], true)) {
            $rows = (new GraphRegistry())->rows($draft, $graph, (new Registry())->field('party.name'));
            foreach ($rows as $row) {
                $text = $this->parts($row, ['name','briefName','country','region','city','street','postalCode','taxId','identifierKind']);
                if ($text !== '') return $text;
            }
            return null;
        }
        if (in_array($id, ['18','21'], true)) {
            foreach ($draft['transports'] as $row) if ($row['stage'] === $graph['entityRole']) return $this->parts($row, ['registration','country','transportType']) ?: null;
        }
        if ($graph['calculationStage'] === 'result') {
            if (!$this->current($state)) return null;
            if ($id === '12') return $state['calculation']['totals']['customsValue'] ?? null;
            foreach ($state['calculation']['items'] as $item) if ($item['id'] === ($goods['id'] ?? null)) {
                if ($id === '45') return $item['customsValue'];
                if ($id === '46') return $item['statisticalValue'];
                if ($id === '47') return '1010: '.$item['fee'].'; 2010: '.$item['duty'].'; 5060: '.$item['vat'];
            }
        }
        return match ($id) {
            '1' => $this->parts($d, ['kind','procedure','format']),
            '5' => (string)count($draft['goods']),
            '6' => isset($d['packageCount']) ? (string)$d['packageCount'] : null,
            '11' => $d['tradeCountry'], '15' => $d['dispatchCountry'], '16' => $d['originCountry'], '17' => 'KZ — КАЗАХСТАН',
            '19' => $d['container']===null?null:($d['container']?$this->compound($d,['container','containerNumbers']):'Нет'),
            '20' => $this->compound($d, ['deliveryCode','deliveryPlace']),
            '22' => $this->parts($d, ['invoiceCurrency','invoiceTotal']),
            '23' => $d['invoiceCurrency'] === 'KZT' ? '1 KZT = 1 KZT' : null,
            '24' => $this->compound($d, ['transactionCode','transactionFeature']),
            '29' => $this->parts($d, ['borderOfficeCode','borderOfficeCountry','borderOfficeName']) ?: null,
            '30' => $this->parts($d, ['locationCode','locationRegistration','locationCountry','locationOfficeCode','locationAddress']) ?: null,
            '31' => $this->parts($goods ?? [], ['description','packageKind','packageQuantity','manufacturer','tradeMark','brand','model','article']) ?: null,
            '32' => isset($goods['ordinal']) ? (string)$goods['ordinal'] : null,
            '36' => $this->compound($goods ?? [], ['hasPreference','preferenceFee','preferenceDuty','preferenceExcise','preferenceVat','preferenceBasis']),
            '37' => $this->compound(['procedure'=>$d['procedure'], ...($goods ?? [])], ['procedure','previousProcedure','movementFeature']),
            '43' => '1',
            '44' => GraphCodecs::documentText($draft['documents'],$goods['id'] ?? null),
            '54' => $this->parts($d, ['signatoryPlace','signatoryLastName','signatoryFirstName','signatoryMiddleName','signatoryPosition','signatoryContact','representativeRegistry','representativeContractNumber','representativeContractDate','authorityNumber','authorityDate','authorityValidUntil']) ?: null,
            default => null,
        };
    }

    private function evidence(array $draft, array $graph, ?string $goodsId): array
    {
        $ids = []; $registry = new Registry(); $graphs = new GraphRegistry();
        foreach ($graph['fieldBindings'] as $fieldId) {
            $field = $registry->field($fieldId);
            foreach ($graphs->rows($draft, $graph, $field) as $row) {
                if ($field['scope'] === 'goods' && $row['id'] !== $goodsId) continue;
                $address = Draft::address($field['scope'], $field['scope'] === 'declaration' ? null : $row['id'], $field['key']);
                array_push($ids, ...($draft['fieldStates'][$address]['evidenceIds'] ?? []));
            }
        }
        return array_values(array_unique($ids));
    }

    private function choices(array $state, array $graph, ?string $goodsId): array
    {
        $registry = new Registry(); $graphs = new GraphRegistry(); $addresses = []; $order = [];
        foreach ($graph['fieldBindings'] as $fieldId) {
            $field = $registry->field($fieldId);
            if (in_array($field['key'], ['sameAsRole','signatureDate','groupConfirmed','packageIds'], true)) continue;
            $order[] = $field['key'];
            foreach ($graphs->rows($state['draft'], $graph, $field) as $row) {
                if ($field['scope'] === 'goods' && $row['id'] !== $goodsId) continue;
                $addresses[Draft::address($field['scope'], $field['scope'] === 'declaration' ? null : $row['id'], $field['key'])] = $field['key'];
            }
        }
        $documents = []; $all = [];
        foreach ($state['candidates'] ?? [] as $candidate) {
            $key = $addresses[$candidate['address']] ?? null;
            if ($key === null || !$candidate['valid']) continue;
            foreach ($candidate['evidenceIds'] as $evidenceId) {
                $source = $state['evidence'][$evidenceId] ?? null;
                if ($source === null) continue;
                $document = $source['documentId']; $value = $candidate['value']; $signature = json_encode($value);
                $documents[$document]['parts'][$key][$signature] = $value;
                $documents[$document]['evidenceIds'][] = $evidenceId;
                $documents[$document]['rank']=min($documents[$document]['rank']??99,$candidate['rank']??99);
                $all[$key][$signature] = $value;
            }
        }
        if ($documents === []) return [];
        // Compatible partial documents combine; a disagreement keeps whole source proposals.
        if (array_filter($all, fn($values)=>count($values)>1) === []) {
            $documents = ['merged'=>['parts'=>$all,'evidenceIds'=>array_merge(...array_column($documents,'evidenceIds')),'rank'=>min(array_column($documents,'rank'))]];
        }
        $choices = [];
        foreach ($documents as $document) {
            if (array_filter($document['parts'], fn($values)=>count($values)>1) !== []) continue;
            $parts = array_map(fn($values)=>array_values($values)[0], $document['parts']);
            $text = in_array($graph['id'],['20','24','36','37'],true) ? $this->compound($parts,$order) : $this->parts($parts, $order);
            if ($text === '') continue;
            $id = 'graph-'.hash('sha256', json_encode([$graph['id'],$goodsId,$text], JSON_UNESCAPED_UNICODE));
            $evidence = array_values(array_unique($document['evidenceIds']));
            if (isset($choices[$id])) $evidence = array_values(array_unique([...$choices[$id]['evidenceIds'], ...$evidence]));
            $choices[$id] = ['id'=>$id,'value'=>$text,'evidenceIds'=>$evidence,'rank'=>$document['rank']??99];
        }
        $choices=array_values($choices);usort($choices,fn($a,$b)=>$a['rank']<=>$b['rank']);return $choices;
    }

    private function documentChoices(array $state, ?string $goodsId): array
    {
        $choices=[];
        foreach($state['draft']['documents'] as $record) {
            if(($record['goodsIds']??[])!==[]&&!in_array($goodsId,$record['goodsIds'],true))continue;
            $conflict=false;
            foreach(['kind','number','date','presentationCode','validFrom','validUntil'] as $key){$m=$state['draft']['fieldStates']['document/'.$record['id'].'/'.$key]??[];if(($m['conflict']??false)||($m['needsReview']??false))$conflict=true;}
            if(!$conflict)continue;$bySource=[];
            foreach($state['candidates']??[] as $candidate) {
                if(($candidate['entityId']??null)!==$record['id']||!$candidate['valid']||!str_starts_with($candidate['fieldId'],'document.'))continue;
                $key=substr($candidate['fieldId'],9);if(!in_array($key,['kind','number','date','name','presentationCode','validFrom','validUntil'],true))continue;
                foreach($candidate['evidenceIds'] as $proof)if(isset($state['evidence'][$proof])){$doc=$state['evidence'][$proof]['documentId'];$bySource[$doc]['values'][$key][json_encode($candidate['value'])]=$candidate['value'];$bySource[$doc]['proof'][]=$proof;}
            }
            foreach($bySource as $source) {
                if(array_filter($source['values'],fn($v)=>count($v)>1)!==[])continue;
                $values=array_map(fn($v)=>array_values($v)[0],$source['values']);$copy=$state['draft']['documents'];
                foreach($copy as &$row)if($row['id']===$record['id'])$row=array_replace($row,$values);unset($row);
                $id='document-choice-'.hash('sha256',json_encode([$record['id'],$values],JSON_UNESCAPED_UNICODE));
                $changed=array_replace($record,$values);$proof=array_values(array_unique([...($choices[$id]['evidenceIds']??[]),...$source['proof']]));
                $choices[$id]=['id'=>$id,'recordId'=>$record['id'],'recordValues'=>$values,'value'=>GraphCodecs::documentText($copy,$goodsId),'label'=>($changed['kind']??'').' №'.($changed['number']??'—').' · '.($changed['date']??'—').' · '.($changed['presentationCode']??'—'),'evidenceIds'=>$proof];
            }
        }
        return array_values($choices);
    }

    private function legacyManual(array $draft,array $graph,?string $goodsId):array
    {
        $manual=false;$conflict=false;$registry=new Registry();$graphs=new GraphRegistry();
        foreach($graph['fieldBindings'] as $fieldId) {
            $f=$registry->field($fieldId);if(in_array($f['key'],['sameAsRole','groupConfirmed','packageIds'],true))continue;
            foreach($graphs->rows($draft,$graph,$f) as $row) {
                if($f['scope']==='goods'&&$row['id']!==$goodsId)continue;
                $m=$draft['fieldStates'][Draft::address($f['scope'],$f['scope']==='declaration'?null:$row['id'],$f['key'])]??[];
                if(in_array($m['status']??'',['manual','selected'],true))$manual=true;
                if(($m['needsReview']??false)||($m['conflict']??false))$conflict=true;
            }
        }
        return [$manual,$conflict];
    }
}
