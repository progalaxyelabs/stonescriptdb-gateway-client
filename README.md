# StoneScriptDB Gateway Client

HTTP client for connecting to [StoneScriptDB Gateway](https://github.com/progalaxyelabs/stonescriptdb-gateway). Use with any PHP application to leverage PostgreSQL via a centralized gateway.

## Features

- 🚀 **Framework Agnostic** - Works with Laravel, CodeIgniter, Symfony, vanilla PHP, etc.
- 🔌 **Simple HTTP API** - Call PostgreSQL functions over HTTP
- 🏢 **Multi-Tenant Ready** - Built-in tenant isolation support
- 📦 **Schema Management** - Register and migrate schemas programmatically
- 🔐 **JWT Token Validation** - Validate tokens from ProGalaxy Auth Service
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

// Connect to gateway. `schema_name` is required — it's your platform's main
// schema label (e.g. "main"); throws if omitted.
$client = new GatewayClient(
    gateway_url: 'http://localhost:9000',
    platform: 'myapp',
    schema_name: 'main'
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

$client = new GatewayClient('http://gateway:9000', 'myapp', schema_name: 'main');

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

Multi-tenant routing is `schema_name` + `uuid` (gateway v4), not a `tenant_id`
constructor arg. Pass `tenant_schema_name` at construction time (the schema label
used for *every* tenant, e.g. `"tenant"`), then switch the active tenant with
`setTenantId($uuid)`:

```php
$client = new GatewayClient(
    gateway_url: 'http://gateway:9000',
    platform: 'saas-platform',
    schema_name: 'main',            // used when no tenant is selected
    tenant_schema_name: 'tenant'     // used once setTenantId() is called
);

// Route subsequent calls to a specific tenant database
$client->setTenantId('11111111-1111-1111-1111-111111111111');
$data = $client->callFunction('get_tenant_data', []);

// Switch tenant dynamically
$client->setTenantId('22222222-2222-2222-2222-222222222222');
$data = $client->callFunction('get_tenant_data', []);

// Reset back to the base/main database
$client->setTenantId(null);
```

### Schema Registration

Schema registration is a two-step upload-then-migrate flow — there is no single
`register()` method:

```php
$client = new GatewayClient('http://gateway:9000', 'myapp', schema_name: 'main');

// 1. Upload the schema archive (tables/functions/migrations tar.gz)
$upload = $client->uploadSchema('/path/to/schema.tar.gz', 'v1.0');
echo "Functions in archive: " . ($upload['has_functions'] ? 'yes' : 'no') . "\n";

// 2. Create the database from that stored schema (requires an admin token —
//    see setAdminToken() below)
$client->setAdminToken(getenv('GATEWAY_ADMIN_TOKEN'));
$created = $client->createDatabase('v1.0', 'main');
echo "Functions deployed: " . $created['functions_deployed'] . "\n";
```

### Hot Schema Migration

Migration is `migrateV2()` (single database) or `migrateAllV2()` (every tenant) —
there is no single `migrate()` method:

```php
// Migrate the main database to an already-uploaded schema version
$response = $client->migrateV2('v1.0', 'main', force: false);
echo "Migrations applied: " . $response['migrations_applied'] . "\n";

// Or migrate every tenant database at once
$response = $client->migrateAllV2('v1.0', force: false);
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

## Authentication

### JWT Token Validation

Validate JWT tokens issued by the ProGalaxy Auth Service (or StoneScriptDB Gateway):

```php
use StoneScriptDB\Auth\TokenValidator;
use StoneScriptDB\Auth\InvalidTokenException;

// Constructor takes the base URL whose /auth/jwks endpoint should be used to
// verify the token's signature — this may be the gateway itself or a central
// auth service, whichever issued the token.
$validator = new TokenValidator('http://auth-service:3139');

try {
    $claims = $validator->validateToken($jwtToken);

    echo "Identity ID: " . $claims->identity_id . "\n";
    echo "Tenant ID: " . $claims->tenant_id . "\n";
    echo "Tenant slug: " . $claims->tenant_slug . "\n";
    echo "Platform: " . $claims->platform_code . "\n";
    echo "Role: " . $claims->role . "\n";
    echo "Expires: " . date('Y-m-d H:i:s', $claims->exp) . "\n";
} catch (InvalidTokenException $e) {
    echo "Invalid token: " . $e->getMessage() . "\n";
}
```

**Use Cases:**
- Middleware for validating incoming API requests
- Extracting user context from JWT tokens
- Multi-tenant applications extracting tenant information from tokens

**Note:** For managing memberships and invitations (backend-to-backend operations), use the auth client in [StoneScriptPHP framework](https://github.com/progalaxyelabs/StoneScriptPHP) instead.

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
            config('database.connections.gateway.platform'),
            schema_name: 'main'
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
            $config['platform'],
            schema_name: 'main'
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
        $client = new GatewayClient(GATEWAY_URL, PLATFORM, schema_name: 'main');
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
new GatewayClient(
    string $gateway_url,
    ?string $platform = null,
    ?string $schema_name = null,        // required — throws if empty
    ?string $tenant_schema_name = null, // required only if you ever call setTenantId()
    ?string $uuid = null,
    ?string $admin_token = null
)
```

#### Methods

- `callFunction(string $name, array $params = []): array` - Call PostgreSQL function (`POST /call`)
- `registerPlatform(): array` - Register this platform with the gateway, idempotent (`POST /platform/register`)
- `uploadSchema(string $archivePath, string $schemaName): array` - Upload a `.tar.gz` schema archive (`POST /platform/{platform}/schema`)
- `createDatabase(string $schemaName, string $uuid): array` - Create a database from a stored schema; requires an admin token (`POST /admin/database/create`)
- `migrateV2(string $schemaName, string $uuid, bool $force = false): array` - Migrate one database to a stored schema (`POST /v2/migrate`)
- `migrateAllV2(string $schemaName, bool $force = false): array` - Migrate every database for the platform (`POST /v2/migrate-all`)
- `healthCheck(): bool` - Check gateway health (`GET /health`)
- `isConnected(): bool` - True once at least one request has succeeded
- `getLastError(): ?string` - Get last error message
- `setTenantId(?string $uuid): self` - Route subsequent calls to a tenant database (`null` resets to the base/main schema)
- `getTenantId(): ?string` - Get the current tenant UUID (alias for `getUuid()`)
- `setSchemaName(?string $schemaName): self` / `getSchemaName(): ?string` - Get/set the schema name used on the next call
- `setUuid(?string $uuid): self` / `getUuid(): ?string` - Get/set the UUID used on the next call
- `setPlatform(?string $platform): self` / `getPlatform(): ?string` - Get/set platform
- `setAdminToken(?string $token): self` - Set the bearer token used for `/admin/*` endpoints
- `setTimeout(int $seconds): self` - Set request timeout
- `setConnectTimeout(int $seconds): self` - Set connection timeout
- `setDebug(bool $enabled): self` - Enable/disable debug logging

### GatewayException

Thrown when gateway requests fail. Extends `Exception`.

```php
try {
    $client->callFunction('invalid_function', []);
} catch (GatewayException $e) {
    echo $e->getMessage();       // Combined human-readable error string
    echo $e->getCode();          // HTTP status code (if applicable)
    echo $e->getGatewayError();  // Machine-readable error code, e.g. "query_failed"
    echo $e->getCause();         // Raw PostgreSQL error/detail text, if the gateway sent one
    echo $e->getDatabase();      // Database name involved, if the gateway sent one
}
```

### TokenValidator

Validates JWT tokens issued by the auth service.

#### Constructor

```php
new TokenValidator(string $gatewayBaseUrl)
```

`$gatewayBaseUrl` is whichever base URL exposes the `/auth/jwks` endpoint for the
issuer of the tokens you need to verify (the gateway itself, or a central auth
service — both serve JWKS the same way).

#### Methods

- `validateToken(string $jwt): TokenClaims` - Validate JWT and return claims
- `getPublicKey(): string` - Get cached or fetch the public key from `{gatewayBaseUrl}/auth/jwks`
- `refreshPublicKey(): void` - Force refresh public key from the JWKS endpoint

### TokenClaims

Value object containing validated JWT claims (`src/Auth/TokenClaims.php`).

#### Properties

- `string $identity_id` - The authenticated user's identity UUID
- `string $tenant_id` - Tenant UUID the token is scoped to
- `string $tenant_slug` - Human-readable tenant slug (empty string if unused by the platform)
- `string $platform_code` - Which platform issued/owns this token
- `string $role` - User's role in this tenant
- `?string $local_user_id` - Platform-specific user record ID, if any
- `int $exp` - Expiration timestamp
- `int $iat` - Issued-at timestamp

#### Methods

- `isExpired(): bool` - Check if token has expired
- `toArray(): array` - Convert to array

**Note:** For membership management and invitations, use the auth clients in [StoneScriptPHP framework](https://github.com/progalaxyelabs/StoneScriptPHP).

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

## Error handling and privacy (4.0)

`GatewayException` never carries PostgreSQL error text. Messages are identifier-only, for example
`Gateway returned HTTP 500: query_failed [function=create_user constraint=users_email_key code=slug_taken ref=...]`.

Signal business errors from SQL with a code, not a sentence:

```sql
RAISE EXCEPTION 'slug_taken';                      -- code only
RAISE EXCEPTION 'slug_taken: slug % is in use', p; -- code + free text (free text is never surfaced)
RAISE EXCEPTION '[ER102] stock short';             -- bracketed tag
```

```php
try { $client->callFunction('create_site', [...]); }
catch (GatewayException $e) {
    if ($e->getAppErrorCode() === 'slug_taken:' || $e->getAppErrorCode() === 'slug_taken') { /* ... */ }
    $e->getSqlState(); $e->getConstraint(); $e->getColumn(); $e->getCorrelationId();
    // $e->getRawCauseForDebug() is debug-only and may contain customer data: never log it.
}
```
