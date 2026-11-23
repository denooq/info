<?php
declare(strict_types=1);

namespace Customs\Domain;

/** Build-time importer. Never fetches legal sources at request time. */
final class ClassifierImporter
{
    public function import(string $html, array $amendments): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $document->loadHTML(file_get_contents($html), LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new \DOMXPath($document);
        $documents = []; $sections = []; $retired = []; $section = null;
        $rows = $this->rows($xpath, 'Классификатор видов документов и сведений');
        foreach ($rows as $row) {
            if (count($row) === 1) { $section = $row[0]; $sections[] = $section; continue; }
            if ($row[0] === 'Код') continue;
            if (!preg_match('/^\d{5}(?:\s*[-–]\s*\d{5})?$/u', $row[0])) throw new \RuntimeException('Unrecognized document code: '.$row[0]);
            if (preg_match('/^Исключен/iu', $row[1])) { $retired[] = ['code' => $row[0], 'reason' => $row[1]]; continue; }
            if (strlen($row[0]) !== 5 || isset($documents[$row[0]])) throw new \RuntimeException('Ambiguous document code');
            $documents[$row[0]] = ['code' => $row[0], 'name' => $row[1], 'section' => $section, 'validFrom' => null, 'validUntil' => null, 'condition' => null, 'source' => '378-annex-8-base', 'restriction' => $this->restriction($row[1])];
        }
        foreach ($amendments['changes'] as $change) {
            $code = $change['code'];
            if ($change['operation'] === 'retire') {
                if (!isset($documents[$code])) throw new \RuntimeException('Retired code missing');
                $documents[$code]['validUntil'] = $change['date'];
            } elseif ($change['operation'] === 'future-name') {
                $documents[$code]['futureName'] = ['date' => $change['date'], 'name' => $documents[$code]['name'].$change['append']];
            } elseif ($change['operation'] === 'append') {
                $documents[$code]['name'] .= $change['text'];
                $documents[$code]['source'] = $change['source'];
            } elseif ($change['operation'] === 'insert') {
                if (!str_contains($documents[$code]['name'], $change['after'])) throw new \RuntimeException('Amendment text missing');
                $documents[$code]['name'] = str_replace($change['after'], $change['after'].$change['text'], $documents[$code]['name']);
                $documents[$code]['source'] = $change['source'];
            } else {
                if (($change['operation'] === 'add') === isset($documents[$code])) throw new \RuntimeException('Amendment precondition failed: '.$code);
                $documents[$code] = ['code' => $code, 'name' => $change['name'], 'section' => $documents[$code]['section'] ?? substr($code,0,2), 'validFrom' => $change['date'], 'validUntil' => null, 'condition' => $change['condition'] ?? null, 'source' => $change['source'], 'restriction' => $this->restriction($change['name'])];
            }
        }
        foreach ($sections as &$name) $name=str_replace(', а также документы, подтверждающие применение централизованного порядка уплаты таможенных пошлин, налогов','',$name);
        unset($name);
        foreach($documents as &$row)$row['section']=str_replace(', а также документы, подтверждающие применение централизованного порядка уплаты таможенных пошлин, налогов','',$row['section']);unset($row);
        ksort($documents, SORT_STRING);
        $countries = [];
        foreach ($this->rows($xpath, 'Классификатор стран мира') as $row) {
            if ($row[0] === 'Код страны') continue;
            if (!preg_match('/^[A-Z]{2}$/D', $row[0]) || isset($countries[$row[0]])) throw new \RuntimeException('Invalid country code');
            $countries[$row[0]] = ['code' => $row[0], 'name' => $row[1]];
        }
        return ['version' => '378-2026-11-23', 'asOf' => '2026-11-23', 'baseSha256' => hash_file('sha256', $html), 'sources' => $amendments['sources'], 'coverage' => ['documentSourceRows' => count($rows), 'countrySourceRows' => count($countries)+1], 'sections' => $sections, 'retired' => $retired, 'documents' => array_values($documents), 'countries' => array_values($countries)];
    }

    private function rows(\DOMXPath $xpath, string $title): array
    {
        $head = $xpath->query('//h3[normalize-space(.)="'.$title.'"]')->item(0);
        if (!$head) throw new \RuntimeException('Classifier heading missing');
        for ($node = $head->nextSibling; $node; $node = $node->nextSibling) {
            if ($node instanceof \DOMElement && in_array($node->tagName,['h2','h3'],true)) break;
            if (!$node instanceof \DOMElement || $node->tagName !== 'table') continue;
            $rows=[];
            foreach ($xpath->query('.//tr', $node) as $tr) {
                $row=[];
                foreach ($xpath->query('./td|./th', $tr) as $cell) $row[]=trim(preg_replace('/\s+/u',' ', $cell->textContent));
                $rows[]=$row;
            }
            return $rows;
        }
        throw new \RuntimeException('Classifier table missing');
    }

    private function restriction(string $name): ?string
    {
        preg_match_all('/\(для [^)]+\)/u', $name, $matches);
        return $matches[0] ? implode('; ', $matches[0]) : null;
    }
}
