<?php
// api/euris-proxy.php
// Backend adapté pour trackship2.bakabi.fr avec trackID

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Gestion preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Récupération du token
$token = null;
$headers = getallheaders();

if (isset($headers['Authorization']) && strpos($headers['Authorization'], 'Bearer ') === 0) {
    $token = substr($headers['Authorization'], 7);
} else {
    http_response_code(401);
    exit(json_encode([
        'error' => 'Authorization header required',
        'message' => 'Le header Authorization avec un token Bearer est requis'
    ]));
}

// Récupération des paramètres
$minLat = $_GET['minLat'] ?? null;
$maxLat = $_GET['maxLat'] ?? null;
$minLon = $_GET['minLon'] ?? null;
$maxLon = $_GET['maxLon'] ?? null;
$pageSize = $_GET['pageSize'] ?? 100;

// Validation des paramètres
if (!$minLat || !$maxLat || !$minLon || !$maxLon) {
    http_response_code(400);
    exit(json_encode([
        'error' => 'Missing required parameters',
        'required' => ['minLat', 'maxLat', 'minLon', 'maxLon']
    ]));
}

// Construction URL EuRIS
$eurisUrl = sprintf(
    'https://www.eurisportal.eu/visuris/api/TracksV2/GetTracksByBBoxV2?minLat=%.6f&maxLat=%.6f&minLon=%.6f&maxLon=%.6f&pageSize=%d',
    floatval($minLat),
    floatval($maxLat),
    floatval($minLon),
    floatval($maxLon),
    intval($pageSize)
);

// Appel à l'API EuRIS
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $eurisUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'User-Agent: TrackShip/1.0 (trackship.bakabi.fr)'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    exit(json_encode([
        'error' => 'Erreur de connexion à l\'API EuRIS',
        'message' => $error
    ]));
}

if ($httpCode !== 200) {
    // Messages d'erreur spécifiques
    $errorMessages = [
        401 => 'Token d\'authentification invalide ou expiré',
        403 => 'Accès interdit - permissions insuffisantes',
        404 => 'Service EuRIS non trouvé',
        429 => 'Trop de requêtes - attendez avant de réessayer',
        500 => 'Service EuRIS temporairement indisponible'
    ];
    
    http_response_code($httpCode);
    exit(json_encode([
        'error' => $errorMessages[$httpCode] ?? 'Erreur API EuRIS',
        'httpStatus' => $httpCode
    ]));
}

// Validation JSON
$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    exit(json_encode([
        'error' => 'Réponse invalide de l\'API EuRIS',
        'message' => json_last_error_msg()
    ]));
}

// L'API retourne { "items": [...], "count": N } depuis la mise à jour EuRIS
$tracks = [];
if (is_array($data)) {
    if (isset($data['items']) && is_array($data['items'])) {
        $tracks = $data['items'];
    } elseif (!isset($data['items'])) {
        // Ancien format : tableau direct
        $tracks = $data;
    }
}

// Normalisation des propriétés en utilisant les vrais champs de l'API
$tracksNormalisees = array_map(function($track) {
    // Extraction des coordonnées
    $lat = isset($track['lat']) ? floatval($track['lat']) : null;
    $lon = isset($track['lon']) ? floatval($track['lon']) : null;
    
    // Utilisation de trackID comme identifiant principal
    $trackId = $track['trackID'] ?? null;
    
    // Statut de mouvement basé sur le champ "moving"
    $enMouvement = isset($track['moving']) ? $track['moving'] : null;
    
    // Vitesse (SOG = Speed Over Ground)
    $vitesse = isset($track['sog']) ? floatval($track['sog']) : null;
    
    // Cap (COG = Course Over Ground)
    $cap = isset($track['cog']) ? floatval($track['cog']) : null;
    
    // Dimensions
    $longueur = isset($track['inlen']) ? floatval($track['inlen']) : null;
    $largeur = isset($track['inbm']) ? floatval($track['inbm']) : null;
    
    // Position fluviale
    $positionISRS = $track['positionISRS'] ?? null;
    $positionName = $track['positionISRSName'] ?? null;
    
    // Statut (st: 1 = en mouvement, 2 = à l'arrêt)
    $statut = isset($track['st']) ? intval($track['st']) : null;
    
    return [
        // Identifiants
        'trackId' => $trackId,
        'mmsi' => $trackId, // On utilise trackID comme MMSI pour compatibilité frontend
        'name' => $track['name'] ?? "Track $trackId",
        'shipName' => $track['name'] ?? "Track $trackId",
        
        // Position
        'latitude' => $lat,
        'longitude' => $lon,
        'positionISRS' => $positionISRS,
        'positionName' => $positionName,
        
        // Mouvement
        'speed' => $vitesse,
        'course' => $cap,
        'moving' => $enMouvement,
        'status' => $statut,
        
        // Dimensions
        'length' => $longueur,
        'width' => $largeur,
        
        // Dimensions détaillées
        'dimA' => isset($track['dimA']) ? intval($track['dimA']) : null,
        'dimB' => isset($track['dimB']) ? intval($track['dimB']) : null,
        'dimC' => isset($track['dimC']) ? intval($track['dimC']) : null,
        'dimD' => isset($track['dimD']) ? intval($track['dimD']) : null,
        
        // Type de navire (non fourni par l'API)
        'shipType' => 'Navire fluvial',
        
        // Timestamp
        'timestamp' => $track['posTS'] ?? null,
        
        // Données originales pour debug
        '_original' => $track
    ];
}, $tracks);

// Filtrage des navires avec coordonnées valides
$tracksValides = array_filter($tracksNormalisees, function($track) {
    return $track['latitude'] !== null && 
           $track['longitude'] !== null &&
           $track['trackId'] !== null;
});

// Réindexation du tableau
$tracksValides = array_values($tracksValides);

// Réponse finale
echo json_encode([
    'tracks' => $tracksValides,
    '_metadata' => [
        'timestamp' => date('c'),
        'source' => 'EuRIS API via trackship.bakabi.fr',
        'trackCount' => count($tracksValides),
        'totalReceived' => count($tracks),
        'validTracks' => count($tracksValides)
    ]
]);
?>
