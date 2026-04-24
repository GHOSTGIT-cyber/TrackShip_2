<?php
/**
 * api/tapo.php - Proxy vers PythonAnywhere
 * 
 * Ce fichier transmet simplement les commandes à votre serveur Flask
 * qui gère la prise Tapo avec la librairie PyP100.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: https://alerte.bakabi.fr');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  echo json_encode(['ok' => true]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
  exit;
}

// ====== CONFIGURATION ======
// ⚠️ REMPLACEZ 'votreusername' par votre vrai username PythonAnywhere
$PYTHONANYWHERE_URL = 'https://ghost6.pythonanywhere.com/api/tapo';

// Sécurité optionnelle : clé partagée entre PHP et Flask
$API_SECRET = getenv('TAPO_API_SECRET') ?: 'votre-secret-partage-123';

// ====== LECTURE DE LA REQUÊTE ======
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$action = $body['action'] ?? null;
$duree = isset($body['duree']) ? intval($body['duree']) : null;

if (!in_array($action, ['on', 'off'], true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => "Action requise: 'on' ou 'off'"]);
  exit;
}

// ====== APPEL À PYTHONANYWHERE ======
try {
  $ch = curl_init($PYTHONANYWHERE_URL);
  
  $payload = ['action' => $action];
  if ($duree) $payload['duree'] = $duree;
  
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'X-API-Secret: ' . $API_SECRET  // Sécurité
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true
  ]);
  
  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  
  // Gestion des erreurs
  if ($error) {
    throw new Exception("Erreur réseau: $error");
  }
  
  if ($httpCode < 200 || $httpCode >= 300) {
    $errorMsg = @json_decode($response, true)['error'] ?? $response;
    throw new Exception("Erreur serveur ($httpCode): $errorMsg");
  }
  
  // Succès : renvoyer la réponse de Flask
  http_response_code($httpCode);
  echo $response;
  
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false, 
    'error' => $e->getMessage(),
    'hint' => 'Vérifiez que PythonAnywhere est accessible et configuré'
  ]);
}