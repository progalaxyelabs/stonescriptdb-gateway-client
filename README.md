# StoneScriptDB Gateway Client

HTTP client for connecting to [StoneScriptDB Gateway](https://github.com/progalaxyelabs/stonescriptdb-gateway). Use with any PHP application to leverage PostgreSQL via a centralized gateway.

## Features

- 🚀 **Framework Agnostic** - Works with Laravel, CodeIgniter, Symfony, vanilla PHP, etc.
- 🔌 **Simple HTTP API** - Call PostgreSQL functions over HTTP
- 🏢 **Multi-Tenant Ready** - Built-in tenant isolation support
- 📦 **Schema Management** - Register and migrate schemas programmatically
- 🔐 **Authentication & Authorization** - JWT token validation, membership management, user invitations
- ⚡ **Production Ready** - Connection pooling, timeout handling, error management
- 🔍 **Minimal Dependencies** - Only requires curl, json, and firebase/php-jwt

## Installation

```bash
composer require progalaxyelabs/stonescriptdb-gateway-client
```

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use StoneScriptDB\GatewayClient;

// Connect to gateway
$client = new GatewayClient(
    gateway_url: 'http://localhost:9000',
    platform: 'myapp'
);

// Call a PostgreSQL function
$users = $client->callFunction('get_users', [
    'limit' => 10
]);

foreach ($users as $user) {
    echo $user['username'] . "\n";
}
```

## Usage

### Basic Function Calls

```php
use StoneScriptDB\GatewayClient;

$client = new GatewayClient('http://gateway:9000', 'myapp');

// Simple function call
$result = $client->callFunction('get_product', [
    'product_id' => 123
]);

// Multi-parameter function
$orders = $client->callFunction('search_orders', [
    'customer_id' => 456,
    'status' => 'completed',
    'limit' => 50
]);
```

### Multi-Tenant Applications

```php
$client = new GatewayClient(
    gateway_url: 'http://gateway:9000',
    platform: 'saas-platform',
    tenant_id: 'acme-corp'
);

// All calls use tenant_id automatically
$data = $client->callFunction('get_tenant_data', []);

// Switch tenant dynamically
$client->setTenantId('globex-inc');
$data = $client->callFunction('get_tenant_data', []);
```

### Schema Registration

```php
$client = new GatewayClient('http://gateway:9000', 'myapp');

// Register schema on deployment
$response = $client->register('/path/to/schema.tar.gz');

echo "Migrations applied: " . $response['migrations_applied'] . "\n";
echo "Functions deployed: " . $response['functions_deployed'] . "\n";
```

### Hot Schema Migration

```php
// Update running production schema
$response = $client->migrate('/path/to/schema.tar.gz');

echo "Databases updated: " . count($response['databases_updated']) . "\n";
```

### Health Checks

```php
if ($client->healthCheck()) {
    echo "Gateway is healthy\n";
} else {
    echo "Gateway unavailable: " . $client->getLastError() . "\n";
}
```

### Error Handling

```php
use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;

$client = new GatewayClient('http://gateway:9000', 'myapp');

try {
    $result = $client->callFunction('risky_operation', [
        'amount' => 5000
    ]);
} catch (GatewayException $e) {
    error_log("Gateway error: " . $e->getMessage());

    // HTTP status code available
    if ($e->getCode() === 500) {
        // Server error
    } elseif ($e->getCode() === 404) {
        // Function not found
    }
}
```

### Configuration

```php
$client = new GatewayClient('http://gateway:9000', 'myapp');

// Custom timeouts
$client->setTimeout(60)              // Request timeout (seconds)
       ->setConnectTimeout(15)       // Connection timeout
       ->setDebug(true);             // Enable debug logging

// Call function
$result = $client->callFunction('long_running_operation', []);
```

## Authentication & Authorization

### JWT Token Validation

Validate JWT tokens issued by the StoneScriptDB Gateway:

```php
use StoneScriptDB\Auth\TokenValidator;
use StoneScriptDB\Auth\InvalidTokenException;

$validator = new TokenValidator('http://gateway:9000');

try {
    $claims = $validator->validateToken($jwtToken);

    echo "User ID: " . $claims->sub . "\n";
    echo "Email: " . $claims->email . "\n";
    echo "Tenant ID: " . $claims->tenantId . "\n";
    echo "Role: " . $claims->role . "\n";
    echo "Expires: " . date('Y-m-d H:i:s', $claims->exp) . "\n";
} catch (InvalidTokenException $e) {
    echo "Invalid token: " . $e->getMessage() . "\n";
}
```

### Membership Management

Manage user memberships and roles within tenants:

```php
use StoneScriptDB\Auth\MembershipClient;

$client = new MembershipClient('http://gateway:9000');

// Get user's memberships
$memberships = $client->getUserMemberships($identityId, 'myapp', $authToken);

foreach ($memberships as $membership) {
    echo "Tenant: " . $membership->tenantId . "\n";
    echo "Role: " . $membership->role . "\n";
    echo "Status: " . $membership->status . "\n";
}

// Update a membership role
$updated = $client->updateMembership($membershipId, [
    'role' => 'admin'
], $authToken);

// Suspend a membership
$client->suspendMembership($membershipId, 'Policy violation', $authToken);

// Reactivate a membership
$client->reactivateMembership($membershipId, $authToken);
```

### User Invitations

Invite users to tenants with specific roles:

```php
use StoneScriptDB\Auth\InvitationClient;

$invitations = new InvitationClient('http://gateway:9000');

// Invite a user
$invitation = $invitations->inviteUser(
    email: 'user@example.com',
    tenantId: 'tenant-123',
    role: 'member'
);

echo "Invitation sent to: " . $invitation->email . "\n";
echo "Token: " . $invitation->token . "\n";
echo "Expires: " . $invitation->expiresAt . "\n";

// Get invitation details
$inv = $invitations->getInvitation($invitationId);

// Resend invitation email
$invitations->resendInvitation($invitationId);

// Cancel invitation
$invitations->cancelInvitation($invitationId);
```

## Integration Examples

### Laravel

```php
// config/database.php
'connections' => [
    'gateway' => [
        'driver' => 'stonescriptdb',
        'url' => env('DB_GATEWAY_URL', 'http://localhost:9000'),
        'platform' => env('DB_GATEWAY_PLATFORM', 'myapp'),
    ],
],

// app/Services/DatabaseService.php
use StoneScriptDB\GatewayClient;

class DatabaseService
{
    private GatewayClient $client;

    public function __construct()
    {
        $this->client = new GatewayClient(
            config('database.connections.gateway.url'),
            config('database.connections.gateway.platform')
        );
    }

    public function getUsers(int $limit = 10): array
    {
        return $this->client->callFunction('get_users', compact('limit'));
    }
}
```

### CodeIgniter 4

```php
// app/Config/Database.php
public array $gateway = [
    'gateway_url' => 'http://localhost:9000',
    'platform' => 'myapp',
];

// app/Models/UserModel.php
use StoneScriptDB\GatewayClient;

class UserModel extends BaseModel
{
    private GatewayClient $client;

    public function __construct()
    {
        $config = config('Database')->gateway;
        $this->client = new GatewayClient(
            $config['gateway_url'],
            $config['platform']
        );
    }

    public function findUser(int $id): ?array
    {
        $result = $this->client->callFunction('get_user', ['id' => $id]);
        return $result[0] ?? null;
    }
}
```

### Vanilla PHP

```php
// config.php
define('GATEWAY_URL', 'http://gateway:9000');
define('PLATFORM', 'myapp');

// database.php
use StoneScriptDB\GatewayClient;

function getDatabase(): GatewayClient
{
    static $client = null;

    if ($client === null) {
        $client = new GatewayClient(GATEWAY_URL, PLATFORM);
    }

    return $client;
}

// index.php
$db = getDatabase();
$products = $db->callFunction('get_products', ['category' => 'electronics']);
```

## API Reference

### GatewayClient

#### Constructor

```php
new GatewayClient(string $gateway_url, ?string $platform = null, ?string $tenant_id = null)
```

#### Methods

- `callFunction(string $name, array $params = []): array` - Call PostgreSQL function
- `register(string $archive_path, array $options = []): array` - Register schema
- `migrate(string $archive_path, array $options = []): array` - Migrate schema
- `healthCheck(): bool` - Check gateway health
- `isConnected(): bool` - Check if connected
- `getLastError(): ?string` - Get last error message
- `setTenantId(?string $id): self` - Set tenant ID
- `getTenantId(): ?string` - Get tenant ID
- `setPlatform(?string $platform): self` - Set platform
- `getPlatform(): ?string` - Get platform
- `setTimeout(int $seconds): self` - Set request timeout
- `setConnectTimeout(int $seconds): self` - Set connection timeout
- `setDebug(bool $enabled): self` - Enable/disable debug logging

### GatewayException

Thrown when gateway requests fail. Extends `Exception`.

```php
try {
    $client->callFunction('invalid_function', []);
} catch (GatewayException $e) {
    echo $e->getMessage(); // Error description
    echo $e->getCode();    // HTTP status code (if applicable)
}
```

### TokenValidator

Validates JWT tokens issued by the gateway.

#### Constructor

```php
new TokenValidator(string $gateway_url)
```

#### Methods

- `validateToken(string $jwt): TokenClaims` - Validate JWT and return claims
- `getPublicKey(): string` - Get cached or fetch gateway's public key
- `refreshPublicKey(): void` - Force refresh public key from JWKS endpoint

### MembershipClient

Manages user memberships and roles.

#### Constructor

```php
new MembershipClient(string $gateway_url)
```

#### Methods

- `getUserMemberships(string $identityId, ?string $platformCode = null, ?string $authToken = null): array` - Get user's memberships
- `getMembership(string $membershipId, ?string $authToken = null): Membership` - Get single membership
- `updateMembership(string $membershipId, array $changes, ?string $authToken = null): Membership` - Update membership
- `suspendMembership(string $membershipId, string $reason, ?string $authToken = null): void` - Suspend membership
- `reactivateMembership(string $membershipId, ?string $authToken = null): void` - Reactivate membership
- `setTimeout(int $seconds): self` - Set request timeout
- `setConnectTimeout(int $seconds): self` - Set connection timeout

### InvitationClient

Handles user invitations to tenants.

#### Constructor

```php
new InvitationClient(string $gateway_url)
```

#### Methods

- `inviteUser(string $email, string $tenantId, string $role): Invitation` - Invite user to tenant
- `getInvitation(string $invitationId): Invitation` - Get invitation details
- `cancelInvitation(string $invitationId): void` - Cancel pending invitation
- `resendInvitation(string $invitationId): void` - Resend invitation email
- `setTimeout(int $seconds): self` - Set request timeout
- `setConnectTimeout(int $seconds): self` - Set connection timeout

## Requirements

- PHP 8.1 or higher
- curl extension
- json extension
- firebase/php-jwt ^6.0 (for JWT token validation)

## Contributing

Contributions are welcome! Please submit issues and pull requests on [GitHub](https://github.com/progalaxyelabs/stonescriptdb-gateway-client).

## License

MIT License. See [LICENSE](LICENSE) for details.

## Related Projects

- [StoneScriptDB Gateway](https://github.com/progalaxyelabs/stonescriptdb-gateway) - The PostgreSQL gateway server
- [StoneScriptPHP](https://github.com/progalaxyelabs/stonescriptphp) - PostgreSQL-native PHP framework

## Support

- GitHub Issues: [progalaxyelabs/stonescriptdb-gateway-client/issues](https://github.com/progalaxyelabs/stonescriptdb-gateway-client/issues)
- Documentation: [docs.progalaxy.com](https://docs.progalaxy.com)
