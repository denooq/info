<?php
declare(strict_types=1);

namespace Customs\Storage;

use Closure;
use Customs\Config;
use Customs\Http\ApiError;

final class SessionStore
{
    private string $root;

    public function __construct(private readonly Config $config, private readonly ?Closure $clock = null)
    {
        $this->root = $config->get('runtime_dir');
        foreach (['sessions','locks'] as $directory) {
            $path = $this->root . '/' . $directory;
            if (!is_dir($path) && !mkdir($path, 0700) && !is_dir($path)) throw new ApiError('STORAGE_ERROR', 'Не удалось подготовить временное хранилище.', 503);
            if (!Config::inside(realpath($path) ?: '', $this->root)) throw new ApiError('STORAGE_ERROR', 'Недопустимый путь хранилища.', 503);
        }
    }

    public function now(): int { return $this->clock === null ? time() : ($this->clock)(); }

    public function create(): array
    {
        $token = bin2hex(random_bytes(32));
        $id = hash('sha256', $token);
        $directory = $this->directory($id);
        if (!mkdir($directory, 0700)) throw new ApiError('STORAGE_ERROR', 'Не удалось создать сессию.', 503);
        foreach (['documents','ocr','artifacts','scratch'] as $part) {
            if (!mkdir($directory . '/' . $part, 0700)) throw new ApiError('STORAGE_ERROR', 'Не удалось подготовить сессию.', 503);
        }
        $now = $this->now();
        $state = [
            'id' => $id, 'createdAt' => $now, 'expiresAt' => $now + $this->config->get('session_ttl'),
            'dataRevision' => 0, 'phase' => 'uploading', 'csrfToken' => bin2hex(random_bytes(24)),
            'documents' => [], 'candidates' => [], 'evidence' => [], 'jobs' => [],
            'draft' => (new \Customs\Domain\Draft(new \Customs\Domain\Registry()))->empty($now),
            'calculation' => null, 'confirmed' => null, 'artifactSet' => null, 'error' => null,
            'registryVersion' => '2.0.0','schemaVersion'=>2,'profile'=>'im40-kz-graphs','uploadGroups'=>[],
        ];
        $this->writeState($directory, $state);
        return ['token' => $token, 'state' => $state];
    }

    public function fromToken(?string $token): array
    {
        if ($token === null || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new ApiError('SESSION_REQUIRED', 'Браузер не передал cookie текущей сессии. Проверьте подключение к прежней сессии.', 401);
        return $this->read(hash('sha256', $token));
    }

    /** Internal worker access. HTTP callers must authenticate through fromToken first. */
    public function read(string $id): array
    {
        $handle = $this->lock($id, LOCK_SH);
        try { return $this->readUnlocked($id); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function mutate(string $id, ?int $expectedRevision, callable $change, bool $dataChange = true, bool $userActivity = false): array
    {
        $handle = $this->lock($id, LOCK_EX);
        try {
            $state = $this->readUnlocked($id);
            if ($expectedRevision !== null && $state['dataRevision'] !== $expectedRevision) {
                throw new ApiError('REVISION_CONFLICT', 'Данные уже изменились. Обновите текущую версию.', 409, ['currentRevision' => $state['dataRevision']]);
            }
            $changed = $change($state);
            if (!is_array($changed)) throw new \LogicException('Mutation must return session state');
            foreach (['id','createdAt','expiresAt','csrfToken'] as $immutable) $changed[$immutable] = $state[$immutable];
            if ($this->now() >= $state['expiresAt']) throw new ApiError('SESSION_EXPIRED', 'Срок сессии истёк. Загрузите документы заново.', 410);
            // Only an authenticated user action renews the idle timeout. Workers and polling do not.
            if ($userActivity) $changed['expiresAt'] = max($state['expiresAt'], $this->now() + $this->config->get('session_ttl'));
            if ($dataChange) {
                $changed['dataRevision'] = $state['dataRevision'] + 1;
                $changed['calculation'] = null;
                $changed['confirmed'] = null;
                $changed['artifactSet'] = null;
                if (!in_array($changed['phase'], ['uploading','recognizing'], true)) $changed['phase'] = 'reviewing';
            } else $changed['dataRevision'] = $state['dataRevision'];
            $changed['draft']=\Customs\Domain\GraphDefaults::apply(\Customs\Domain\DeclarationDate::defaults($changed['draft'],$this->now()));
            $this->writeState($this->directory($id), $changed);
            return $changed;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function directory(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new ApiError('SESSION_REQUIRED', 'Недопустимая сессия.', 401);
        $path = $this->root . '/sessions/' . $id;
        if (is_link($path)) throw new ApiError('STORAGE_ERROR', 'Недопустимый каталог сессии.', 503);
        return $path;
    }

    public function documentPath(string $id, string $documentId, string $extension): string
    {
        $this->read($id);
        if (!preg_match('/^[a-f0-9]{32}$/D', $documentId) || !in_array($extension, ['pdf','jpg','png','xlsx'], true)) {
            throw new ApiError('DOCUMENT_INVALID', 'Недопустимый идентификатор документа.');
        }
        return $this->directory($id) . '/documents/' . $documentId . '.' . $extension;
    }

    public function publicView(array $state): array
    {
        $copy = array_intersect_key($state, array_flip(['createdAt','expiresAt','dataRevision','phase','csrfToken','draft','candidates','evidence','calculation','error','registryVersion','mappingIssues','exchangeRates']));
        $copy['capabilities']=['nbkRates'=>true,'graphs'=>true,'export'=>false];
        $copy['profile']='im40-kz-graphs';$copy['uploadGroups']=array_values($state['uploadGroups']??[]);
        $copy['graphProjection']=(new \Customs\Domain\GraphProjection())->project($state);
        $copy['documents'] = array_map(fn(array $doc): array => array_intersect_key($doc, array_flip(['id','name','mime','size','pages','status','error','kind','groupId','documentKind','classifierVersion'])), array_values($state['documents']));
        $copy['jobs'] = array_map(fn(array $job): array => array_intersect_key($job, array_flip(['id','kind','documentId','chunkStart','status','attempts','nextAttemptAt','error'])), array_values($state['jobs']));
        $copy['ready'] = false;
        $copy['limits'] = $this->config->clientLimits();
        $copy['reviewValidation']=(new \Customs\Domain\ReviewValidator())->validate($state);
        $copy['calculationValidation']=(new \Customs\Domain\Validator(new \Customs\Domain\Registry()))->validate($state);
        $copy['validation']=$copy['reviewValidation'];
        return $copy;
    }

    public function cleanupExpired(): array
    {
        $removed = 0; $errors = 0;
        $sessionsRoot = realpath($this->root . '/sessions');
        foreach (glob($this->root . '/sessions/*', GLOB_ONLYDIR) as $directory) {
            $id = basename($directory);
            if (!preg_match('/^[a-f0-9]{64}$/D', $id) || is_link($directory)) continue;
            $resolved = realpath($directory);
            if ($resolved === false || $sessionsRoot === false || !Config::inside($resolved, $sessionsRoot)) continue;
            $lockPath = $this->root . '/locks/' . $id . '.lock';
            $handle = @fopen($lockPath, 'c+b');
            if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) { if (is_resource($handle)) fclose($handle); continue; }
            $deleted = false;
            try {
                $statePath = $directory . '/state.json';
                $state = is_file($statePath) ? json_decode(file_get_contents($statePath), true) : null;
                $expired = is_array($state) && isset($state['expiresAt'])
                    ? $this->now() >= $state['expiresAt']
                    : $this->now() >= filemtime($directory) + $this->config->get('session_ttl');
                if (!$expired) continue;
                // Validate the absolute target and each child; never follow symlinks.
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $entry) {
                    $path = $entry->getPathname();
                    if (str_replace('\\', '/', $path) === str_replace('\\', '/', $statePath)) continue;
                    if (!Config::inside(str_replace('\\', '/', $path), $resolved)) throw new ApiError('CLEANUP_PATH', 'Недопустимый путь очистки.', 503);
                    if ($entry->isLink()) { if (!unlink($path)) throw new \RuntimeException('cleanup link'); continue; }
                    $realChild = realpath($path);
                    if ($realChild === false || !Config::inside($realChild, $resolved)) throw new ApiError('CLEANUP_PATH', 'Недопустимый путь очистки.', 503);
                    $ok = $entry->isDir() ? rmdir($path) : unlink($path);
                    if (!$ok) throw new \RuntimeException('cleanup child');
                }
                if (is_file($statePath) && !unlink($statePath)) throw new \RuntimeException('cleanup state');
                if (!rmdir($directory)) throw new \RuntimeException('cleanup directory');
                $deleted = true; $removed++;
            } catch (\Throwable) { $errors++; }
            finally {
                flock($handle, LOCK_UN); fclose($handle);
                if ($deleted) @unlink($lockPath);
            }
        }
        foreach (glob($this->root . '/locks/*.lock') as $lockPath) {
            $id = basename($lockPath, '.lock');
            if (!preg_match('/^[a-f0-9]{64}$/D', $id) || is_dir($this->root . '/sessions/' . $id) || is_link($lockPath)) continue;
            $handle = @fopen($lockPath, 'r+b');
            if ($handle !== false && flock($handle, LOCK_EX | LOCK_NB)) { flock($handle, LOCK_UN); fclose($handle); @unlink($lockPath); }
            elseif (is_resource($handle)) fclose($handle);
        }
        return ['removed' => $removed, 'errors' => $errors];
    }

    private function readUnlocked(string $id): array
    {
        $directory = $this->directory($id);
        $path = $directory . '/state.json';
        if (!is_file($path) || is_link($path)) throw new ApiError('SESSION_EXPIRED', 'Сессия недоступна. Начните новую загрузку.', 410);
        $real = realpath($path);
        if ($real === false || !Config::inside($real, $this->root . '/sessions')) throw new ApiError('STORAGE_ERROR', 'Недопустимый путь состояния.', 503);
        try { $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiError('STORAGE_ERROR', 'Не удалось прочитать состояние сессии.', 503); }
        if (!is_array($state) || ($state['id'] ?? null) !== $id) throw new ApiError('STORAGE_ERROR', 'Неверное состояние сессии.', 503);
        if ($this->now() >= $state['expiresAt']) throw new ApiError('SESSION_EXPIRED', 'Срок сессии истёк. Загрузите документы заново.', 410);
        return SessionMigration::upgrade($state);
    }

    private function lock(string $id, int $mode)
    {
        $directory = $this->directory($id);
        if (!is_file($directory . '/state.json')) throw new ApiError('SESSION_EXPIRED', 'Сессия недоступна. Начните новую загрузку.', 410);
        $handle = @fopen($this->root . '/locks/' . $id . '.lock', 'c+b');
        if ($handle === false || !flock($handle, $mode)) throw new ApiError('STORAGE_ERROR', 'Не удалось заблокировать состояние сессии.', 503);
        return $handle;
    }

    private function writeState(string $directory, array $state): void
    {
        if (!is_dir($directory) || is_link($directory)) throw new ApiError('SESSION_EXPIRED', 'Сессия больше недоступна.', 410);
        $path = $directory . '/state-' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $handle = @fopen($path, 'xb');
        if ($handle === false) throw new ApiError('STORAGE_ERROR', 'Не удалось записать состояние сессии.', 503);
        try {
            @chmod($path, 0600);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) throw new ApiError('STORAGE_ERROR', 'Неполная запись состояния.', 503);
        } finally { fclose($handle); }
        $published=false;
        for($attempt=0;$attempt<6;$attempt++) {
            if(@rename($path,$directory.'/state.json')){$published=true;break;}
            // Windows scanners can briefly hold an otherwise closed file. Keep the old JSON
            // intact and retry replacement under the same session lock, never unlink it first.
            if(PHP_OS_FAMILY!=='Windows')break;
            usleep(20_000*($attempt+1));
        }
        if (!$published) {
            @unlink($path);
            throw new ApiError('STORAGE_ERROR', 'Не удалось опубликовать состояние сессии.', 503);
        }
    }
}
