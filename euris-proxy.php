<?php
/**
 * Proxy EuRIS pour hébergement mutualisé (Hostinger)
 * - Objectif: exposer en GET une boîte EuRIS GetTracksByBBoxV2 avec CORS,
 *   en transmettant le header Authorization: Bearer <TOKEN> reçu du front.
 * - Avantages: masque CORS, garde le token côté client sans l’exposer aux domaines tiers,
 *   simplifie le débogage (statuts et messages normalisés JSON).
 *
 * SÉCURITÉ:
 * - Ce proxy accepte tout origine par défaut (Access-Control-Allow-Origin: *).
 *   Adapter si besoin: remplacer * par https://alerte.bakabi.fr
 */

// ====== HEADERS CORS ET JSON ======
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  echo json_encode(['message' => 'CORS preflight OK']);
  exit;
}

// Limiter aux GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'Méthode non autorisée. Utiliser GET.']);
  exit;
}

// ====== RÉCUP DES EN-TÊTES ======
$headers = function_exists('getallheaders') ? getallheaders() : [];
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? null;
if (!$auth || stripos($auth, 'Bearer ') !== 0) {
  http_response_code(401);
  echo json_encode([
    'error' => 'Authorization header required',
    'message' => 'Header Authorization: Bearer <TOKEN> manquant'
  ]);
  exit;
}

// ====== PARAMÈTRES REQUIS ======
$minLat = isset($_GET['minLat']) ? floatval($_GET['minLat']) : null;
$maxLat = isset($_GET['maxLat']) ? floatval($_GET['maxLat']) : null;
$minLon = isset($_GET['minLon']) ? floatval($_GET['minLon']) : null;
$maxLon = isset($_GET['maxLon']) ? floatval($_GET['maxLon']) : null;
$pageSize = isset($_GET['pageSize']) ? intval($_GET['pageSize']) : 100;

if ($minLat === null || $maxLat === null || $minLon === null || $maxLon === null) {
  http_response_code(400);
  echo json_encode([
    'error' => 'Missing required parameters',
    'required' => ['minLat','maxLat','minLon','maxLon']
  ]);
  exit;
}

// ====== CONSTRUCTION DE L’URL EURIS ======
// Remarque: EuRIS attend 6 décimales sur la bbox.
$eurisUrl = sprintf(
  'https://www.eurisportal.eu/visuris/api/TracksV2/GetTracksByBBoxV2?minLat=%.6f&maxLat=%.6f&minLon=%.6f&maxLon=%.6f&pageSize=%d',
  $minLat, $maxLat, $minLon, $maxLon, $pageSize
);

// ====== APPEL EURIS ======
$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL => $eurisUrl,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTPHEADER => [
    'Accept: application/json',
    $auth ? 'Authorization: ' . $auth : ''
  ]
]);

$resp = curl_exec($ch);
$errno = curl_errno($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Erreurs réseau
if ($errno) {
  http_response_code(502);
  echo json_encode([
    'error' => 'Erreur réseau proxy',
    'message' => $err,
    'upstream' => $eurisUrl
  ]);
  exit;
}

// Passer les erreurs HTTP de manière lisible
if ($code < 200 || $code >= 300) {
  http_response_code($code);
  $msg = @json_decode($resp, true);
  echo json_encode([
    'error' => 'Erreur EuRIS',
    'status' => $code,
    'message' => $msg['message'] ?? $msg['error'] ?? (is_string($resp) ? $resp : 'inconnu')
  ]);
  exit;
}

// ====== NORMALISATION OPTIONNELLE ======
// Certaines réponses EuRIS incluent des propriétés différentes selon zones/versions.
// On tente de fournir un tableau "tracks" simple avec latitude/longitude + méta courantes.
$data = @json_decode($resp, true);

// Si la réponse est déjà un tableau de tracks
$tracks = [];
if (is_array($data)) {
  // Deux formats possibles: tableau pur, ou objet { items: [...] }
  if (isset($data['items']) && is_array($data['items'])) {
    $source = $data['items'];
  } else {
    $source = $data;
  }

  foreach ($source as $t) {
    $lat = $t['latitude'] ?? $t['lat'] ?? $t['Latitude'] ?? null;
    $lon = $t['longitude'] ?? $t['lon'] ?? $t['Longitude'] ?? null;
    // Garder seulement les points ayant une position
    if ($lat !== null && $lon !== null) {
      $tracks[] = [
        'mmsi'     => $t['mmsi'] ?? $t['MMSI'] ?? null,
        'shipName' => $t['shipName'] ?? $t['vesselName'] ?? $t['ShipName'] ?? null,
        'latitude' => (float)$lat,
        'longitude'=> (float)$lon,
        'speed'    => $t['speed'] ?? $t['SOG'] ?? null,
        'course'   => $t['course'] ?? $t['COG'] ?? null,
        'ts'       => $t['timestamp'] ?? $t['time'] ?? null,
      ];
    }
  }
} else {
  // Si la réponse n’est pas JSON, renvoyer telle quelle
  echo $resp;
  exit;
}

// ====== SORTIE ======
echo json_encode([
  'ok' => true,
  'count' => count($tracks),
  'tracks' => $tracks
], JSON_UNESCAPED_UNICODE);
