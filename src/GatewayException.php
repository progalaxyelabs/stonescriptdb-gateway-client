<?php

namespace StoneScriptDB;

use Exception;

/**
 * Exception thrown by GatewayClient when requests fail.
 *
 * @package StoneScriptDB
 */
class GatewayException extends Exception
{
    /**
     * Gateway error response body (if available).
     *
     * @var array|null
     */
    private ?array $gatewayErrorBody = null;

    /**
     * Create a new gateway exception.
     *
     * @param string $message The exception message
     * @param int $code The HTTP status code (if applicable)
     * @param Exception|null $previous Previous exception
     * @param array|null $errorBody The gateway error response body (optional)
     */
    public function __construct(string $message = "", int $code = 0, ?Exception $previous = null, ?array $errorBody = null)
    {
        parent::__construct($message, $code, $previous);
        $this->gatewayErrorBody = $errorBody;
    }

    /**
     * Get the underlying cause from the gateway error (e.g., PostgreSQL RAISE EXCEPTION text).
     *
     * @return string|null The cause text if present in the gateway response
     */
    public function getCause(): ?string
    {
        return $this->gatewayErrorBody['cause'] ?? null;
    }

    /**
     * Get the database name from the gateway error.
     *
     * @return string|null The database name if present
     */
    public function getDatabase(): ?string
    {
        return $this->gatewayErrorBody['database'] ?? null;
    }

    /**
     * Get the error code from the gateway response.
     *
     * @return string|null The gateway error code (e.g., 'query_failed')
     */
    public function getGatewayError(): ?string
    {
        return $this->gatewayErrorBody['error'] ?? null;
    }
}
