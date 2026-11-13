<?php
declare(strict_types=1);

use Customs\Config;
use Customs\Documents\Inspector;
use Customs\Documents\Uploads;

test('all primary documents from both supplied sets pass format and readability checks', function (): void {
    $inspector = new Inspector(new Config());
    foreach (['reference-cpt','reference-fca'] as $fixtureName) {
        $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/' . $fixtureName . '.json'), true);
        foreach ($fixture['inputs'] as $relative) {
            $info = $inspector->inspect(dirname(__DIR__, 2) . '/' . $relative, basename($relative));
            truth($info['pages'] > 0, $relative);
            truth($info['size'] > 0);
        }
    }
});

test('mislabelled, truncated, protected and oversized documents are rejected', function (): void {
    $config = new Config(); $inspector = new Inspector($config);
    $pdf = dirname(__DIR__, 2) . '/reference/source-documents/example2/авиа.pdf';
    errorCode('FILE_TYPE', fn() => $inspector->inspect($pdf, 'renamed.jpg'));
    errorCode('FILE_LIMIT', fn() => (new Inspector(new Config(['max_file_bytes' => 10])))->inspect($pdf, 'file.pdf'));
    $file = tempnam(sys_get_temp_dir(), 'customs-invalid-');
    try {
        file_put_contents($file, '%PDF-1.7 broken');
        errorCode('PDF_INVALID', fn() => $inspector->inspect($file, 'broken.pdf'));
        $mpdf = new Mpdf\Mpdf(['tempDir' => $config->get('runtime_dir') . '/test-mpdf']);
        $mpdf->SetProtection(['copy'], 'test-user-password', 'test-owner-password');
        $mpdf->WriteHTML('<p>Protected test</p>');
        file_put_contents($file, $mpdf->Output('', Mpdf\Output\Destination::STRING_RETURN));
        errorCode('PDF_ENCRYPTED', fn() => $inspector->inspect($file, 'protected.pdf'));
        unset($mpdf); gc_collect_cycles();
    } finally { unlink($file); }
});

test('upload limits and deletion preserve another document and other session state', function (): void {
    $root = sys_get_temp_dir() . '/customs-test-' . bin2hex(random_bytes(8));
    $config = new Config(['runtime_dir' => $root, 'max_files' => 2]);
    $store = new Customs\Storage\SessionStore($config);
    $uploads = new Uploads($config, $store);
    $a = $store->create(); $b = $store->create();
    $pdf = dirname(__DIR__, 2) . '/reference/source-documents/example2/авиа.pdf';
    $one = $uploads->addFromPath($a['state']['id'], $pdf, '../../CMR.pdf', 0);
    $two = $uploads->addFromPath($a['state']['id'], $pdf, 'CMR.pdf', 1);
    same(2, count($two['documents']));
    $first = array_values($two['documents'])[0];
    same('CMR.pdf', $first['name']);
    truth(preg_match('/^[a-f0-9]{32}\.pdf$/D', $first['storageName']) === 1);
    errorCode('FILE_COUNT_LIMIT', fn() => $uploads->addFromPath($a['state']['id'], $pdf, 'third.pdf', 2));
    same(2, count($store->read($a['state']['id'])['documents']));
    errorCode('DOCUMENT_NOT_FOUND', fn() => $uploads->remove($b['state']['id'], $first['id'], 0));
    $remaining = $uploads->remove($a['state']['id'], $first['id'], 2);
    same(1, count($remaining['documents']));
    truth(!is_file($store->directory($a['state']['id']) . '/documents/' . $first['storageName']));
    same([], $store->read($b['state']['id'])['documents']);
});

test('aggregate page, session-size and global disk caps reject without losing accepted files', function (): void {
    $pdf = dirname(__DIR__, 2) . '/reference/source-documents/example2/авиа.pdf';
    foreach ([['max_pages' => 1, 'expected' => 'PAGE_LIMIT'], ['max_session_bytes' => filesize($pdf) + 1, 'expected' => 'SESSION_SIZE_LIMIT']] as $case) {
        $expected = $case['expected']; unset($case['expected']);
        $config = new Config(array_replace(['runtime_dir' => sys_get_temp_dir() . '/customs-test-' . bin2hex(random_bytes(8))], $case));
        $store = new Customs\Storage\SessionStore($config); $session = $store->create(); $uploads = new Uploads($config, $store);
        $uploads->addFromPath($session['state']['id'], $pdf, 'a.pdf', 0);
        errorCode($expected, fn() => $uploads->addFromPath($session['state']['id'], $pdf, 'b.pdf', 1));
        same(1, count($store->read($session['state']['id'])['documents']));
    }
    $config = new Config(['runtime_dir' => sys_get_temp_dir() . '/customs-test-' . bin2hex(random_bytes(8)), 'max_runtime_bytes' => 1]);
    $store = new Customs\Storage\SessionStore($config); $session = $store->create();
    errorCode('STORAGE_LIMIT', fn() => (new Uploads($config, $store))->addFromPath($session['state']['id'], $pdf, 'a.pdf', 0));
    same([], $store->read($session['state']['id'])['documents']);
});
