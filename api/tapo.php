<?php
/**
 * api/tapo.php - Solution directe pour Hostinger
 * Version optimisée pour hébergement mutualisé
 */

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
    echo json_encode(['ok' => false, 'error' => 'Seul POST est autorisé']);
    exit;
}

// ==================== CONFIGURATION ====================
// 🔧 MODIFIEZ CES VALEURS AVEC VOS INFORMATIONS
$DEVICE_IP = '192.168.1.100';          // 👈 REMPLACEZ par l'IP de votre prise
$TAPO_EMAIL = 'bakabi06@gmail.com';     // 👈 Votre email TP-Link
$TAPO_PASSWORD = 'efoilfrance62';       // 👈 Votre mot de passe TP-Link

// Lecture des données envoyées
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
    echo json_encode(['ok' => false, 'error' => 'Action invalide. Utilisez "on" ou "off"']);
    exit;
}

// ==================== FONCTIONS ====================

/**
 * Log des erreurs (pour débogage)
 */
function logError($message) {
    $logFile = __DIR__ . '/tapo_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

/**
 * Requête HTTP POST avec gestion d'erreurs
 */
function makeHttpRequest($url, $data, $timeout = 10) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: TapoApp/1.0'
        ],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    
    if ($errno) {
        throw new Exception("Erreur cURL [$errno]: $error");
    }
    
    if ($httpCode === 0) {
        throw new Exception("Impossible de contacter l'appareil. Vérifiez l'IP: $url");
    }
    
    return [$httpCode, $response];
}

/**
 * Contrôle de la prise Tapo - Version simplifiée
 */
function controlTapoDevice($deviceIP, $email, $password, $action) {
    $baseUrl = "http://$deviceIP/app";
    
    try {
        // Étape 1: Test de connectivité
        logError("Tentative de connexion à $baseUrl");
        
        // Étape 2: Handshake
        $handshakePayload = [
            'method' => 'handshake',
            'params' => [
                'key' => ''
            ]
        ];
        
        [$code, $response] = makeHttpRequest($baseUrl, $handshakePayload, 8);
        logError("Handshake - Code: $code, Response: " . substr($response, 0, 200));
        
        if ($code !== 200) {
            throw new Exception("Handshake échoué - HTTP $code");
        }
        
        $handshakeResult = json_decode($response, true);
        if (!$handshakeResult || ($handshakeResult['error_code'] ?? -1) !== 0) {
            throw new Exception("Handshake invalide: " . ($handshakeResult['msg'] ?? 'Format incorrect'));
        }
        
        // Étape 3: Authentification
        $loginPayload = [
            'method' => 'login_device',
            'params' => [
                'username' => base64_encode($email),
                'password' => base64_encode($password)
            ]
        ];
        
        [$code, $response] = makeHttpRequest($baseUrl, $loginPayload, 8);
        logError("Login - Code: $code");
        
        if ($code !== 200) {
            throw new Exception("Login échoué - HTTP $code");
        }
        
        $loginResult = json_decode($response, true);
        if (!$loginResult || ($loginResult['error_code'] ?? -1) !== 0) {
            $errorMsg = $loginResult['msg'] ?? 'Identifiants incorrects';
            throw new Exception("Authentification échouée: $errorMsg");
        }
        
        $token = $loginResult['result']['token'] ?? null;
        if (!$token) {
            throw new Exception("Token non reçu dans la réponse");
        }
        
        logError("Token reçu: " . substr($token, 0, 20) . "...");
        
        // Étape 4: Contrôle ON/OFF
        $controlUrl = $baseUrl . '?token=' . urlencode($token);
        $controlPayload = [
            'method' => 'set_device_info',
            'params' => [
                'device_on' => ($action === 'on')
            ]
        ];
        
        [$code, $response] = makeHttpRequest($controlUrl, $controlPayload, 8);
        logError("Contrôle $action - Code: $code");
        
        if ($code !== 200) {
            throw new Exception("Contrôle échoué - HTTP $code");
        }
        
        $controlResult = json_decode($response, true);
        if (!$controlResult || ($controlResult['error_code'] ?? -1) !== 0) {
            $errorMsg = $controlResult['msg'] ?? 'Commande rejetée';
            throw new Exception("Commande échouée: $errorMsg");
        }
        
        logError("Succès: Prise " . ($action === 'on' ? 'allumée' : 'éteinte'));
        return true;
        
    } catch (Exception $e) {
        logError("Erreur: " . $e->getMessage());
        throw $e;
    }
}

// ==================== EXÉCUTION PRINCIPALE ====================

try {
    // Contrôle immédiat de l'appareil
    $success = controlTapoDevice($DEVICE_IP, $TAPO_EMAIL, $TAPO_PASSWORD, $action);
    
    // Gestion spéciale pour l'allumage temporaire
    if ($duree !== null && $duree > 0 && $action === 'on') {
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
        
        // Si la connexion du client s'interrompt, continuer quand même
        if (connection_aborted()) {
            logError("Client déconnecté, mais on continue l'extinction programmée");
        }
        
        // Attendre la durée spécifiée
        sleep($duree);
        
        // Éteindre automatiquement
        try {
            controlTapoDevice($DEVICE_IP, $TAPO_EMAIL, $TAPO_PASSWORD, 'off');
            logError("Extinction automatique réussie après $duree secondes");
        } catch (Exception $e) {
            logError("Erreur extinction auto: " . $e->getMessage());
        }
        
        exit;
    }
    
    // Réponse normale pour les autres cas
    echo json_encode([
        'ok' => true,
        'message' => $action === 'on' ? 'Prise allumée' : 'Prise éteinte',
        'device_ip' => $DEVICE_IP
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'device_ip' => $DEVICE_IP,
        'debug' => 'Vérifiez le fichier tapo_debug.log pour plus d\'infos'
    ]);
}
?>