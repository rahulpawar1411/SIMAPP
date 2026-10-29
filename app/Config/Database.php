<?php
namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

class Database {
    private static ?PDO $instance = null;

    private const HOST = '127.0.0.1';
    private const PORT = 3306;
    private const DBNAME = 'freezer_management';
    private const USER = 'root';
    private const PASS = '';

    private function __construct() {}
    private function __clone() {}

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                self::HOST,
                self::PORT,
                self::DBNAME
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            try {
                self::$instance = new PDO($dsn, self::USER, self::PASS, $options);
            } catch (PDOException $e) {
                error_log("Database connection failure: " . $e->getMessage());
                throw new RuntimeException("Database connection error. Please ensure MySQL is active.");
            }
        }
        return self::$instance;
    }
}
