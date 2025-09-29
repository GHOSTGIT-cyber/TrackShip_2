<?php
/**
 * api/test-tapo.php - Test complet de la connexion Tapo
 * Visitez : https://alerte.bakabi.fr/api/test-tapo.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php';

use Kuhschnappel\TapoApi\Tapo;

header('Content-Type: text/html; charset=utf-8');

// Configuration
$DEVICE_IP = getenv('TAPO_DEVICE_IP') ?: '192.168.1.100';
$TAPO_EMAIL = getenv('TAPO_EMAIL') ?: 'bakabi06@gmail.com';
$TAPO_PASSWORD = getenv('TAPO_PASSWORD') ?: 'efoilfrance62';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Tapo - Debug Complet</title>
    <style>
        body { 
            font-family: monospace; 
            background: #1e1e1e; 
            color: #d4d4d4; 
            padding: 20px;
            line-height: 1.6;
        }
        .section { 
            background: #252526; 
            padding: 15px; 
            margin: 10px 0; 
            border-left: 4px solid #007acc;
            border-radius: 4px;
        }
        .success { border-left-color: #4ec9b0; }
        .error { border-left-color: #f48771; }
        .warning { border-left-color: #dcdcaa; }
        h2 { color: #569cd6; margin-top: 0; }
        pre { 
            background: #1e1e1e; 
            padding: 10px; 
            overflow-x: auto;
            border-radius: 4px;
        }
        .label { color: #9cdcfe; }
        .value { color: #ce9178; }
        button {
            background: #0e639c;
            color: white;
            border: none;
            padding: 10px 20px;
            margin: 5px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 14px;
        }
        button:hover { background: #1177bb; }
        .test-on { background: #28a745; }
        .test-off { background: #dc3545; }
    </style>
</head>
<body>
    <h1>🔌 Test Tapo - Diagnostic Complet</h1>

    <div class="section success">
        <h2>✅ 1. Configuration</h2>
        <div><span class="label">Device IP:</span> <span class="value"><?= htmlspecialchars($DEVICE_IP) ?></span></div>
        <div><span class="label">Email:</span> <span class="value"><?= htmlspecialchars($TAPO_EMAIL) ?></span></div>
        <div><span class="label">Password Length:</span> <span class="value"><?= strlen($TAPO_PASSWORD) ?> caractères</span></div>
        <div><span class="label">Full URL:</span> <span class="value">http://<?= htmlspecialchars($DEVICE_IP) ?></span></div>
    </div>

    <div class="section">
        <h2>🧪 2. Test de Connectivité Réseau</h2>
        <?php
        $url = "http://{$DEVICE_IP}";
        echo "<div><span class='label'>Test ping HTTP vers:</span> <span class='value'>$url</span></div>";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        if ($curlErrno === 0 || $httpCode > 0) {
            echo "<div class='success'>✅ Serveur accessible (HTTP code: $httpCode)</div>";
        } else {
            echo "<div class='error'>❌ Erreur de connexion: $curlError (errno: $curlErrno)</div>";
            echo "<div class='warning'>⚠️ Le serveur Hostinger ne peut probablement pas accéder à votre réseau local 192.168.x.x</div>";
        }
        ?>
    </div>

    <div class="section">
        <h2>🔐 3. Test Bibliothèque Tapo</h2>
        <?php
        echo "<div><span class='label'>Classe Tapo existe:</span> ";
        if (class_exists('Kuhschnappel\TapoApi\Tapo')) {
            echo "<span class='value'>✅ OUI</span></div>";
        } else {
            echo "<span class='error'>❌ NON</span></div>";
            die("</div></body></html>");
        }
        ?>
    </div>

    <div class="section">
        <h2>🚀 4. Test Connexion à l'Appareil</h2>
        <?php
        try {
            echo "<div>📡 Tentative de connexion...</div>";
            
            $device = new Tapo($TAPO_EMAIL, $TAPO_PASSWORD, "http://{$DEVICE_IP}");
            
            echo "<div class='success'>✅ Objet Tapo créé avec succès</div>";
            
            // Essayer de récupérer les infos (si la bibliothèque le permet)
            try {
                echo "<div>📊 Récupération des informations de l'appareil...</div>";
                
                // Test simple : essayer d'allumer
                echo "<div>🔴 Test: Tentative d'allumage...</div>";
                $device->setPowerOn();
                $device->sendChangedSettings();
                
                echo "<div class='success'>✅ Commande ALLUMER envoyée avec succès !</div>";
                
                sleep(2);
                
                // Puis éteindre
                echo "<div>⚪ Test: Tentative d'extinction...</div>";
                $device->setPowerOff();
                $device->sendChangedSettings();
                
                echo "<div class='success'>✅ Commande ÉTEINDRE envoyée avec succès !</div>";
                
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erreur lors du test: " . htmlspecialchars($e->getMessage()) . "</div>";
                echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            }
            
        } catch (Exception $e) {
            echo "<div class='error'>❌ Erreur de connexion: " . htmlspecialchars($e->getMessage()) . "</div>";
            echo "<div class='warning'>Détails de l'erreur:</div>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        }
        ?>
    </div>

    <div class="section">
        <h2>🎮 5. Tests Manuels</h2>
        <div>Cliquez sur les boutons ci-dessous pour tester manuellement :</div>
        <br>
        <button class="test-on" onclick="test('on')">🔴 ALLUMER</button>
        <button class="test-off" onclick="test('off')">⚪ ÉTEINDRE</button>
        <button onclick="test('on', 5)">⏰ TEST 5 secondes</button>
        <br><br>
        <div id="result"></div>
    </div>

    <div class="section warning">
        <h2>⚠️ Points de Vérification</h2>
        <ul>
            <li>✅ La prise Tapo est-elle sur le <strong>même réseau</strong> que l'IP configurée ?</li>
            <li>✅ Pouvez-vous pinguer <?= $DEVICE_IP ?> depuis un appareil sur votre réseau local ?</li>
            <li>❌ Le serveur Hostinger (sur Internet) ne peut <strong>PAS</strong> accéder à votre réseau local 192.168.x.x</li>
            <li>💡 <strong>Solution :</strong> Exposer la prise via Port Forwarding sur votre box Internet</li>
        </ul>
    </div>

    <div class="section">
        <h2>🔧 Configuration Requise</h2>
        <ol>
            <li>Sur votre <strong>box Internet</strong>, configurez le <strong>Port Forwarding</strong> :
                <ul>
                    <li>Port externe : <code>8080</code></li>
                    <li>Port interne : <code>80</code></li>
                    <li>IP destination : <code><?= $DEVICE_IP ?></code></li>
                </ul>
            </li>
            <li>Trouvez votre <strong>IP publique</strong> : <a href="https://www.whatismyip.com" target="_blank">whatismyip.com</a></li>
            <li>Dans votre code, utilisez : <code>VOTRE_IP_PUBLIQUE:8080</code></li>
        </ol>
    </div>

    <script>
        function test(action, duree) {
            const result = document.getElementById('result');
            result.innerHTML = '<div style="padding:10px;background:#dcdcaa;color:#1e1e1e;">⏳ Test en cours...</div>';
            
            const payload = { action };
            if (duree) payload.duree = duree;
            
            fetch('/api/tapo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                console.log('Réponse:', data);
                if (data.ok) {
                    result.innerHTML = `<div style="padding:10px;background:#4ec9b0;color:#1e1e1e;">✅ ${data.message}</div>`;
                } else {
                    result.innerHTML = `<div style="padding:10px;background:#f48771;color:#1e1e1e;">❌ ${data.error}</div>`;
                }
            })
            .catch(err => {
                result.innerHTML = `<div style="padding:10px;background:#f48771;color:#1e1e1e;">❌ Erreur: ${err.message}</div>`;
            });
        }
    </script>
</body>
</html>