<?php

namespace StoneScriptDB\Tests;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayException;

/**
 * Test GatewayException including the new cause/database/error accessors.
 */
class GatewayExceptionTest extends TestCase
{
    /**
     * Test basic exception construction without error body (backward compatibility).
     */
    public function testBasicExceptionWithoutErrorBody(): void
    {
        $exception = new GatewayException('Test error', 500);

        $this->assertEquals('Test error', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());
        $this->assertNull($exception->getCause());
        $this->assertNull($exception->getDatabase());
        $this->assertNull($exception->getGatewayError());
    }

    /**
     * Test exception with full gateway error body including cause field.
     *
     * Gateway returns a 500 with a PostgreSQL RAISE EXCEPTION message in the 'cause' field.
     */
    public function testExceptionWithCauseField(): void
    {
        $errorBody = [
            'error' => 'query_failed',
            'message' => 'Query for function \'create_record\' failed',
            'database' => 'example_db',
            'cause' => 'A record with this name already exists. Please choose a different name.'
        ];

        $message = "Gateway returned HTTP 500: query_failed - Query for function 'create_record' failed (Cause: A record with this name already exists. Please choose a different name.)";

        $exception = new GatewayException($message, 500, null, $errorBody);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());
        $this->assertEquals('A record with this name already exists. Please choose a different name.', $exception->getCause());
        $this->assertEquals('example_db', $exception->getDatabase());
        $this->assertEquals('query_failed', $exception->getGatewayError());
    }

    /**
     * Test that the message includes the cause text for backward compatibility
     * with existing str_contains() checks in app code.
     */
    public function testMessageIncludesCauseForBackwardCompatibility(): void
    {
        $errorBody = [
            'error' => 'query_failed',
            'message' => 'Query failed',
            'cause' => 'A record with this name already exists. Please choose a different name.'
        ];

        $message = "Gateway returned HTTP 500: query_failed - Query failed (Cause: A record with this name already exists. Please choose a different name.)";

        $exception = new GatewayException($message, 500, null, $errorBody);

        // Verify the existing str_contains() check pattern will work
        $this->assertStringContainsString('A record with this name already exists', $exception->getMessage());
    }

    /**
     * Test exception with partial error body (no cause field).
     */
    public function testExceptionWithoutCauseField(): void
    {
        $errorBody = [
            'error' => 'connection_failed',
            'message' => 'Could not connect to database'
        ];

        $exception = new GatewayException('Gateway returned HTTP 500: connection_failed - Could not connect to database', 500, null, $errorBody);

        $this->assertNull($exception->getCause());
        $this->assertNull($exception->getDatabase());
        $this->assertEquals('connection_failed', $exception->getGatewayError());
    }

    /**
     * Test exception with empty error body array.
     */
    public function testExceptionWithEmptyErrorBody(): void
    {
        $exception = new GatewayException('Gateway error', 500, null, []);

        $this->assertNull($exception->getCause());
        $this->assertNull($exception->getDatabase());
        $this->assertNull($exception->getGatewayError());
    }
}
