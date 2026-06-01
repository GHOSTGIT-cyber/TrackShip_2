<?php
declare(strict_types=1);

/**
 * cron/tick.php — Un tick autonome de surveillance navires + déclenchement Shelly.
 *
 * Appelé par Coolify Scheduled Tasks. Commande recommandée :
 *   php /var/www/html/cron/tick.php && sleep 30 && php /var/www/html/cron/tick.php
 * Cron expression : * * * * *  (chaque minute, soit deux ticks à 30s d'intervalle)
 */

date_default_timezone_set('Europe/Paris');

const STATE_DIR   = __DIR__ . '/data';
const STATE_FILE  = STATE_DIR . '/state.json';
const CONFIG_FILE = STATE_DIR . '/config.json';
const LOG_FILE    = STATE_DIR . '/tick.log';

@mkdir(STATE_DIR, 0777, true);
@chmod(STATE_DIR, 0777);

// ====== CONFIG (env Coolify = défauts, config.json piloté par la page = override) ======
$EURIS_TOKEN  = (string) getenv('EURIS_TOKEN');
$BBOX_PAGE    = (int)   (getenv('EURIS_PAGE_SIZE') ?: 200);
$SHELLY_HOST  = (string) getenv('SHELLY_HOST');
$SHELLY_KEY   = (string) getenv('SHELLY_AUTH_KEY');
$SHELLY_ID    = (string) getenv('SHELLY_DEVICE_ID');

$envLat   = (float) (getenv('ALERTE_LAT')   ?: 48.853229);
$envLon   = (float) (getenv('ALERTE_LON')   ?: 2.225328);
$envRayon = (int)   (getenv('ALERTE_RAYON') ?: 1000);

$cfg = [];
if (is_file(CONFIG_FILE)) {
    $cfg = @json_decode((string) @file_get_contents(CONFIG_FILE), true) ?: [];
}
$ENABLED = !array_key_exists('enabled', $cfg) || (bool) $cfg['enabled'];
$LAT     = (float) ($cfg['lat']      ?? $envLat);
$LON     = (float) ($cfg['lon']      ?? $envLon);
$RAYON   = (int)   ($cfg['radius_m'] ?? $envRayon);

// ====== HELPERS ======
function tickLog(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R   = 6371000.0;
    $rad = M_PI / 180.0;
    $dLat = ($lat2 - $lat1) * $rad;
    $dLon = ($lon2 - $lon1) * $rad;
    $a = sin($dLat / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin($dLon / 2) ** 2;
    return 2 * $R * asin(sqrt($a));
}

function shellySet(string $host, string $key, string $id, bool $on): array {
    $url  = sprintf('https://%s/v2/devices/api/set/switch?auth_key=%s', $host, urlencode($key));
    $body = json_encode(['id' => $id, 'channel' => 0, 'on' => $on]);
    $ch   = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $t0   = microtime(true);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'http' => $code,
        'ms'   => (int) round((microtime(true) - $t0) * 1000),
        'err'  => $err,
        'body' => is_string($resp) ? substr($resp, 0, 200) : null,
    ];
}

// ====== VALIDATION ======
if ($EURIS_TOKEN === '') { tickLog('ABANDON — EURIS_TOKEN manquant');  exit(1); }
if ($SHELLY_HOST === '' || $SHELLY_KEY === '' || $SHELLY_ID === '') {
    tickLog('ABANDON — SHELLY_HOST/AUTH_KEY/DEVICE_ID manquants');
    exit(1);
}

// ====== KILL SWITCH : surveillance désactivée depuis la page ======
if (!$ENABLED) {
    // Mise à jour minimale du state pour que la page voie "désactivé"
    $prevState = is_file(STATE_FILE)
        ? (@json_decode(@file_get_contents(STATE_FILE), true) ?: [])
        : [];
    $prevState['timestamp'] = date('c');
    $prevState['disabled']  = true;
    $prevState['enabled']   = false;
    file_put_contents(STATE_FILE, json_encode($prevState, JSON_UNESCAPED_UNICODE));
    @chmod(STATE_FILE, 0666);
    tickLog('surveillance désactivée (api/config.php) — skip tick');
    exit(0);
}

// ====== BBOX (légèrement plus grande que le rayon pour ne rien rater) ======
$rayonBBox = max(2000, $RAYON);
$dLat = $rayonBBox / 111320;
$dLon = $rayonBBox / (111320 * cos($LAT * M_PI / 180));
$minLat = $LAT - $dLat; $maxLat = $LAT + $dLat;
$minLon = $LON - $dLon; $maxLon = $LON + $dLon;

// ====== APPEL EURIS ======
$eurisUrl = sprintf(
    'https://www.eurisportal.eu/visuris/api/TracksV2/GetTracksByBBoxV2?minLat=%.6f&maxLat=%.6f&minLon=%.6f&maxLon=%.6f&pageSize=%d',
    $minLat, $maxLat, $minLon, $maxLon, $BBOX_PAGE
);
$ch = curl_init($eurisUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Authorization: Bearer ' . $EURIS_TOKEN,
    ],
]);
$t0 = microtime(true);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);
$eurisMs = (int) round((microtime(true) - $t0) * 1000);

if ($resp === false || $curlErr !== '') {
    tickLog("EuRIS réseau ÉCHEC ({$eurisMs}ms): {$curlErr}");
    exit(1);
}
if ($httpCode < 200 || $httpCode >= 300) {
    tickLog("EuRIS HTTP {$httpCode} ({$eurisMs}ms)");
    exit(1);
}

$data   = json_decode($resp, true);
$source = is_array($data) ? ($data['items'] ?? $data) : [];
if (!is_array($source)) $source = [];

// ====== DISTANCE ET FILTRE ======
$ships     = [];   // tous les navires avec position (pour la carte)
$proches   = [];   // ≤ rayon
$plusProche = null;
foreach ($source as $t) {
    $nlat = $t['latitude'] ?? $t['lat'] ?? $t['Latitude'] ?? null;
    $nlon = $t['longitude'] ?? $t['lon'] ?? $t['Longitude'] ?? null;
    if ($nlat === null || $nlon === null) continue;
    $d = (int) round(haversine($LAT, $LON, (float) $nlat, (float) $nlon));
    $info = [
        'name'     => $t['shipName'] ?? $t['vesselName'] ?? $t['ShipName'] ?? null,
        'mmsi'     => $t['mmsi']     ?? $t['MMSI']       ?? null,
        'lat'      => (float) $nlat,
        'lon'      => (float) $nlon,
        'distance' => $d,
        'speed'    => $t['speed']  ?? $t['SOG'] ?? null,
        'course'   => $t['course'] ?? $t['COG'] ?? null,
    ];
    $ships[] = $info;
    if ($plusProche === null || $d < $plusProche['distance']) $plusProche = $info;
    if ($d <= $RAYON) $proches[] = $info;
}
usort($proches, fn($a, $b) => $a['distance'] - $b['distance']);
usort($ships, fn($a, $b) => $a['distance'] - $b['distance']);

// ====== ÉTAT PRÉCÉDENT ======
$prev = null;
if (is_file(STATE_FILE)) {
    $prev = @json_decode(@file_get_contents(STATE_FILE), true);
}
$prevActive = $prev !== null ? (bool) ($prev['alert_active'] ?? false) : null;
$nowActive  = count($proches) > 0;

// ====== SHELLY : transition OU resynchronisation après redémarrage ======
$shellyAction = null;
$shellyResult = null;
if ($prevActive === null || $nowActive !== $prevActive) {
    $shellyAction = $nowActive ? 'on' : 'off';
    $shellyResult = shellySet($SHELLY_HOST, $SHELLY_KEY, $SHELLY_ID, $nowActive);
}

// ====== PERSIST STATE ======
$state = [
    'timestamp'      => date('c'),
    'enabled'        => true,
    'disabled'       => false,
    'alert_active'   => $nowActive,
    'ships_total'    => count($ships),
    'ships_in_range' => count($proches),
    'closest_ship'   => $plusProche,
    'ships'          => $ships,
    'base'           => ['lat' => $LAT, 'lon' => $LON],
    'radius_m'       => $RAYON,
    'euris_ms'       => $eurisMs,
    'shelly_action'  => $shellyAction,
    'shelly_result'  => $shellyResult,
];
file_put_contents(STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE));
@chmod(STATE_FILE, 0666);

// ====== LOG COMPACT ======
$procheTxt = $plusProche
    ? sprintf(' | + proche %s @ %dm', $plusProche['name'] ?? '???', $plusProche['distance'])
    : '';
$shellyTxt = $shellyAction
    ? sprintf(' | Shelly %s HTTP %s', strtoupper($shellyAction), $shellyResult['http'] ?? '?')
    : '';
tickLog(sprintf(
    'scan %dms %d navires, %d ≤ %dm%s%s',
    $eurisMs, count($ships), count($proches), $RAYON, $procheTxt, $shellyTxt
));
