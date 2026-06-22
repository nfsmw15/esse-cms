<?php

declare(strict_types=1);

namespace Esse;

// Sicherheits-Audit-Log: protokolliert sicherheitsrelevante Ereignisse
// (Logins, Passwort-Reset, 2FA/Passkey-Änderungen, Benutzerverwaltung).
//
// DSGVO: Speicherung erfolgt auf Basis berechtigten Interesses (Art. 6 Abs. 1 lit. f
// DSGVO, ErwG 49 — Sicherstellung der Netz- und Informationssicherheit). Einträge
// werden nach Ablauf der Aufbewahrungsfrist (Standard 90 Tage, einstellbar) automatisch
// gelöscht. Es werden volle IP-Adressen gespeichert, um z.B. Brute-Force-Angriffe und
// Account-Übernahmen nachvollziehen zu können.
class AuditLog
{
    private const DEFAULT_RETENTION_DAYS = 90;

    // event-key => deutsches Label für die Anzeige im Admin-Bereich.
    public const EVENTS = [
        'login_success'                  => 'Anmeldung erfolgreich',
        'login_failed'                   => 'Anmeldung fehlgeschlagen',
        'login_locked'                   => 'Anmeldung gesperrt (zu viele Versuche)',
        '2fa_failed'                      => '2FA-Code falsch',
        '2fa_locked'                      => '2FA gesperrt (zu viele Versuche)',
        'password_reset_requested'       => 'Passwort-Reset angefordert',
        'password_reset_completed'       => 'Passwort zurückgesetzt',
        '2fa_enabled'                     => '2FA aktiviert',
        '2fa_disabled'                    => '2FA deaktiviert',
        '2fa_backup_codes_regenerated'   => '2FA-Backup-Codes neu erzeugt',
        'passkey_added'                   => 'Passkey hinzugefügt',
        'passkey_removed'                 => 'Passkey entfernt',
        'user_created'                    => 'Benutzer angelegt',
        'user_role_changed'               => 'Benutzerrolle geändert',
        'user_permissions_changed'        => 'Zusätzliche Berechtigungen geändert',
        'role_permissions_changed'        => 'Rollen-Berechtigungen geändert',
        'role_created'                    => 'Rolle erstellt',
        'role_deleted'                    => 'Rolle gelöscht',
        'profile_password_changed'        => 'Eigenes Passwort geändert',
        'profile_email_changed'           => 'Eigene E-Mail-Adresse geändert',
        'php_page_uploaded'               => 'PHP-/HTML-Seite hochgeladen',
        'plugin_installed'                => 'Plugin installiert',
        'plugin_updated'                  => 'Plugin aktualisiert',
        'plugin_enabled'                  => 'Plugin aktiviert',
        'plugin_disabled'                 => 'Plugin deaktiviert',
        'plugin_uninstalled'              => 'Plugin deinstalliert',
        'self_update'                     => 'CMS-Update durchgeführt',
        'self_update_failed'              => 'CMS-Update fehlgeschlagen',
        'backup_restored'                 => 'Backup wiederhergestellt',
        'backup_restore_failed'           => 'Backup-Wiederherstellung fehlgeschlagen',
        'settings_changed'                => 'Sicherheitsrelevante Einstellung geändert',
        'user_activated'                  => 'Benutzer aktiviert',
        'user_deactivated'                => 'Benutzer deaktiviert',
        'csrf_failed'                      => 'CSRF-Prüfung fehlgeschlagen',
        'rate_limit_locked'                => 'Rate-Limit erreicht (gesperrt)',
        'password_reset_invalid_token'    => 'Passwort-Reset: ungültiger/abgelaufener Token',
        'passkey_login_failed'            => 'Passkey-Anmeldung fehlgeschlagen',
        'totp_setup_started'              => '2FA-Einrichtung gestartet',
        'totp_setup_cancelled'            => '2FA-Einrichtung abgebrochen',
        'media_uploaded'                   => 'Datei hochgeladen',
        'media_deleted'                    => 'Datei gelöscht',
        'media_delete_failed'             => 'Datei konnte nicht gelöscht werden',
        'media_visibility_changed'        => 'Datei-Sichtbarkeit geändert',
        'file_upload_rejected'            => 'Upload abgelehnt',
        'update_prepare'                   => 'CMS-Update vorbereitet',
        'backup_created'                   => 'Backup erstellt',
        'backup_deleted'                   => 'Backup gelöscht',
        'plugin_install_failed'           => 'Plugin-Installation fehlgeschlagen',
        'plugin_uninstall_failed'         => 'Plugin-Deinstallation fehlgeschlagen',
        'theme_installed'                  => 'Theme installiert',
        'theme_install_failed'            => 'Theme-Installation fehlgeschlagen',
        'theme_deleted'                    => 'Theme gelöscht',
        'theme_delete_failed'             => 'Theme-Löschung fehlgeschlagen',
    ];

    public static function migrateDb(): void
    {
        $tl = DB::table('audit_log');

        DB::query("CREATE TABLE IF NOT EXISTS `{$tl}` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `event`      VARCHAR(50)  NOT NULL,
            `user_id`    INT UNSIGNED NULL,
            `email`      VARCHAR(190) NULL,
            `ip_address` VARCHAR(45)  NULL,
            `details`    TEXT         NULL,
            PRIMARY KEY (`id`),
            KEY `idx_audit_log_created_at` (`created_at`),
            KEY `idx_audit_log_event` (`event`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Schreibt einen Audit-Eintrag. Darf einen Request niemals zum Scheitern bringen.
    public static function record(string $event, ?int $userId, ?string $email, array $details = []): void
    {
        try {
            $tl = DB::table('audit_log');
            DB::insert($tl, [
                'event'      => $event,
                'user_id'    => $userId,
                'email'      => $email,
                'ip_address' => self::clientIp(),
                'details'    => $details ? json_encode($details) : null,
            ]);

            // Gelegentliches Aufräumen statt Cron — vermeidet zusätzliche Infrastruktur.
            if (random_int(1, 50) === 1) {
                self::cleanup();
            }
        } catch (\Throwable) {
            // Tabelle evtl. noch nicht migriert o.ä. — Audit-Log darf den Request nie blockieren.
        }
    }

    // Löscht Einträge, die älter als die Aufbewahrungsfrist sind.
    public static function cleanup(): void
    {
        $tl = DB::table('audit_log');
        DB::query(
            "DELETE FROM `{$tl}` WHERE created_at < (NOW() - INTERVAL ? DAY)",
            [self::retentionDays()]
        );
    }

    public static function retentionDays(): int
    {
        $ts    = DB::table('settings');
        $value = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'audit_log_retention_days'");
        $days  = (int) ($value ?? self::DEFAULT_RETENTION_DAYS);
        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    public static function clientIp(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    // Liefert eine Seite des Audit-Logs, optional gefiltert nach Event-Typ.
    public static function paginate(int $page, int $perPage, ?string $event = null): array
    {
        $tl     = DB::table('audit_log');
        $page   = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $where  = '';
        $params = [];
        if ($event !== null && $event !== '') {
            $where  = 'WHERE event = ?';
            $params = [$event];
        }

        $total = (int) DB::value("SELECT COUNT(*) FROM `{$tl}` {$where}", $params);

        $rows = DB::fetchAll(
            "SELECT * FROM `{$tl}` {$where} ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'rows'  => $rows,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'page'  => $page,
        ];
    }
}
