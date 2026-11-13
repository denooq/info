<?php
declare(strict_types=1);

namespace Customs\Documents;

use Customs\Config;
use Customs\Http\ApiError;

final class Inspector
{
    public function __construct(private readonly Config $config) {}

    public function inspect(string $path, string $name): array
    {
        $size = filesize($path);
        if ($size === false || $size < 1) throw new ApiError('FILE_EMPTY', 'Файл пуст или недоступен.');
        if ($size > $this->config->get('max_file_bytes')) throw new ApiError('FILE_LIMIT', 'Файл превышает допустимый размер.');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === 'jpeg') $extension = 'jpg';
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $expected = ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'png' => ['image/png'], 'xlsx' => ['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];
        if (!isset($expected[$extension]) || !in_array($mime, $expected[$extension], true)) throw new ApiError('FILE_TYPE', 'Формат содержимого не соответствует поддерживаемому расширению.');
        $pages = 1;
        if ($extension === 'pdf') $pages = $this->pdfPages($path);
        elseif ($extension === 'xlsx') {
            $book = (new XlsxReader($this->config->get('max_unpacked_bytes')))->read($path);
            $pages = count($book['sheets']);
        } else $this->validateImage($path, $extension, $size);
        if ($pages > $this->config->get('max_pages')) throw new ApiError('PAGE_LIMIT', 'Документ превышает лимит страниц сессии.');
        return ['size' => $size, 'mime' => $mime, 'extension' => $extension, 'pages' => $pages, 'hash' => hash_file('sha256', $path)];
    }

    private function pdfPages(string $path): int
    {
        $handle = fopen($path, 'rb');
        try {
            $header = fread($handle, 1024);
            fseek($handle, -min(filesize($path), 4096), SEEK_END);
            $tail = stream_get_contents($handle);
        } finally { fclose($handle); }
        if (!str_contains($header, '%PDF-') || !str_contains($tail, '%%EOF')) throw new ApiError('PDF_INVALID', 'PDF повреждён или не завершён.');
        $config = new \Smalot\PdfParser\Config();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(32 * 1024 * 1024);
        $config->setMaxNestingDepth(256);
        $config->setIgnoreEncryption(false);
        try {
            $parser = new \Smalot\PdfParser\Parser([], $config);
            $document = $parser->parseFile($path);
            $count = count($document->getPages());
            if ($count < 1) throw new ApiError('PDF_INVALID', 'В PDF не найдено страниц.');
            return $count;
        } catch (ApiError $error) { throw $error; }
        catch (\Throwable $error) {
            $encrypted = str_contains(strtolower($error->getMessage()), 'secur') || str_contains(strtolower($error->getMessage()), 'encrypt');
            throw new ApiError($encrypted ? 'PDF_ENCRYPTED' : 'PDF_INVALID', $encrypted ? 'Защищённый PDF: предоставьте файл без пароля.' : 'Не удалось проверить структуру PDF.');
        } finally { unset($document, $parser); gc_collect_cycles(); }
    }

    private function validateImage(string $path, string $extension, int $size): void
    {
        $dimensions = @getimagesize($path);
        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1) throw new ApiError('IMAGE_INVALID', 'Изображение повреждено.');
        $pixels = $dimensions[0] * $dimensions[1];
        $limit = ini_get('memory_limit');
        $memoryLimit = self::iniBytes($limit);
        // Free pages retained by Zend's allocator can be reused by decoding; count live
        // allocations instead of treating the whole reserved arena as occupied memory.
        if ($pixels > 50_000_000 || ($memoryLimit > 0 && $pixels * 5 + $size + memory_get_usage(false) + 8_388_608 > $memoryLimit)) {
            throw new ApiError('IMAGE_LIMIT', 'Изображение слишком велико для безопасной обработки на текущем хостинге. Уменьшите разрешение.');
        }
        $image = $extension === 'png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if ($image === false) throw new ApiError('IMAGE_INVALID', 'Не удалось прочитать изображение.');
        imagedestroy($image);
        unset($image);
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') return -1;
        $number = (int) $value;
        return $number * match (strtolower(substr($value, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
    }
}
