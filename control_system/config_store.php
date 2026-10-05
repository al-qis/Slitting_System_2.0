<?php
/**
 * Control Center Configuration Storage Manager
 * Location: /control_system/config_store.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SYSTEM_CONTROL_FILE', __DIR__ . '/system_control.json');

/**
 * Default System Control Settings
 */
function getDefaultControlConfig(): array {
    return [
        'supervisor_pin' => '1234',
        'button_states' => [
            'recoiling_recoil'     => 'active',
            'reslit_reslit'        => 'active',
            'finish_stock_edit'    => 'active',
            'finish_produced_edit' => 'active',
        ],
        'last_updated' => date('Y-m-d H:i:s'),
        'updated_by'   => 'system'
    ];
}

/**
 * Retrieve System Control Settings
 */
function getSystemControlConfig(): array {
    if (!file_exists(SYSTEM_CONTROL_FILE)) {
        $default = getDefaultControlConfig();
        saveSystemControlConfig($default);
        return $default;
    }

    $content = file_get_contents(SYSTEM_CONTROL_FILE);
    $data = json_decode($content, true);

    if (!is_array($data) || !isset($data['supervisor_pin']) || !isset($data['button_states'])) {
        $default = getDefaultControlConfig();
        saveSystemControlConfig($default);
        return $default;
    }

    return $data;
}

/**
 * Save System Control Settings
 */
function saveSystemControlConfig(array $config): bool {
    $config['last_updated'] = date('Y-m-d H:i:s');
    $config['updated_by']   = $_SESSION['username'] ?? $_SESSION['role'] ?? 'system';
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents(SYSTEM_CONTROL_FILE, $json, LOCK_EX) !== false;
}

/**
 * Get active Supervisor PIN for SFC processing & supervisor gates
 */
function getSupervisorPin(): string {
    $config = getSystemControlConfig();
    return trim($config['supervisor_pin'] ?? '1234');
}

/**
 * Generate a random numeric supervisor authentication code (default 6 digits)
 */
function generateSupervisorAuthCode(int $digits = 6): string {
    $min = (int)pow(10, $digits - 1);
    $max = (int)pow(10, $digits) - 1;
    try {
        $code = (string)random_int($min, $max);
    } catch (\Throwable $e) {
        $code = (string)mt_rand($min, $max);
    }
    return str_pad($code, $digits, '0', STR_PAD_LEFT);
}

/**
 * Auto-rotate & save a new passkey/PIN for the next operation
 */
function rotateSupervisorPin(int $digits = 6): string {
    $config = getSystemControlConfig();
    $newPin = generateSupervisorAuthCode($digits);
    $config['supervisor_pin'] = $newPin;
    $config['last_updated']   = date('Y-m-d H:i:s');
    $config['updated_by']     = 'Auto-rotated (SFC Process)';
    
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents(SYSTEM_CONTROL_FILE, $json, LOCK_EX);
    
    return $newPin;
}

/**
 * Check if a system button / feature is ACTIVE
 */
function isButtonActive(string $buttonKey): bool {
    $config = getSystemControlConfig();
    $state  = $config['button_states'][$buttonKey] ?? 'active';
    return strtolower($state) === 'active';
}
