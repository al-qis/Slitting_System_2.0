<?php

namespace App\Core;

use mysqli;
use PDO;
use PDOException;

class Database
{
    private static ?mysqli $mysqli = null;
    private static ?PDO $pdo = null;

    private static string $host = 'localhost';
    private static string $user = 'root';
    private static string $pass = 'MIS_1990';
    private static string $dbname = 'slitting_db';
    private static string $charset = 'utf8mb4';

    /**
     * Get or initialize the MySQLi connection (compatible with existing code)
     */
    public static function getConnection(): mysqli
    {
        if (self::$mysqli === null) {
            self::$mysqli = new mysqli(self::$host, self::$user, self::$pass, self::$dbname);

            if (self::$mysqli->connect_error) {
                die("Database Connection Failed: " . self::$mysqli->connect_error);
            }

            self::$mysqli->set_charset(self::$charset);
            date_default_timezone_set('Asia/Kuala_Lumpur');
        }

        return self::$mysqli;
    }

    /**
     * Alias for getConnection()
     */
    public static function getMysqli(): mysqli
    {
        return self::getConnection();
    }

    /**
     * Get or initialize a PDO connection for clean prepared statements
     */
    public static function getPDO(): PDO
    {
        if (self::$pdo === null) {
            $dsn = "mysql:host=" . self::$host . ";dbname=" . self::$dbname . ";charset=" . self::$charset;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$pdo = new PDO($dsn, self::$user, self::$pass, $options);
            } catch (PDOException $e) {
                die("PDO Connection Failed: " . $e->getMessage());
            }
        }

        return self::$pdo;
    }
}
