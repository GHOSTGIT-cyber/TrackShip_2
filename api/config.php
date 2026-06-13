<?php
/**
 * api/config.php — Config runtime de la surveillance, pilotée depuis la page.
 *
 * GET  → renvoie la config courante (enabled, lat, lon, radius_m).
 * POST → merge des valeurs envoyées (JSON) dans cron/data/config.json.
 *
 * Le cron lit ce fichier à chaque tick : il respecte `enabled`, et utilise
 * lat/lon/radius_m si présents (sinon fallback sur les env vars Coolify).
 */

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://alerte.bakabi.fr');
header('Access-Control-Allow-Headers: Content-Type, X-Page-Auth');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$dataDir   = __DIR__ . '/../cron/data';
$file      = $dataDir . '/config.json';
@mkdir($dataDir, 0777, true);

$defaults = [
    'enabled'  => true,
    'lat'      => (float) (getenv('ALERTE_LAT')   ?: 48.853229),
    'lon'      => (float) (getenv('ALERTE_LON')   ?: 2.225328),
    'radius_m' => (int)   (getenv('ALERTE_RAYON') ?: 1000),
];

function loadConfig(string $file, array $defaults): array {
    if (is_file($file)) {
        $cur = json_decode((string) @file_get_contents($file), true);
        if (is_array($cur)) return array_merge($defaults, $cur);
    }
    return $defaults;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(loadConfig($file, $defaults));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePageAuth();
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'JSON requis']);
        exit;
    }
    $cur = loadConfig($file, $defaults);
    if (array_key_exists('enabled', $body))  $cur['enabled']  = (bool)  $body['enabled'];
    if (array_key_exists('lat', $body))      $cur['lat']      = (float) $body['lat'];
    if (array_key_exists('lon', $body))      $cur['lon']      = (float) $body['lon'];
    if (array_key_exists('radius_m', $body)) $cur['radius_m'] = max(50, (int) $body['radius_m']);

    if (file_put_contents($file, json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Écriture impossible (vérifier permissions volume Coolify)']);
        exit;
    }
    @chmod($file, 0666);
    echo json_encode(['ok' => true, 'config' => $cur]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'GET ou POST attendu']);
