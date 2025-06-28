// netlify/functions/euris-proxy.js
// Fonction serverless pour Netlify

exports.handler = async (event, context) => {
  // Headers CORS
  const headers = {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Headers': 'Content-Type',
    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    'Content-Type': 'application/json'
  };

  // Gestion des requêtes OPTIONS (preflight)
  if (event.httpMethod === 'OPTIONS') {
    return {
      statusCode: 200,
      headers,
      body: ''
    };
  }

  // Récupération des paramètres
  const { minLat, maxLat, minLon, maxLon, pageSize = 100, token } = event.queryStringParameters || {};

  // Validation des paramètres
  if (!minLat || !maxLat || !minLon || !maxLon || !token) {
    return {
      statusCode: 400,
      headers,
      body: JSON.stringify({
        error: 'Missing parameters',
        required: ['minLat', 'maxLat', 'minLon', 'maxLon', 'token']
      })
    };
  }

  // Construction de l'URL EuRIS
  const eurisUrl = `https://www.eurisportal.eu/visuris/api/TracksV2/GetTracksByBBoxV2?minLat=${minLat}&maxLat=${maxLat}&minLon=${minLon}&maxLon=${maxLon}&pageSize=${pageSize}`;

  try {
    // Appel à l'API EuRIS
    const response = await fetch(eurisUrl, {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
        'User-Agent': 'Mozilla/5.0 (compatible; Netlify-EuRIS-Proxy/1.0)'
      }
    });

    if (!response.ok) {
      throw new Error(`EuRIS API error: ${response.status} ${response.statusText}`);
    }

    const data = await response.json();

    return {
      statusCode: 200,
      headers,
      body: JSON.stringify(data)
    };

  } catch (error) {
    console.error('Proxy error:', error);
    
    return {
      statusCode: 502,
      headers,
      body: JSON.stringify({
        error: 'Failed to fetch from EuRIS API',
        message: error.message,
        timestamp: new Date().toISOString()
      })
    };
  }
}; 
//test de git de develop