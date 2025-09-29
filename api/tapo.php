<?php
/**
 * api/tapo.php - API complète avec bibliothèque Composer
 * Solution simple 
 */

// Charger l'autoloader Composer
require __DIR__ . '/vendor/autoload.php';

use Kuhschnappel\TapoApi\Tapo;

// Headers CORS et JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://alerte.bakabi.fr');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Gestion des requêtes OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'CORS OK']);
    exit;
}

// Vérification de la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée, utiliser POST']);
    exit;
}

// ==================== CONFIGURATION ====================
// Variables d'environnement (avec valeurs par défaut pour le développement)
$DEVICE_IP = getenv('TAPO_DEVICE_IP') ?: '192.168.1.100';
$TAPO_EMAIL = getenv('TAPO_EMAIL') ?: 'bakabi06@gmail.com';
$TAPO_PASSWORD = getenv('TAPO_PASSWORD') ?: 'efoilfrance62';

// Lecture des données POST
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON invalide']);
    exit;
}

$action = $data['action'] ?? null;
$duree = isset($data['duree']) ? intval($data['duree']) : null;

// Validation de l'action
if (!in_array($action, ['on', 'off'], true)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false, 
        'error' => 'Action invalide. Utilisez "on" ou "off"'
    ]);
    exit;
}

// ==================== FONCTION PRINCIPALE ====================

try {
    // Connexion à la prise Tapo avec la bibliothèque
    $device = new Tapo($TAPO_EMAIL, $TAPO_PASSWORD, "http://{$DEVICE_IP}");
    
    // Exécuter l'action demandée
    if ($action === 'on') {
        $device->setPowerOn();
        $message = 'Prise allumée';
    } else {
        $device->setPowerOff();
        $message = 'Prise éteinte';
    }
    
    // Envoyer les changements à l'appareil
    $device->sendChangedSettings();
    
    // ==================== GESTION DURÉE ====================
    // Si une durée est spécifiée et action = on, programmer l'extinction
    if ($duree && $duree > 0 && $action === 'on') {
        // Répondre immédiatement au client
        ignore_user_abort(true);
        header('Connection: close');
        ob_start();
        
        echo json_encode([
            'ok' => true,
            'message' => "Prise allumée pour $duree secondes"
        ]);
        
        $size = ob_get_length();
        header("Content-Length: $size");
        ob_end_flush();
        flush();
        
        // Si le client se déconnecte, continuer quand même
        if (connection_aborted()) {
            error_log("Client déconnecté mais extinction programmée continue");
        }
        
        // Attendre la durée spécifiée
        sleep($duree);
        
        // Éteindre automatiquement
        try {
            $deviceOff = new Tapo($TAPO_EMAIL, $TAPO_PASSWORD, "http://{$DEVICE_IP}");
            $deviceOff->setPowerOff();
            $deviceOff->sendChangedSettings();
            error_log("Extinction automatique réussie après $duree secondes");
        } catch (Exception $e) {
            error_log("Erreur extinction auto: " . $e->getMessage());
        }
        
        exit;
    }
    
    // ==================== RÉPONSE NORMALE ====================
    echo json_encode([
        'ok' => true,
        'message' => $message,
        'device_ip' => $DEVICE_IP,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // ==================== GESTION D'ERREUR ====================
    http_response_code(500);
    
    // Déterminer le type d'erreur pour aider au débogage
    $errorMessage = $e->getMessage();
    $help = '';
    
    if (strpos($errorMessage, 'Failed to open stream') !== false) {
        $help = 'Impossible de contacter l\'appareil. Vérifiez l\'IP du device.';
    } elseif (strpos($errorMessage, 'Auth') !== false || strpos($errorMessage, 'login') !== false) {
        $help = 'Erreur d\'authentification. Vérifiez l\'email et le mot de passe Tapo.';
    } elseif (strpos($errorMessage, 'timeout') !== false) {
        $help = 'Timeout de connexion. L\'appareil est-il accessible ?';
    }
    
    echo json_encode([
        'ok' => false,
        'error' => $errorMessage,
        'help' => $help,
        'device_ip' => $DEVICE_IP
    ]);
}
?>