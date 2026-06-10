<?php

declare(strict_types=1);

// Schlanker Test-Runner ohne externe Abhaengigkeiten: laedt jede tests/*Test.php,
// die ein Array [Beschreibung => Closure] zurueckgibt, und fuehrt es aus.
// Aufruf: php tests/run.php

define('ESSE_ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Esse\\')) {
        $path = ESSE_ROOT . '/core/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (file_exists($path)) require_once $path;
    }
});

require_once __DIR__ . '/Assert.php';

$total  = 0;
$failed = 0;

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    $tests = require $file;
    foreach ($tests as $name => $test) {
        $total++;
        try {
            $test();
            echo "  ok   {$name}\n";
        } catch (\Throwable $e) {
            $failed++;
            echo "FAIL   {$name}\n";
            echo "       " . $e->getMessage() . "\n";
        }
    }
}

printf("\n%d Tests, %d bestanden, %d fehlgeschlagen.\n", $total, $total - $failed, $failed);
exit($failed > 0 ? 1 : 0);
