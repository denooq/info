<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Http\ApiError;

/** Streams a JSON data URI without keeping a copy of every document in memory. */
final class OcrRequestBody
{
    private $file;
    private string $prefix;
    private string $suffix;
    public readonly int $length;

    public function __construct(string $path, array $payload)
    {
        $this->file = @fopen($path, 'rb');
        if ($this->file === false) throw new ApiError('DOCUMENT_NOT_FOUND', 'Документ недоступен или удалён.', 404);
        $marker = '__BASE64_' . bin2hex(random_bytes(16)) . '__';
        $key = $payload['document']['type'];
        $payload['document'][$key] .= $marker;
        [$this->prefix, $this->suffix] = explode($marker, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 2);
        $size = fstat($this->file)['size'];
        $this->length = strlen($this->prefix) + 4 * intdiv($size + 2, 3) + strlen($this->suffix);
        stream_filter_append($this->file, 'convert.base64-encode', STREAM_FILTER_READ);
    }

    public function read(int $length): string
    {
        $result = substr($this->prefix, 0, $length);
        $this->prefix = substr($this->prefix, strlen($result));
        if (strlen($result) < $length && !feof($this->file)) {
            $chunk = fread($this->file, $length - strlen($result));
            if ($chunk === false) throw new ApiError('DOCUMENT_NOT_FOUND', 'Не удалось прочитать документ.', 404);
            $result .= $chunk;
        }
        if (strlen($result) < $length && feof($this->file)) {
            $chunk = substr($this->suffix, 0, $length - strlen($result));
            $this->suffix = substr($this->suffix, strlen($chunk));
            $result .= $chunk;
        }
        return $result;
    }

    public function __destruct()
    {
        if (is_resource($this->file)) fclose($this->file);
    }
}
