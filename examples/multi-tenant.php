<?php
/**
 * Multi-Tenant Example - StoneScriptDB Gateway Client
 *
 * This example demonstrates:
 * - Creating a multi-tenant client
 * - Switching between tenants
 * - Isolated data access per tenant
 */

require __DIR__ . '/../vendor/autoload.php';

use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;

$gatewayUrl = getenv('GATEWAY_URL') ?: 'http://localhost:9000';
$platform = getenv('PLATFORM') ?: 'saas-platform';

echo "=== Multi-Tenant Example ===\n\n";

// Create client with initial tenant
$client = new GatewayClient($gatewayUrl, $platform, 'tenant_001');

echo "1. Accessing Tenant 001\n";
try {
    $data = $client->callFunction('get_tenant_info', []);
    echo "   Current tenant: " . $client->getTenantId() . "\n";
    echo "   Data: " . json_encode($data) . "\n\n";
} catch (GatewayException $e) {
    echo "   Error: " . $e->getMessage() . "\n\n";
}

// Switch to different tenant
echo "2. Switching to Tenant 002\n";
$client->setTenantId('tenant_002');

try {
    $data = $client->callFunction('get_tenant_info', []);
    echo "   Current tenant: " . $client->getTenantId() . "\n";
    echo "   Data: " . json_encode($data) . "\n\n";
} catch (GatewayException $e) {
    echo "   Error: " . $e->getMessage() . "\n\n";
}

// Access main database (no tenant)
echo "3. Accessing Main Database\n";
$client->setTenantId(null);

try {
    $data = $client->callFunction('get_platform_stats', []);
    echo "   Tenant ID: " . ($client->getTenantId() ?? '<none>') . "\n";
    echo "   Stats: " . json_encode($data) . "\n\n";
} catch (GatewayException $e) {
    echo "   Error: " . $e->getMessage() . "\n\n";
}

echo "=== Example Complete ===\n";
