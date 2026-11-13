<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Domain\Registry;
use Customs\Http\ApiError;

final class Mapper
{
    public const PROMPT_VERSION = 'customs-mapping-v3-scoped';
    public function __construct(private readonly MistralClient $client, private readonly Registry $registry) {}

    public function request(array $sources, ?array $fieldIds = null): array
    {
        $fields = $this->registry->extractable();
        if ($fieldIds !== null) $fields = array_intersect_key($fields, array_flip($fieldIds));
        if ($fields === []) throw new ApiError('MAPPING_FIELDS', 'Нет разрешённых полей для сопоставления.');
        $schema = json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/schemas/mapping.v1.json'), true, 128, JSON_THROW_ON_ERROR);
        $schema['properties']['candidates']['items']['properties']['fieldId']['enum'] = array_keys($fields);
        [$blocks, $references] = $this->sourceReferences($sources);
        $wireSchema = $schema;
        $entityScopes=array_values(array_diff(array_unique(array_column($fields,'scope')),['declaration','keden']));
        if($entityScopes!==[])$wireSchema['properties']['entities']['items']['properties']['scope']['enum']=$entityScopes;
        $citation = ['type' => 'object', 'additionalProperties' => false, 'required' => ['sourceId','quote'], 'properties' => [
            'sourceId' => ['type' => 'string','enum' => array_keys($references)],
            'quote' => ['type' => 'string','minLength' => 1,'maxLength' => 1000],
        ]];
        foreach (['entities','candidates'] as $key) $wireSchema['properties'][$key]['items']['properties']['source'] = $citation;
        if ($blocks === []) return ['entities' => [],'candidates' => [],'warnings' => []];
        $prompt = file_get_contents(dirname(__DIR__, 2) . '/resources/prompts/mapping.v1.txt');
        $input = json_encode(['fields' => array_values(array_map(fn(array $field): array => array_intersect_key($field, array_flip(['id','scope','label','type','unit','sourceHints'])), $fields)), 'sources' => $blocks], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($input) > 600_000) throw new ApiError('MAPPING_CONTEXT_LIMIT', 'Требуется разбиение сопоставления на части.');
        $response = $this->client->structured($prompt, $input, $wireSchema);
        $choice = $response['choices'][0] ?? null;
        if (($choice['finish_reason'] ?? null) !== 'stop') throw new ApiError('MAPPING_INCOMPLETE', 'Структурированный ответ сопоставления не завершён.', 502);
        $content = $choice['message']['content'] ?? null;
        if (!is_string($content)) throw new ApiError('MAPPING_RESPONSE', 'Нет структурированного ответа сопоставления.', 502);
        try { $object = json_decode($content, false, 128, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiError('MAPPING_SCHEMA', 'AI вернул JSON, не соответствующий схеме.', 502); }
        $validator = new \Opis\JsonSchema\Validator();
        $validation = $validator->validate($object, json_decode(json_encode($wireSchema, JSON_THROW_ON_ERROR)));
        if (!$validation->isValid()) throw new ApiError('MAPPING_SCHEMA', 'AI вернул поля или типы вне разрешённой схемы.', 502);
        $result = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
        foreach (['entities','candidates'] as $key) foreach ($result[$key] as &$item) {
            $item['source'] = [...$references[$item['source']['sourceId']], 'quote' => $item['source']['quote']];
        }
        unset($item);
        $result['usage'] = $response['usage'] ?? [];
        $result['promptVersion'] = self::PROMPT_VERSION;
        return $result;
    }

    /** Short server-issued references prevent the model from reconstructing document/page/block pointers. */
    private function sourceReferences(array $sources): array
    {
        $blocks = []; $references = [];
        foreach ($sources as $source) {
            foreach ($source['pages'] ?? [] as $page) foreach ($page['blocks'] as $block) {
                if (trim($block['text']) === '') continue;
                $id = 's' . (count($references) + 1);
                $references[$id] = ['documentId' => $source['documentId'],'page' => $page['page'],'blockId' => $block['id'],'sheet' => null,'cell' => null];
                $blocks[] = ['sourceId' => $id,'document' => $source['name'],'page' => $page['page'],'text' => $block['text']];
            }
            foreach ($source['sheets'] ?? [] as $sheet) foreach ($sheet['cells'] as $cell => $data) {
                if ($data['value'] === null || $data['value'] === '') continue;
                $id = 's' . (count($references) + 1);
                $references[$id] = ['documentId' => $source['documentId'],'page' => null,'blockId' => null,'sheet' => $sheet['name'],'cell' => $cell];
                $blocks[] = ['sourceId' => $id,'document' => $source['name'],'sheet' => $sheet['name'],'cell' => $cell,'text' => (string) $data['value']];
            }
        }
        return [$blocks,$references];
    }
}
