-- Einmalig auszufuehren, z.B.: sudo mysql < tests/integration/setup-db.sql
-- Legt eine separate Test-Datenbank + dedizierten User fuer die ESSE-Integrationstests an.
-- Das Schema selbst wird von tests/integration/bootstrap.php (Esse\Schema) erzeugt/zurueckgesetzt.

CREATE DATABASE IF NOT EXISTS esse_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'esse_test'@'localhost' IDENTIFIED BY 'esse_test';
GRANT ALL PRIVILEGES ON esse_test.* TO 'esse_test'@'localhost';
FLUSH PRIVILEGES;
