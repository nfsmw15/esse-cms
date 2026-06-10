<?php

declare(strict_types=1);

use Esse\Updater;

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
];
