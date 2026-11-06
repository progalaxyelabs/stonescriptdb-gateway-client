<?php

namespace StoneScriptDB\Tests;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayClient;

/**
 * Pins the outgoing payload shape of createDatabase() and migrateV2(): `uuid`
 * must be present with the caller-supplied value, and `database_id` must never
 * appear (the gateway only recognises `uuid`).
 */
class GatewayClientCreateDatabasePayloadTest extends TestCase
{
    public function testCreateDatabasePayloadUsesUuidFieldNotDatabaseId(): void
    {
        $client = new class ('http://gateway:9000', 'myapp', 'main') extends GatewayClient {
            public function exposeBuildCreateDatabasePayload(string $schema_name, string $uuid): array
            {
                return $this->buildCreateDatabasePayload($schema_name, $uuid);
            }
        };

        $payload = $client->exposeBuildCreateDatabasePayload('tenant', 'tenant-uuid-123');

        $this->assertSame('myapp', $payload['platform']);
        $this->assertSame('tenant', $payload['schema_name']);
        $this->assertSame('tenant-uuid-123', $payload['uuid']);
        $this->assertArrayNotHasKey(
            'database_id',
            $payload,
            'payload must never carry database_id — the gateway\'s ' .
            'CreateDatabaseRequest struct does not have that field and silently drops ' .
            'it, defaulting uuid to None (the ghost-tenant root cause).'
        );
    }

    public function testMigrateV2PayloadUsesUuidFieldNotDatabaseId(): void
    {
        $client = new class ('http://gateway:9000', 'myapp', 'main') extends GatewayClient {
            public function exposeBuildMigrateV2Payload(string $schema_name, string $uuid, bool $force): array
            {
                return $this->buildMigrateV2Payload($schema_name, $uuid, $force);
            }
        };

        $payload = $client->exposeBuildMigrateV2Payload('tenant', 'tenant-uuid-456', true);

        $this->assertSame('myapp', $payload['platform']);
        $this->assertSame('tenant', $payload['schema_name']);
        $this->assertSame('tenant-uuid-456', $payload['uuid']);
        $this->assertTrue($payload['force']);
        $this->assertArrayNotHasKey(
            'database_id',
            $payload,
            'payload must never carry database_id — the gateway\'s ' .
            'MigrateRequest struct does not have that field and silently drops it, ' .
            'defaulting uuid to None.'
        );
    }
}
