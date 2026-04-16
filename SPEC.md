# stonescriptdb-gateway-client — Interface Specification

> **What this document is:** The contract between the PHP client library and the
> StoneScriptDB Gateway HTTP API. It is written from the perspective of the PHP
> layer (StoneScriptPHP) calling the gateway. It is **not** a code reference — see
> `src/GatewayClient.php` for the implementation.

---

## 1. Overview

The StoneScriptDB Gateway is a Rust HTTP service that sits in front of PostgreSQL.
It exposes a small REST API over which PHP platform APIs (built with StoneScriptPHP)
call PostgreSQL functions, manage schemas, and validate JWT tokens.

```
Angular Frontend
     │  (JWT in Authorization header)
     ▼
PHP Platform API  ──────────────────────────────────────────────┐
 ├─ TokenValidator  ──► GET /auth/jwks                          │
 │     (validate incoming JWT before processing request)        │
 └─ GatewayClient  ──► POST /call  (call PostgreSQL functions)  │
                    ──► POST /platform/register                  │
                    ──► POST /platform/{p}/schema               │
                    ──► POST /v2/migrate                        │
                    ──► POST /admin/database/create             │
                    ──► GET  /health                            ▼
                                                       StoneScriptDB Gateway
                                                              │
                                                              ▼
                                                         PostgreSQL
```

The PHP library ships two components:

| Class | Purpose |
|---|---|
| `StoneScriptDB\GatewayClient` | HTTP client — all DB and schema operations |
| `StoneScriptDB\Auth\TokenValidator` | JWT validation via the gateway's JWKS endpoint |

---

## 2. Transport

All communication is plain HTTP (internal network only — never exposed to the public internet).

| Property | Value |
|---|---|
| Protocol | HTTP/1.1 |
| Content-Type (request) | `application/json` (or `multipart/form-data` for schema upload) |
| Accept (request) | `application/json` |
| Content-Type (response) | `application/json` |
| Charset | UTF-8 |
| Default timeout | 30 s request, 10 s connect |

The gateway URL is configured at construction time:

```php
$client = new GatewayClient('http://gateway:9000', 'myplatform');
```

---

## 3. Endpoints Used by the PHP Client

### 3.1 `POST /call` — Call a PostgreSQL Function

The primary data path. Calls a named PostgreSQL function and returns its result rows.

**Request**

```json
{
  "platform":  "myplatform",
  "tenant_id": "tenant-uuid-or-null",
  "function":  "get_users",
  "params":    ["value1", 42, null]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `platform` | string | yes | Platform identifier (determines which PostgreSQL database) |
| `tenant_id` | string \| null | no | Tenant UUID for multi-tenant databases; omit for the shared/main database |
| `function` | string | yes | PostgreSQL function name (lowercase, underscores only, max 63 chars) |
| `params` | JSON array | yes | Positional parameter list; can be empty (`[]`) |

Function name rules (enforced by gateway, not the PHP client):
- Characters: `[a-z0-9_]` only
- First character: letter or `_`
- Max length: 63 characters

Parameter value mapping:

| PHP value | JSON sent | PostgreSQL type |
|---|---|---|
| `null` | `null` | `NULL` |
| `true` / `false` | `true` / `false` | `boolean` |
| integer / float | number | numeric |
| string | `"…"` | `text` (or cast if registered) |
| array (list) | `[…]` | `jsonb` (or typed array if registered) |
| array (map) | `{…}` | `jsonb` |
| byte array | `[72, 101, …]` + type `bytea` | `\xHEX::bytea` |

The gateway looks up a per-function type registry (`_stonescriptdb_gateway_functions`)
and casts string values to registered types (e.g., `::uuid`, `::timestamptz`).

**Success Response — HTTP 200**

```json
{
  "rows": [
    { "id": "uuid-here", "name": "Alice", "created_at": "2026-04-12T10:00:00Z" },
    { "id": "uuid-here", "name": "Bob",   "created_at": "2026-04-11T09:00:00Z" }
  ],
  "row_count": 2,
  "execution_time_ms": 4
}
```

| Field | Type | Description |
|---|---|---|
| `rows` | array of objects | Each object is one result row; keys are column names |
| `row_count` | integer | Number of rows returned |
| `execution_time_ms` | integer | Gateway-measured query time in milliseconds |

PostgreSQL → JSON type conversions:

| PostgreSQL type | JSON representation |
|---|---|
| `bool` | `true` / `false` |
| `int2`, `int4`, `int8` | number |
| `float4`, `float8`, `numeric` | number |
| `json`, `jsonb` | object or array (native JSON) |
| `timestamptz` | RFC 3339 string (e.g., `"2026-04-12T10:00:00Z"`) |
| `timestamp` | ISO 8601 string without timezone (e.g., `"2026-04-12 10:00:00"`) |
| `date` | `"2026-04-12"` |
| `time` | `"10:00:00"` |
| `NULL` | `null` |
| everything else | string |

The PHP client returns `$response['rows']` — an array of associative arrays.

---

### 3.2 `GET /health` — Liveness Probe

Used by `GatewayClient::healthCheck()` to confirm the gateway is reachable.

**Request** — no body, no auth.

**Response — HTTP 200** — body is not parsed; only the status code matters.

---

### 3.3 `POST /platform/register` — Register a Platform

Idempotent. Tells the gateway about a new platform so it can manage its databases.

**Request**

```json
{
  "platform":    "myplatform",
  "db_user":     "myplatform_user",
  "db_password": "s3cr3t"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `platform` | string | yes | Platform identifier |
| `db_user` | string | no | Dedicated PostgreSQL user for database isolation |
| `db_password` | string | no | Password for the dedicated user |

Omitting `db_user`/`db_password` registers the platform using the gateway's own
default PostgreSQL credentials (less isolated).

**Success Response — HTTP 201**

```json
{
  "status":                   "registered",
  "platform":                 "myplatform",
  "message":                  "Platform registered …",
  "has_dedicated_credentials": true
}
```

**Already-registered** — HTTP 409 `platform_already_registered` (idempotent callers
should ignore this or check before calling).

---

### 3.4 `POST /platform/{platform}/schema` — Upload a Schema Archive

Uploads a `.tar.gz` archive containing the PostgreSQL schema for a platform.

**Request** — `multipart/form-data`

| Part | Description |
|---|---|
| `schema_name` (text field) | Schema version label, e.g., `"v1.0"` or `"latest"` |
| `schema` (file, `application/gzip`) | The `.tar.gz` archive containing `.pgsql` files |

Archive internal structure expected by the gateway:

```
tables/       *.pgsql   — CREATE TABLE definitions
functions/    *.pgsql   — CREATE OR REPLACE FUNCTION definitions
migrations/   *.pgsql   — ALTER TABLE, data migrations (run in order)
extensions/   *.pgsql   — CREATE EXTENSION IF NOT EXISTS …
types/        *.pgsql   — CREATE TYPE definitions (enums, composites)
seeders/      *.pgsql   — INSERT INTO … (reference/seed data)
```

**Success Response — HTTP 201**

```json
{
  "status":        "registered",
  "platform":      "myplatform",
  "schema_name":   "v1.0",
  "has_tables":    true,
  "has_functions": true,
  "has_migrations": false,
  "checksum":      "sha256:abcdef..."
}
```

**Timeout:** The PHP client uses 60 s for this call (schema files can be large).

---

### 3.5 `POST /v2/migrate` — Migrate One Database

Applies the stored schema to a single database (main or a specific tenant).

**Request**

```json
{
  "platform":    "myplatform",
  "schema_name": "v1.0",
  "database_id": "main",
  "force":       false
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `platform` | string | yes | Platform identifier |
| `schema_name` | string | yes | Schema version stored via `/platform/{p}/schema` |
| `database_id` | string | yes | `"main"` for the shared database, or the tenant UUID |
| `force` | boolean | no | Bypass DATALOSS safety checks (default `false`) |

**Success Response — HTTP 200**

```json
{
  "status":             "completed",
  "platform":           "myplatform",
  "schema_name":        "v1.0",
  "databases_updated":  ["myplatform_main"],
  "migrations_applied": 3,
  "tables_created":     5,
  "functions_updated":  12,
  "seeder_validations": [],
  "schema_validation":  { "safe_changes": [], "dataloss_changes": [], "incompatible_changes": [] },
  "verification":       { "passed": true, "extensions_verified": true, "types_verified": true,
                          "tables_verified": true, "seeders_verified": true, "error_log": null },
  "execution_time_ms":  248
}
```

`status` is `"completed"` when verification passed, `"completed_with_warnings"` when
verification failed but `force` was `true`.

**Timeout:** The PHP client uses 120 s for this call.

---

### 3.6 `POST /v2/migrate-all` — Migrate All Tenant Databases

Like `/v2/migrate` but runs against every database for the platform (main + all tenants).

**Request**

```json
{
  "platform":    "myplatform",
  "schema_name": "v1.0",
  "force":       false
}
```

**Success Response — HTTP 200** — same shape as `/v2/migrate` but
`databases_updated` lists every database touched.

**Timeout:** The PHP client uses 300 s (5 min) for this call.

---

### 3.7 `POST /admin/database/create` — Create a Tenant Database

Creates a new PostgreSQL database from a stored schema. Requires admin auth.

**Authentication** — `Authorization: Bearer <ADMIN_TOKEN>` header.

**Request**

```json
{
  "platform":    "myplatform",
  "schema_name": "v1.0",
  "database_id": "tenant-uuid-here"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `platform` | string | yes | Platform identifier |
| `schema_name` | string | yes | Schema version to deploy |
| `database_id` | string | yes | Tenant UUID (becomes part of the database name) |

**Success Response — HTTP 201**

```json
{
  "status": "created",
  "platform": "myplatform",
  "schema_name": "v1.0",
  "database_name": "myplatform_tenant-uuid-here",
  "extensions_installed": 2,
  "types_deployed": 3,
  "tables_created": 10,
  "functions_deployed": 25,
  "seeders": [
    { "table": "roles", "inserted": 5, "skipped": 0 }
  ],
  "execution_time_ms": 1234
}
```

---

### 3.8 `GET /auth/jwks` — JSON Web Key Set

Used by `TokenValidator` to fetch the gateway's RSA public key for JWT verification.

**Request** — no body, no auth.

**Response — HTTP 200**

```json
{
  "keys": [
    {
      "kty": "RSA",
      "use": "sig",
      "alg": "RS256",
      "n":   "<base64url-encoded modulus>",
      "e":   "<base64url-encoded exponent>"
    }
  ]
}
```

The PHP `TokenValidator` converts this JWK to PEM format and passes it to
`firebase/php-jwt` for signature verification. The public key is cached in memory
for 1 hour (`CACHE_TTL = 3600`).

---

## 4. Authentication Model

The gateway enforces two separate auth layers:

### 4.1 IP Allowlist (Gateway-level)

`/call`, `/platform/*`, `/v2/migrate*` are IP-filtered. The PHP API must run
inside the allowed network range configured on the gateway
(`ALLOWED_NETWORKS` env var). No token is required for these endpoints.

### 4.2 Admin Token (Admin endpoints)

`/admin/*` endpoints require:

```
Authorization: Bearer <ADMIN_TOKEN>
```

The PHP client sends this header when `$with_admin_auth = true` and
`$this->admin_token` is set. Currently only `createDatabase()` uses this.

### 4.3 JWT Validation (Inbound requests from Angular)

The gateway itself does **not** validate JWTs on `/call`. The PHP API is
responsible for validating the user's JWT before calling the gateway. The
`TokenValidator` class performs this:

1. Fetches JWKS from `GET /auth/jwks`
2. Verifies signature using RS256
3. Checks `exp` claim
4. Returns `TokenClaims` with user context

The `tenant_id` extracted from the JWT is then passed to `GatewayClient` so the
correct tenant database is queried.

---

## 5. Error Responses

All gateway errors return JSON in this shape:

```json
{
  "error":    "query_failed",
  "message":  "Query for function 'get_users' failed",
  "database": "myplatform_main",
  "cause":    "ERROR: function get_users() does not exist | HINT: …"
}
```

| Field | Type | Always present |
|---|---|---|
| `error` | string | yes — machine-readable error code |
| `message` | string | yes — human-readable description |
| `database` | string | no — which database was involved |
| `cause` | string | no — raw PostgreSQL error detail |

### HTTP Status Codes and Error Codes

| HTTP | `error` field | Meaning |
|---|---|---|
| 400 | `invalid_request` | Bad request (invalid function name, missing field, etc.) |
| 400 | `schema_extraction_failed` | Uploaded archive could not be unpacked |
| 400 | `extension_not_available` | Requested PostgreSQL extension not installed on server |
| 401 | `signature_verification_failed` | Admin request with invalid signature |
| 401 | `timestamp_expired` | Admin request replay protection triggered |
| 401 | `invalid_client_id` | Unknown client ID in admin request |
| 403 | `unauthorized` | Request from an IP not in the allowlist |
| 403 | `platform_isolation_violation` | Cross-platform database access attempt |
| 403 | `unauthorized_function` | Function not in client's allowed list |
| 404 | `database_not_found` | Platform/tenant combination has no database |
| 409 | `database_already_exists` | `createDatabase` called for an existing DB |
| 409 | `platform_already_registered` | `registerPlatform` called for existing platform |
| 500 | `migration_failed` | Schema migration error |
| 500 | `function_deploy_failed` | PostgreSQL function deployment error |
| 500 | `query_failed` | PostgreSQL function execution error |
| 500 | `extension_install_failed` | Failed to `CREATE EXTENSION` |
| 500 | `internal_error` | Unclassified internal error |
| 503 | `connection_failed` | Cannot connect to PostgreSQL |
| 503 | `pool_exhausted` | All connections in the pool are in use |

The PHP `GatewayException` carries the HTTP status code as its `$code` and the
human-readable message as `$message`. The raw `error` code and `cause` are not
currently surfaced as separate fields on the exception — callers inspect
`getMessage()` for the combined error string.

---

## 6. Multi-Tenant Database Routing

The gateway resolves the target PostgreSQL database from `platform` + `tenant_id`:

| `tenant_id` | Database name |
|---|---|
| `null` / omitted | `{platform}_main` |
| `"some-tenant-uuid"` | `{platform}_{tenant_id}` |

The PHP client sets `$tenant_id` at construction or via `setTenantId()`. It is
included in every `/call` request automatically.

---

## 7. PHP Client Reference Summary

### `GatewayClient`

| Method | Endpoint | Auth |
|---|---|---|
| `callFunction(string $fn, array $params)` | `POST /call` | IP allowlist |
| `registerPlatform()` | `POST /platform/register` | IP allowlist |
| `uploadSchema(string $path, string $name)` | `POST /platform/{p}/schema` | IP allowlist |
| `createDatabase(string $schema, string $dbId)` | `POST /admin/database/create` | Admin token |
| `migrateV2(string $schema, string $dbId, bool $force)` | `POST /v2/migrate` | IP allowlist |
| `migrateAllV2(string $schema, bool $force)` | `POST /v2/migrate-all` | IP allowlist |
| `healthCheck()` | `GET /health` | None |

### `TokenValidator`

| Method | Endpoint | Purpose |
|---|---|---|
| `validateToken(string $jwt)` | `GET /auth/jwks` (cached) | Verify JWT signature and expiry; return `TokenClaims` |
| `getPublicKey()` | `GET /auth/jwks` (cached) | Return PEM key (fetches if cache expired) |
| `refreshPublicKey()` | `GET /auth/jwks` | Force-refresh the cached public key |

### `TokenClaims` Fields

| Field | Type | Description |
|---|---|---|
| `identity_id` | string (UUID) | User's identity UUID |
| `tenant_id` | string (UUID) | Tenant the token is scoped to |
| `tenant_slug` | string | Human-readable tenant slug |
| `platform_code` | string | Platform the token belongs to |
| `role` | string | User's role in this tenant |
| `local_user_id` | string \| null | Platform-specific user record ID |
| `exp` | int | Unix timestamp — token expiry |
| `iat` | int | Unix timestamp — token issued-at |

---

## 8. Timeouts

| Operation | Timeout |
|---|---|
| Health check | 5 s |
| Function call (`/call`) | 30 s (default) |
| Schema upload (`/platform/{p}/schema`) | 60 s |
| Single migration (`/v2/migrate`) | 120 s |
| Bulk migration (`/v2/migrate-all`) | 300 s |
| Admin operations (`/admin/*`) | 30 s (default) |
| JWKS fetch (`/auth/jwks`) | 10 s |

---

## 9. What This Client Does NOT Do

The following operations belong to other components and are **out of scope** for
this library:

| Operation | Where to do it |
|---|---|
| User login / registration | Call the Auth Service directly (Angular or PHP Auth Clients) |
| Token refresh | Call the Auth Service directly |
| Membership management | Angular → Auth Service, or StoneScriptPHP `MembershipClient` |
| User invitations | Angular → Auth Service, or StoneScriptPHP `InvitationClient` |
| OAuth flows | Auth Service |
| Password reset | Auth Service |
