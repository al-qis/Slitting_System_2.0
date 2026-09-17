<?php

namespace App\Models;

use App\Core\Model;

class User extends Model
{
    /**
     * Find a user by username
     */
    public function findByUsername(string $username): ?array
    {
        $sql = "SELECT id, username, password, role FROM users WHERE username = :username LIMIT 1";
        return $this->queryOne($sql, ['username' => $username]);
    }

    /**
     * Find a user by ID
     */
    public function findById(int $id): ?array
    {
        $sql = "SELECT id, username, role FROM users WHERE id = :id LIMIT 1";
        return $this->queryOne($sql, ['id' => $id]);
    }

    /**
     * Verify credentials matching username, password, and designated role
     */
    public function verifyCredentials(string $username, string $password, string $role): ?array
    {
        $user = $this->findByUsername($username);

        if (!$user) {
            return null;
        }

        if (password_verify($password, $user['password']) && strtolower($user['role']) === strtolower($role)) {
            return $user;
        }

        return null;
    }
}
