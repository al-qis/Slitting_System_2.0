<?php

namespace App\Core;

use PDO;
use mysqli;

abstract class Model
{
    protected PDO $db;
    protected mysqli $mysqli;

    public function __construct()
    {
        $this->db = Database::getPDO();
        $this->mysqli = Database::getConnection();
    }

    /**
     * Helper to execute a prepared PDO statement and return all rows
     */
    protected function queryAll(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Helper to execute a prepared PDO statement and return a single row
     */
    protected function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Helper to execute an INSERT/UPDATE/DELETE statement
     */
    protected function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Get the last inserted ID
     */
    protected function lastInsertId(): string
    {
        return $this->db->lastInsertId();
    }
}
