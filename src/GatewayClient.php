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
 * @version 1.0.0
 */
class GatewayClient
{
    private string $gateway_url;
    private ?string $platform;
    private ?string $tenant_id;
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
     * @param string|null $tenant_id Optional tenant identifier for multi-tenant apps
     */
    public function __construct(string $gateway_url, ?string $platform = null, ?string $tenant_id = null)
    {
        if (!extension_loaded('curl')) {
            throw new Exception('GatewayClient requires the curl extension');
        }

        $this->gateway_url = rtrim($gateway_url, '/');
        $this->platform = $platform;
        $this->tenant_id = $tenant_id;
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

        $payload = [
            'platform' => $this->platform,
            'tenant_id' => $this->tenant_id,
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

            // Log execution time from gateway if available
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

    /**
     * Register schema with the gateway.
     * Uploads .tar.gz archive of PostgreSQL schema (migrations, functions, tables).
     *
     * @param string $schema_archive_path Path to .tar.gz schema archive
     * @param array $options Optional registration options
     * @return array Gateway response with migration/function counts
     * @throws GatewayException If registration fails
     */
    public function register(string $schema_archive_path, array $options = []): array
    {
        if (!file_exists($schema_archive_path)) {
            throw new GatewayException("Schema archive not found: {$schema_archive_path}");
        }

        $url = $this->gateway_url . '/register';
        $boundary = uniqid();
        $body = '';

        // Add platform field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"platform\"\r\n\r\n";
        $body .= "{$this->platform}\r\n";

        // Add tenant_id if present
        if ($this->tenant_id) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"tenant_id\"\r\n\r\n";
            $body .= "{$this->tenant_id}\r\n";
        }

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
     * Migrate schema to the gateway (hot update).
     * Similar to register but uses /migrate endpoint.
     *
     * @param string $schema_archive_path Path to .tar.gz schema archive
     * @param array $options Optional migration options (e.g., tenant_id)
     * @return array Gateway response with databases updated
     * @throws GatewayException If migration fails
     */
    public function migrate(string $schema_archive_path, array $options = []): array
    {
        if (!file_exists($schema_archive_path)) {
            throw new GatewayException("Schema archive not found: {$schema_archive_path}");
        }

        $url = $this->gateway_url . '/migrate';
        $boundary = uniqid();
        $body = '';

        // Add platform field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"platform\"\r\n\r\n";
        $body .= "{$this->platform}\r\n";

        // Add tenant_id if present (from options or instance property)
        $tenant_id = $options['tenant_id'] ?? $this->tenant_id;
        if ($tenant_id) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"tenant_id\"\r\n\r\n";
            $body .= "{$tenant_id}\r\n";
        }

        // Add schema file
        $fileContent = file_get_contents($schema_archive_path);
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"schema\"; filename=\"postgresql.tar.gz\"\r\n";
        $body .= "Content-Type: application/gzip\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $response = $this->httpPostMultipart($url, $body, $boundary, 120);

        $this->connected = true;
        $this->last_error = null;

        return $response;
    }

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

    /**
     * Set the tenant ID for subsequent requests.
     *
     * @param string|null $tenant_id The tenant identifier
     * @return self For method chaining
     */
    public function setTenantId(?string $tenant_id): self
    {
        $this->tenant_id = $tenant_id;
        return $this;
    }

    /**
     * Get the current tenant ID.
     *
     * @return string|null The current tenant identifier
     */
    public function getTenantId(): ?string
    {
        return $this->tenant_id;
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

    /**
     * Perform an HTTP POST request to the gateway (JSON).
     *
     * @param string $url The URL to post to
     * @param array $data The data to send as JSON
     * @return array The decoded JSON response
     * @throws GatewayException If the request fails
     */
    private function httpPost(string $url, array $data): array
    {
        $json_payload = json_encode($data);

        if ($json_payload === false) {
            throw new GatewayException('Failed to encode request payload as JSON: ' . json_last_error_msg());
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new GatewayException('Failed to initialize curl');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json_payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($json_payload)
            ],
            CURLOPT_TIMEOUT => $this->timeout,
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

            // Try to extract error message from response
            if ($response !== false && !empty($response)) {
                $error_body = json_decode($response, true);
                if (is_array($error_body) && isset($error_body['error'])) {
                    $error_message .= ': ' . $error_body['error'];
                }
            }

            throw new GatewayException($error_message, $http_code);
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

            if ($response !== false && !empty($response)) {
                $error_body = json_decode($response, true);
                if (is_array($error_body) && isset($error_body['error'])) {
                    $error_message .= ': ' . $error_body['error'];
                }
            }

            throw new GatewayException($error_message, $http_code);
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
