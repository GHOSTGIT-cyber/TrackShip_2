<?php
/**
 * api/tapo.php
 *
 * Endpoint serveur pour piloter une prise Tapo via le cloud, sans exposer d’identifiants côté front.
 * Usage: POST /api/tapo.php  { "action": "on"|"off", "duree": <secondes facultatif> }
 *
 * Sécurité:
 * - Stocker TAPO_EMAIL, TAPO_PASSWORD, TAPO_DEVICE_ID (ou TAPO_DEVICE_ALIAS) en variables d’environnement Hostinger.
 * - Optionnel: ajouter un secret applicatif X-API-Key côté serveur et exiger ce header côté client.
 *
 * Note: Le cloud Tapo utilise un flux d’authentification (eu-login) puis des appels d’actions device.
 * Ici, on illustre un schéma générique basé sur le cloud Tapo; selon l’API publique/disponible, adapter les URLs.
 * Si l’accès cloud n’est pas possible, utiliser une lib PHP Tapo locale côté serveur (nécessite accès réseau à la prise).
 */

// ---------- Réglages et helpers ----------
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: https://alerte.bakabi.fr'); // restreindre à votre domaine
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  echo json_encode(['ok' => true, 'message' => 'CORS OK']);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée, utiliser POST']);
  exit;
}

// Optionnel: clé d’API simple pour le front de votre site
$requiredApiKey = getenv('APP_API_KEY') ?: null;
$clientApiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
if ($requiredApiKey && $clientApiKey !== $requiredApiKey) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Non autorisé']);
  exit;
}

// Lecture payload JSON
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$action = $body['action'] ?? null;
$duree = isset($body['duree']) ? intval($body['duree']) : null;

if (!in_array($action, ['on', 'off'], true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => "Paramètre 'action' requis: 'on' ou 'off'"]);
  exit;
}

// Variables d’environnement (configurées dans Hostinger > Avancé > Variables d’environnement)
$email   = getenv('TAPO_EMAIL') ?: 'bakabi06@gmail.com';
$pass    = getenv('TAPO_PASSWORD') ?: 'efoilfrance62';
$deviceId = getenv('TAPO_DEVICE_ID') ?: ''; // id unique de l’appareil (préféré)
$deviceAlias = getenv('TAPO_DEVICE_ALIAS') ?: 'Alerte Gyro'; // alias si pas d’id
$region  = getenv('TAPO_REGION') ?: 'EU'; // ex: EU/US, selon compte

if (!$email || !$pass || (!$deviceId && !$deviceAlias)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Configuration serveur incomplète (email/mot de passe/device)']);
  exit;
}

// ---------- Fonctions HTTP ----------
function http_post_json($url, $payload, $headers = [], $timeout = 20) {
  $ch = curl_init($url);
  $headers = array_merge(['Content-Type: application/json'], $headers);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => $timeout,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $resp, $err];
}

function http_post_form($url, $fields, $headers = [], $timeout = 20) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => http_build_query($fields),
    CURLOPT_TIMEOUT => $timeout,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $resp, $err];
}

// ---------- Authentification Cloud Tapo (schéma générique) ----------
// A adapter selon les endpoints officiels disponibles pour votre compte/région.
// Exemple indicatif: login pour obtenir un token, puis appels device list/control.
try {
  // 1) Login
  // Remplacer l’URL par l’endpoint d’auth Tapo valide pour votre région/compte.
  $loginUrl = 'https://wap.tplinkcloud.com'; // endpoint historique (ex) ; à adapter si nécessaire
  $loginPayload = [
    'method' => 'login',
    'params' => [
      'appType' => 'Tapo_iOS',  // ou Android
      'cloudUserName' => $email,
      'cloudPassword' => $pass,
      'terminalUUID' => bin2hex(random_bytes(8))
    ]
  ];
  [$code, $resp, $err] = http_post_json($loginUrl, $loginPayload);
  if ($err) throw new Exception("Erreur réseau login: $err");
  if ($code < 200 || $code >= 300) throw new Exception("HTTP $code login: $resp");
  $j = json_decode($resp, true);
  if (!isset($j['result']['token'])) throw new Exception("Token cloud introuvable: $resp");
  $token = $j['result']['token'];

  // 2) Trouver l’appareil si on a un alias au lieu de deviceId
  $devId = $deviceId;
  if (!$devId) {
    $listPayload = ['method' => 'getDeviceList'];
    $listUrl = $loginUrl . '?token=' . urlencode($token);
    [$code2, $resp2, $err2] = http_post_json($listUrl, $listPayload);
    if ($err2) throw new Exception("Erreur réseau device list: $err2");
    if ($code2 < 200 || $code2 >= 300) throw new Exception("HTTP $code2 device list: $resp2");
    $lj = json_decode($resp2, true);
    if (!isset($lj['result']) || !is_array($lj['result'])) throw new Exception("Liste devices invalide");
    foreach ($lj['result'] as $dev) {
      if (isset($dev['alias']) && $dev['alias'] === $deviceAlias) {
        $devId = $dev['deviceId'] ?? null;
        break;
      }
    }
    if (!$devId) throw new Exception('Appareil non trouvé par alias');
  }

  // 3) Contrôle ON/OFF
  // Pour beaucoup de prises Tapo, l’action passe par "passthrough" avec un JSON d’operation.
  $controlUrl = $loginUrl . '?token=' . urlencode($token);
  $requestData = [
    'deviceId' => $devId,
    'requestData' => json_encode([
      'method' => 'set_device_info',
      'params' => [
        'device_on' => $action === 'on'
      ]
    ])
  ];
  $controlPayload = ['method' => 'passthrough', 'params' => $requestData];
  [$code3, $resp3, $err3] = http_post_json($controlUrl, $controlPayload);
  if ($err3) throw new Exception("Erreur réseau control: $err3");
  if ($code3 < 200 || $code3 >= 300) throw new Exception("HTTP $code3 control: $resp3");
  $cj = json_decode($resp3, true);
  if (isset($cj['error_code']) && $cj['error_code'] !== 0) {
    throw new Exception('Erreur cloud: ' . $resp3);
  }

  // 4) Si une durée est fournie, programmer l’extinction côté serveur
  if ($duree !== null && $duree > 0 && $action === 'on') {
    // NB: Sur mutualisé, pas de long-running. On renvoie OK immédiat
    // et on déclenche une tâche "best effort" via close connection.
    ignore_user_abort(true);
    header('Connection: close');
    ob_start();
    echo json_encode(['ok' => true, 'message' => "Prise allumée ($duree s)"]);
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush();
    flush();

    // "Sleep" puis off en tâche de fond
    // Attention: selon la config PHP (fastcgi), ce sleep peut être coupé.
    // Pour une fiabilité parfaite, préférer un cron/queue.
    sleep($duree);

    $requestDataOff = [
      'deviceId' => $devId,
      'requestData' => json_encode([
        'method' => 'set_device_info',
        'params' => [ 'device_on' => false ]
      ])
    ];
    $controlPayloadOff = ['method' => 'passthrough', 'params' => $requestDataOff];
    http_post_json($controlUrl, $controlPayloadOff);
    exit;
  }

  echo json_encode(['ok' => true, 'message' => $action === 'on' ? 'Prise allumée' : 'Prise éteinte']);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
