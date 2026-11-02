<?php
/**
 * Database Connection Configuration (Strict Isolation)
 * Location: /stock_draft_system/backend/config/database.php
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 2));
}

// Load credentials from root config if present
$rootConfig = dirname(__DIR__, 2) . '/../config.php';
$host   = 'localhost';
$user   = 'root';
$pass   = 'MIS_1990';
$dbname = 'slitting_db';

if (file_exists($rootConfig)) {
    // Include in an isolated scope or parse
    include $rootConfig;
}

class Database {
    private static ?PDO $readOnlyConn = null;
    private static ?PDO $draftConn = null;

    /**
     * Get READ-ONLY connection to slitting_db
     */
    public static function getReadOnlyConnection(): PDO {
        global $host, $user, $pass, $dbname;
        if (self::$readOnlyConn === null) {
            try {
                $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
                self::$readOnlyConn = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]);
            } catch (PDOException $e) {
                die(json_encode(['ok' => false, 'error' => 'Read-Only Database Connection Failed: ' . $e->getMessage()]));
            }
        }
        return self::$readOnlyConn;
    }

    /**
     * Get READ/WRITE connection for draft storage (draft_* tables in slitting_db or stock_draft_db)
     */
    public static function getDraftConnection(): PDO {
        global $host, $user, $pass, $dbname;
        if (self::$draftConn === null) {
            try {
                $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
                self::$draftConn = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]);

                // Auto-initialize draft tables if not created
                self::ensureSchemaExists(self::$draftConn);
            } catch (PDOException $e) {
                die(json_encode(['ok' => false, 'error' => 'Draft Database Connection Failed: ' . $e->getMessage()]));
            }
        }
        return self::$draftConn;
    }

    private static function ensureSchemaExists(PDO $pdo): void {
        $schemaFile = dirname(__DIR__, 2) . '/database/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $stmt) {
                if (!empty($stmt)) {
                    $pdo->exec($stmt);
                }
            }
        }
    }
}
