<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Config;
use Customs\Http\ApiError;

/** One process, concurrent network transfers, and a callback for each completed file. */
final class ParallelOcrTransport
{
    public function __construct(private readonly Config $config) {}

    public function send(string $url, array $requests, array $headers, string $directory, callable $complete): void
    {
        $multi = curl_multi_init();
        $active = [];
        $used = $this->runtimeBytes();
        try {
            foreach ($requests as $id => $request) {
                try {
                    $body = new OcrRequestBody($request['path'], $request['payload']);
                    if (!is_dir($directory)) throw new ApiError('SESSION_EXPIRED', 'Сессия завершена.', 410);
                    $path = $directory . '/transfer-' . bin2hex(random_bytes(16)) . '.tmp';
                    $file = @fopen($path, 'x+b');
                    if ($file === false) throw new ApiError('RUNTIME_UNWRITABLE', 'Не удалось сохранить ответ распознавания.', 503);
                    @chmod($path, 0600);
                    $transfer = (object) ['id' => $id, 'body' => $body, 'file' => $file, 'path' => $path, 'bytes' => 0, 'headers' => [], 'error' => null];
                    $curl = curl_init($url);
                    $transfer->curl = $curl;
                    $active[spl_object_id($curl)] = $transfer;
                    curl_setopt_array($curl, [
                        CURLOPT_UPLOAD => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_INFILESIZE => $body->length,
                        CURLOPT_READFUNCTION => static function ($handle, $stream, int $length) use ($body, $transfer): string|int {
                            try { return $body->read($length); }
                            catch (\Throwable $error) { $transfer->error = $error; return CURL_READFUNC_ABORT; }
                        },
                        CURLOPT_HTTPHEADER => [...$headers, 'Expect:', 'Content-Length: ' . $body->length],
                        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $this->config->get('http_timeout'),
                        CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use ($transfer, &$used): int {
                            $length = strlen($chunk);
                            if ($transfer->bytes + $length > 16_777_216) {
                                $transfer->error = new ApiError('MISTRAL_RESPONSE_LIMIT', 'Ответ распознавания превышает безопасный объём.', 502);
                                return 0;
                            }
                            if ($used + $length > $this->config->get('max_runtime_bytes')) {
                                $transfer->error = new ApiError('RUNTIME_QUOTA', 'Недостаточно места для ответа распознавания.', 503);
                                return 0;
                            }
                            $written = fwrite($transfer->file, $chunk);
                            if ($written === false || $written !== $length) {
                                $transfer->error = new ApiError('RUNTIME_UNWRITABLE', 'Не удалось сохранить ответ распознавания.', 503);
                                return 0;
                            }
                            $transfer->bytes += $length; $used += $length;
                            return $length;
                        },
                        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use ($transfer): int {
                            if (str_contains($line, ':')) {
                                [$name, $value] = explode(':', $line, 2);
                                $transfer->headers[strtolower(trim($name))] = trim($value);
                            }
                            return strlen($line);
                        },
                    ]);
                    if (curl_multi_add_handle($multi, $curl) !== CURLM_OK) throw new ApiError('NETWORK_ERROR', 'Не удалось запустить распознавание.', 502);
                } catch (\Throwable $error) {
                    if (isset($curl) && isset($active[spl_object_id($curl)])) {
                        $this->close($multi, $active[spl_object_id($curl)]);
                        unset($active[spl_object_id($curl)]);
                    }
                    $complete($id, null, $error);
                } finally { unset($curl, $body, $transfer); }
            }
            while ($active !== []) {
                if (curl_multi_exec($multi, $running) !== CURLM_OK) throw new ApiError('NETWORK_ERROR', 'Параллельная обработка прервана.', 502, ['outcomeUnknown' => true]);
                while (($info = curl_multi_info_read($multi)) !== false) {
                    $key = spl_object_id($info['handle']);
                    $transfer = $active[$key];
                    $error = $transfer->error;
                    if ($error === null && $info['result'] !== CURLE_OK) $error = new ApiError('NETWORK_ERROR', 'Нет ответа Mistral. Состояние этапа сохранено для ограниченного повтора.', 502, ['outcomeUnknown' => true]);
                    rewind($transfer->file);
                    $response = $error === null ? ['status' => curl_getinfo($transfer->curl, CURLINFO_HTTP_CODE), 'headers' => $transfer->headers, 'body' => stream_get_contents($transfer->file)] : null;
                    $id = $transfer->id;
                    $this->close($multi, $transfer);
                    unset($active[$key], $transfer);
                    $complete($id, $response, $error);
                    unset($response);
                    $used = $this->runtimeBytes();
                }
                if ($active !== [] && curl_multi_select($multi, 0.5) === -1) usleep(10_000);
            }
        } finally {
            foreach ($active as $transfer) $this->close($multi, $transfer);
            curl_multi_close($multi);
        }
    }

    private function close(\CurlMultiHandle $multi, object $transfer): void
    {
        curl_multi_remove_handle($multi, $transfer->curl);
        curl_close($transfer->curl);
        fclose($transfer->file);
        @unlink($transfer->path);
        unset($transfer->body);
    }

    private function runtimeBytes(): int
    {
        clearstatcache();
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->config->get('runtime_dir'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) if ($file->isFile() && !$file->isLink()) $bytes += $file->getSize();
        return $bytes;
    }
}
