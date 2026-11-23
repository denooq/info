<?php
declare(strict_types=1);
namespace Customs\Domain;

final class GraphEditors
{
    public const VERSION = '3.0.0';

    public function data(): array
    {
        static $data;
        if($data===null){$data=json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/registry/graph-editors.im40.v3.json'), true, 512, JSON_THROW_ON_ERROR);foreach($data['graphs'] as &$editor)$editor['hint']=GraphCodecs::HINTS[$editor['graphId']]??null;unset($editor);}
        return $data;
    }

    public function editor(string $id): array
    {
        foreach ($this->data()['graphs'] as $editor) if ($editor['graphId'] === $id) return $editor;
        throw new \Customs\Http\ApiError('GRAPH_UNKNOWN', 'Неизвестная графа.', 422);
    }

    public function fields(): array
    {
        $fields = [];
        foreach ($this->data()['graphs'] as $editor) array_push($fields, ...$editor['visibleInputs']);
        return array_values(array_unique($fields));
    }

    public function hidden(string $fieldId, ?string $role): bool
    {
        if (in_array($fieldId,$this->fields(),true) || in_array($fieldId,['goods.packageIds','declaration.shipmentPackageIds'],true)) return false;
        foreach ((new GraphRegistry())->graphs() as $graph) {
            if ($graph['parentGraph'] !== null || !in_array($fieldId,$graph['fieldBindings'],true)) continue;
            if (str_starts_with($fieldId,'party.') || str_starts_with($fieldId,'transport.')) {
                if (($graph['entityRole'] ?? null) !== $role) continue;
            }
            if (in_array($this->editor($graph['id'])['editorKind'],['text','signature','code-text','documents'],true)) return true;
        }
        return false;
    }
}
