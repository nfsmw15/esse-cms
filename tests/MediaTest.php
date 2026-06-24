<?php

declare(strict_types=1);

use Esse\Media;

return [
    'typeFromMime: erkennt Bilder' => function () {
        Assert::same('image', Media::typeFromMime('image/png'));
        Assert::same('image', Media::typeFromMime('image/jpeg'));
    },

    'typeFromMime: erkennt Dokumente' => function () {
        Assert::same('document', Media::typeFromMime('application/pdf'));
        Assert::same('document', Media::typeFromMime('application/msword'));
        Assert::same('document', Media::typeFromMime('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    },

    'typeFromMime: faellt auf "file" zurueck' => function () {
        Assert::same('file', Media::typeFromMime('application/zip'));
        Assert::same('file', Media::typeFromMime(''));
    },

    'reencodeImage: entfernt angehaengte Bytes hinter einem gueltigen PNG (Polyglot-Schutz)' => function () {
        $path = tempnam(sys_get_temp_dir(), 'esse-test-polyglot-') . '.png';
        $img  = imagecreatetruecolor(4, 4);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 50, 50));
        imagepng($img, $path);
        imagedestroy($img);

        // Polyglot-Simulation: ein gueltiges PNG, an das zusaetzliche Bytes angehaengt sind.
        // getimagesize() akzeptiert das weiterhin (liest nur den Header), die Originaldatei
        // enthaelt die Payload aber unveraendert mit.
        $payload = "<?php /* injected payload */ ?>";
        file_put_contents($path, file_get_contents($path) . $payload);
        Assert::true(str_contains((string) file_get_contents($path), $payload), 'Testaufbau: Payload sollte vor dem Re-Encoding in der Datei stecken');
        Assert::true(@getimagesize($path) !== false, 'Testaufbau: getimagesize() sollte das Polyglot-PNG weiterhin als Bild erkennen');

        $ok = Media::reencodeImage($path, 'png');
        Assert::true($ok, 'reencodeImage() sollte ein gueltiges (Polyglot-)PNG erfolgreich neu schreiben');

        $content = (string) file_get_contents($path);
        Assert::true(!str_contains($content, $payload), 'Angehaengte Payload sollte nach dem Re-Encoding weg sein');
        Assert::true(@getimagesize($path) !== false, 'Datei sollte nach dem Re-Encoding weiterhin ein gueltiges Bild sein');

        @unlink($path);
    },

    'reencodeImage: lehnt eine Datei ab, die getimagesize() akzeptiert, aber GD nicht dekodieren kann' => function () {
        // Reines getimagesize() laesst sich durch einen gefaelschten Header taeuschen (liest nur
        // die ersten Bytes), GD muss die Datei aber tatsaechlich dekodieren koennen.
        $path = tempnam(sys_get_temp_dir(), 'esse-test-fakejpg-') . '.jpg';
        file_put_contents($path, "not actually a jpeg, just garbage bytes");

        $ok = Media::reencodeImage($path, 'jpg');
        Assert::false($ok, 'reencodeImage() sollte eine nicht dekodierbare Datei ablehnen');

        @unlink($path);
    },

    'reencodeImage: akzeptiert ein normales JPEG unveraendert funktional' => function () {
        $path = tempnam(sys_get_temp_dir(), 'esse-test-normal-') . '.jpg';
        $img  = imagecreatetruecolor(4, 4);
        imagejpeg($img, $path);
        imagedestroy($img);

        $ok = Media::reencodeImage($path, 'jpg');
        Assert::true($ok);
        Assert::true(@getimagesize($path) !== false);

        @unlink($path);
    },
];
