<?php

declare(strict_types=1);

namespace Esse;

use PHPMailer\PHPMailer\PHPMailer as PM;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

class Mailer
{
    // Send a single email. Throws \RuntimeException on failure.
    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $body,
        bool   $isHtml = true
    ): void {
        $cfg = self::config();

        if (empty($cfg['smtp_host'])) {
            throw new \RuntimeException('SMTP nicht konfiguriert. Bitte unter Admin → Einstellungen → E-Mail einrichten.');
        }

        $mail = new PM(true);

        $mail->isSMTP();
        $mail->Host       = $cfg['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['smtp_user'];
        $mail->Password   = $cfg['smtp_pass'];
        $mail->SMTPSecure = $cfg['smtp_encryption'] === 'ssl' ? PM::ENCRYPTION_SMTPS : PM::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) ($cfg['smtp_port'] ?: 587);
        $mail->CharSet    = PM::CHARSET_UTF8;

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

    // Test connection — returns true or throws with error message
    public static function test(): bool
    {
        $cfg = self::config();

        if (empty($cfg['smtp_host'])) {
            throw new \RuntimeException('SMTP-Host nicht konfiguriert.');
        }

        $smtp = new SMTP();
        $smtp->do_debug = SMTP::DEBUG_OFF;

        $connected = $smtp->connect($cfg['smtp_host'], (int)($cfg['smtp_port'] ?: 587), 5);
        if (!$connected) {
            throw new \RuntimeException("Verbindung zu {$cfg['smtp_host']}:{$cfg['smtp_port']} fehlgeschlagen.");
        }

        $smtp->hello('esse-cms');

        if ($cfg['smtp_encryption'] !== 'ssl') {
            $smtp->startTLS();
        }

        $authed = $smtp->authenticate($cfg['smtp_user'], $cfg['smtp_pass']);
        $smtp->quit();

        if (!$authed) {
            throw new \RuntimeException('SMTP-Authentifizierung fehlgeschlagen. Benutzername/Passwort prüfen.');
        }

        return true;
    }

    private static function config(): array
    {
        $ts   = DB::table('settings');
        $rows = DB::fetchAll("SELECT `key`, `value` FROM `{$ts}` WHERE `key` LIKE 'smtp_%'");
        return array_column($rows, 'value', 'key');
    }
}
