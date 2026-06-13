<?php
/**
 * api/shelly-status.php — Détection de la prise via Shelly Cloud (lecture seule).
 * Renvoie : en ligne ?, sortie ON/OFF ?, puissance instantanée (W).
 * Utilise l'endpoint cloud /device/status (stable, documenté).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://alerte.bakabi.fr');
header('Cache-Control: no-cache, no-store, must-revalidate');

$host = getenv('SHELLY_HOST');
$key  = getenv('SHELLY_AUTH_KEY');
$id   = getenv('SHELLY_DEVICE_ID');

if (!$host || !$key || !$id) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Variables SHELLY_* manquantes dans Coolify']);
    exit;
}

$url = sprintf('https://%s/device/status', rtrim($host, '/'));
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['id' => $id, 'auth_key' => $key]),
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 15,
]);
$t0   = microtime(true);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
$ms = (int) round((microtime(true) - $t0) * 1000);

if ($resp === false || $err !== '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Réseau Shelly Cloud: ' . ($err ?: 'pas de réponse'), 'ms' => $ms]);
    exit;
}

$data = json_decode($resp, true);
if (!is_array($data) || ($data['isok'] ?? false) !== true) {
    http_response_code(502);
    echo json_encode([
        'ok'     => false,
        'error'  => "Shelly Cloud HTTP {$code}",
        'detail' => is_array($data) ? ($data['errors'] ?? $data) : substr((string) $resp, 0, 300),
        'ms'     => $ms,
    ]);
    exit;
}

$d      = $data['data'] ?? [];
$status = $d['device_status'] ?? [];
// Plus Plug S (Gen2) : l'état du relais est dans "switch:0"
$sw     = $status['switch:0'] ?? [];

echo json_encode([
    'ok'      => true,
    'online'  => (bool) ($d['online'] ?? false),
    'output'  => isset($sw['output']) ? (bool) $sw['output'] : null,
    'power_w' => isset($sw['apower']) ? (float) $sw['apower'] : null,
    'updated' => $status['_updated'] ?? null,
    'ms'      => $ms,
]);
