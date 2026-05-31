<?php

declare(strict_types=1);

namespace Esse;

use PHPMailer\PHPMailer\PHPMailer as PM;

class Mailer
{
    // Send a single email. Throws on failure.
    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $body,
        bool   $isHtml = true
    ): void {
        $cfg  = self::config();
        $mail = self::createMailer($cfg);

        $mail->setFrom($cfg['smtp_from'], $cfg['smtp_from_name'] ?: 'ESSE CMS');
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;

        if ($isHtml) {
            $mail->isHTML(true);
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);
        } else {
            $mail->isHTML(false);
            $mail->Body = $body;
        }

        $mail->send();
    }

    // Test SMTP connection and auth. Returns true or throws.
    public static function test(): bool
    {
        $cfg  = self::config();

        if (empty($cfg['smtp_host'])) {
            throw new \RuntimeException('SMTP-Host nicht konfiguriert.');
        }

        $mail = self::createMailer($cfg);

        if (!$mail->smtpConnect()) {
            throw new \RuntimeException('Verbindung fehlgeschlagen.');
        }

        $mail->smtpClose();
        return true;
    }

    private static function createMailer(array $cfg): PM
    {
        if (empty($cfg['smtp_host'])) {
            throw new \RuntimeException('SMTP nicht konfiguriert. Bitte unter Admin → Einstellungen → E-Mail einrichten.');
        }

        $mail = new PM(true);
        $mail->isSMTP();
        $mail->Host       = $cfg['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['smtp_user'] ?? '';
        $mail->Password   = Crypto::decrypt($cfg['smtp_pass'] ?? '');
        $mail->SMTPSecure = ($cfg['smtp_encryption'] ?? 'tls') === 'ssl'
            ? PM::ENCRYPTION_SMTPS
            : PM::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) ($cfg['smtp_port'] ?: 587);
        $mail->CharSet    = PM::CHARSET_UTF8;

        return $mail;
    }

    private static function config(): array
    {
        $ts   = DB::table('settings');
        $rows = DB::fetchAll("SELECT `key`, `value` FROM `{$ts}` WHERE `key` LIKE 'smtp_%'");
        return array_column($rows, 'value', 'key');
    }
}
