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
];
