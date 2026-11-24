<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

test('legacy statutory migration preserves facts files and text and is idempotent', function(): void {
    $s=completeDraftState();$s+=['schemaVersion'=>2,'jobs'=>[],'createdAt'=>10,'expiresAt'=>10000,'id'=>str_repeat('a',64),'csrfToken'=>'kept','confirmed'=>null,'artifactSet'=>null];
    $s['draft']['goods'][0]['vatRate']='12';$s['draft']['graphTexts']['graph/2/_']=['value'=>'Ручной текст','status'=>'manual','evidenceIds'=>[]];
    $s['calculation']=['dataRevision'=>1];$s['phase']='calculated';$s['graphMappingFingerprint']='legacy-fingerprint';$s['graphMapping']=['schemaVersion'=>'old'];
    $m=Customs\Storage\SessionMigration::upgrade($s);same(2,$m['dataRevision']);same(null,$m['calculation']);same(10000,$m['expiresAt']);same($s['draft']['goods'][0]['invoiceValue'],$m['draft']['goods'][0]['invoiceValue']);truth($m['mappingNeedsExplicitRetry']);
    same('Ручной текст',$m['draft']['graphTexts']['graph/2/_']['value']);same('12',$m['draft']['legacyStatutory']['goods/goods-1/vatRate']['value']);same('16',$m['draft']['goods'][0]['vatRate']);
    same($m,Customs\Storage\SessionMigration::upgrade($m));truth($m['migrationNotices'][0]['changes']!==[]);
    $m['draft']['declaration']['signatureDate']=$m['draft']['declaration']['calculationDate']='2027-01-01';
    $next=Customs\Storage\SessionMigration::upgrade($m);same(null,$next['draft']['declaration']['feeBase']);same('unavailable',$next['draft']['statutoryRules']['status']);
    // Additive representation retains the previous schema marker for rollback readers.
    same(2,$m['schemaVersion']);same($s['documents'],$m['documents']);
});
