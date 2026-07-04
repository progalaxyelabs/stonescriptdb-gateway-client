# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.1.1] - 2026-07-04

### Fixed
- `GatewayClient::createDatabase()` and `::migrateV2()` now send `uuid` (previously
  `database_id`, which the gateway's `CreateDatabaseRequest` / `MigrateRequest` structs
  do not deserialize — it was silently dropped, leaving `uuid=None` and collapsing every
  tenant onto the shared `{platform}_{schema_name}` base database instead of a per-tenant
  database ("ghost tenants"). The `$database_id` parameter is renamed to `$uuid` on both
  methods to match the wire contract, and payload building is extracted into
  `buildCreateDatabasePayload()` / `buildMigrateV2Payload()` for unit-testing the exact
  outgoing JSON. Required for the strict (deny_unknown_fields) gateway v4.2.7, which now
  rejects an unknown `database_id` key with 422 instead of silently dropping it.

## [1.0.0] - 2026-01-05

### Added
- Initial release
- `GatewayClient` class for HTTP-based PostgreSQL function calls
- `callFunction()` method for executing database functions
- `register()` method for schema registration
- `migrate()` method for hot schema updates
- `healthCheck()` method for gateway availability
- Multi-tenant support with `setTenantId()` and `getTenantId()`
- Platform switching with `setPlatform()` and `getPlatform()`
- Configurable timeouts with `setTimeout()` and `setConnectTimeout()`
- Debug logging with `setDebug()`
- `GatewayException` for error handling
- **Authentication & Authorization:**
  - `TokenValidator` class for JWT token validation with JWKS support
  - `MembershipClient` class for user membership management
  - `InvitationClient` class for tenant user invitations
  - `TokenClaims`, `Membership`, and `Invitation` data objects
  - `InvalidTokenException` for token validation errors
- Comprehensive README with examples
- Laravel, CodeIgniter, and vanilla PHP integration examples
- MIT License

### Requirements
- PHP 8.1+
- curl extension
- json extension
- firebase/php-jwt ^6.0
