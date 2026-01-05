<?php
/**
 * Schema Registration Example - StoneScriptDB Gateway Client
 *
 * This example demonstrates:
 * - Registering a schema archive with the gateway
 * - Hot migrating schema changes
 */

require __DIR__ . '/../vendor/autoload.php';

use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;

$gatewayUrl = getenv('GATEWAY_URL') ?: 'http://localhost:9000';
$platform = getenv('PLATFORM') ?: 'myapp';
$schemaPath = getenv('SCHEMA_PATH') ?: '/path/to/schema.tar.gz';

echo "=== Schema Registration Example ===\n\n";

$client = new GatewayClient($gatewayUrl, $platform);

// Register schema (first time deployment)
echo "1. Registering Schema\n";
echo "   Gateway: $gatewayUrl\n";
echo "   Platform: $platform\n";
echo "   Schema: $schemaPath\n\n";

try {
    $response = $client->register($schemaPath);

    echo "   ✓ Registration successful!\n";
    echo "   Status: " . ($response['status'] ?? 'unknown') . "\n";
    echo "   Database: " . ($response['database'] ?? 'unknown') . "\n";
    echo "   Migrations applied: " . ($response['migrations_applied'] ?? 0) . "\n";
    echo "   Functions deployed: " . ($response['functions_deployed'] ?? 0) . "\n\n";
} catch (GatewayException $e) {
    echo "   ✗ Registration failed: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Hot migrate (update running system)
echo "2. Hot Migration (Optional)\n";
echo "   This updates the schema without restart\n\n";

try {
    $response = $client->migrate($schemaPath);

    echo "   ✓ Migration successful!\n";
    echo "   Databases updated: " . count($response['databases_updated'] ?? []) . "\n";
    echo "   Migrations applied: " . ($response['migrations_applied'] ?? 0) . "\n";
    echo "   Functions updated: " . ($response['functions_updated'] ?? 0) . "\n\n";
} catch (GatewayException $e) {
    echo "   ✗ Migration failed: " . $e->getMessage() . "\n\n";
}

echo "=== Example Complete ===\n";
