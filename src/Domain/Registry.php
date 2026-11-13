<?php
declare(strict_types=1);

namespace Customs\Domain;

use Customs\Http\ApiError;

final class Registry
{
    private array $data;
    private array $fields;
    public function __construct()
    {
        $this->data = self::resource('fields');
        $this->fields = array_column($this->data['fields'], null, 'id');
    }
    public static function resource(string $name): array
    {
        if (!in_array($name, ['fields','rules','catalogs','calculation-profile'], true)) throw new \InvalidArgumentException('Unknown registry resource');
        return json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/registry/' . $name . '.v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    public function field(string $id): array
    {
        return $this->fields[$id] ?? throw new ApiError('FIELD_UNKNOWN', 'Неизвестное поле декларации.');
    }
    public function fields(): array { return $this->fields; }
    public function data(): array { return $this->data; }
    public function extractable(): array { return array_filter($this->fields, fn(array $field): bool => in_array('extract', $field['acquisition'], true)); }
}
