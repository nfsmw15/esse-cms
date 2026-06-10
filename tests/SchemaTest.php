<?php

declare(strict_types=1);

use Esse\Schema;

return [
    'tables: liefert CREATE-TABLE-Statements fuer alle Kerntabellen mit Prefix' => function () {
        $tables = Schema::tables('esse_');

        Assert::true(count($tables) >= 11, 'erwarte mindestens 11 Kerntabellen');

        $expected = [
            'esse_users', 'esse_permissions', 'esse_roles', 'esse_role_permissions',
            'esse_user_permissions', 'esse_pages', 'esse_settings', 'esse_menus',
            'esse_menu_items', 'esse_password_resets', 'esse_webauthn_credentials',
        ];
        $sql = implode("\n", $tables);
        foreach ($expected as $table) {
            Assert::true(
                str_contains($sql, "CREATE TABLE IF NOT EXISTS `{$table}`"),
                "Tabelle {$table} fehlt im Schema"
            );
        }
    },

    'tables: jedes Statement ist gueltiges CREATE TABLE' => function () {
        foreach (Schema::tables('esse_') as $sql) {
            Assert::true(str_starts_with(trim($sql), 'CREATE TABLE IF NOT EXISTS'));
        }
    },
];
