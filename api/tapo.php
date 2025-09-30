<?php
/**
 * api/tapo.php - Pilotage prise Tapo via API cloud avec authentification signée
 * 
 * Basé sur le reverse engineering de l'API Tapo:
 * - Secret hardcodé: 6ed7d97f3e73467f8a5bab90b577ba4c
 * - Timestamp fixe: 9999999999
 * - Signature HMAC-SHA1 avec presignature MD5
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: https://alerte.bakabi.fr');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  echo json_encode(['ok' => true, 'message' => 'CORS OK']);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
  exit;
}

// Lecture payload
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$action = $body['action'] ?? null;
$duree = isset($body['duree']) ? intval($body['duree']) : null;

if (!in_array($action, ['on', 'off'], true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => "action: 'on' ou 'off'"]);
  exit;
}

// Identifiants Tapo
$email = getenv('TAPO_EMAIL') ?: 'bakari06@live.fr';
$pass = getenv('TAPO_PASSWORD') ?: 'Alerte06200?';
$deviceAlias = getenv('TAPO_DEVICE_ALIAS') ?: 'Alerte Gyro';

// Secret hardcodé de l'app Tapo (trouvé par reverse engineering)
const TAPO_SECRET = '6ed7d97f3e73467f8a5bab90b577ba4c';
const TAPO_TIMESTAMP = 9999999999;

// ============ FONCTIONS SIGNATURE TAPO ============

function generateUUID() {
  return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
  );
}

function createPresignature($jsonBody) {
  $md5 = md5($jsonBody, true);
  return base64_encode($md5);
}

function createSignature($presignature, $timestamp, $nonce, $apiUrl, $secret) {
  $parts = [];
  if ($presignature) $parts[] = $presignature;
  $parts[] = $timestamp;
  if ($nonce) $parts[] = $nonce;
  $parts[] = $apiUrl;
  
  $data = implode("\n", $parts);
  $hmac = hash_hmac('sha1', $data, $secret, true);
  return bin2hex($hmac);
}

function tapoRequest($endpoint, $payload, $token = null) {
  $url = 'https://eu-wap.tplinkcloud.com' . $endpoint;
  $jsonBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
  
  $nonce = generateUUID();
  $presignature = createPresignature($jsonBody);
  $signature = createSignature($presignature, TAPO_TIMESTAMP, $nonce, $endpoint, TAPO_SECRET);
  
  $headers = [
    'Content-Type: application/json',
    'User-Agent: Tapo-Android/3.7.113',
    'X-Nonce: ' . $nonce,
    'X-Signature: ' . $signature,
    'X-Timestamp: ' . TAPO_TIMESTAMP,
    'X-App-Type: TP-Link_Tapo_Android'
  ];
  
  if ($token) {
    $headers[] = 'X-Token: ' . $token;
  }
  
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => $jsonBody,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true
  ]);
  
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  
  if ($err) throw new Exception("Erreur réseau: $err");
  if ($code < 200 || $code >= 300) throw new Exception("HTTP $code: $resp");
  
  return json_decode($resp, true);
}

// ============ FLUX PRINCIPAL ============

try {
  // 1) Login
  $terminalUUID = bin2hex(random_bytes(16));
  $loginPayload = [
    'appType' => 'TP-Link_Tapo_Android',
    'appVersion' => '3.7.113',
    'cloudUserName' => $email,
    'cloudPassword' => $pass,
    'terminalUUID' => $terminalUUID,
    'terminalName' => 'Server-PHP',
    'platform' => 'Linux',
    'refreshTokenNeeded' => false,
    'terminalMeta' => '1'
  ];
  
  $loginResp = tapoRequest('/api/v2/account/login', $loginPayload);
  
  if (!isset($loginResp['result']['token'])) {
    throw new Exception('Token non reçu: ' . json_encode($loginResp));
  }
  
  $token = $loginResp['result']['token'];
  
  // 2) Liste des appareils
  $listPayload = ['method' => 'getDeviceList'];
  $listResp = tapoRequest('?token=' . urlencode($token), $listPayload, $token);
  
  $deviceId = null;
  if (isset($listResp['result']['deviceList'])) {
    foreach ($listResp['result']['deviceList'] as $dev) {
      if (($dev['alias'] ?? '') === $deviceAlias) {
        $deviceId = $dev['deviceId'];
        break;
      }
    }
  }
  
  if (!$deviceId) {
    throw new Exception("Appareil '$deviceAlias' non trouvé");
  }
  
  // 3) Contrôle ON/OFF
  $requestData = json_encode([
    'method' => 'set_device_info',
    'params' => ['device_on' => $action === 'on']
  ]);
  
  $controlPayload = [
    'method' => 'passthrough',
    'params' => [
      'deviceId' => $deviceId,
      'requestData' => $requestData
    ]
  ];
  
  $controlResp = tapoRequest('?token=' . urlencode($token), $controlPayload, $token);
  
  if (isset($controlResp['error_code']) && $controlResp['error_code'] !== 0) {
    throw new Exception('Erreur control: ' . json_encode($controlResp));
  }
  
  // 4) Durée temporisée
  if ($duree !== null && $duree > 0 && $action === 'on') {
    ignore_user_abort(true);
    header('Connection: close');
    ob_start();
    echo json_encode(['ok' => true, 'message' => "Prise allumée ($duree s)"]);
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush();
    flush();
    
    sleep($duree);
    
    $offData = json_encode([
      'method' => 'set_device_info',
      'params' => ['device_on' => false]
    ]);
    
    $offPayload = [
      'method' => 'passthrough',
      'params' => [
        'deviceId' => $deviceId,
        'requestData' => $offData
      ]
    ];
    
    tapoRequest('?token=' . urlencode($token), $offPayload, $token);
    exit;
  }
  
  echo json_encode([
    'ok' => true, 
    'message' => $action === 'on' ? 'Prise allumée' : 'Prise éteinte'
  ]);
  
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}