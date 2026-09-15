<?php

namespace App\Core;

abstract class Controller
{
    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Render a view file from app/Views/{$viewPath}.php
     */
    protected function render(string $viewPath, array $data = []): void
    {
        // Extract variables to be accessible in the view template
        extract($data, EXTR_SKIP);

        $viewFile = dirname(__DIR__) . '/Views/' . ltrim($viewPath, '/') . '.php';

        if (!file_exists($viewFile)) {
            throw new \RuntimeException("View file not found: {$viewFile}");
        }

        require $viewFile;
    }

    /**
     * Return a JSON response (useful for AJAX endpoints)
     */
    protected function json(mixed $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    /**
     * Redirect to a given URL
     */
    protected function redirect(string $url): void
    {
        header("Location: {$url}");
        exit;
    }

    /**
     * Enforce authentication and role checking
     */
    protected function requireRole(array $allowedRoles, string $redirectUrl = 'login.php'): void
    {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            $this->redirect($redirectUrl);
        }

        $currentRole = strtolower($_SESSION['role']);
        $normalizedAllowed = array_map('strtolower', $allowedRoles);

        if (!in_array($currentRole, $normalizedAllowed, true)) {
            $this->redirect($redirectUrl);
        }
    }
}
