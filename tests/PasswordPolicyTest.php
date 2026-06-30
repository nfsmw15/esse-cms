<?php

declare(strict_types=1);

use Esse\PasswordPolicy;

return [
    'check (custom): zu kurzes Passwort wird abgelehnt' => function () {
        $errors = PasswordPolicy::check('Ab1!Ab1', 'custom', 10, 1);
        Assert::true(count($errors) > 0, 'Erwartet: Fehlermeldung wegen Laenge');
    },

    'check (custom): "0123456789" wird bei minClasses=3 abgelehnt (nur 1 Zeichenklasse)' => function () {
        $errors = PasswordPolicy::check('0123456789', 'custom', 10, 3);
        Assert::true(count($errors) > 0, 'Erwartet: Fehlermeldung wegen Zeichenklassen');
    },

    'check (custom): minClasses=1 bedeutet keine Zeichenklassen-Anforderung' => function () {
        $errors = PasswordPolicy::check('0123456789', 'custom', 10, 1);
        Assert::true($errors === [], 'Bei minClasses=1 sollte nur die Laenge zaehlen');
    },

    'check (custom): erfuellendes Passwort wird akzeptiert' => function () {
        $errors = PasswordPolicy::check('TestPassword123', 'custom', 10, 3);
        Assert::true($errors === [], 'Erwartet: keine Fehler');
    },

    'check (bsi): 8 Zeichen mit allen 4 Klassen ist gueltig' => function () {
        $errors = PasswordPolicy::check('Ab1!Ab1!', 'bsi', 10, 3);
        Assert::true($errors === [], 'Erwartet: keine Fehler (Stufe 1)');
    },

    'check (bsi): 25 Zeichen nur Kleinbuchstaben ist gueltig' => function () {
        $errors = PasswordPolicy::check(str_repeat('a', 25), 'bsi', 10, 3);
        Assert::true($errors === [], 'Erwartet: keine Fehler (Stufe 2, lang+einfach)');
    },

    'check (bsi): 24 Zeichen, wenige Klassen, kein MFA ist ungueltig' => function () {
        $errors = PasswordPolicy::check(str_repeat('a', 24), 'bsi', 10, 3);
        Assert::true(count($errors) > 0, 'Erwartet: Fehlermeldung (knapp unter Stufe 2, erfuellt auch Stufe 1/3 nicht)');
    },

    'check (bsi): 8 Zeichen mit 3 Klassen ohne MFA ist ungueltig' => function () {
        $errors = PasswordPolicy::check('Abcdefg1', 'bsi', 10, 3, hasMfa: false);
        Assert::true(count($errors) > 0, 'Erwartet: Fehlermeldung (Stufe 3 nur mit aktivem MFA gueltig)');
    },

    'check (bsi): 8 Zeichen mit 3 Klassen MIT aktivem MFA ist gueltig' => function () {
        $errors = PasswordPolicy::check('Abcdefg1', 'bsi', 10, 3, hasMfa: true);
        Assert::true($errors === [], 'Erwartet: keine Fehler (Stufe 3, MFA-Bonus)');
    },

    'check (custom): "abcd" mit Sequenz-Limit 3 wird abgelehnt' => function () {
        $errors = PasswordPolicy::check('abcd', 'custom', 1, 1, maxSequential: 3);
        Assert::true(count($errors) > 0, 'Erwartet: Fehlermeldung wegen Zeichenfolge');
    },

    'check (custom): "abc" mit Sequenz-Limit 3 ist erlaubt (Lauf von genau 3)' => function () {
        $errors = PasswordPolicy::check('abc', 'custom', 1, 1, maxSequential: 3);
        Assert::true($errors === [], 'Lauf von genau 3 sollte noch erlaubt sein');
    },

    'check (custom): "abcba" mit Sequenz-Limit 3 ist erlaubt (Richtungswechsel bricht Lauf ab)' => function () {
        $errors = PasswordPolicy::check('abcba', 'custom', 1, 1, maxSequential: 3);
        Assert::true($errors === [], 'Richtungswechsel sollte den Lauf unterbrechen, kein 4er-Lauf');
    },

    'check (custom): "12345678" mit Sequenz-Limit 3 wird abgelehnt' => function () {
        $errors = PasswordPolicy::check('12345678', 'custom', 1, 1, maxSequential: 3);
        Assert::true(count($errors) > 0, 'Erwartet: Fehlermeldung wegen Zeichenfolge bei Ziffern');
    },

    'check (custom): Sequenz-Limit 0 bedeutet keine Pruefung' => function () {
        $errors = PasswordPolicy::check('abcdefgh', 'custom', 1, 1, maxSequential: 0);
        Assert::true($errors === [], 'Bei maxSequential=0 sollte die Zeichenfolge nicht geprueft werden');
    },

    'check (bsi): Sequenz-Limit wird im BSI-Modus ignoriert' => function () {
        $errors = PasswordPolicy::check('Abcdefgh1!', 'bsi', 10, 3, hasMfa: false, maxSequential: 3);
        Assert::true($errors === [], 'BSI-Modus prueft kein Sequenz-Limit, auch wenn "bcdefgh" eine lange Folge waere');
    },
];
