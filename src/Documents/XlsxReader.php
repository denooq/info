<?php
declare(strict_types=1);

namespace Customs\Documents;

use Customs\Http\ApiError;
use XMLReader;
use ZipArchive;

final class XlsxReader
{
    public function __construct(private readonly int $maxUnpackedBytes = 67108864, private readonly int $maxCells = 100000) {}

    public function read(string $path): array
    {
        $real = realpath($path);
        if ($real === false || str_contains($real, '#')) throw new ApiError('XLSX_INVALID', 'Не удалось открыть таблицу.');
        $zip = new ZipArchive();
        if ($zip->open($real) !== true) throw new ApiError('XLSX_INVALID', 'Повреждённый XLSX.');
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $bytes = 0;
            if ($zip->numFiles > 3000) throw new ApiError('XLSX_LIMIT', 'Слишком много элементов в XLSX.');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $bytes += $stat['size'];
                if ($bytes > $this->maxUnpackedBytes) throw new ApiError('XLSX_LIMIT', 'Распакованный XLSX превышает лимит.');
            }
            if ($zip->locateName('xl/vbaProject.bin') !== false) throw new ApiError('XLSX_MACROS', 'Таблицы с макросами не поддерживаются.');
            foreach (['xl/workbook.xml','xl/_rels/workbook.xml.rels'] as $required) {
                if ($zip->locateName($required) === false) throw new ApiError('XLSX_INVALID', 'Не найдена структура книги XLSX.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $reader = $this->xml($real, 'xl/sharedStrings.xml');
                while ($reader->read()) {
                    $this->rejectDoctype($reader);
                    if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') $strings[] = $this->elementText($reader);
                }
                $reader->close();
            }
            $relationships = [];
            $reader = $this->xml($real, 'xl/_rels/workbook.xml.rels');
            while ($reader->read()) {
                $this->rejectDoctype($reader);
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Relationship') continue;
                if ($reader->getAttribute('TargetMode') === 'External') continue;
                $target = $reader->getAttribute('Target') ?? '';
                if (str_contains($target, '..') || str_contains($target, ':') || str_contains($target, '\\')) continue;
                $entry = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                if (str_starts_with($entry, 'xl/worksheets/')) $relationships[$reader->getAttribute('Id')] = $entry;
            }
            $reader->close();
            $sheetDefinitions = [];
            $reader = $this->xml($real, 'xl/workbook.xml');
            while ($reader->read()) {
                $this->rejectDoctype($reader);
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sheet') {
                    $id = $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    if (isset($relationships[$id])) $sheetDefinitions[] = ['name' => $reader->getAttribute('name'), 'entry' => $relationships[$id]];
                }
            }
            $reader->close();
            $result = [];
            $cellCount = 0;
            foreach ($sheetDefinitions as $sheet) {
                $cells = [];
                $reader = $this->xml($real, $sheet['entry']);
                while ($reader->read()) {
                    $this->rejectDoctype($reader);
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') continue;
                    if (++$cellCount > $this->maxCells) throw new ApiError('XLSX_LIMIT', 'Слишком много ячеек XLSX.');
                    $address = $reader->getAttribute('r');
                    if ($address === null || !preg_match('/^[A-Z]{1,3}[1-9][0-9]{0,6}$/D', $address)) throw new ApiError('XLSX_INVALID', 'Неверный адрес ячейки.');
                    $type = $reader->getAttribute('t');
                    $value = null;
                    $formula = null;
                    $hasCachedValue = false;
                    $depth = $reader->depth;
                    if (!$reader->isEmptyElement) while ($reader->read()) {
                        $this->rejectDoctype($reader);
                        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) break;
                        if ($reader->nodeType !== XMLReader::ELEMENT) continue;
                        if ($reader->localName === 'f') $formula = $reader->readString();
                        if ($reader->localName === 'v') { $value = $reader->readString(); $hasCachedValue = true; }
                        if ($reader->localName === 'is') $value = $this->elementText($reader);
                    }
                    if ($type === 's' && $value !== null) {
                        if (!ctype_digit($value) || !array_key_exists((int) $value, $strings)) throw new ApiError('XLSX_INVALID', 'Неверная ссылка на строку XLSX.');
                        $value = $strings[(int) $value];
                    }
                    if ($type === 'e' || ($formula !== null && !$hasCachedValue)) $value = null;
                    if ($value !== null || $formula !== null) $cells[$address] = ['value' => $value, 'formula' => $formula, 'hasCachedValue' => $hasCachedValue, 'source' => ['sheet' => $sheet['name'], 'cell' => $address]];
                }
                $reader->close();
                $result[] = ['name' => $sheet['name'], 'cells' => $cells];
            }
            if (libxml_get_errors() !== []) throw new ApiError('XLSX_INVALID', 'Повреждён XML внутри XLSX.');
            if ($result === []) throw new ApiError('XLSX_INVALID', 'В книге нет доступных листов.');
            return ['sheets' => $result, 'cellCount' => $cellCount, 'unpackedBytes' => $bytes];
        } finally {
            $zip->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function xml(string $path, string $entry): XMLReader
    {
        $reader = new XMLReader();
        if (!@$reader->open('zip://' . $path . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT)) throw new ApiError('XLSX_INVALID', 'Не читается XML внутри XLSX.');
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
        $reader->setParserProperty(XMLReader::LOADDTD, false);
        return $reader;
    }

    private function rejectDoctype(XMLReader $reader): void
    {
        if ($reader->nodeType === XMLReader::DOC_TYPE) throw new ApiError('XLSX_UNSAFE_XML', 'Внешние сущности XML не допускаются.');
    }

    private function elementText(XMLReader $reader): string
    {
        $text = '';
        $depth = $reader->depth;
        if ($reader->isEmptyElement) return $text;
        while ($reader->read()) {
            $this->rejectDoctype($reader);
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) break;
            if (in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE], true)) $text .= $reader->value;
        }
        return $text;
    }
}
