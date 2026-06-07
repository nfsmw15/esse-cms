<?php

declare(strict_types=1);

namespace Esse;

// Leichtgewichtiger Spam-Schutz ohne externe Dienste: Rechenaufgabe + Honeypot + Mindestzeit.
// Kein Bild-CAPTCHA — moderne OCR/KI liest verzerrten Text ohnehin mühelos, der
// vermeintliche Sicherheitsgewinn wäre real null, der Accessibility-Nachteil aber konkret.
class Captcha
{
    // Feldname bewusst unauffällig — "honeypot" o.ä. würde Bots zum Auslassen triggern
    public const HONEYPOT_FIELD = 'website_url';
    private const MIN_SECONDS = 3;

    public static function challenge(): string
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $_SESSION['esse_captcha'] = [
            'answer'  => (string) ($a + $b),
            'created' => time(),
        ];
        return "{$a} + {$b}";
    }

    public static function verify(string $answer, string $honeypot): bool
    {
        $challenge = $_SESSION['esse_captcha'] ?? null;
        unset($_SESSION['esse_captcha']);

        if ($honeypot !== '') return false;
        if (!$challenge) return false;
        if (time() - $challenge['created'] < self::MIN_SECONDS) return false;

        return hash_equals($challenge['answer'], trim($answer));
    }
}
