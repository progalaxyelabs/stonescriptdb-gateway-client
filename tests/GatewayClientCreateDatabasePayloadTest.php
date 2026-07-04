<?php

namespace StoneScriptDB\Tests;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayClient;

/**
 * Regression coverage for task #3165 — the ghost-tenant framework bug.
 *
 * ROOT CAUSE: GatewayClient::createDatabase() and ::migrateV2() POSTed a JSON
 * body with key `database_id`. The gateway's Rust request structs
 * (stonescriptdb-gateway src/api/database.rs CreateDatabaseRequest,
 * src/api/migrate.rs MigrateRequest) only recognize `uuid: Option<String>` —
 * there is no `database_id` field. Serde silently dropped the unrecognized
 * key (no deny_unknown_fields at the time), so `uuid` always deserialized to
 * None and every tenant's database collapsed onto the shared
 * `{platform}_{schema_name}` base database instead of its own per-tenant
 * `{platform}_{schema_name}_{uuid}` database ("ghost tenants").
 *
 * These tests pin the outgoing payload shape for both methods: `uuid` must be
 * present with the caller-supplied value, and `database_id` must never appear.
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
            'Regression (#3165): payload must never carry database_id — the gateway\'s ' .
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
            'Regression (#3165): payload must never carry database_id — the gateway\'s ' .
            'MigrateRequest struct does not have that field and silently drops it, ' .
            'defaulting uuid to None.'
        );
    }
}
