<?php
/**
 * api/shelly.php — Proxy Shelly Cloud Control API v2
 *
 * Contrôle la prise Shelly Plus Plug S via le cloud officiel Shelly.
 * Aucun accès au réseau local du Shelly requis : 100% HTTPS sortant.
 *
 * Entrée  : POST JSON  { "action": "on"|"off", "duree"?: <secondes> }
 * Sortie  : JSON       { "ok": bool, "message": str, ... }
 *
 * Doc     : https://shelly-api-docs.shelly.cloud/cloud-control-api/communication-v2/
 */

require_once __DIR__ . '/auth.php';

// ====== CORS ======
header('Access-Control-Allow-Origin: https://alerte.bakabi.fr');
header('Access-Control-Allow-Headers: Content-Type, X-Page-Auth');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Vary: Origin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée (POST attendu)']);
    exit;
}

requirePageAuth();

// ====== CONFIGURATION (variables d'environnement Coolify) ======
$host     = getenv('SHELLY_HOST');
$authKey  = getenv('SHELLY_AUTH_KEY');
$deviceId = getenv('SHELLY_DEVICE_ID');

if (!$host || !$authKey || !$deviceId) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Configuration serveur incomplète',
        'hint'  => 'Définir SHELLY_HOST, SHELLY_AUTH_KEY, SHELLY_DEVICE_ID dans Coolify',
    ]);
    exit;
}

// ====== LECTURE REQUÊTE ======
$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true) ?? [];
$action = $body['action'] ?? null;
$duree  = isset($body['duree']) ? max(1, intval($body['duree'])) : null;

if (!in_array($action, ['on', 'off'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Champ 'action' requis : 'on' ou 'off'"]);
    exit;
}

// ====== PAYLOAD SHELLY ======
$payload = [
    'id'      => $deviceId,
    'channel' => 0,
    'on'      => ($action === 'on'),
];

// Auto-extinction : timer géré par le firmware de la prise (toggle_after).
// Aucun sleep PHP, aucun cron : fiable même si le client se déconnecte.
// Limite : si la prise perd le WiFi avant l'échéance, le timer est perdu.
if ($duree !== null && $action === 'on') {
    $payload['toggle_after'] = $duree;
}

// ====== APPEL API SHELLY CLOUD ======
$host = rtrim($host, '/');
$url  = sprintf('https://%s/v2/devices/api/set/switch?auth_key=%s', $host, urlencode($authKey));

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// ====== GESTION D'ERREURS ======

// 1. Erreur réseau / DNS / TLS / timeout
if ($response === false || $curlErr !== '') {
    http_response_code(502);
    echo json_encode([
        'ok'     => false,
        'error'  => 'Échec réseau vers Shelly Cloud',
        'detail' => $curlErr !== '' ? $curlErr : 'pas de réponse',
    ]);
    exit;
}

// 2. Réponse HTTP non-2xx
if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    echo json_encode([
        'ok'       => false,
        'error'    => "Shelly Cloud a répondu HTTP {$httpCode}",
        'detail'   => substr($response, 0, 500),
        'hint'     => $httpCode === 401 ? 'AUTH_KEY invalide ?' : ($httpCode === 404 ? 'HOST ou DEVICE_ID incorrect ?' : null),
    ]);
    exit;
}

// 3. Réponse non-JSON
$decoded = json_decode($response, true);
if (!is_array($decoded)) {
    http_response_code(502);
    echo json_encode([
        'ok'     => false,
        'error'  => 'Réponse Shelly Cloud invalide (JSON attendu)',
        'detail' => substr($response, 0, 500),
    ]);
    exit;
}

// 4. Cloud Shelly renvoie { "isok": true|false, ... }
$isOk = $decoded['isok'] ?? null;
if ($isOk === false) {
    http_response_code(502);
    echo json_encode([
        'ok'     => false,
        'error'  => 'Shelly Cloud a rejeté la commande',
        'detail' => $decoded['errors'] ?? $decoded,
    ]);
    exit;
}

// ====== SUCCÈS ======
echo json_encode([
    'ok'      => true,
    'action'  => $action,
    'duree'   => $duree,
    'message' => $action === 'on'
        ? ($duree ? "Prise allumée pour {$duree}s (timer firmware)" : 'Prise allumée')
        : 'Prise éteinte',
]);
