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
    /** Runtime-scope token: held by the application server. */
    private ?string $platform_token = null;
    /** Deploy-scope token: held only by the deploy manager. */
    private ?string $deploy_token = null;

    /** Runtime token (`/call`, new tenant databases). */
    private const CRED_PLATFORM = 'platform';
    /** Runtime token when set, else the deploy token (new tenant create/migrate). */
    private const CRED_RUNTIME_PREFERRED = 'runtime_preferred';
    /** Deploy token only (upload, migrate-all, main database, guard overrides). */
    private const CRED_DEPLOY = 'deploy';
    /** Admin token when set, else the deploy token (platform registration). */
    private const CRED_ADMIN_PREFERRED = 'admin_preferred';
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
     * @param string|null $platform_token Optional runtime-scope token (held by the app server): `/call` and new tenant databases
     * @param string|null $deploy_token Optional deploy-scope token (held by the deploy manager only): registration, schema upload, migrations
     */
    public function __construct(
        string $gateway_url,
        ?string $platform = null,
        ?string $schema_name = null,
        ?string $tenant_schema_name = null,
        ?string $uuid = null,
        ?string $admin_token = null,
        #[\SensitiveParameter] ?string $platform_token = null,
        #[\SensitiveParameter] ?string $deploy_token = null
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
        $this->platform_token = ($platform_token !== null && $platform_token !== '') ? $platform_token : null;
        $this->deploy_token = ($deploy_token !== null && $deploy_token !== '') ? $deploy_token : null;
    }

    /**
     * Keep credentials out of var_dump()/print_r() output.
     */
    public function __debugInfo(): array
    {
        $props = get_object_vars($this);
        foreach (['admin_token', 'platform_token', 'deploy_token'] as $k) {
            if ($props[$k] !== null) {
                $props[$k] = '<redacted>';
            }
        }
        return $props;
    }

    /**
     * Call a PostgreSQL function via the gateway.
     *
     * @param string $function_name The PostgreSQL function name
     * @param array $params Associative array of function parameters
     * @return array Array of result rows
     * @throws GatewayException If the request fails
     */
    public function callFunction(string $function_name, #[\SensitiveParameter] array $params = []): array
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
     * Credential: the admin token when one is set (a new platform has no
     * token yet); otherwise the deploy token (re-registering an existing
     * platform).
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

        $response = $this->httpPost($url, $payload, self::CRED_ADMIN_PREFERRED);
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
     * A tenant database (`$uuid` given) is created with the runtime token
     * (the sign-up path; the deploy token is used when no runtime token is
     * set). The main database (`$uuid` null) is a deploy step: deploy token.
     * The admin token is never used here.
     *
     * The gateway's create request only deserializes a `uuid` field (there is
     * no `database_id`), so the payload always carries `uuid`; sending an
     * unknown field is rejected by the gateway.
     *
     * @param string $schema_name Schema version to deploy
     * @param string|null $uuid Tenant database identifier; null for the main (non-tenant) database
     * @return array Gateway response with deployment details
     * @throws GatewayException If creation fails
     */
    public function createDatabase(string $schema_name, ?string $uuid = null): array
    {
        $url = $this->gateway_url . '/admin/database/create';
        $payload = $this->buildCreateDatabasePayload($schema_name, $uuid);

        $credential = $uuid !== null ? self::CRED_RUNTIME_PREFERRED : self::CRED_DEPLOY;
        $response = $this->httpPost($url, $payload, $credential);
        $this->connected = true;
        $this->last_error = null;

        return $response;
    }

    /**
     * Build the /admin/database/create payload. Extracted (protected) so unit
     * tests can assert on the exact outgoing payload shape without a live
     * gateway.
     */
    protected function buildCreateDatabasePayload(string $schema_name, ?string $uuid): array
    {
        $payload = [
            'platform' => $this->platform,
            'schema_name' => $schema_name,
        ];
        if ($uuid !== null) {
            $payload['uuid'] = $uuid;
        }
        return $payload;
    }

    /**
     * Migrate a single database using a stored schema.
     *
     * POST /v2/migrate
     *
     * Same contract as createDatabase(): the payload carries `uuid`.
     *
     * Migrating a NEW tenant database (`$uuid` given, no force/allow) uses the
     * runtime token (deploy token if no runtime token is set). Everything else
     * (the main database, existing databases, force/allow) uses the deploy
     * token. When `$force` is true or `$allow` is non-empty the admin token (if
     * configured) is also sent, in the `X-Gateway-Admin-Token` header, to the
     * configured gateway URL only.
     *
     * @param string $schema_name Schema version to migrate to
     * @param string|null $uuid Tenant database identifier; null for the main (non-tenant) database
     * @param bool $force Bypass DATALOSS safety checks
     * @param string[] $allow Granular guard overrides (e.g. "drop_column")
     * @return array Gateway response with migration details
     * @throws GatewayException If migration fails
     */
    public function migrateV2(string $schema_name, ?string $uuid = null, bool $force = false, array $allow = []): array
    {
        $url = $this->gateway_url . '/v2/migrate';
        $payload = $this->buildMigrateV2Payload($schema_name, $uuid, $force, $allow);

        $override = $force || !empty($allow);
        $credential = ($uuid !== null && !$override) ? self::CRED_RUNTIME_PREFERRED : self::CRED_DEPLOY;
        $response = $this->httpPost($url, $payload, $credential, 120, $override);
        $this->connected = true;
        $this->last_error = null;

        return $response;
    }

    /**
     * Build the /v2/migrate payload. Extracted (protected) so unit tests can
     * assert on the exact outgoing payload shape without a live gateway.
     *
     * @param string[] $allow
     */
    protected function buildMigrateV2Payload(string $schema_name, ?string $uuid, bool $force, array $allow = []): array
    {
        $payload = [
            'platform' => $this->platform,
            'schema_name' => $schema_name,
            'force' => $force,
        ];
        if ($uuid !== null) {
            $payload['uuid'] = $uuid;
        }
        if (!empty($allow)) {
            $payload['allow'] = array_values($allow);
        }
        return $payload;
    }

    /**
     * Migrate all databases for a platform using a stored schema.
     *
     * POST /v2/migrate-all
     *
     * @param string $schema_name Schema version to migrate to
     * @param bool $force Bypass DATALOSS safety checks (needs the admin token, see migrateV2)
     * @param string[] $allow Granular guard overrides (needs the admin token, see migrateV2)
     * @return array Gateway response with per-database results
     * @throws GatewayException If migration fails
     */
    public function migrateAllV2(string $schema_name, bool $force = false, array $allow = []): array
    {
        $url = $this->gateway_url . '/v2/migrate-all';

        $payload = [
            'platform' => $this->platform,
            'schema_name' => $schema_name,
            'force' => $force,
        ];
        if (!empty($allow)) {
            $payload['allow'] = array_values($allow);
        }

        $response = $this->httpPost($url, $payload, self::CRED_DEPLOY, 300, $force || !empty($allow));
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
     * Set the platform token sent as `Authorization: Bearer <token>` on gateway requests.
     *
     * @param string|null $token Platform token (null or empty disables it)
     * @return self For method chaining
     */
    public function setPlatformToken(#[\SensitiveParameter] ?string $token): self
    {
        $this->platform_token = ($token !== null && $token !== '') ? $token : null;
        return $this;
    }

    /**
     * Set the deploy-scope token (deploy manager only; never an app server).
     *
     * @param string|null $token Deploy token (null or empty disables it)
     * @return self For method chaining
     */
    public function setDeployToken(#[\SensitiveParameter] ?string $token): self
    {
        $this->deploy_token = ($token !== null && $token !== '') ? $token : null;
        return $this;
    }

    /**
     * Whether a deploy token is configured (the token itself is never exposed).
     */
    public function hasDeployToken(): bool
    {
        return $this->deploy_token !== null;
    }

    /**
     * Whether a platform token is configured (the token itself is never exposed).
     */
    public function hasPlatformToken(): bool
    {
        return $this->platform_token !== null;
    }

    /**
     * Build the Authorization header line, or null when no credential applies.
     *
     * - CRED_PLATFORM: the runtime token.
     * - CRED_RUNTIME_PREFERRED: the runtime token when set, else the deploy token.
     * - CRED_DEPLOY: the deploy token.
     * - CRED_ADMIN_PREFERRED: the admin token when set, else the deploy token.
     */
    protected function buildAuthorizationHeader(string $credential = self::CRED_PLATFORM): ?string
    {
        $token = match ($credential) {
            self::CRED_PLATFORM => $this->platform_token,
            self::CRED_RUNTIME_PREFERRED => $this->platform_token ?? $this->deploy_token,
            self::CRED_DEPLOY => $this->deploy_token,
            self::CRED_ADMIN_PREFERRED => ($this->admin_token ?: null) ?? $this->deploy_token,
            default => null,
        };
        return $token !== null ? 'Authorization: Bearer ' . $token : null;
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
     * @param string $credential Which credential goes in the Authorization header
     * @param int|null $timeout Override timeout for this request
     * @param bool $send_admin_header Also send the admin token in X-Gateway-Admin-Token
     * @return array The decoded JSON response
     * @throws GatewayException If the request fails
     */
    private function httpPost(string $url, #[\SensitiveParameter] array $data, string $credential = self::CRED_PLATFORM, ?int $timeout = null, bool $send_admin_header = false): array
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

        $auth = $this->buildAuthorizationHeader($credential);
        if ($auth !== null) {
            $headers[] = $auth;
        }
        // The admin token goes only to the configured gateway URL.
        if ($send_admin_header && $this->admin_token && $this->isConfiguredGatewayUrl($url)) {
            $headers[] = 'X-Gateway-Admin-Token: ' . $this->admin_token;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json_payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout ?? $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);


        if ($curl_errno !== 0) {
            throw new GatewayException("Gateway request failed: $curl_error (errno: $curl_errno)");
        }

        if ($http_code < 200 || $http_code >= 300) {
            throw $this->buildHttpError($http_code, $response, isset($data['function']) && is_string($data['function']) ? $data['function'] : null, $credential);
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
     * True when `$url` is under the gateway URL this client was configured with.
     */
    private function isConfiguredGatewayUrl(string $url): bool
    {
        return $url === $this->gateway_url || str_starts_with($url, $this->gateway_url . '/');
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
    private function httpPostMultipart(string $url, #[\SensitiveParameter] string $body, string $boundary, int $timeout = 60): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new GatewayException('Failed to initialize curl');
        }

        $mp_headers = [
            "Content-Type: multipart/form-data; boundary={$boundary}",
            'Content-Length: ' . strlen($body)
        ];
        $auth = $this->buildAuthorizationHeader(self::CRED_DEPLOY);
        if ($auth !== null) {
            $mp_headers[] = $auth;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $mp_headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);


        if ($curl_errno !== 0) {
            throw new GatewayException("Gateway request failed: $curl_error (errno: $curl_errno)");
        }

        if ($http_code < 200 || $http_code >= 300) {
            throw $this->buildHttpError($http_code, $response, null, self::CRED_DEPLOY);
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
     * Build an identifier-only GatewayException from a non-200 gateway response.
     *
     * The raw `cause` / `message` text (PostgreSQL message, DETAIL, HINT, RAISE EXCEPTION text)
     * can echo customer data, so it is NOT put in the exception message, `last_error` or any log.
     * It stays reachable only through GatewayException::getRawCauseForDebug().
     *
     * @param string|false $response
     */
    private function buildHttpError(int $http_code, #[\SensitiveParameter] $response, ?string $function_name, string $credential = self::CRED_PLATFORM): GatewayException
    {
        $error_body = null;
        if ($response !== false && $response !== '' && $response !== null) {
            $decoded = json_decode((string) $response, true);
            $error_body = is_array($decoded) ? $decoded : null;
        }
        if ($error_body === null) {
            $error_body = [];
        }
        if ($function_name !== null && !isset($error_body['function'])) {
            $error_body['function'] = $function_name;
        }
        if (!isset($error_body['correlation_id']) && !isset($error_body['request_id'])) {
            $error_body['client_ref'] = bin2hex(random_bytes(4));
        }

        $probe = new GatewayException('', $http_code, null, $error_body);
        $ctx = $probe->getSafeContext();
        $message = "Gateway returned HTTP $http_code";
        if (isset($ctx['error'])) {
            $message .= ': ' . $ctx['error'];
        }
        $message .= GatewayException::formatSafeSuffix($ctx);
        $code = is_string($error_body['error'] ?? null) ? $error_body['error'] : '';
        if ($http_code === 401) {
            $message .= match ($credential) {
                self::CRED_DEPLOY => ' — the gateway requires a valid deploy token (the deploy manager supplies DB_GATEWAY_DEPLOY_TOKEN)',
                self::CRED_ADMIN_PREFERRED => ' — the gateway requires the admin token or a valid deploy token for this step',
                self::CRED_RUNTIME_PREFERRED => ' — the gateway requires a valid runtime token (set DB_GATEWAY_PLATFORM_TOKEN)',
                default => ' — the gateway requires a valid platform token (set DB_GATEWAY_PLATFORM_TOKEN)',
            };
        } elseif ($http_code === 403) {
            if ($code === 'platform_isolation_violation') {
                $message .= ' — the platform token belongs to a different platform than the one named in the request';
            } elseif ($code === 'scope_not_permitted') {
                $message .= ' — this token\'s scope does not allow this operation (runtime tokens serve /call and new tenant databases; deploy tokens are for the deploy manager)';
            } elseif ($code === 'admin_scope_required') {
                $message .= ' — force/allow need the gateway admin token (set DB_GATEWAY_ADMIN_TOKEN)';
            } elseif ($code === 'unauthorized') {
                $message .= ' — the gateway does not accept requests from this address (check its ALLOWED_NETWORKS)';
            } else {
                $message .= ' — the gateway refused the request';
            }
        }

        return new GatewayException($message, $http_code, null, $error_body);
    }
}
