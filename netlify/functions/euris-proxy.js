// netlify/functions/euris-proxy.js
exports.handler = async (event, context) => {
    console.log('🚀 Démarrage du proxy EuRIS');
    console.log('📝 Méthode HTTP:', event.httpMethod);
    console.log('📝 Paramètres reçus:', event.queryStringParameters);
    console.log('📝 Headers reçus:', event.headers);

    // Headers CORS pour toutes les réponses
    const headers = {
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Headers': 'Content-Type, Authorization',
        'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
        'Content-Type': 'application/json',
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache',
        'Expires': '0'
    };

    // Gestion des requêtes OPTIONS (preflight CORS)
    if (event.httpMethod === 'OPTIONS') {
        console.log('✅ Requête OPTIONS (preflight) - Réponse CORS');
        return {
            statusCode: 200,
            headers,
            body: JSON.stringify({ message: 'CORS preflight OK' })
        };
    }

    // Vérification méthode GET uniquement
    if (event.httpMethod !== 'GET') {
        console.log('❌ Méthode HTTP non autorisée:', event.httpMethod);
        return {
            statusCode: 405,
            headers,
            body: JSON.stringify({
                error: 'Method Not Allowed',
                message: 'Seules les requêtes GET sont autorisées',
                allowedMethods: ['GET', 'OPTIONS']
            })
        };
    }

    // Récupération du token depuis les headers Authorization
    let token = null;
    const authHeader = event.headers.authorization || event.headers.Authorization;
    
    if (authHeader && authHeader.startsWith('Bearer ')) {
        token = authHeader.substring(7); // Supprime "Bearer "
        console.log('🔑 Token récupéré depuis les headers Authorization');
    } else {
        console.log('❌ Header Authorization manquant ou invalide');
        return {
            statusCode: 401,
            headers,
            body: JSON.stringify({
                error: 'Authorization header required',
                message: 'Le header Authorization avec un token Bearer est requis',
                receivedHeaders: Object.keys(event.headers),
                example: 'Authorization: Bearer YOUR_TOKEN_HERE'
            })
        };
    }

    // Récupération et validation des paramètres
    const params = event.queryStringParameters || {};
    const { minLat, maxLat, minLon, maxLon, pageSize = 100 } = params;

    console.log('🔍 Validation des paramètres...');

    // Vérification des paramètres obligatoires
    if (!minLat || !maxLat || !minLon || !maxLon) {
        console.log('❌ Paramètres manquants');
        return {
            statusCode: 400,
            headers,
            body: JSON.stringify({
                error: 'Missing required parameters',
                message: 'Les paramètres minLat, maxLat, minLon, maxLon sont obligatoires',
                required: ['minLat', 'maxLat', 'minLon', 'maxLon'],
                received: Object.keys(params)
            })
        };
    }

    // Validation et conversion des coordonnées
    const coordinates = {
        minLat: parseFloat(minLat),
        maxLat: parseFloat(maxLat),
        minLon: parseFloat(minLon),
        maxLon: parseFloat(maxLon)
    };

    // Vérification que les coordonnées sont des nombres valides
    for (const [key, value] of Object.entries(coordinates)) {
        if (isNaN(value)) {
            console.log(`❌ Coordonnée invalide: ${key} = ${params[key.replace('coordinates.', '')]}`);
            return {
                statusCode: 400,
                headers,
                body: JSON.stringify({
                    error: 'Invalid coordinates',
                    message: `La coordonnée ${key} doit être un nombre valide`,
                    invalidValue: params[key],
                    coordinates: params
                })
            };
        }
    }

    // Validation des limites géographiques
    if (coordinates.minLat < -90 || coordinates.maxLat > 90 || coordinates.minLat >= coordinates.maxLat) {
        console.log('❌ Latitude invalide');
        return {
            statusCode: 400,
            headers,
            body: JSON.stringify({
                error: 'Invalid latitude range',
                message: 'Les latitudes doivent être entre -90 et 90, et minLat < maxLat',
                received: { minLat: coordinates.minLat, maxLat: coordinates.maxLat }
            })
        };
    }

    if (coordinates.minLon < -180 || coordinates.maxLon > 180 || coordinates.minLon >= coordinates.maxLon) {
        console.log('❌ Longitude invalide');
        return {
            statusCode: 400,
            headers,
            body: JSON.stringify({
                error: 'Invalid longitude range',
                message: 'Les longitudes doivent être entre -180 et 180, et minLon < maxLon',
                received: { minLon: coordinates.minLon, maxLon: coordinates.maxLon }
            })
        };
    }

    // Validation de la taille de page
    const pageSizeNum = parseInt(pageSize);
    if (isNaN(pageSizeNum) || pageSizeNum < 1 || pageSizeNum > 1000) {
        console.log('❌ Taille de page invalide:', pageSize);
        return {
            statusCode: 400,
            headers,
            body: JSON.stringify({
                error: 'Invalid page size',
                message: 'La taille de page doit être un nombre entre 1 et 1000',
                received: pageSize
            })
        };
    }

    // Construction de l'URL EuRIS avec les coordonnées validées
    const eurisUrl = `https://www.eurisportal.eu/visuris/api/TracksV2/GetTracksByBBoxV2?minLat=${coordinates.minLat}&maxLat=${coordinates.maxLat}&minLon=${coordinates.minLon}&maxLon=${coordinates.maxLon}&pageSize=${pageSizeNum}`;
    
    console.log('🌐 URL EuRIS construite:', eurisUrl);
    console.log('🔑 Token fourni (premiers caractères):', token.substring(0, 10) + '...');

    try {
        console.log('📡 Appel à l\'API EuRIS en cours...');
        
        // Import dynamique de node-fetch pour les environnements Netlify
        let fetch;
        try {
            fetch = (await import('node-fetch')).default;
        } catch (e) {
            // Fallback vers fetch global si disponible
            fetch = globalThis.fetch;
        }
        
        if (!fetch) {
            throw new Error('fetch non disponible dans cet environnement');
        }
        
        // Configuration de la requête vers EuRIS
        const fetchOptions = {
            method: 'GET',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Accept': 'application/json',
                'User-Agent': 'Mozilla/5.0 (compatible; Netlify-EuRIS-Proxy/2.0; Surveillance-Navires)',
                'Content-Type': 'application/json'
            }
        };

        // Appel à l'API EuRIS avec timeout
        console.log('📡 Envoi de la requête vers EuRIS...');
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000); // Timeout de 30 secondes
        
        const response = await fetch(eurisUrl, {
            ...fetchOptions,
            signal: controller.signal
        });
        
        clearTimeout(timeoutId);
        
        console.log('📊 Réponse EuRIS - Status:', response.status);
        console.log('📊 Réponse EuRIS - StatusText:', response.statusText);

        // Lecture du contenu de la réponse
        const responseText = await response.text();
        console.log('📊 Taille de la réponse:', responseText.length, 'caractères');
        console.log('📊 Début de la réponse:', responseText.substring(0, 200));

        // Gestion des erreurs HTTP
        if (!response.ok) {
            let errorMessage = `EuRIS API error: ${response.status} ${response.statusText}`;
            let errorDetails = '';
            
            // Messages d'erreur spécifiques selon le code de statut
            switch (response.status) {
                case 401:
                    errorMessage = 'Token d\'authentification invalide ou expiré';
                    errorDetails = 'Vérifiez que votre token EuRIS est valide et non expiré';
                    break;
                case 403:
                    errorMessage = 'Accès interdit - permissions insuffisantes';
                    errorDetails = 'Votre token n\'a pas les permissions nécessaires pour accéder à cette ressource';
                    break;
                case 404:
                    errorMessage = 'Endpoint API non trouvé';
                    errorDetails = 'L\'URL de l\'API EuRIS semble incorrecte ou l\'endpoint n\'existe plus';
                    break;
                case 429:
                    errorMessage = 'Limite de taux d\'appels dépassée';
                    errorDetails = 'Trop de requêtes envoyées. Attendez avant de réessayer';
                    break;
                case 500:
                    errorMessage = 'Erreur interne du serveur EuRIS';
                    errorDetails = 'Le serveur EuRIS rencontre un problème technique';
                    break;
                case 502:
                case 503:
                case 504:
                    errorMessage = 'Service EuRIS temporairement indisponible';
                    errorDetails = 'Le service EuRIS est en maintenance ou surchargé';
                    break;
                default:
                    errorDetails = 'Erreur inconnue de l\'API EuRIS';
            }

            console.log('❌ Erreur API EuRIS:', errorMessage);
            console.log('❌ Réponse d\'erreur:', responseText);

            return {
                statusCode: response.status,
                headers,
                body: JSON.stringify({
                    error: errorMessage,
                    details: errorDetails,
                    httpStatus: response.status,
                    apiResponse: responseText,
                    timestamp: new Date().toISOString(),
                    requestUrl: eurisUrl.replace(token, '[TOKEN_HIDDEN]')
                })
            };
        }

        // Parsing de la réponse JSON
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            console.error('❌ Erreur de parsing JSON:', parseError);
            console.log('📊 Réponse brute:', responseText);
            
            return {
                statusCode: 500,
                headers,
                body: JSON.stringify({
                    error: 'Invalid JSON response from EuRIS API',
                    message: 'La réponse de l\'API EuRIS n\'est pas au format JSON valide',
                    parseError: parseError.message,
                    responsePreview: responseText.substring(0, 500),
                    timestamp: new Date().toISOString()
                })
            };
        }
        
        console.log('✅ Données EuRIS récupérées avec succès');
        console.log('📊 Structure des données reçues:', Object.keys(data));
        
        // Validation et extraction des tracks
        let tracks = [];
        if (data.tracks && Array.isArray(data.tracks)) {
            tracks = data.tracks;
        } else if (Array.isArray(data)) {
            tracks = data;
        } else if (data.data && Array.isArray(data.data)) {
            tracks = data.data;
        } else if (data.result && Array.isArray(data.result)) {
            tracks = data.result;
        } else {
            console.warn('⚠️ Structure de données inattendue, recherche de tableaux...');
            // Recherche récursive de tableaux dans la réponse
            for (const [key, value] of Object.entries(data)) {
                if (Array.isArray(value) && value.length > 0) {
                    console.log(`📊 Tableau trouvé dans data.${key}:`, value.length, 'éléments');
                    tracks = value;
                    break;
                }
            }
        }

        console.log('📊 Nombre de tracks extraites:', tracks.length);
        
        if (tracks.length > 0) {
            console.log('📊 Premier track (exemple):', JSON.stringify(tracks[0], null, 2));
            
            // Validation des propriétés des tracks
            const premierTrack = tracks[0];
            const proprietes = Object.keys(premierTrack);
            console.log('📊 Propriétés disponibles dans les tracks:', proprietes);
            
            // Vérification des coordonnées
            if (!premierTrack.latitude && !premierTrack.lat && !premierTrack.Latitude) {
                console.warn('⚠️ Aucune propriété de latitude trouvée dans les tracks');
            }
            if (!premierTrack.longitude && !premierTrack.lon && !premierTrack.Longitude) {
                console.warn('⚠️ Aucune propriété de longitude trouvée dans les tracks');
            }
        }

        // Normalisation des données pour assurer la compatibilité
        const tracksNormalisees = tracks.map(track => {
            // Normalisation des noms de propriétés
            const trackNormalise = {
                // Coordonnées (plusieurs formats possibles)
                latitude: track.latitude || track.lat || track.Latitude || track.LAT,
                longitude: track.longitude || track.lon || track.Longitude || track.LON,
                
                // Informations du navire
                mmsi: track.mmsi || track.MMSI || track.id,
                shipName: track.shipName || track.ship_name || track.name || track.vesselName || track.NAME,
                shipType: track.shipType || track.ship_type || track.type || track.vesselType || track.TYPE,
                
                // Vitesse et navigation
                speed: track.speed || track.Speed || track.SOG || track.sog,
                course: track.course || track.Course || track.COG || track.cog,
                heading: track.heading || track.Heading || track.HDG || track.hdg,
                
                // Dimensions
                length: track.length || track.Length || track.LOA || track.loa,
                width: track.width || track.Width || track.beam || track.BEAM,
                
                // Statut et temps
                status: track.status || track.Status || track.navStatus,
                timestamp: track.timestamp || track.Timestamp || track.time || track.lastUpdate,
                
                // Données brutes pour débogage
                _original: track
            };
            
            return trackNormalise;
        });

        console.log('📊 Tracks normalisées:', tracksNormalisees.length);

        // Retour des données avec métadonnées enrichies
        const response_data = {
            tracks: tracksNormalisees,
            _metadata: {
                timestamp: new Date().toISOString(),
                source: 'EuRIS API via Netlify Proxy',
                originalTrackCount: tracks.length,
                normalizedTrackCount: tracksNormalisees.length,
                requestParams: {
                    bbox: coordinates,
                    pageSize: pageSizeNum
                },
                dataStructure: Object.keys(data),
                apiResponseSize: responseText.length,
                processingTime: Date.now()
            }
        };

        return {
            statusCode: 200,
            headers,
            body: JSON.stringify(response_data)
        };

    } catch (error) {
        console.error('❌ Erreur lors de l\'appel EuRIS:', error);
        
        // Gestion des différents types d'erreurs
        let errorType = 'Unknown error';
        let errorMessage = error.message;
        let statusCode = 500;
        
        if (error.name === 'AbortError') {
            errorType = 'Timeout error';
            errorMessage = 'L\'API EuRIS a mis trop de temps à répondre (timeout 30s)';
            statusCode = 504;
        } else if (error.name === 'TypeError' && error.message.includes('fetch')) {
            errorType = 'Network error';
            errorMessage = 'Impossible de contacter l\'API EuRIS. Vérifiez la connectivité réseau.';
            statusCode = 503;
        } else if (error.name === 'SyntaxError') {
            errorType = 'JSON parsing error';
            errorMessage = 'La réponse de l\'API EuRIS n\'est pas au format JSON valide.';
            statusCode = 502;
        } else if (error.code === 'ENOTFOUND') {
            errorType = 'DNS error';
            errorMessage = 'Impossible de résoudre le nom de domaine de l\'API EuRIS.';
            statusCode = 503;
        } else if (error.code === 'ECONNREFUSED') {
            errorType = 'Connection refused';
            errorMessage = 'La connexion à l\'API EuRIS a été refusée.';
            statusCode = 503;
        } else if (error.code === 'ETIMEDOUT') {
            errorType = 'Timeout error';
            errorMessage = 'L\'API EuRIS a mis trop de temps à répondre.';
            statusCode = 504;
        }

        return {
            statusCode: statusCode,
            headers,
            body: JSON.stringify({
                error: 'Internal proxy error',
                type: errorType,
                message: errorMessage,
                details: error.message,
                stack: process.env.NODE_ENV === 'development' ? error.stack : undefined,
                timestamp: new Date().toISOString(),
                requestUrl: eurisUrl.replace(token, '[TOKEN_HIDDEN]'),
                errorCode: error.code || 'UNKNOWN'
            })
        };
    }
};