<?php

declare(strict_types=1);

use Esse\Captcha;

// Erzeugt eine Challenge und liefert die korrekte Antwort. Setzt "created" zusaetzlich
// in die Vergangenheit, damit die Mindest-Ausfuellzeit von Captcha::verify() erfuellt ist.
function captchaSolve(): string
{
    $challenge = Captcha::challenge();
    [$a, $b] = array_map('intval', explode(' + ', $challenge));
    $_SESSION['esse_captcha']['created'] -= 5;
    return (string) ($a + $b);
}

return [
    'verify: korrekte Antwort nach Mindestzeit wird akzeptiert' => function () {
        $_SESSION = [];
        $answer = captchaSolve();
        Assert::true(Captcha::verify($answer, ''));
    },

    'verify: zu schnelle Antwort wird abgelehnt (Bot-Verdacht)' => function () {
        $_SESSION = [];
        $challenge = Captcha::challenge();
        [$a, $b] = array_map('intval', explode(' + ', $challenge));
        Assert::false(Captcha::verify((string) ($a + $b), ''));
    },

    'verify: ausgefuelltes Honeypot-Feld wird immer abgelehnt' => function () {
        $_SESSION = [];
        $answer = captchaSolve();
        Assert::false(Captcha::verify($answer, 'http://spam.example'));
    },

    'verify: falsche Antwort wird abgelehnt' => function () {
        $_SESSION = [];
        $answer = captchaSolve();
        Assert::false(Captcha::verify((string) ((int) $answer + 1), ''));
    },

    'verify: Challenge ist nur einmal verwendbar' => function () {
        $_SESSION = [];
        $answer = captchaSolve();
        Captcha::verify($answer, '');
        Assert::false(isset($_SESSION['esse_captcha']));
        Assert::false(Captcha::verify($answer, ''));
    },
];
