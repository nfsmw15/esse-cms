<?php

declare(strict_types=1);

// Integrationstest-Runner: setzt die esse_test-DB zurueck, startet einen
// PHP-Built-in-Server gegen das Projekt und fuehrt tests/integration/*Test.php aus.
// Aufruf: php tests/integration/run.php
//
// Voraussetzung (einmalig): tests/integration/setup-db.sql (siehe README.md "Tests").

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Http.php';
require __DIR__ . '/helpers.php';
require dirname(__DIR__) . '/Assert.php';

$pdo = testPdo();
resetDatabase($pdo);
seed($pdo);
writeTestConfig();

// Lazily-erstellte Tabellen (page_visibility, page_roles, 2FA/WebAuthn) anlegen,
// damit Seitenaufrufe in der Test-Umgebung nicht mit fehlenden Tabellen scheitern.
require TEST_CONFIG_DIR . '/config/config.php';
\Esse\PageVisibility::migrateDb();
\Esse\TwoFactor::migrateDb();
\Esse\AuditLog::migrateDb();
\Esse\RateLimit::migrateDb();

$root = dirname(__DIR__, 2);
[$host, $port] = ['127.0.0.1', 8089];

$cmd = sprintf(
    'php -S %s:%d -t %s %s',
    $host,
    $port,
    escapeshellarg($root),
    escapeshellarg(__DIR__ . '/router.php')
);

$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    fwrite(STDERR, "Konnte Test-Server nicht starten.\n");
    exit(1);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$baseUrl = "http://{$host}:{$port}";
$ready   = false;
for ($i = 0; $i < 50; $i++) {
    $ch = curl_init($baseUrl . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 200]);
    $ok = curl_exec($ch) !== false;
    curl_close($ch);
    if ($ok) { $ready = true; break; }
    usleep(100_000);
}

if (!$ready) {
    proc_terminate($process);
    fwrite(STDERR, "Test-Server unter {$baseUrl} antwortet nicht.\n");
    fwrite(STDERR, stream_get_contents($pipes[2]));
    exit(1);
}

$total  = 0;
$failed = 0;

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    $tests = require $file;
    foreach ($tests as $name => $test) {
        $total++;
        $http = new Http($baseUrl);
        try {
            $test($http);
            echo "  ok   {$name}\n";
        } catch (\Throwable $e) {
            $failed++;
            echo "FAIL   {$name}\n";
            echo "       " . $e->getMessage() . "\n";
        }
    }
}

proc_terminate($process);
proc_close($process);

printf("\n%d Tests, %d bestanden, %d fehlgeschlagen.\n", $total, $total - $failed, $failed);
exit($failed > 0 ? 1 : 0);
