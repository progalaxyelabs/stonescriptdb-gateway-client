# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.0] - 2026-10-21

MAJOR release (message contract change). Nothing changes for an application until it opts in to `^4.0`.

### Security
- `GatewayException` / `GatewayClient` never put the raw gateway `cause` (PostgreSQL message, `DETAIL`, `HINT`,
  `CONTEXT`, text and values of a `RAISE EXCEPTION`) in exception messages, `getLastError()`, `previous` chains,
  `__toString()`, `print_r`/`var_dump`/`var_export`, `(array)` casts or `serialize()`.
- Messages are identifier-only: `Gateway returned HTTP 500: query_failed [database=.. function=.. sqlstate=..
  constraint=.. code=.. ref=..]`. Only allow-listed, shape-validated fields are kept; a value that fails validation
  is shown as `<redacted>`; secret-shaped tokens (long hex, digit or base64 runs) are refused.
- The raw cause is NOT stored on the exception object. It lives in a weak registry and is reachable only through the
  debug-only `getRawCauseForDebug()`. Never log its result.
- Identifier extraction from the cause text (gateways that send no structured fields) reads ONLY the PostgreSQL
  primary message line, and only PostgreSQL's own fixed phrasings (`duplicate key value violates unique constraint
  "x"`, not-null, foreign key, check, undefined relation/column). It never reads DETAIL/HINT/CONTEXT or the trailing
  `SCHEMA:/TABLE:/COLUMN:` segments, and never takes identifiers out of a `P0001` `RAISE` text.
- The client no longer pretends a generated id is a gateway id: when the gateway sends no `correlation_id` /
  `request_id`, the message carries `client_ref=<id>` (not greppable in gateway logs).
- `#[\SensitiveParameter]` on query parameters and raw responses, so stack traces never show them.

### Added
- Application error code contract. `RAISE EXCEPTION '<code>'` or `'<code>: <free text>'`. `getAppErrorCode()` returns
  the code (a token at the very start of the primary message: `[A-Za-z][A-Za-z0-9_.-]{0,63}` followed by `:` or end of
  line, or a bracketed `[ERxxx]` / `[public:code:status]` tag) and the message carries `code=<token>`. Free text is
  never surfaced. Sentence-style RAISEs yield no code. The client prefers gateway-sent structured fields
  (`sqlstate`, `app_code`, `constraint`, `correlation_id`, `table`, `column`, `schema`) over parsing.
- Safe accessors: `getSafeContext()`, `getSqlState()`, `getConstraint()`, `getColumn()`, `getAppErrorCode()`,
  `getCorrelationId()`, `getClientRef()`, `hasRawCause()`.

### UPGRADE from 3.x
1. Replace `str_contains($e->getMessage(), '<postgres text>')` with `getAppErrorCode()` (business errors),
   `getConstraint()` / `getSqlState()` (database errors), or `getRawCauseForDebug()` (debug only).
2. Change sentence-style RAISEs to codes: `RAISE EXCEPTION 'insufficient_stock: item %', id;`. Only the code is surfaced.
3. `getCause()` still works but is deprecated and returns raw text. Never log it.
4. The gateway's free-text `message` field is no longer appended to the exception message.

### Fixed
- `GatewayClientRoutingTest` constructed clients without the (required) `schema_name`.

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
