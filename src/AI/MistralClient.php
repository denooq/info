<?php
declare(strict_types=1);

namespace Customs\AI;

use Closure;
use Customs\Config;
use Customs\Http\ApiError;

final class MistralClient
{
    public function __construct(private readonly Config $config, private readonly ?Closure $transport = null) {}

    public function ocr(string $path, string $mime, array $pages): array
    {
        $result = null; $failure = null;
        $this->ocrBatch(['single' => ['path' => $path, 'mime' => $mime, 'pages' => $pages]], dirname($path), static function ($id, $response, $error) use (&$result, &$failure): void { $result = $response; $failure = $error; });
        if ($failure !== null) throw $failure;
        return $result;
    }

    public function ocrBatch(array $requests, string $directory, callable $complete): void
    {
        $key = (string) $this->config->get('mistral_api_key');
        if ($key === '') throw new ApiError('MISTRAL_NOT_CONFIGURED', 'Ключ Mistral не настроен на backend.', 503);
        $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/json'];
        $transfers = [];
        foreach ($requests as $id => $request) $transfers[$id] = ['path' => $request['path'], 'payload' => $this->ocrPayload($request['mime'], $request['pages'])];
        $receive = function ($id, ?array $response, ?\Throwable $error) use ($complete): void {
            $decoded = null;
            if ($error === null) {
                try { $decoded = $this->decode($response); }
                catch (\Throwable $failure) { $error = $failure; }
            }
            $complete($id, $decoded, $error);
        };
        if ($this->transport === null) {
            (new ParallelOcrTransport($this->config))->send('https://api.mistral.ai/v1/ocr', $transfers, $headers, $directory, $receive);
            return;
        }
        // Injectable transport is for offline tests; production uses curl_multi above.
        foreach ($transfers as $id => $request) {
            $response = null; $error = null;
            try {
                $body = new OcrRequestBody($request['path'], $request['payload']);
                $json = '';
                while (($chunk = $body->read(65_536)) !== '') $json .= $chunk;
                $response = ($this->transport)('https://api.mistral.ai/v1/ocr', json_decode($json, true, 256, JSON_THROW_ON_ERROR), $headers);
            } catch (\Throwable $failure) { $error = $failure; }
            $receive($id, $response, $error);
        }
    }

    private function ocrPayload(string $mime, array $pages): array
    {
        $data = 'data:' . $mime . ';base64,';
        return [
            'model' => $this->config->get('ocr_model'),
            'document' => str_starts_with($mime, 'image/') ? ['type' => 'image_url','image_url' => $data] : ['type' => 'document_url','document_url' => $data],
            'pages' => $pages, 'include_blocks' => true, 'table_format' => 'html',
            'confidence_scores_granularity' => 'block', 'include_image_base64' => false,
        ];
    }

    public function structured(string $system, string $input, array $schema): array
    {
        return $this->request('/chat/completions', [
            'model' => $this->config->get('mapping_model'), 'temperature' => 0, 'max_tokens' => 16000,
            'messages' => [['role' => 'system','content' => $system], ['role' => 'user','content' => $input]],
              'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'customs_graph_mapping_v2','strict' => true,'schema' => $schema]],
        ]);
    }

    private function request(string $endpoint, array $payload): array
    {
        $key = (string) $this->config->get('mistral_api_key');
        if ($key === '') throw new ApiError('MISTRAL_NOT_CONFIGURED', 'Ключ Mistral не настроен на backend.', 503);
        $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/json'];
        if ($this->transport !== null) {
            $response = ($this->transport)('https://api.mistral.ai/v1' . $endpoint, $payload, $headers);
        } else {
            $body = ''; $responseHeaders = []; $oversized = false;
            $curl = curl_init('https://api.mistral.ai/v1' . $endpoint);
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $this->config->get('http_timeout'),
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$oversized): int {
                    if (strlen($body) + strlen($chunk) > 16_777_216) { $oversized = true; return 0; }
                    $body .= $chunk; return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                    if (str_contains($line, ':')) { [$name,$value] = explode(':', $line, 2); $responseHeaders[strtolower(trim($name))] = trim($value); }
                    return strlen($line);
                },
            ]);
            $success = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($oversized) throw new ApiError('MISTRAL_RESPONSE_LIMIT', 'Ответ распознавания превышает безопасный объём.', 502);
            if ($success === false) throw new ApiError('NETWORK_ERROR', 'Нет ответа Mistral. Состояние этапа сохранено для ограниченного повтора.', 502, ['outcomeUnknown' => true]);
            $response = ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
        }
        return $this->decode($response);
    }

    private function decode(array $response): array
    {
        $status = (int) ($response['status'] ?? 0);
        $retryAfter = $response['headers']['retry-after'] ?? null;
        if ($status === 429) throw new ApiError('MISTRAL_RATE_LIMIT', 'Достигнут лимит запросов Mistral. Обработка будет повторена.', 429, ['retryAfter' => is_numeric($retryAfter) ? min(300, (int) $retryAfter) : 0]);
        if ($status >= 500 || $status === 0) throw new ApiError('MISTRAL_TEMPORARY', 'Mistral временно недоступен.', 502);
        if ($status === 401 || $status === 403) throw new ApiError('MISTRAL_AUTH', 'Mistral не принял ключ или доступ к модели.', 503);
        if ($status === 402) throw new ApiError('MISTRAL_BILLING', 'Mistral сообщает о недоступном балансе или оплате.', 503);
        if ($status < 200 || $status >= 300) throw new ApiError('MISTRAL_REQUEST', 'Mistral отклонил формат документа или параметры запроса.', 502, ['providerStatus' => $status]);
        try { $decoded = json_decode($response['body'], true, 256, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiError('MISTRAL_RESPONSE', 'Mistral вернул нечитаемый структурированный ответ.', 502); }
        if (!is_array($decoded)) throw new ApiError('MISTRAL_RESPONSE', 'Неверный формат ответа Mistral.', 502);
        return $decoded;
    }
}
