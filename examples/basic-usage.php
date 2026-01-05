<?php
/**
 * Basic Usage Example - StoneScriptDB Gateway Client
 *
 * This example demonstrates:
 * - Creating a gateway client
 * - Calling PostgreSQL functions
 * - Basic error handling
 */

require __DIR__ . '/../vendor/autoload.php';

use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;

// Configuration
$gatewayUrl = getenv('GATEWAY_URL') ?: 'http://localhost:9000';
$platform = getenv('PLATFORM') ?: 'myapp';

echo "=== StoneScriptDB Gateway Client - Basic Usage ===\n\n";

// Create client
$client = new GatewayClient($gatewayUrl, $platform);

// Health check
echo "1. Health Check\n";
if ($client->healthCheck()) {
    echo "   ✓ Gateway is healthy\n\n";
} else {
    echo "   ✗ Gateway unavailable: " . $client->getLastError() . "\n\n";
    exit(1);
}

// Call a function
echo "2. Calling PostgreSQL Function\n";
try {
    $result = $client->callFunction('get_users', [
        'limit' => 5
    ]);

    echo "   ✓ Function called successfully\n";
    echo "   Retrieved " . count($result) . " users\n\n";

    // Display results
    foreach ($result as $i => $user) {
        echo "   User " . ($i + 1) . ": " . ($user['username'] ?? 'N/A') . "\n";
    }
    echo "\n";
} catch (GatewayException $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n\n";
}

// Check connection status
echo "3. Connection Status\n";
echo "   Connected: " . ($client->isConnected() ? 'Yes' : 'No') . "\n";
echo "   Platform: " . $client->getPlatform() . "\n";
echo "   Tenant ID: " . ($client->getTenantId() ?? '<none>') . "\n\n";

echo "=== Example Complete ===\n";
