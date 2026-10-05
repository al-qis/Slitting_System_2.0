<?php
/**
 * Control Center Configuration Storage Manager (Database Driven)
 * Location: /control_system/config_store.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';

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
 * Retrieve System Control Settings from MySQL database table `system_settings`
 */
function getSystemControlConfig(): array {
    global $conn;
    $defaults = getDefaultControlConfig();

    if (!$conn || $conn->connect_error) {
        return $defaults;
    }

    $keys = [
        'supervisor_pin',
        'ctrl_recoiling_recoil',
        'ctrl_reslit_reslit',
        'ctrl_finish_stock_edit',
        'ctrl_finish_produced_edit',
        'ctrl_last_updated',
        'ctrl_updated_by'
    ];

    $inClause = "'" . implode("','", $keys) . "'";
    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($inClause)");

    $dbSettings = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $dbSettings[$row['setting_key']] = $row['setting_value'];
        }
    }

    // Check if legacy system_control.json file exists — if so, migrate it to DB once & remove file
    $jsonFile = __DIR__ . '/system_control.json';
    if (file_exists($jsonFile)) {
        $jsonContent = @file_get_contents($jsonFile);
        $jsonData = @json_decode($jsonContent, true);
        if (is_array($jsonData) && isset($jsonData['supervisor_pin'])) {
            $migratedConfig = [
                'supervisor_pin' => $jsonData['supervisor_pin'] ?? '1234',
                'button_states'  => $jsonData['button_states'] ?? $defaults['button_states'],
                'last_updated'   => $jsonData['last_updated'] ?? date('Y-m-d H:i:s'),
                'updated_by'     => $jsonData['updated_by'] ?? 'system'
            ];
            saveSystemControlConfig($migratedConfig);
            @unlink($jsonFile);
            return $migratedConfig;
        } else {
            @unlink($jsonFile);
        }
    }

    // If no settings found in DB yet, initialize defaults
    if (empty($dbSettings)) {
        saveSystemControlConfig($defaults);
        return $defaults;
    }

    return [
        'supervisor_pin' => $dbSettings['supervisor_pin'] ?? $defaults['supervisor_pin'],
        'button_states'  => [
            'recoiling_recoil'     => $dbSettings['ctrl_recoiling_recoil'] ?? 'active',
            'reslit_reslit'        => $dbSettings['ctrl_reslit_reslit'] ?? 'active',
            'finish_stock_edit'    => $dbSettings['ctrl_finish_stock_edit'] ?? 'active',
            'finish_produced_edit' => $dbSettings['ctrl_finish_produced_edit'] ?? 'active',
        ],
        'last_updated' => $dbSettings['ctrl_last_updated'] ?? date('Y-m-d H:i:s'),
        'updated_by'   => $dbSettings['ctrl_updated_by'] ?? 'system'
    ];
}

/**
 * Save System Control Settings into MySQL database table `system_settings`
 */
function saveSystemControlConfig(array $config): bool {
    global $conn;
    if (!$conn || $conn->connect_error) {
        return false;
    }

    $lastUpdated = date('Y-m-d H:i:s');
    $updatedBy   = $_SESSION['username'] ?? $_SESSION['role'] ?? 'system';

    $toSave = [
        'supervisor_pin'            => trim($config['supervisor_pin'] ?? '1234'),
        'ctrl_recoiling_recoil'     => strtolower(trim($config['button_states']['recoiling_recoil'] ?? 'active')),
        'ctrl_reslit_reslit'        => strtolower(trim($config['button_states']['reslit_reslit'] ?? 'active')),
        'ctrl_finish_stock_edit'    => strtolower(trim($config['button_states']['finish_stock_edit'] ?? 'active')),
        'ctrl_finish_produced_edit' => strtolower(trim($config['button_states']['finish_produced_edit'] ?? 'active')),
        'ctrl_last_updated'         => $lastUpdated,
        'ctrl_updated_by'           => $updatedBy
    ];

    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    if (!$stmt) {
        return false;
    }

    foreach ($toSave as $key => $val) {
        $stmt->bind_param("ss", $key, $val);
        $stmt->execute();
    }
    $stmt->close();

    // Clean up system_control.json if it exists
    $jsonFile = __DIR__ . '/system_control.json';
    if (file_exists($jsonFile)) {
        @unlink($jsonFile);
    }

    return true;
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
    
    saveSystemControlConfig($config);
    return $newPin;
}

/**
 * Check if a system button / feature is ACTIVE
 */
function isButtonActive(string $buttonKey): bool {
    $config = getSystemControlConfig();
    $state  = $config['button_states'][$buttonKey] ?? 'active';
    return strtolower(trim($state)) === 'active';
}
