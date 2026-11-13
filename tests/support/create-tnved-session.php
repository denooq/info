<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';
require __DIR__ . '/fixtures.php';
$store = new Customs\Storage\SessionStore(Customs\Config::load());
$created = $store->create();
$state = $store->mutate($created['state']['id'], 0, static function (array $state): array {
    $state = array_replace($state, completeDraftState(2));
    foreach (['Этилацетат', 'Клей'] as $index => $name) {
        $id = $state['draft']['goods'][$index]['id'];
        $state['draft']['goods'][$index]['description'] = $name;
        $state['draft']['goods'][$index]['commodityCode'] = null;
        unset($state['draft']['fieldStates']['goods/' . $id . '/commodityCode']);
    }
    return (new Customs\Domain\TnvedSuggestions())->apply($state);
});
echo json_encode(['state' => $store->publicView($state)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
