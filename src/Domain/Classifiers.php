<?php
declare(strict_types=1);

namespace Customs\Domain;

use Customs\Http\ApiError;

final class Classifiers
{
    private array $data;
    public function __construct()
    {
        static $cache=null;
        $this->data = $cache??=json_decode(file_get_contents(dirname(__DIR__,2).'/resources/registry/classifiers.v2.json'),true,512,JSON_THROW_ON_ERROR);
    }
    public function data(): array { return $this->data; }
    public function version(): string { return $this->data['version']; }
    public function country(string $code): ?array
    {
        foreach ($this->data['countries'] as $row) if ($row['code'] === $code) return $row;
        return null;
    }
    public function documents(string $query = '', int $offset = 0, int $limit = 40, ?string $date = null): array
    {
        $query = self::searchText($query); $rows = [];
        foreach ($this->data['documents'] as $row) {
            if ($query !== '' && !str_contains($row['code'], $query) && !str_contains(self::searchText($row['name']), $query)) continue;
            $rows[] = $this->availability($row, $date ?? DeclarationDate::today(time()));
        }
        $offset = max(0,$offset); $limit = min(100,max(1,$limit));
        return ['version'=>$this->version(),'asOf'=>$this->data['asOf'],'items'=>array_slice($rows,$offset,$limit),'total'=>count($rows),'offset'=>$offset,'nextOffset'=>$offset+$limit<count($rows)?$offset+$limit:null,'retired'=>$query === '' ? [] : array_values(array_filter($this->data['retired'], fn($r)=>str_contains($r['code'],$query)))];
    }
    public function document(string $code, ?string $date = null): ?array
    {
        foreach ($this->data['documents'] as $row) if ($row['code'] === $code) return $this->availability($row,$date ?? DeclarationDate::today(time()));
        return null;
    }
    public function requireDocument(string $code, string $version, ?string $date = null): array
    {
        if ($version !== $this->version()) throw new ApiError('CLASSIFIER_VERSION','Обновите список видов документов: версия справочника изменилась.',409);
        $row = $this->document($code,$date);
        if (!$row) throw new ApiError('DOCUMENT_KIND_UNKNOWN','Код отсутствует в принятом официальном классификаторе.',422);
        if (!$row['selectable']) throw new ApiError('DOCUMENT_KIND_UNAVAILABLE',$row['unavailableReason'],422);
        return $row;
    }
    private function availability(array $row, string $date): array
    {
        if(isset($row['futureName'])&&$date>=$row['futureName']['date'])$row['name']=$row['futureName']['name'];
        $reason = $row['condition'];
        if ($row['validFrom'] !== null && $date < $row['validFrom']) $reason='Позиция действует с '.$row['validFrom'];
        if ($row['validUntil'] !== null && $date >= $row['validUntil']) $reason='Позиция отменена с '.$row['validUntil'];
        if ($row['restriction'] !== null && !str_contains($row['restriction'],'Казахстан')) $reason='Область применения не включает Казахстан: '.$row['restriction'];
        return [...$row,'selectable'=>$reason === null,'unavailableReason'=>$reason];
    }
    private static function searchText(string $value): string { return mb_strtolower(trim(preg_replace('/\s+/u',' ',$value))); }
}
