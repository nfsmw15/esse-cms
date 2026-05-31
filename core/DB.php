<?php

declare(strict_types=1);

namespace Esse;

class DB
{
    private static ?\PDO $connection = null;

    public static function connect(): void
    {
        if (self::$connection !== null) return;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            defined('ESSE_DB_HOST') ? \ESSE_DB_HOST : 'localhost',
            defined('ESSE_DB_PORT') ? \ESSE_DB_PORT : 3306,
            \ESSE_DB_NAME
        );

        self::$connection = new \PDO($dsn, \ESSE_DB_USER, \ESSE_DB_PASS, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    // Returns the prefixed table name, e.g. table('users') → 'esse_users'
    public static function table(string $name): string
    {
        $prefix = defined('ESSE_DB_PREFIX') ? \ESSE_DB_PREFIX : 'esse_';
        return $prefix . $name;
    }

    // Run a raw prepared query and return the statement
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // Fetch a single row or null
    public static function fetch(string $sql, array $params = []): ?array
    {
        return self::query($sql, $params)->fetch() ?: null;
    }

    // Fetch all rows
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    // Fetch a single column value from the first row
    public static function value(string $sql, array $params = []): mixed
    {
        $row = self::fetch($sql, $params);
        return $row ? array_values($row)[0] : null;
    }

    // INSERT — returns the new row's ID
    public static function insert(string $table, array $data): int
    {
        $cols         = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        self::query(
            "INSERT INTO `{$table}` ({$cols}) VALUES ({$placeholders})",
            array_values($data)
        );

        return (int) self::pdo()->lastInsertId();
    }

    // UPDATE — returns number of affected rows
    public static function update(string $table, array $data, array $where): int
    {
        $set  = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(fn($k) => "`{$k}` = ?", array_keys($where)));

        return self::query(
            "UPDATE `{$table}` SET {$set} WHERE {$cond}",
            [...array_values($data), ...array_values($where)]
        )->rowCount();
    }

    // DELETE — returns number of affected rows
    public static function delete(string $table, array $where): int
    {
        $cond = implode(' AND ', array_map(fn($k) => "`{$k}` = ?", array_keys($where)));

        return self::query(
            "DELETE FROM `{$table}` WHERE {$cond}",
            array_values($where)
        )->rowCount();
    }

    // Begin / commit / rollback for transactions
    public static function beginTransaction(): void  { self::pdo()->beginTransaction(); }
    public static function commit(): void            { self::pdo()->commit(); }
    public static function rollback(): void          { self::pdo()->rollBack(); }

    private static function pdo(): \PDO
    {
        if (self::$connection === null) self::connect();
        return self::$connection;
    }
}
