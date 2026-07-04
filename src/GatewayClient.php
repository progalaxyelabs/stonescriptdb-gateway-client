<?php

namespace StoneScriptDB;

use Exception;

/**
 * StoneScriptDB Gateway Client
 *
 * HTTP client for connecting to StoneScriptDB Gateway.
 * Works with any PHP application (Laravel, CodeIgniter, Symfony, etc.)
 *
 * @package StoneScriptDB
 * @version 3.0.0
 */
class GatewayClient
{
    private string $gateway_url;
    private ?string $platform;
    private ?string $schema_name;
    private ?string $uuid;
    private ?string $base_schema_name;
    private ?string $tenant_schema_name;
    private ?string $admin_token;
    private bool $connected = false;
    private ?string $last_error = null;
    private int $timeout = 30;
    private int $connect_timeout = 10;
    private bool $debug = false;

    /**
     * Create a new gateway client.
     *
     * @param string $gateway_url The URL of the gateway service (e.g., http://gateway:9000)
     * @param string|null $platform The platform identifier (e.g., myapp)
     * @param string $schema_name Main schema name (e.g. "main"). Required — no default.
     * @param string|null $tenant_schema_name Tenant schema name (e.g. "tenant"). Required only for multi-tenant platforms.
     * @param string|null $uuid Tenant UUID for tenant databases; null for the base schema
     * @param string|null $admin_token Optional admin token for /admin/* endpoints
     */
    public function __construct(
        string $gateway_url,
        ?string $platform = null,
        ?string $schema_name = null,
        ?string $tenant_schema_name = null,
        ?string $uuid = null,
        ?string $admin_token = null
    ) {
        if (!extension_loaded('curl')) {
            throw new Exception('GatewayClient requires the curl extension');
        }

        if (empty($schema_name)) {
            throw new Exception(
                'GatewayClient: schema_name is required. ' .
                'Set DB_GATEWAY_SCHEMA_NAME in your .env file (e.g. DB_GATEWAY_SCHEMA_NAME=main).'
            );
        }

        $this->gateway_url = rtrim($gateway_url, '/');
        $this->platform = $platform;
        $this->base_schema_name = $schema_name;
        $this->schema_name = $this->base_schema_name;
        $this->tenant_schema_name = $tenant_schema_name;
        $this->uuid = $uuid;
        $this->admin_token = $admin_token;
    }

    /**
     * Call a PostgreSQL function via the gateway.
     *
     * @param string $function_name The PostgreSQL function name
     * @param array $params Associative array of function parameters
     * @return array Array of result rows
     * @throws GatewayException If the request fails
     */
    public function callFunction(string $function_name, array $params = []): array
    {
        $url = $this->gateway_url . '/call';

        // Convert associative params to positional array (gateway expects JSON array, not object)
        if (!empty($params) && !array_is_list($params)) {
            $params = array_values($params);
        }

        $payload = [
            'platform' => $this->platform,
            'schema_name' => $this->schema_name,
            'uuid' => $this->uuid,
            'function' => $function_name,
            'params' => $params
        ];

        $start_time = microtime(true);

        try {
            $response = $this->httpPost($url, $payload);
            $elapsed_time = microtime(true) - $start_time;

            if ($this->debug) {
                error_log(sprintf(
                    '[GatewayClient] Call to %s took %.2fms',
                    $function_name,
                    $elapsed_time * 1000
                ));
            }

            if (!isset($response['rows'])) {
                throw new GatewayException('Invalid gateway response: missing rows field');
            }

            $this->connected = true;
            $this->last_error = null;

            if ($this->debug && isset($response['execution_time_ms'])) {
                error_log(sprintf(
                    '[GatewayClient] Gateway reported execution time: %sms',
                    $response['execution_time_ms']
                ));
            }

            return $response['rows'];
        } catch (GatewayException $e) {
            $this->last_error = $e->getMessage();
            throw $e;
        }
    }

    // ─── V2 Platform & Schema Management ─────────────────────────────────────

    /**
     * Register a platform with the gateway (idempotent).
     *
     * POST /platform/register
     *
     * @return array Gateway response
     * @throws GatewayException If registration fails
     */
    public function registerPlatform(): array
    {
        $url = $this->gateway_url . '/platform/register';

        $payload = [
            'platform' => $this->platform,
        ];

        $response = $this->httpPost($url, $payload);
        $this->connected = true;
        $this->last_error = null;

        return $response;
    }

    /**
     * Upload a schema archive for a platform.
     *
     * POST /platform/{platform}/schema
     *
     * @param string $schema_archive_path Path to .tar.gz schema archive
     * @param string $schema_name Schema version name (e.g., "v1.0", "latest")
     * @return array Gateway response with schema info (has_tables, has_functions, checksum, etc.)
     * @throws GatewayException If upload fails
     */
    public function uploadSchema(string $schema_archive_path, string $schema_name): array
    {
        if (!file_exists($schema_archive_path)) {
            throw new GatewayException("Schema archive not found: {$schema_archive_path}");
        }

        $url = $this->gateway_url . '/platform/' . urlencode($this->platform) . '/schema';
        $boundary = uniqid();
        $body = '';

        // Add schema_name field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"schema_name\"\r\n\r\n";
        $body .= "{$schema_name}\r\n";

        // Add schema file
        $fileContent = file_get_contents($schema_archive_path);
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"schema\"; filename=\"postgresql.tar.gz\"\r\n";
        $body .= "Content-Type: application/gzip\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $response = $this->httpPostMultipart($url, $body, $boundary, 60);
        $this->connected = true;
        $this->last_error = null;

        return $response;
    }

    /**
     * Create a database from a stored schema.
     *
     * POST /admin/database/create
     * Requires ADMIN_TOKEN.
     *
     * IMPORTANT (regression guard, task #3165): the gateway's Rust
     * CreateDatabaseRequest struct (stonescriptdb-gateway src/api/database.rs)
     * only deserializes a `uuid` field — it has no `database_id` field. This
     * method used to send `database_id`, which serde silently dropped (no
     * deny_unknown_fields at the time), leaving uuid=None on every call. Every
     * tenant then collapsed onto the shared `{platform}_{schema_name}` base
     * database instead of getting its own `{platform}_{schema_name}_{uuid}`
     * database ("ghost tenants" — active tenant rows with no real per-tenant
     * DB). Always send `uuid`, never `database_id`.
     *
     * @param string $schema_name Schema version to deploy
     * @param string $uuid Database identifier ("main" or tenant UUID)
     * @return array Gateway response with deployment details
     * @throws GatewayException If creation fails
     */
    public function createDatabase(string $schema_name, string $uuid): array
    {
        $url = $this->gateway_url . '/admin/database/create';
        $payload = $this->buildCreateDatabasePayload($schema_name, $uuid);

        $response = $this->httpPost($url, $payload, true);
        $this->connected = true;
        $this->last_error = null;

        return $response;
    }

    /**
     * Build the /admin/database/create payload. Extracted (protected) so unit
     * tests can assert on the exact outgoing payload shape (regression guard
     * for task #3165) without a live gateway.
     */
    protected function buildCreateDatabasePayload(string $schema_name, string $uuid): array
    {
        return [
            'platform' => $this->platform,
            'schema_name' => $schema_name,
            'uuid' => $uuid,
        ];
    }

    /**
     * Migrate a single database using a stored schema.
     *
     * POST /v2/migrate
     *
     * IMPORTANT (regression guard, task #3165): same contract as
     * createDatabase() above — the gateway's MigrateRequest struct
     * (stonescriptdb-gateway src/api/migrate.rs) only deserializes `uuid`, not
     * `database_id`. Always send `uuid`.
     *
     * @param string $schema_name Schema version to migrate to
     * @param string $uuid Database identifier ("main" or tenant UUID)
     * @param bool $force Bypass DATALOSS safety checks
     * @return array Gateway response with migration details
     * @throws GatewayException If migration fails
     */
    public function migrateV2(string $schema_name, string $uuid, bool $force = false): array
    {
        $url = $this->gateway_url . '/v2/migrate';
        $payload = $this->buildMigrateV2Payload($schema_name, $uuid, $force);

        $response = $this->httpPost($url, $payload, false, 120);
        $this->connected = true;
        $this->last_error = null;

        return $response;
    }

    /**
     * Build the /v2/migrate payload. Extracted (protected) so unit tests can
     * assert on the exact outgoing payload shape (regression guard for task
     * #3165) without a live gateway.
     */
    protected function buildMigrateV2Payload(string $schema_name, string $uuid, bool $force): array
    {
        return [
            'platform' => $this->platform,
            'schema_name' => $schema_name,
            'uuid' => $uuid,
            'force' => $force,
        ];
    }

    /**
     * Migrate all databases for a platform using a stored schema.
     *
     * POST /v2/migrate-all
     *
     * @param string $schema_name Schema version to migrate to
     * @param bool $force Bypass DATALOSS safety checks
     * @return array Gateway response with per-database results
     * @throws GatewayException If migration fails
     */
    public function migrateAllV2(string $schema_name, bool $force = false): array
    {
        $url = $this->gateway_url . '/v2/migrate-all';

        $payload = [
            'platform' => $this->platform,
            'schema_name' => $schema_name,
            'force' => $force,
        ];

        $response = $this->httpPost($url, $payload, false, 300);
        $this->connected = true;
        $this->last_error = null;

        return $response;
    }

    // ─── Health & Status ─────────────────────────────────────────────────────

    /**
     * Check if the gateway is reachable.
     *
     * @return bool True if gateway health check passes
     */
    public function healthCheck(): bool
    {
        try {
            $ch = curl_init($this->gateway_url . '/health');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $http_code === 200;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * Check if connected to the gateway.
     *
     * @return bool True if at least one successful request has been made
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Get the last error message.
     *
     * @return string|null The last error message or null if no error
     */
    public function getLastError(): ?string
    {
        return $this->last_error;
    }

    // ─── Configuration ───────────────────────────────────────────────────────

    /**
     * Route subsequent requests to a tenant database.
     *
     * Called by GatewayTenantMiddleware / StoreAccessMiddleware with the user's
     * tenant UUID. Passing null resets routing back to the base schema (main DB).
     *
     * Gateway v4 routing (see SPEC):
     *   - null  → schema_name = base_schema_name, uuid = null  → {platform}_{main_schema}
     *   - $uuid → schema_name = tenant_schema_name, uuid = $uuid → {platform}_{tenant_schema}_{uuid}
     *
     * @param string|null $tenant_id Tenant UUID, or null to reset to the base schema
     * @return self For method chaining
     * @throws Exception If routing to a tenant and DB_GATEWAY_TENANT_SCHEMA_NAME is not configured
     */
    public function setTenantId(?string $tenant_id): self
    {
        if ($tenant_id === null) {
            $this->schema_name = $this->base_schema_name;
            $this->uuid = null;
        } else {
            if (empty($this->tenant_schema_name)) {
                throw new Exception(
                    'DB_GATEWAY_TENANT_SCHEMA_NAME is required for multi-tenant routing. ' .
                    'Add it to your .env file (e.g. DB_GATEWAY_TENANT_SCHEMA_NAME=tenant).'
                );
            }
            $this->schema_name = $this->tenant_schema_name;
            $this->uuid = $tenant_id;
        }
        return $this;
    }

    /**
     * Get the current tenant UUID (null when on the base/main database).
     *
     * @return string|null The current tenant UUID
     */
    public function getTenantId(): ?string
    {
        return $this->uuid;
    }

    /**
     * Set the schema name for subsequent requests.
     *
     * @param string|null $schema_name Schema name (e.g. "main", "tenant")
     * @return self For method chaining
     */
    public function setSchemaName(?string $schema_name): self
    {
        $this->schema_name = $schema_name;
        return $this;
    }

    /**
     * Get the current schema name.
     *
     * @return string|null The current schema name
     */
    public function getSchemaName(): ?string
    {
        return $this->schema_name;
    }

    /**
     * Set the UUID for tenant database routing.
     *
     * @param string|null $uuid Tenant UUID; null for the base/main database
     * @return self For method chaining
     */
    public function setUuid(?string $uuid): self
    {
        $this->uuid = $uuid;
        return $this;
    }

    /**
     * Get the current UUID.
     *
     * @return string|null The current UUID
     */
    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    /**
     * Set the platform identifier for subsequent requests.
     *
     * @param string|null $platform The platform identifier
     * @return self For method chaining
     */
    public function setPlatform(?string $platform): self
    {
        $this->platform = $platform;
        return $this;
    }

    /**
     * Get the current platform identifier.
     *
     * @return string|null The current platform identifier
     */
    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    /**
     * Set the admin token for /admin/* endpoints.
     *
     * @param string|null $token Admin authentication token
     * @return self For method chaining
     */
    public function setAdminToken(?string $token): self
    {
        $this->admin_token = $token;
        return $this;
    }

    /**
     * Set request timeout in seconds.
     *
     * @param int $timeout Timeout in seconds
     * @return self For method chaining
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Set connection timeout in seconds.
     *
     * @param int $timeout Connection timeout in seconds
     * @return self For method chaining
     */
    public function setConnectTimeout(int $timeout): self
    {
        $this->connect_timeout = $timeout;
        return $this;
    }

    /**
     * Enable or disable debug logging.
     *
     * @param bool $enabled True to enable debug logging
     * @return self For method chaining
     */
    public function setDebug(bool $enabled): self
    {
        $this->debug = $enabled;
        return $this;
    }

    // ─── HTTP Transport ──────────────────────────────────────────────────────

    /**
     * Perform an HTTP POST request to the gateway (JSON).
     *
     * @param string $url The URL to post to
     * @param array $data The data to send as JSON
     * @param bool $with_admin_auth Include admin token in Authorization header
     * @param int|null $timeout Override timeout for this request
     * @return array The decoded JSON response
     * @throws GatewayException If the request fails
     */
    private function httpPost(string $url, array $data, bool $with_admin_auth = false, ?int $timeout = null): array
    {
        $json_payload = json_encode($data);

        if ($json_payload === false) {
            throw new GatewayException('Failed to encode request payload as JSON: ' . json_last_error_msg());
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new GatewayException('Failed to initialize curl');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($json_payload)
        ];

        if ($with_admin_auth && $this->admin_token) {
            $headers[] = 'Authorization: Bearer ' . $this->admin_token;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json_payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout ?? $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);

        curl_close($ch);

        if ($curl_errno !== 0) {
            throw new GatewayException("Gateway request failed: $curl_error (errno: $curl_errno)");
        }

        if ($http_code !== 200) {
            $error_message = "Gateway returned HTTP $http_code";
            $error_body = null;

            if ($response !== false && !empty($response)) {
                $error_body = json_decode($response, true);
                if (is_array($error_body) && isset($error_body['error'])) {
                    $error_message .= ': ' . $error_body['error'];
                }
                if (is_array($error_body) && isset($error_body['message'])) {
                    $error_message .= ' - ' . $error_body['message'];
                }
                // Include cause in the message for backward compatibility with str_contains() checks
                if (is_array($error_body) && isset($error_body['cause'])) {
                    $error_message .= ' (Cause: ' . $error_body['cause'] . ')';
                }
            }

            throw new GatewayException($error_message, $http_code, null, $error_body);
        }

        if ($response === false || $response === '') {
            throw new GatewayException('Empty response from gateway');
        }

        $decoded = json_decode($response, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new GatewayException('Failed to decode gateway response: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Perform an HTTP POST request with multipart/form-data.
     *
     * @param string $url The URL to post to
     * @param string $body The raw multipart body
     * @param string $boundary The multipart boundary
     * @param int $timeout Request timeout in seconds
     * @return array The decoded JSON response
     * @throws GatewayException If the request fails
     */
    private function httpPostMultipart(string $url, string $body, string $boundary, int $timeout = 60): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new GatewayException('Failed to initialize curl');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: multipart/form-data; boundary={$boundary}",
                'Content-Length: ' . strlen($body)
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);

        curl_close($ch);

        if ($curl_errno !== 0) {
            throw new GatewayException("Gateway request failed: $curl_error (errno: $curl_errno)");
        }

        if ($http_code !== 200) {
            $error_message = "Gateway returned HTTP $http_code";
            $error_body = null;

            if ($response !== false && !empty($response)) {
                $error_body = json_decode($response, true);
                if (is_array($error_body) && isset($error_body['error'])) {
                    $error_message .= ': ' . $error_body['error'];
                }
                if (is_array($error_body) && isset($error_body['message'])) {
                    $error_message .= ' - ' . $error_body['message'];
                }
                // Include cause in the message for backward compatibility with str_contains() checks
                if (is_array($error_body) && isset($error_body['cause'])) {
                    $error_message .= ' (Cause: ' . $error_body['cause'] . ')';
                }
            }

            throw new GatewayException($error_message, $http_code, null, $error_body);
        }

        if ($response === false || $response === '') {
            throw new GatewayException('Empty response from gateway');
        }

        $decoded = json_decode($response, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new GatewayException('Failed to decode gateway response: ' . json_last_error_msg());
        }

        return $decoded;
    }
}
