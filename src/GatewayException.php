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
     * Create a new gateway exception.
     *
     * @param string $message The exception message
     * @param int $code The HTTP status code (if applicable)
     * @param Exception|null $previous Previous exception
     */
    public function __construct(string $message = "", int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
