<?php
declare(strict_types=1);
namespace Customs\Domain;

use Brick\Math\BigDecimal;
use Customs\Http\ApiError;

final class Entities
{
    public function __construct(private readonly Registry $registry) {}

    public function change(array $state, array $request): array
    {
        $scope = $request['scope'] ?? '';
        if (!in_array($scope, ['goods','document','cost','rate','payment','package'], true)) throw new ApiError('ENTITY_SCOPE','Этот раздел нельзя изменять как список.');
        $model = new Draft($this->registry); $d =& $state['draft'];
        $collection = $scope === 'package' ? 'packages' : $model->collection($scope);
        $action = $request['action'] ?? 'add';
        if ($action === 'add') {
            if (count($d[$collection]) >= 200) throw new ApiError('ENTITY_LIMIT','Достигнут лимит строк раздела.');
            $id = $scope.'-'.bin2hex(random_bytes(8));
            $row = $scope === 'package' ? ['id'=>$id,'label'=>'','quantity'=>null,'kind'=>null] : ['id'=>$id,...$model->row($scope)];
            if ($scope === 'goods') {
                $line = 'manual-'.bin2hex(random_bytes(8));
                $d['sourceLines'][$line] = ['id'=>$line,'origin'=>'manual','quote'=>'Строка, добавленная вручную'];
                $row['sourceLineIds'] = [$line];
            }
            $d[$collection][] = $row;
        } elseif ($action === 'remove') {
            $id = $request['id'] ?? '';
            if (!in_array($id,array_column($d[$collection],'id'),true)) throw new ApiError('ENTITY_UNKNOWN','Строка не найдена.');
            $d[$collection] = array_values(array_filter($d[$collection],fn($r)=>$r['id']!==$id));
            foreach (['goods'=>'goodsIds','package'=>'packageIds'] as $type=>$key) if ($type===$scope) {
                foreach (['goods','documents','costs'] as $list) foreach ($d[$list] as &$r) if (isset($r[$key])) $r[$key]=array_values(array_diff($r[$key],[$id]));
                unset($r);
            }
            if ($scope === 'package') $d['declaration']['shipmentPackageIds']=array_values(array_diff($d['declaration']['shipmentPackageIds'],[$id]));
            if ($scope === 'document') $d['declaration']['valuationDocumentIds']=array_values(array_diff($d['declaration']['valuationDocumentIds'],[$id]));
            foreach ($state['candidates'] as $key=>$c) if ($c['entityId']===$id) unset($state['candidates'][$key]);
            foreach ($d['fieldStates'] as $key=>$v) if (str_starts_with($key,$scope.'/'.$id.'/')) unset($d['fieldStates'][$key]);
            foreach ($d['entityAliases'] as $key=>$v) if ($v===$id) unset($d['entityAliases'][$key]);
        } elseif ($scope === 'package' && $action === 'update') {
            foreach ($d['packages'] as &$p) if ($p['id']===($request['id']??'')) {
                $label=$request['label']??''; $quantity=$request['quantity']??null;
                if (!is_string($label)||mb_strlen($label)>200||!is_int($quantity)||$quantity<1||$quantity>1000000) throw new ApiError('PACKAGE_VALUE','Нужны название группы мест и положительное целое количество.');
                $p['label']=trim($label); $p['quantity']=$quantity; $p['kind']=$request['kind']??null;
                return $state;
            }
            throw new ApiError('ENTITY_UNKNOWN','Группа грузовых мест не найдена.');
        } elseif ($scope === 'cost' && $action === 'allocations') {
            $row =& $model->entity($d,'cost',$request['id']??null);
            if (!is_array($request['allocations']??null)||count($request['allocations'])>200) throw new ApiError('ALLOCATION','Нужны доли расходов.');
            $row['allocations']=[];
            foreach ($request['allocations'] as $id=>$amount) {
                if (!in_array($id,array_column($d['goods'],'id'),true)||!is_string($amount)) throw new ApiError('ALLOCATION','Неверная ссылка или сумма доли.');
                $row['allocations'][$id]=(new Normalizer())->value(['type'=>'decimal'], $amount,null,'');
            }
        } elseif ($scope === 'goods' && $action === 'split') {
            $row =& $model->entity($d,'goods',$request['id']??null);
            $lines=$request['sourceLineIds']??[];
            if (!is_array($lines)||$lines===[]||array_diff($lines,$row['sourceLineIds'])||count($lines)>=count($row['sourceLineIds'])) throw new ApiError('GROUP_SPLIT','Выберите часть исходных строк для отделения.');
            $new=['id'=>'goods-'.bin2hex(random_bytes(8)),...$model->row('goods')];
            foreach (['commodityCode','originCountry','description'] as $key) $new[$key]=$row[$key];
            $new['sourceLineIds']=$lines;
            $row['sourceLineIds']=array_values(array_diff($row['sourceLineIds'],$lines));
            // No speculative allocation of totals: both groups require explicit input and confirmation.
            foreach (['grossMass','netMass','invoiceValue','packageQuantity','groupConfirmed','additionalQuantity','secondaryAdditionalQuantity','dutyQuantity','tariffConfirmed'] as $key) $row[$key]=null;
            $d['goods'][]=$new;
        } elseif ($scope === 'goods' && $action === 'merge') {
            $ids=$request['ids']??[];
            if (!is_array($ids)||count(array_unique($ids))<2||array_diff($ids,array_column($d['goods'],'id'))) throw new ApiError('GROUP_SELECTION','Выберите минимум два существующих товара.');
            if (($request['confirmed']??false)!==true||!in_array($request['mode']??'', ['sum','same-item'],true)) throw new ApiError('GROUP_CONFIRM','Укажите, это отдельные строки для суммирования или повторные сведения об одном товаре.');
            $selected=array_values(array_filter($d['goods'],fn($g)=>in_array($g['id'],$ids,true)));
            $merged=$selected[0];
            foreach (['commodityCode','originCountry','previousProcedure','movementFeature','preferenceDuty','preferenceVat','vatRate','prohibitionCodes',...array_diff(TariffEngine::OPERANDS,['dutyQuantity'])] as $key) {
                $values=[];
                foreach ($selected as $g) if (!Conditions::missing($g[$key]??null)) $values[json_encode($g[$key])]=true;
                if (count($values)>1) throw new ApiError('GROUP_INCOMPATIBLE','Не совпадают классификация, происхождение или меры: '.$this->registry->field('goods.'.$key)['label']);
            }
            foreach ($this->registry->fields() as $f) if ($f['scope']==='goods') {
                $key=$f['key']; $values=array_column($selected,$key);
                if (in_array($key,['sourceLineIds','packageIds'],true)) $merged[$key]=array_values(array_unique(array_merge(...$values)));
                elseif (in_array($key,['grossMass','netMass','invoiceValue','additionalQuantity','secondaryAdditionalQuantity','dutyQuantity'],true)) {
                    if ($request['mode']==='sum') $merged[$key]=in_array(null,$values,true)?null:(string)array_reduce($values,fn($s,$v)=>$s->plus($v),BigDecimal::zero());
                    elseif (count(array_unique(array_filter($values,fn($v)=>$v!==null)))>1) $merged[$key]=null;
                    else $merged[$key]=array_values(array_filter($values,fn($v)=>$v!==null))[0]??null;
                } elseif (($merged[$key]??null)===null) foreach ($values as $v) if ($v!==null) { $merged[$key]=$v; break; }
            }
            $merged['groupConfirmed']=null; $merged['packageQuantity']=null; $merged['tariffConfirmed']=false;
            $d['goods']=array_values(array_filter($d['goods'],fn($g)=>!in_array($g['id'],$ids,true)));
            $d['goods'][]=$merged;
            foreach (['documents','costs'] as $list) foreach ($d[$list] as &$r) if (array_intersect($r['goodsIds'],$ids)) $r['goodsIds']=array_values(array_unique([...array_diff($r['goodsIds'],$ids),$merged['id']]));
            unset($r);
            // Move candidates to the surviving group and require a new explicit choice.
            foreach ($state['candidates'] as &$c) if (in_array($c['entityId'],$ids,true)) {
                $c['entityId']=$merged['id']; $f=$this->registry->field($c['fieldId']);
                $c['address']=Draft::address('goods',$merged['id'],$f['key']);
            }
            unset($c);
            foreach ($d['fieldStates'] as $key=>$meta) foreach ($ids as $id) if (str_starts_with($key,'goods/'.$id.'/')) unset($d['fieldStates'][$key]);
            foreach ($this->registry->fields() as $f) if ($f['scope']==='goods') $d['fieldStates'][Draft::address('goods',$merged['id'],$f['key'])]=['status'=>'manual','candidateId'=>null,'evidenceIds'=>[],'conflict'=>false,'needsReview'=>false];
            foreach ($d['entityAliases'] as $key=>$id) if (in_array($id,$ids,true)) $d['entityAliases'][$key]=$merged['id'];
        } else throw new ApiError('ENTITY_ACTION','Неизвестное действие со строкой.');
        return $state;
    }
}
