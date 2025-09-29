<?php
/**
 * api/debug-tapo.php - Version debug pour diagnostiquer les problèmes
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

$debug = [];

// 1. Vérifier vendor/autoload.php
$debug['vendor_exists'] = file_exists(__DIR__ . '/vendor/autoload.php');
$debug['vendor_path'] = __DIR__ . '/vendor/autoload.php';

if (!$debug['vendor_exists']) {
    echo json_encode([
        'ok' => false,
        'error' => 'vendor/autoload.php not found',
        'solution' => 'Run: composer install',
        'debug' => $debug
    ], JSON_PRETTY_PRINT);
    exit;
}

// 2. Charger Composer
try {
    require __DIR__ . '/vendor/autoload.php';
    $debug['composer_loaded'] = true;
} catch (Exception $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load Composer',
        'message' => $e->getMessage(),
        'debug' => $debug
    ], JSON_PRETTY_PRINT);
    exit;
}

// 3. Vérifier la classe Tapo
$debug['tapo_class_exists'] = class_exists('Kuhschnappel\TapoApi\Tapo');

if (!$debug['tapo_class_exists']) {
    echo json_encode([
        'ok' => false,
        'error' => 'Tapo class not found',
        'solution' => 'Run: composer install',
        'debug' => $debug
    ], JSON_PRETTY_PRINT);
    exit;
}

// 4. Configuration
$DEVICE_IP = getenv('TAPO_DEVICE_IP') ?: '192.168.1.100';
$TAPO_EMAIL = getenv('TAPO_EMAIL') ?: 'bakabi06@gmail.com';
$TAPO_PASSWORD = getenv('TAPO_PASSWORD') ?: 'efoilfrance62';

$debug['device_ip'] = $DEVICE_IP;
$debug['email'] = $TAPO_EMAIL;
$debug['password_length'] = strlen($TAPO_PASSWORD);

// 5. Test de connexion
use Kuhschnappel\TapoApi\Tapo;

try {
    $debug['connection_test'] = 'Attempting connection...';
    
    $device = new Tapo($TAPO_EMAIL, $TAPO_PASSWORD, "http://{$DEVICE_IP}");
    
    $debug['connection_test'] = 'Connected successfully';
    $debug['device_created'] = true;
    
    // Test ON
    $device->setPowerOn();
    $device->sendChangedSettings();
    
    $debug['test_on'] = 'Success';
    
    echo json_encode([
        'ok' => true,
        'message' => 'Tapo API is working!',
        'debug' => $debug
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'debug' => $debug
    ], JSON_PRETTY_PRINT);
}
?>