<?php
declare(strict_types=1);
namespace Customs\Domain;

use Customs\Http\ApiError;

final class GraphEdits
{
    public function edit(array $state, string $graphId, ?string $entityId, mixed $value, ?string $candidateId = null): array
    {
        $editor = (new GraphEditors())->editor($graphId);
        if ($editor['readOnlyWhen'] || $editor['textBinding'] === null) throw new ApiError('FIELD_READ_ONLY','Эта графа не редактируется как текст.',422);
        $item = (new CompactGraphs())->item($state,$graphId,$entityId);
        $evidence = [];
        if ($candidateId !== null) {
            $choice = array_column($item['choices'],null,'id')[$candidateId] ?? null;
            if ($choice === null) throw new ApiError('CANDIDATE_FIELD','Предложение отсутствует или относится к другой графе.',422);
            $value = $choice['value']; $evidence = $choice['evidenceIds'];
        }
        if (!is_string($value) || mb_strlen($value) > 20000) throw new ApiError('GRAPH_VALUE','Введите текст графы допустимой длины.',422);
        $value = trim($value); $address = CompactGraphs::address($graphId,$entityId);
        $state['draft']['graphTexts'][$address] = ['value'=>$value,'status'=>$candidateId === null?'manual':'selected','evidenceIds'=>$evidence,'reviewedCandidateIds'=>array_column($item['choices'],'id'),'editorVersion'=>GraphEditors::VERSION,'error'=>null];
        if(($choice['kind']??null)==='manual')$state['draft']['graphTexts'][$address]['status']='manual';
        if (isset(GraphCodecs::HINTS[$graphId])) {
            try { $state = $graphId==='44'&&isset($choice['recordId']) ? (new GraphCodecs())->documentChoice($state,$choice['recordId'],$choice['recordValues'],$entityId) : (new GraphCodecs())->parse($state,$graphId,$entityId,$value); }
            catch (ApiError $error) { $state['draft']['graphTexts'][$address]['error']=$error->getMessage(); }
        }
        if ($graphId === '31') {
            $model = new Draft(new Registry()); $row =& $model->entity($state['draft'],'goods',$entityId);
            $row['description'] = $value === '' ? null : $value; unset($row);
            $state['draft']['fieldStates']['goods/'.$entityId.'/description'] = ['status'=>'manual','evidenceIds'=>$evidence,'candidateId'=>null,'conflict'=>false,'needsReview'=>false];
        }
        return $state;
    }
}
