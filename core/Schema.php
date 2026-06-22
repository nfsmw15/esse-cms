<?php

declare(strict_types=1);

namespace Esse;

// Core database schema, shared by the web installer (install/index.php) and
// the integration test bootstrap (tests/integration/bootstrap.php).
class Schema
{
    public static function tables(string $p): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `{$p}users` (
                `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `display_name` VARCHAR(100) NOT NULL,
                `email`        VARCHAR(255) NOT NULL,
                `password`     VARCHAR(255) NOT NULL,
                `role`         VARCHAR(50) NOT NULL DEFAULT 'member',
                `active`       TINYINT(1)   NOT NULL DEFAULT 1,
                `totp_secret`  VARCHAR(255) NULL,
                `totp_enabled` TINYINT(1)   NOT NULL DEFAULT 0,
                `totp_backup_codes` TEXT NULL,
                `password_changed_at` DATETIME NULL,
                `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}permissions` (
                `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `slug`        VARCHAR(100) NOT NULL,
                `label`       VARCHAR(255) NOT NULL,
                `description` TEXT,
                UNIQUE KEY `uq_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}roles` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `slug`       VARCHAR(50)  NOT NULL,
                `label`      VARCHAR(255) NOT NULL,
                `is_default` TINYINT(1)   NOT NULL DEFAULT 0,
                UNIQUE KEY `uq_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}role_permissions` (
                `role_id`       INT UNSIGNED NOT NULL,
                `permission_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`role_id`, `permission_id`),
                FOREIGN KEY (`role_id`)       REFERENCES `{$p}roles`(`id`)       ON DELETE CASCADE,
                FOREIGN KEY (`permission_id`) REFERENCES `{$p}permissions`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}user_permissions` (
                `user_id`         INT UNSIGNED NOT NULL,
                `permission_slug` VARCHAR(100) NOT NULL,
                `granted`         TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (`user_id`, `permission_slug`),
                FOREIGN KEY (`user_id`) REFERENCES `{$p}users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}pages` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `slug`       VARCHAR(255) NOT NULL,
                `title`      VARCHAR(500) NOT NULL,
                `content`    LONGTEXT,
                `meta_description` VARCHAR(300) DEFAULT NULL,
                `icon`       VARCHAR(100) DEFAULT NULL,
                `hide_title` TINYINT(1)   NOT NULL DEFAULT 0,
                `type`       ENUM('standard','php') NOT NULL DEFAULT 'standard',
                `file_path`  VARCHAR(500) DEFAULT NULL,
                `visibility` VARCHAR(20) NOT NULL DEFAULT 'public',
                `status`     ENUM('published','draft')        NOT NULL DEFAULT 'draft',
                `author_id`  INT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_slug` (`slug`),
                FOREIGN KEY (`author_id`) REFERENCES `{$p}users`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}settings` (
                `key`   VARCHAR(100) NOT NULL PRIMARY KEY,
                `value` TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}menus` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name`       VARCHAR(100) NOT NULL,
                `slug`       VARCHAR(100) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}menu_items` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `menu_id`    INT UNSIGNED NOT NULL,
                `parent_id`  INT UNSIGNED DEFAULT NULL,
                `type`       ENUM('page','url','header') NOT NULL DEFAULT 'page',
                `label`      VARCHAR(255) NOT NULL,
                `page_slug`  VARCHAR(255) DEFAULT NULL,
                `url`        VARCHAR(500) DEFAULT NULL,
                `target`     ENUM('_self','_blank') NOT NULL DEFAULT '_self',
                `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `icon`       VARCHAR(100) DEFAULT NULL,
                `active`     TINYINT(1)   NOT NULL DEFAULT 1,
                FOREIGN KEY (`menu_id`)   REFERENCES `{$p}menus`(`id`)      ON DELETE CASCADE,
                FOREIGN KEY (`parent_id`) REFERENCES `{$p}menu_items`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}password_resets` (
                `token`      VARCHAR(64)  NOT NULL PRIMARY KEY,
                `email`      VARCHAR(255) NOT NULL,
                `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}user_fields` (
                `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `field_key`        VARCHAR(100) NOT NULL,
                `label`            VARCHAR(255) NOT NULL,
                `type`             ENUM('text','textarea','select','checkbox','date') NOT NULL DEFAULT 'text',
                `options`          TEXT NULL,
                `required`         TINYINT(1) NOT NULL DEFAULT 0,
                `show_on_register` TINYINT(1) NOT NULL DEFAULT 0,
                `show_on_profile`  TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY `uq_field_key` (`field_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}user_field_values` (
                `user_id`  INT UNSIGNED NOT NULL,
                `field_id` INT UNSIGNED NOT NULL,
                `value`    TEXT,
                PRIMARY KEY (`user_id`, `field_id`),
                FOREIGN KEY (`user_id`)  REFERENCES `{$p}users`(`id`)       ON DELETE CASCADE,
                FOREIGN KEY (`field_id`) REFERENCES `{$p}user_fields`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}media_folders` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name`       VARCHAR(255) NOT NULL,
                `parent_id`  INT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`parent_id`) REFERENCES `{$p}media_folders`(`id`) ON DELETE CASCADE,
                KEY `idx_parent` (`parent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}media` (
                `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `path`        VARCHAR(500) NOT NULL,
                `filename`    VARCHAR(255) NOT NULL,
                `mime_type`   VARCHAR(100) NOT NULL DEFAULT '',
                `type`        VARCHAR(20)  NOT NULL DEFAULT 'file',
                `size`        INT UNSIGNED NOT NULL DEFAULT 0,
                `alt_text`    VARCHAR(255) NOT NULL DEFAULT '',
                `description` VARCHAR(500) NOT NULL DEFAULT '',
                `visibility`  ENUM('public','private') NOT NULL DEFAULT 'public',
                `source`      VARCHAR(100) NOT NULL DEFAULT 'core',
                `uploaded_by` INT UNSIGNED NULL,
                `folder_id`   INT UNSIGNED NULL,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_path` (`path`),
                KEY `idx_type` (`type`),
                KEY `idx_visibility` (`visibility`),
                KEY `idx_folder` (`folder_id`),
                FOREIGN KEY (`folder_id`) REFERENCES `{$p}media_folders`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}webauthn_credentials` (
                `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id`       INT UNSIGNED NOT NULL,
                `credential_id` VARCHAR(1024) NOT NULL,
                `public_key`    TEXT NOT NULL,
                `sign_counter`  INT UNSIGNED NOT NULL DEFAULT 0,
                `label`         VARCHAR(100) NOT NULL DEFAULT '',
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_used_at`  DATETIME NULL,
                KEY `idx_user` (`user_id`),
                UNIQUE KEY `uq_credential_id` (`credential_id`(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
}
