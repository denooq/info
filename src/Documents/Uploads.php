<?php
declare(strict_types=1);

namespace Customs\Documents;

use Customs\Config;
use Customs\Http\ApiError;
use Customs\Storage\SessionStore;

final class Uploads
{
    public function __construct(private readonly Config $config, private readonly SessionStore $store) {}

    public function receive(string $sessionId, array $upload, int $revision, bool $userActivity = false): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $limit = in_array($upload['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new ApiError($limit ? 'FILE_LIMIT' : 'UPLOAD_FAILED', $limit ? 'Превышен лимит загрузки PHP/приложения.' : 'Не удалось загрузить файл целиком.');
        }
        if (!is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name']) || !is_string($upload['name'] ?? null)) throw new ApiError('UPLOAD_FAILED', 'Неверная загрузка файла.');
        return $this->addFromPath($sessionId, $upload['tmp_name'], $upload['name'], $revision, $userActivity);
    }

    /** Local tests and HTTP receive() supply a local path; never expose this method with a client path. */
    public function addFromPath(string $sessionId, string $sourcePath, string $name, int $revision, bool $userActivity = false): array
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))) ?: 'document';
        if (mb_strlen($name) > 240) throw new ApiError('FILE_NAME', 'Слишком длинное имя файла.');
        $this->store->read($sessionId);
        $lock = fopen($this->config->get('runtime_dir') . '/heavy.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new ApiError('PROCESSING_BUSY', 'Обработка занята. Повторите загрузку через несколько секунд.', 503);
        }
        $destination = null;
        $committed = false;
        try {
            $info = (new Inspector($this->config))->inspect($sourcePath, $name);
            $id = bin2hex(random_bytes(16));
            $destination = $this->store->documentPath($sessionId, $id, $info['extension']);
            $state = $this->store->mutate($sessionId, $revision, function (array $state) use ($id, $sourcePath, $name, $info, $destination): array {
                if (count($state['documents']) >= $this->config->get('max_files')) throw new ApiError('FILE_COUNT_LIMIT', 'Превышен лимит количества файлов.');
                $total = array_sum(array_column($state['documents'], 'size'));
                $pages = array_sum(array_column($state['documents'], 'pages'));
                if ($total + $info['size'] > $this->config->get('max_session_bytes')) throw new ApiError('SESSION_SIZE_LIMIT', 'Превышен общий размер документов сессии.');
                if ($pages + $info['pages'] > $this->config->get('max_pages')) throw new ApiError('PAGE_LIMIT', 'Превышен общий лимит страниц сессии.');
                $used = $this->runtimeSize();
                $free = disk_free_space($this->config->get('runtime_dir'));
                if ($used + $info['size'] > $this->config->get('max_runtime_bytes') || ($free !== false && $free < $info['size'] + 10_485_760)) throw new ApiError('STORAGE_LIMIT', 'Недостаточно места во временном хранилище.', 503);
                if (!copy($sourcePath, $destination)) throw new ApiError('STORAGE_ERROR', 'Не удалось сохранить загруженный файл.', 503);
                @chmod($destination, 0600);
                $state['documents'][$id] = ['id' => $id, 'name' => $name, 'mime' => $info['mime'], 'size' => $info['size'], 'pages' => $info['pages'], 'hash' => $info['hash'], 'storageName' => basename($destination), 'extension' => $info['extension'], 'status' => 'pending', 'error' => null];
                $state['phase'] = 'uploading';
                return $state;
            },true,$userActivity);
            $committed = true;
            return $state;
        } finally {
            if (!$committed && $destination !== null && is_file($destination)) @unlink($destination);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function remove(string $sessionId, string $documentId, int $revision, bool $userActivity = false): array
    {
        $filename = null;
        $state = $this->store->mutate($sessionId, $revision, function (array $state) use ($documentId, &$filename): array {
            if (!isset($state['documents'][$documentId])) throw new ApiError('DOCUMENT_NOT_FOUND', 'Документ не найден.', 404);
            $filename = $state['documents'][$documentId]['storageName'];
            unset($state['documents'][$documentId]);
            $removedEvidence = [];
            foreach ($state['evidence'] as $key => $evidence) if (($evidence['documentId'] ?? null) === $documentId) { $removedEvidence[] = $key; unset($state['evidence'][$key]); }
            $state['candidates'] = array_filter($state['candidates'], fn(array $candidate): bool => !in_array($documentId, $candidate['documentIds'] ?? [], true));
            foreach ($state['draft']['fieldStates'] ?? [] as $key => $field) {
                if (array_intersect($field['evidenceIds'] ?? [], $removedEvidence)) $state['draft']['fieldStates'][$key]['needsReview'] = true;
            }
            $state['phase'] = 'uploading';
            return $state;
        },true,$userActivity);
        if (is_string($filename) && preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|xlsx)$/D', $filename)) @unlink($this->store->directory($sessionId) . '/documents/' . $filename);
        return $state;
    }

    private function runtimeSize(): int
    {
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->config->get('runtime_dir'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && !$file->isLink()) $size += $file->getSize();
        }
        return $size;
    }
}
