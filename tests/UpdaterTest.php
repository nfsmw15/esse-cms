<?php

declare(strict_types=1);

use Esse\Updater;

// Ruft die private removeOrphanedFiles() ueber Reflection mit einem Temp-Verzeichnis als Wurzel
// auf, statt \ESSE_ROOT zu beeinflussen (Konstante, kann im laufenden Prozess nicht neu gesetzt
// werden).
function callRemoveOrphanedFiles(string $root, array $backedUpPaths): int
{
    $ref    = new ReflectionClass(Updater::class);
    $method = $ref->getMethod('removeOrphanedFiles');
    return $method->invoke(null, $root, $backedUpPaths);
}

function makeFile(string $path): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, 'x');
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

return [
    'isNewer: hoehere Version wird erkannt' => function () {
        Assert::true(Updater::isNewer('0.2.1-alpha', '0.2.0-alpha'));
    },
    'isNewer: gleiche Version ist nicht neuer' => function () {
        Assert::false(Updater::isNewer('0.2.0-alpha', '0.2.0-alpha'));
    },
    'isNewer: niedrigere Version ist nicht neuer' => function () {
        Assert::false(Updater::isNewer('0.1.9-alpha', '0.2.0-alpha'));
    },
    'isNewer: fuehrendes "v" wird ignoriert' => function () {
        Assert::true(Updater::isNewer('v0.3.0-alpha', '0.2.1-alpha'));
    },
    'isNewer: "-dev"-Suffix der lokalen Version wird ignoriert' => function () {
        Assert::false(Updater::isNewer('0.2.1-alpha', '0.2.1-alpha-dev'));
    },

    'removeOrphanedFiles: entfernt Dateien, die nicht im Backup waren (z.B. neue Uploads)' => function () {
        $root = sys_get_temp_dir() . '/esse-restore-test-' . bin2hex(random_bytes(4));
        makeFile($root . '/plugins/keep-plugin/file.php');
        makeFile($root . '/public/uploads/orphan.txt');
        try {
            $removed = callRemoveOrphanedFiles($root, ['plugins/keep-plugin/file.php' => true]);
            Assert::same(1, $removed);
            Assert::true(file_exists($root . '/plugins/keep-plugin/file.php'), 'Datei aus dem Backup darf nicht geloescht werden');
            Assert::true(!file_exists($root . '/public/uploads/orphan.txt'), 'Datei, die nicht im Backup war, muss entfernt werden');
        } finally {
            rrmdir($root);
        }
    },

    'removeOrphanedFiles: raeumt leere Verzeichnisse nach dem Entfernen auf' => function () {
        $root = sys_get_temp_dir() . '/esse-restore-test-' . bin2hex(random_bytes(4));
        makeFile($root . '/plugins/orphan-plugin/file.php');
        try {
            callRemoveOrphanedFiles($root, []);
            Assert::true(!is_dir($root . '/plugins/orphan-plugin'), 'Leeres Verzeichnis sollte nach dem Aufraeumen weg sein');
        } finally {
            rrmdir($root);
        }
    },

    'removeOrphanedFiles: ruehrt BACKUP_EXCLUDED-Pfade (public/vendor/) nie an' => function () {
        $root = sys_get_temp_dir() . '/esse-restore-test-' . bin2hex(random_bytes(4));
        makeFile($root . '/public/vendor/some-lib/lib.js');
        try {
            callRemoveOrphanedFiles($root, []);
            Assert::true(file_exists($root . '/public/vendor/some-lib/lib.js'), 'public/vendor/ wird nie von Backups erfasst und darf beim Aufraeumen nicht geloescht werden');
        } finally {
            rrmdir($root);
        }
    },

    'removeOrphanedFiles: ruehrt geschuetzte Pfade (config/, local.php, storage/) nie an' => function () {
        $root = sys_get_temp_dir() . '/esse-restore-test-' . bin2hex(random_bytes(4));
        makeFile($root . '/config/config.php');
        makeFile($root . '/local.php');
        makeFile($root . '/storage/cache/foo.json');
        try {
            callRemoveOrphanedFiles($root, []);
            Assert::true(file_exists($root . '/config/config.php'));
            Assert::true(file_exists($root . '/local.php'));
            Assert::true(file_exists($root . '/storage/cache/foo.json'));
        } finally {
            rrmdir($root);
        }
    },
];
