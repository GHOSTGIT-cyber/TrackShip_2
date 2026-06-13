<?php
/**
 * api/auth.php — Mot de passe simple sur les endpoints de commande.
 * La page envoie le header X-Page-Auth ; comparé à PAGE_PASSWORD (env Coolify).
 * Les endpoints de lecture (state, status) restent publics.
 */

function requirePageAuth(): void
{
    $expected = getenv('PAGE_PASSWORD') ?: 'bakabi06';
    $got = $_SERVER['HTTP_X_PAGE_AUTH'] ?? '';
    if (!is_string($got) || !hash_equals($expected, $got)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Mot de passe requis ou invalide']);
        exit;
    }
}
