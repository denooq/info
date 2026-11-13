<?php
declare(strict_types=1);

namespace Customs\Http;

use Customs\Config;
use Customs\Storage\SessionStore;

final class Application
{
    private SessionStore $store;
    public function __construct(private readonly Config $config)
    {
        $this->store = new SessionStore($config);
    }

    public function handle(string $method, string $path): array|Download
    {
        if ($method === 'GET' && $path === '/api/registry') {
            return ['fields' => $this->resource('fields'), 'catalogs' => $this->resource('catalogs'), 'rules' => $this->resource('rules')];
        }
        if ($method === 'POST' && $path === '/api/session') {
            $session = $this->store->create();
            setcookie('customs_session', $session['token'], [
                'expires' => $session['state']['expiresAt'], 'path' => '/',
                'secure' => (bool) $this->config->get('secure_cookie'), 'httponly' => true, 'samesite' => 'Strict',
            ]);
            return $this->store->publicView($session['state']);
        }
        $state = $this->store->fromToken($_COOKIE['customs_session'] ?? null);
        if ($method !== 'GET') $this->csrf($state);
        if ($method === 'GET' && $path === '/api/session') return $this->store->publicView($state);
        if($method==='GET' && preg_match('#^/api/download/(pdf|xml)$#D',$path,$match))return Download::fromState($this->store,$state,$match[1]);
        if ($method === 'POST' && $path === '/api/recognize') {
            $body = $this->body();
            $pipeline = new \Customs\AI\Pipeline($this->config, $this->store, new \Customs\AI\MistralClient($this->config));
            return $this->store->publicView($pipeline->start($state['id'], (bool) ($body['retry'] ?? false)));
        }
        if ($method === 'PATCH' && $path === '/api/draft') {
            $body = $this->body();
            if (!is_int($body['revision'] ?? null) || !is_array($body['changes'] ?? null) || count($body['changes']) > 1000) throw new ApiError('DRAFT_REQUEST', 'Нужны версия данных и изменения полей.');
            $updated = $this->store->mutate($state['id'], $body['revision'], function (array $current) use ($body): array {
                $draft = new \Customs\Domain\Draft(new \Customs\Domain\Registry());
                foreach ($body['changes'] as $change) {
                    if (!is_string($change['fieldId'] ?? null)) throw new ApiError('FIELD_UNKNOWN', 'Не указано поле изменения.');
                    $current = $draft->edit($current, $change['fieldId'], $change['entityId'] ?? null, $change['value'] ?? null, $change['candidateId'] ?? null);
                }
                return $current;
            });
            return $this->store->publicView($updated);
        }
        if ($method === 'POST' && $path === '/api/entities') {
            $body=$this->body();
            if (!is_int($body['revision']??null)) throw new ApiError('REVISION_REQUIRED','Нужна версия данных.');
            return $this->store->publicView($this->store->mutate($state['id'],$body['revision'],fn(array $s):array=>(new \Customs\Domain\Entities(new \Customs\Domain\Registry()))->change($s,$body)));
        }
        if ($method === 'POST' && $path === '/api/validate') return (new \Customs\Domain\Validator(new \Customs\Domain\Registry()))->validate($state);
        if ($method === 'POST' && $path === '/api/calculate') {
            $body=$this->body();
            if(!is_int($body['revision']??null))throw new ApiError('REVISION_REQUIRED','Нужна версия данных.');
            $updated=$this->store->mutate($state['id'],$body['revision'],function(array $s):array {
                if(in_array($s['phase'],['uploading','recognizing'],true))throw new ApiError('EXTRACTION_PENDING','Сначала завершите распознавание и проверку.',409);
                $s['calculation']=(new \Customs\Domain\Calculator())->calculate($s);
                $s['confirmed']=$s['artifactSet']=null; $s['phase']='calculated';return $s;
            },false);
            return $this->store->publicView($updated);
        }
        if ($method === 'POST' && $path === '/api/confirm') {
            $body=$this->body();
            if(!is_int($body['revision']??null)||!is_string($body['calculationHash']??null))throw new ApiError('CONFIRM_REQUEST','Нужны текущие версии данных и расчёта.');
            $updated=$this->store->mutate($state['id'],$body['revision'],function(array $s)use($body):array{
                $s=(new \Customs\Export\Snapshot())->confirm($s,$body['calculationHash']);
                (new \Customs\Export\XmlProfiles())->requireSupported($s['confirmed']);return $s;
            },false);
            (new \Customs\Jobs\Queue($this->config,$this->store))->enqueue($state['id'],'generate',null,true);
            return $this->store->publicView($this->store->read($state['id']));
        }
        if ($method === 'PATCH' && $path === '/api/calculation') {
            $body=$this->body();
            if(!is_int($body['revision']??null)||!is_string($body['entityId']??null)||!is_string($body['key']??null))throw new ApiError('CALC_REQUEST','Нужны версия, товар и поле расчёта.');
            return $this->store->publicView($this->store->mutate($state['id'],$body['revision'],function(array $s)use($body):array {
                if($s['calculation']===null||$s['calculation']['dataRevision']!==$s['dataRevision'])throw new ApiError('CALC_STALE','Выполните актуальный расчёт.',409);
                $s['calculation']=(new \Customs\Domain\Calculator())->propose($s['calculation'],$body['entityId'],$body['key'],$body['value']??null);
                $s['confirmed']=$s['artifactSet']=null;$s['phase']='calculated';return $s;
            },false));
        }
        if ($method === 'POST' && $path === '/api/documents') {
            $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($revision === false || !isset($_FILES['file'])) throw new ApiError('UPLOAD_FAILED', 'Нужны файл и текущая версия данных.');
            $updated = (new \Customs\Documents\Uploads($this->config, $this->store))->receive($state['id'], $_FILES['file'], $revision);
            return $this->store->publicView($updated);
        }
        if ($method === 'DELETE' && preg_match('#^/api/documents/([a-f0-9]{32})$#D', $path, $matches)) {
            $body = $this->body();
            if (!is_int($body['revision'] ?? null)) throw new ApiError('REVISION_REQUIRED', 'Нужна текущая версия данных.');
            $updated = (new \Customs\Documents\Uploads($this->config, $this->store))->remove($state['id'], $matches[1], $body['revision']);
            return $this->store->publicView($updated);
        }
        throw new ApiError('NOT_FOUND', 'Операция API не найдена.', 404);
    }

    private function body(): array
    {
        $raw = file_get_contents('php://input', false, null, 0, 2_097_153);
        if (strlen($raw) > 2_097_152) throw new ApiError('REQUEST_LIMIT', 'Слишком большой запрос.', 413);
        try { $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiError('JSON_INVALID', 'Неверный JSON запроса.'); }
        if (!is_array($body)) throw new ApiError('JSON_INVALID', 'Ожидается объект JSON.');
        return $body;
    }

    private function csrf(array $state): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals($state['csrfToken'], $token)) throw new ApiError('CSRF_INVALID', 'Обновите страницу перед изменением данных.', 403);
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null) {
            $originHost = parse_url($origin, PHP_URL_HOST);
            $requestHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
            if (!is_string($originHost) || !is_string($requestHost) || strcasecmp($originHost, $requestHost) !== 0) {
                throw new ApiError('ORIGIN_INVALID', 'Источник запроса не разрешён.', 403);
            }
        }
    }

    private function resource(string $name): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/registry/' . $name . '.v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
