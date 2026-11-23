<?php
declare(strict_types=1);

namespace Customs\Http;

use Customs\Config;
use Customs\Storage\SessionStore;

final class Application
{
    private SessionStore $store;
    public function __construct(private readonly Config $config, ?SessionStore $store = null, private readonly ?\Customs\Rates\NationalBankClient $bank = null)
    {
        $this->store = $store ?? new SessionStore($config);
    }

    public function handle(string $method, string $path): array|Download
    {
        if ($method === 'GET' && $path === '/api/registry') {
            return ['fields' => (new \Customs\Domain\Registry())->data(), 'catalogs' => \Customs\Domain\Registry::resource('catalogs'), 'rules' => $this->resource('rules'), 'graphs'=>(new \Customs\Domain\GraphRegistry())->data()];
        }
        if($method==='GET' && $path==='/api/classifiers/documents') {
            $query=$_GET['q']??'';$offset=filter_var($_GET['offset']??0,FILTER_VALIDATE_INT);$limit=filter_var($_GET['limit']??40,FILTER_VALIDATE_INT);
            if(!is_string($query)||mb_strlen($query)>500||$offset===false||$limit===false)throw new ApiError('CLASSIFIER_QUERY','Укажите строку поиска, offset и limit.',422);
            return (new \Customs\Domain\Classifiers())->documents($query,$offset,$limit,\Customs\Domain\DeclarationDate::today($this->store->now()));
        }
        if ($method === 'POST' && $path === '/api/session') {
            $session = $this->store->create();
            $this->sessionCookie($session['token'], $session['state']['expiresAt']);
            return $this->store->publicView($session['state']);
        }
        $state = $this->store->fromToken($_COOKIE['customs_session'] ?? null);
        if ($method !== 'GET') $this->csrf($state);
        if(in_array($path,['/api/confirm','/api/create','/api/generate'],true)||str_starts_with($path,'/api/download/'))throw new ApiError('EXPORT_DISABLED','Формирование PDF и XML отключено в профиле подготовки 54 граф.',409);
        if($method==='POST' && $path==='/api/upload-groups') {
            $body=$this->body();if(!is_int($body['revision']??null))throw new ApiError('REVISION_REQUIRED','Нужна текущая версия данных.');
            return $this->view($this->store->mutate($state['id'],$body['revision'],fn($s)=>(new \Customs\Documents\UploadGroups())->change($s,$body),true,true));
        }
        if($method==='PATCH' && preg_match('#^/api/documents/([a-f0-9]{32})/type$#D',$path,$match)) {
            $body=$this->body();if(!is_int($body['revision']??null)||!is_string($body['groupId']??null))throw new ApiError('UPLOAD_GROUP_REQUEST','Выберите вид и текущую версию данных.',422);
            return $this->view($this->store->mutate($state['id'],$body['revision'],fn($s)=>(new \Customs\Documents\UploadGroups())->assign($s,$match[1],$body['groupId']),true,true));
        }
        if ($method === 'GET' && $path === '/api/session') {
            $defaults=\Customs\Domain\DeclarationDate::defaults($state['draft'],$this->store->now());
            if($defaults!==$state['draft'])$state=$this->store->mutate($state['id'],$state['dataRevision'],function($s)use($defaults){$s['draft']=$defaults;return $s;});
            return $this->view($state);
        }
        if ($method === 'POST' && $path === '/api/rates') {
            $body=$this->body();
            if(!is_int($body['revision']??null) || (isset($body['force'])&&!is_bool($body['force'])))throw new ApiError('RATES_REQUEST','Нужна текущая версия данных.',422);
            $service=new \Customs\Rates\ExchangeRates($this->store,$this->bank??new \Customs\Rates\NationalBankClient());
            return $this->view($service->refresh($state['id'],$body['revision'],$body['force']??false));
        }
        if($method==='GET' && preg_match('#^/api/download/(pdf|xml)$#D',$path,$match)) {
            $download = Download::fromState($this->store,$state,$match[1]);
            $state = $this->store->mutate($state['id'],null,fn($s)=>$s,false,true);
            $this->sessionCookie($_COOKIE['customs_session'],$state['expiresAt']);
            return $download;
        }
        if ($method === 'POST' && $path === '/api/recognize') {
            $body = $this->body();
            $pipeline = new \Customs\AI\Pipeline($this->config, $this->store, new \Customs\AI\MistralClient($this->config));
            $pipeline->start($state['id'], (bool) ($body['retry'] ?? false));
            return $this->view($this->store->mutate($state['id'],null,fn($s)=>$s,false,true));
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
                $goodsIds = [];
                foreach ($body['changes'] as $change) if (in_array($change['fieldId'], ['goods.description', 'goods.commodityCode'], true) && isset($change['entityId'])) $goodsIds[] = $change['entityId'];
                if ($goodsIds !== []) $current = (new \Customs\Domain\TnvedSuggestions())->apply($current, array_values(array_unique($goodsIds)));
                return (new \Customs\Domain\TariffDefaults())->apply($current);
            },true,true);
            return $this->view($updated);
        }
        if ($method === 'POST' && $path === '/api/entities') {
            $body=$this->body();
            if (!is_int($body['revision']??null)) throw new ApiError('REVISION_REQUIRED','Нужна версия данных.');
            return $this->view($this->store->mutate($state['id'],$body['revision'],function(array $s)use($body):array {
                $s=(new \Customs\Domain\Entities(new \Customs\Domain\Registry()))->change($s,$body);
                if (($body['scope']??null)==='goods') $s = (new \Customs\Domain\TnvedSuggestions())->apply($s);
                return (new \Customs\Domain\TariffDefaults())->apply($s);
            },true,true));
        }
        if ($method === 'POST' && $path === '/api/validate') return (new \Customs\Domain\ReviewValidator())->validate($state);
        if($method==='POST'&&$path==='/api/review/complete') {
            $body=$this->body();if(!is_int($body['revision']??null))throw new ApiError('REVISION_REQUIRED','Нужна текущая версия данных.');
            return $this->view($this->store->mutate($state['id'],$body['revision'],function($s){if(in_array($s['phase'],['uploading','recognizing'],true))throw new ApiError('EXTRACTION_PENDING','Завершите обработку документов.',409);(new \Customs\Domain\ReviewValidator())->requireValid($s);$s['phase']='calculation';return $s;},false,true));
        }
        if ($method === 'POST' && $path === '/api/calculate') {
            $body=$this->body();
            if(!is_int($body['revision']??null))throw new ApiError('REVISION_REQUIRED','Нужна версия данных.');
            $updated=$this->store->mutate($state['id'],$body['revision'],function(array $s):array {
                if(in_array($s['phase'],['uploading','recognizing'],true))throw new ApiError('EXTRACTION_PENDING','Сначала завершите распознавание и проверку.',409);
                (new \Customs\Domain\ReviewValidator())->requireValid($s);
                $s['calculation']=(new \Customs\Domain\Calculator())->calculate($s);
                $s['confirmed']=$s['artifactSet']=null; $s['phase']='calculated';return $s;
            },false,true);
            return $this->view($updated);
        }
        if ($method === 'POST' && $path === '/api/confirm') {
            $body=$this->body();
            if(!is_int($body['revision']??null)||!is_string($body['calculationHash']??null))throw new ApiError('CONFIRM_REQUEST','Нужны текущие версии данных и расчёта.');
            $updated=$this->store->mutate($state['id'],$body['revision'],function(array $s)use($body):array{
                $s=(new \Customs\Export\Snapshot())->confirm($s,$body['calculationHash']);
                (new \Customs\Export\XmlProfiles())->requireSupported($s['confirmed']);return $s;
            },false,true);
            (new \Customs\Jobs\Queue($this->config,$this->store))->enqueue($state['id'],'generate',null,true);
            return $this->view($this->store->read($state['id']));
        }
        if ($method === 'PATCH' && $path === '/api/calculation') {
            $body=$this->body();
            if(!is_int($body['revision']??null)||!is_string($body['entityId']??null)||!is_string($body['key']??null))throw new ApiError('CALC_REQUEST','Нужны версия, товар и поле расчёта.');
            return $this->view($this->store->mutate($state['id'],$body['revision'],function(array $s)use($body):array {
                if($s['calculation']===null||$s['calculation']['dataRevision']!==$s['dataRevision'])throw new ApiError('CALC_STALE','Выполните актуальный расчёт.',409);
                $s['calculation']=(new \Customs\Domain\Calculator())->propose($s['calculation'],$body['entityId'],$body['key'],$body['value']??null);
                $s['confirmed']=$s['artifactSet']=null;$s['phase']='calculated';return $s;
            },false,true));
        }
        if ($method === 'POST' && $path === '/api/documents') {
            $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($revision === false || !isset($_FILES['file']) || !is_string($_POST['groupId']??null)) throw new ApiError('UPLOAD_FAILED', 'Нужны файл, выбранный вид и текущая версия данных.');
            $updated = (new \Customs\Documents\Uploads($this->config, $this->store))->receive($state['id'], $_FILES['file'], $revision, true, $_POST['groupId']);
            return $this->view($updated);
        }
        if ($method === 'DELETE' && preg_match('#^/api/documents/([a-f0-9]{32})$#D', $path, $matches)) {
            $body = $this->body();
            if (!is_int($body['revision'] ?? null)) throw new ApiError('REVISION_REQUIRED', 'Нужна текущая версия данных.');
            $updated = (new \Customs\Documents\Uploads($this->config, $this->store))->remove($state['id'], $matches[1], $body['revision'], true);
            return $this->view($updated);
        }
        throw new ApiError('NOT_FOUND', 'Операция API не найдена.', 404);
    }

    private function sessionCookie(string $token, int $expiresAt): void
    {
        setcookie('customs_session', $token, ['expires'=>$expiresAt,'path'=>'/','secure'=>(bool)$this->config->get('secure_cookie'),'httponly'=>true,'samesite'=>'Strict']);
    }

    private function view(array $state): array
    {
        $this->sessionCookie($_COOKIE['customs_session'], $state['expiresAt']);
        return $this->store->publicView($state);
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
