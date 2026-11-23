<?php
declare(strict_types=1);

namespace Customs\Domain;

use Customs\Http\ApiError;

final class Draft
{
    public function __construct(private readonly Registry $registry) {}

    public function empty(?int $timestamp = null): array
    {
        $draft = ['declaration' => $this->row('declaration'), 'keden' => $this->row('keden'), 'parties' => [], 'goods' => [], 'transports' => [], 'documents' => [], 'costs' => [], 'rates' => [], 'payments' => [], 'sourceLines' => [], 'packages' => [], 'fieldStates' => [], 'entityAliases' => []];
        foreach (['seller','buyer','consignor','consignee','declarant','financial'] as $role) $draft['parties'][] = ['id' => 'party-' . $role, ...$this->row('party'), 'role' => $role];
        foreach (['arrival','border'] as $stage) $draft['transports'][] = ['id' => 'transport-' . $stage, ...$this->row('transport'), 'stage' => $stage];
        $draft['goods'][] = ['id' => 'goods-1', ...$this->row('goods')];
        return GraphDefaults::apply(DeclarationDate::defaults($draft,$timestamp??time()));
    }

    public function row(string $scope): array
    {
        $row = [];
        foreach ($this->registry->fields() as $field) if ($field['scope'] === $scope) $row[$field['key']] = $field['fixedValue'] ?? (str_ends_with($field['type'], '-list') ? [] : null);
        return $row;
    }

    public function &entity(array &$draft, string $scope, ?string $entityId): array
    {
        $collection = $this->registry->data()['scopes'][$scope]['collection'] ?? null;
        if ($collection === null) {
            if (!isset($draft[$scope])) throw new ApiError('ENTITY_UNKNOWN', 'Раздел не найден.');
            return $draft[$scope];
        }
        foreach ($draft[$collection] as &$row) if ($row['id'] === $entityId) return $row;
        unset($row);
        throw new ApiError('ENTITY_UNKNOWN', 'Строка формы не найдена.');
    }

    public function collection(string $scope): string
    {
        return $this->registry->data()['scopes'][$scope]['collection'] ?? throw new ApiError('ENTITY_SCOPE', 'Этот раздел не является списком.');
    }

    public static function address(string $scope, ?string $entityId, string $key): string { return $scope . '/' . ($entityId ?? '_') . '/' . $key; }

    public function edit(array $state, string $fieldId, ?string $entityId, mixed $value, ?string $candidateId = null): array
    {
        $field = $this->registry->field($fieldId);
        if (in_array('calculated', $field['acquisition'], true) || isset($field['fixedValue'])) throw new ApiError('FIELD_READ_ONLY', 'Измените исходные параметры этого поля.');
        $target =& $this->entity($state['draft'],$field['scope'],$entityId);
        $role = $target['role'] ?? $target['stage'] ?? null; unset($target);
        if ((new GraphEditors())->hidden($fieldId,$role)) throw new ApiError('GRAPH_EDIT_REQUIRED','Измените полный текст соответствующей графы в компактной форме.',422);
        $address = self::address($field['scope'], $entityId, $field['key']);
        $evidenceIds = [];
        $status = 'manual';
        if ($candidateId !== null) {
            $candidate = $state['candidates'][$candidateId] ?? throw new ApiError('CANDIDATE_UNKNOWN', 'Предложение больше недоступно.');
            if ($candidate['address'] !== $address) throw new ApiError('CANDIDATE_FIELD', 'Предложение относится к другому полю.');
            $value = $candidate['value']; $evidenceIds = $candidate['evidenceIds']; $status = 'selected';
        } else {
            try { $value = $this->manualValue($field, $value); }
            catch (ApiError $error) {
                if (!in_array($field['type'], ['decimal','integer','date'], true) || !is_string($value)) throw $error;
                $state['draft']['invalidInputs'][$address] = ['raw'=>$value,'message'=>$error->getMessage()];
                return $state;
            }
        }
        unset($state['draft']['invalidInputs'][$address]);
        $row =& $this->entity($state['draft'], $field['scope'], $entityId);
        $oldValue=$row[$field['key']]??null;
        if ($field['scope'] === 'goods' && isset($row['_tariff']) && (in_array($field['key'], TariffDefaults::UNIT_FIELDS, true) || ($field['key'] === 'dutyQuantity' && (TariffDefaults::linkedQuantity($row) !== null || ($row['dutyQuantityBasis'] ?? '') === 'net_mass')))) throw new ApiError('FIELD_READ_ONLY', 'Единица задана кодом ТН ВЭД; связанное количество меняется через исходную массу или количество товара.', 422);
        $row[$field['key']] = $value;
        if ($fieldId === 'document.kind') {
            $catalog=new Classifiers();$official=$catalog->requireDocument((string)$value,$catalog->version());
            $row['classifierVersion']=$catalog->version();
            $nameMeta=$state['draft']['fieldStates'][self::address('document',$entityId,'name')]??[];
            if(Conditions::missing($row['name']??null)||(!in_array($nameMeta['status']??'',['manual','selected'],true)&&($nameMeta['evidenceIds']??[])===[]))$row['name']=$official['name'];
            $row['dateRequired'] = true;
            foreach (Registry::resource('catalogs')['lists']['documentKinds'] as $kind) if ($kind['code'] === $value) $row['dateRequired'] = $kind['dateRequired'];
        }
        $reviewed = [];
        foreach ($state['candidates'] as $candidate) if ($candidate['address'] === $address) $reviewed[] = $candidate['id'];
        $state['draft']['fieldStates'][$address] = ['status' => $status,'candidateId' => $candidateId,'evidenceIds' => $evidenceIds,'reviewedCandidateIds' => $reviewed,'conflict' => false,'needsReview' => false];
        if (in_array($fieldId,['declaration.signatureDate','declaration.calculationDate'],true)) {
            $state['draft']['declaration']['signatureDate']=$value;
            if($fieldId==='declaration.calculationDate')$state['draft']['fieldStates']['declaration/_/signatureDate']=DeclarationDate::meta('manual');
            $state['draft']=DeclarationDate::defaults($state['draft'],time());
            if(isset($state['exchangeRates']))$state['exchangeRates']['status']='stale';
        }
        if ($field['scope']==='rate' && $field['key']!=='confirmed') {
            $row['confirmed']=false;$state['draft']['fieldStates'][self::address('rate',$entityId,'confirmed')]=DeclarationDate::meta('linked');
            if ($field['key']==='currency' && $value!==$oldValue) {
                foreach(['value','nominal','source'] as $key){$row[$key]=null;$state['draft']['fieldStates'][self::address('rate',$entityId,$key)]=DeclarationDate::meta('missing');}
                $row['date']=$state['draft']['declaration']['signatureDate'];unset($row['_nbk']);
            }
        }
        return $state;
    }

    private function manualValue(array $field, mixed $value): mixed
    {
        if ($value === null || $value === '') return null;
        $type = $field['type'];
        if ($type === 'boolean' && !is_bool($value)) throw new ApiError('FIELD_VALUE', 'Ожидается явный ответ да/нет.');
        if ($type === 'boolean') return $value;
        if (str_ends_with($type, '-list')) {
            if (!is_array($value) || count($value) > 1000 || array_filter($value, fn($item): bool => !is_string($item))) throw new ApiError('FIELD_VALUE', 'Ожидается список строк.');
            return array_values(array_unique($value));
        }
        if ($type === 'integer') {
            if (is_string($value)) return (new Normalizer())->value($field,$value,null,'');
            if (!is_int($value) || $value < 0) throw new ApiError('FIELD_VALUE', 'Ожидается неотрицательное целое число.');
            return $value;
        }
        if (!is_string($value) || mb_strlen($value) > 10000) throw new ApiError('FIELD_VALUE', 'Ожидается строковое значение допустимой длины.');
        if ($type === 'decimal') return (new Normalizer())->value($field, $value, ($field['unit'] ?? '') === 'kg' ? 'kg' : null, 'kg');
        if ($type === 'date') return (new Normalizer())->date($value);
        if ($field['id'] === 'goods.commodityCode') return (new Normalizer())->value($field, $value, null, '');
        if (in_array($field['id'],['rate.currency','declaration.invoiceCurrency','cost.currency','goods.dutyCurrency'],true)) return strtoupper(trim($value));
        if ($type === 'enum' && isset($field['values']) && !in_array($value, $field['values'], true)) throw new ApiError('FIELD_VALUE', 'Неизвестный вариант значения.');
        return trim($value);
    }
}
