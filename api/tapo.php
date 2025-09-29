<?php
/**
 * api/tapo.php - Solution PHP pure pour Hostinger
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://alerte.bakabi.fr');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Seul POST est autorisé']);
    exit;
}

// ========== CONFIGURATION - MODIFIEZ ICI ==========
$DEVICE_IP = '192.168.0.42';          // ⚠️ CHANGEZ l'IP de votre prise
$TAPO_EMAIL = 'bakabi06@gmail.com';    // Votre email TP-Link
$TAPO_PASSWORD = 'efoilfrance62';      // Votre mot de passe TP-Link
// ==================================================

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON invalide']);
    exit;
}

$action = $data['action'] ?? null;
$duree = isset($data['duree']) ? intval($data['duree']) : null;

if (!in_array($action, ['on', 'off'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Action invalide']);
    exit;
}

function httpPost($url, $data, $timeout = 10) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) throw new Exception("cURL error: $error");
    return [$httpCode, $response];
}

function controlTapo($deviceIP, $email, $password, $action) {
    $baseUrl = "http://$deviceIP/app";
    
    // Handshake
    [$code, $resp] = httpPost($baseUrl, [
        'method' => 'handshake',
        'params' => ['key' => '']
    ]);
    
    if ($code !== 200) throw new Exception("Handshake failed HTTP $code");
    
    $result = json_decode($resp, true);
    if (($result['error_code'] ?? -1) !== 0) {
        throw new Exception("Handshake error: " . ($result['msg'] ?? 'unknown'));
    }
    
    // Login
    [$code, $resp] = httpPost($baseUrl, [
        'method' => 'login_device',
        'params' => [
            'username' => base64_encode($email),
            'password' => base64_encode($password)
        ]
    ]);
    
    if ($code !== 200) throw new Exception("Login failed HTTP $code");
    
    $loginResult = json_decode($resp, true);
    if (($loginResult['error_code'] ?? -1) !== 0) {
        throw new Exception("Auth failed: " . ($loginResult['msg'] ?? 'bad credentials'));
    }
    
    $token = $loginResult['result']['token'] ?? null;
    if (!$token) throw new Exception("No token received");
    
    // Control
    $controlUrl = $baseUrl . '?token=' . urlencode($token);
    [$code, $resp] = httpPost($controlUrl, [
        'method' => 'set_device_info',
        'params' => ['device_on' => ($action === 'on')]
    ]);
    
    if ($code !== 200) throw new Exception("Control failed HTTP $code");
    
    $controlResult = json_decode($resp, true);
    if (($controlResult['error_code'] ?? -1) !== 0) {
        throw new Exception("Command failed: " . ($controlResult['msg'] ?? 'device error'));
    }
    
    return true;
}

try {
    controlTapo($DEVICE_IP, $TAPO_EMAIL, $TAPO_PASSWORD, $action);
    
    if ($duree && $duree > 0 && $action === 'on') {
        ignore_user_abort(true);
        header('Connection: close');
        ob_start();
        echo json_encode(['ok' => true, 'message' => "Prise allumée pour $duree secondes"]);
        $size = ob_get_length();
        header("Content-Length: $size");
        ob_end_flush();
        flush();
        
        sleep($duree);
        controlTapo($DEVICE_IP, $TAPO_EMAIL, $TAPO_PASSWORD, 'off');
        exit;
    }
    
    echo json_encode([
        'ok' => true,
        'message' => $action === 'on' ? 'Prise allumée' : 'Prise éteinte'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
?>