<?php
/**
 * api/state.php — Expose l'état du dernier tick autonome (cron/tick.php).
 * Lecture seule, utilisé par index.html pour afficher la carte live.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://alerte.bakabi.fr');
header('Cache-Control: no-cache, no-store, must-revalidate');

$file = __DIR__ . '/../cron/data/state.json';

if (!is_file($file)) {
    http_response_code(404);
    echo json_encode([
        'ok'    => false,
        'error' => 'Aucun tick enregistré',
        'hint'  => 'Le cron Coolify n\'a pas encore tourné, ou la commande échoue',
    ]);
    exit;
}

$raw = @file_get_contents($file);
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'state.json invalide']);
    exit;
}

$age = time() - @filemtime($file);
$decoded['_age_seconds'] = $age;
$decoded['_stale']       = $age > 90; // > 90s → cron probablement HS
$decoded['ok']           = true;

echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
