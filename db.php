<?php
/**
 * Database bootstrap. Exposes a PDO instance as $pdo.
 *
 * Two modes, picked by the DB_DRIVER environment variable:
 *
 *   DB_DRIVER=mysql (default)  — for the hosted web deployment.
 *     Settings: DB_HOST, DB_USER, DB_PASS, DB_NAME (env vars, falling back to
 *     the constants below). On first run, if the database doesn't exist,
 *     schema.sql creates it and loads sample data.
 *
 *   DB_DRIVER=sqlite  — for the standalone desktop build.
 *     Settings: DB_SQLITE_PATH (env var; falls back to data/vault.sqlite next
 *     to this file). The vault is just that one file: copy it, back it up, or
 *     move it anywhere. If the file doesn't exist yet, schema.sqlite.sql
 *     creates an EMPTY vault (no sample data) — a fresh personal vault, not a
 *     demo. The Electron desktop wrapper sets these two env vars itself
 *     before starting PHP, based on which vault file the user picked.
 */

const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '';              // XAMPP default is an empty root password
const DB_NAME = 'asset_tracker';

function driver(): string
{
    return getenv('DB_DRIVER') ?: 'mysql';
}

function connect_mysql(bool $withDb): PDO
{
    $host = getenv('DB_HOST') ?: DB_HOST;
    $name = getenv('DB_NAME') ?: DB_NAME;
    $dsn = "mysql:host=$host;charset=utf8mb4" . ($withDb ? ";dbname=$name" : '');

    return new PDO($dsn, getenv('DB_USER') ?: DB_USER, getenv('DB_PASS') !== false ? getenv('DB_PASS') : DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function sqlite_path(): string
{
    return getenv('DB_SQLITE_PATH') ?: __DIR__ . '/data/vault.sqlite';
}

function connect_sqlite(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    if (driver() === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info($table)");
        foreach ($stmt->fetchAll() as $row) {
            if ($row['name'] === $column) {
                return true;
            }
        }
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function table_exists(PDO $pdo, string $table): bool
{
    if (driver() === 'sqlite') {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

/** Drops a column, first dropping any foreign key that references it (MySQL won't drop the column otherwise). */
function drop_column(PDO $pdo, string $table, string $column): void
{
    if (!column_exists($pdo, $table, $column)) {
        return;
    }
    if (driver() === 'sqlite') {
        // No legacy SQLite vaults exist yet (this driver is new), so this is here only for parity;
        // SQLite needs a full table rebuild to drop a column, which isn't worth it for a path unused today.
        return;
    }
    $stmt = $pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL');
    $stmt->execute([$table, $column]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fk) {
        $pdo->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$fk`");
    }
    $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$column`");
}

/** Brings a database created by an older version of the app up to date (safe to run repeatedly). MySQL only. */
function migrate(PDO $pdo): void
{
    if (driver() === 'sqlite') {
        return; // the SQLite driver is new as of this version; nothing older to upgrade from
    }
    if (!table_exists($pdo, 'folders') || !table_exists($pdo, 'assets') || !table_exists($pdo, 'asset_history')) {
        return; // fresh install already created by schema.sql
    }

    if (!column_exists($pdo, 'folders', 'type')) {
        $pdo->exec("ALTER TABLE folders ADD COLUMN type ENUM('Physical', 'Digital') NOT NULL DEFAULT 'Physical' AFTER name");
        // Give each existing folder the type of the assets it already holds.
        $pdo->exec("UPDATE folders f SET f.type = COALESCE(
            (SELECT a.type FROM assets a WHERE a.folder_id = f.id GROUP BY a.type ORDER BY COUNT(*) DESC LIMIT 1), 'Physical')");
    }
    if (!column_exists($pdo, 'assets', 'nickname')) {
        $pdo->exec('ALTER TABLE assets ADD COLUMN nickname VARCHAR(100) NULL AFTER name');
    }
    if (!column_exists($pdo, 'asset_history', 'directions')) {
        $pdo->exec('ALTER TABLE asset_history ADD COLUMN directions TEXT NULL AFTER notes');
    }

    // Dropped the multi-user system (accounts, roles, per-folder access) in favor of a single
    // optional master password. Remove what's left of it from any older database.
    drop_column($pdo, 'assets', 'created_by');
    drop_column($pdo, 'asset_history', 'updated_by');
    if (table_exists($pdo, 'user_folders')) {
        $pdo->exec('DROP TABLE user_folders');
    }
    if (table_exists($pdo, 'users')) {
        $pdo->exec('DROP TABLE users');
    }

    if (!table_exists($pdo, 'app_settings')) {
        $pdo->exec('CREATE TABLE app_settings (
            id TINYINT PRIMARY KEY DEFAULT 1,
            password_hash VARCHAR(255) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT single_row CHECK (id = 1)
        ) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO app_settings (id, password_hash) VALUES (1, NULL)');
    }
}

function init_mysql(): PDO
{
    try {
        $pdo = connect_mysql(true);
    } catch (PDOException $e) {
        if ((int) $e->getCode() !== 1049) { // 1049 = unknown database
            throw $e;
        }
        // First run: build the schema from schema.sql, then reconnect.
        $setup = connect_mysql(false);
        $setup->setAttribute(PDO::ATTR_EMULATE_PREPARES, true); // needed for multi-statement exec
        $setup->exec(file_get_contents(__DIR__ . '/schema.sql'));
        $pdo = connect_mysql(true);
    }
    migrate($pdo);
    return $pdo;
}

function init_sqlite(): PDO
{
    $path = sqlite_path();
    @mkdir(dirname($path), 0755, true);
    $empty = !file_exists($path) || filesize($path) === 0;

    $pdo = connect_sqlite($path);
    if (!table_exists($pdo, 'app_settings')) {
        // Only build the schema in a brand-new/empty file; never inside some other SQLite database.
        if (!$empty) {
            throw new RuntimeException('Not an AssetTracker vault: ' . $path);
        }
        $pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
    }
    return $pdo;
}

try {
    $pdo = driver() === 'sqlite' ? init_sqlite() : init_mysql();
} catch (Throwable $e) {
    error_log('AssetTracker DB error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    $hint = driver() === 'sqlite'
        ? 'Could not open the vault file. Check DB_SQLITE_PATH and that its folder is writable.'
        : 'Database connection failed. Check db.php settings and that MySQL is running.';
    echo json_encode(['error' => $hint]);
    exit;
}
