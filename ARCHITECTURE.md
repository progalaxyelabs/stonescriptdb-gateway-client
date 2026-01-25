# Architecture & Design Decisions

## Purpose

This library is the **database client** for StoneScriptDB Gateway. It provides:

1. **Database operations** - Call PostgreSQL functions via HTTP
2. **Schema management** - Register and migrate database schemas
3. **JWT token validation** - Validate tokens from ProGalaxy Auth Service

## What This Library Does NOT Do

This library does NOT handle:
- User login/registration (use ProGalaxy Auth Service directly)
- Membership management (use StoneScriptPHP Auth Clients)
- User invitations (use StoneScriptPHP Auth Clients)
- Backend-to-backend auth operations (use StoneScriptPHP Auth Clients)

## Architecture Separation

```
┌─────────────────────────────────────────────────────────────┐
│  Angular Frontend                                            │
│  • Login/register → Auth Service (direct HTTP)              │
│  • API calls → Platform API (JWT in header)                 │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│  PHP Platform API (StoneScriptPHP)                          │
│                                                              │
│  [Request Middleware]                                        │
│    ↓                                                         │
│  TokenValidator (from this library)                         │
│    • Validates JWT signature                                │
│    • Fetches JWKS from auth service                         │
│    • Extracts user/tenant context                           │
│                                                              │
│  [Route Handler]                                             │
│    ↓                                                         │
│  GatewayClient (from this library)                          │
│    • Calls PostgreSQL functions                             │
│    • Multi-tenant database routing                          │
│                                                              │
│  [Backend Automation - Optional]                            │
│    ↓                                                         │
│  Auth Clients (from StoneScriptPHP)                         │
│    • MembershipClient - Manage memberships                  │
│    • InvitationClient - Send invitations                    │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌──────────────────┐              ┌──────────────────┐
│ StoneScriptDB    │              │ ProGalaxy Auth   │
│ Gateway          │              │ Service          │
│ (PostgreSQL)     │              │ (Rust)           │
└──────────────────┘              └──────────────────┘
```

## Component Responsibilities

### stonescriptdb-gateway-client (This Library)

**Database Operations:**
```php
use StoneScriptDB\GatewayClient;

$client = new GatewayClient('http://gateway:9000', 'myapp');
$users = $client->callFunction('get_users', ['limit' => 10]);
```

**JWT Token Validation:**
```php
use StoneScriptDB\Auth\TokenValidator;

$validator = new TokenValidator('http://auth:3139');
$claims = $validator->validateToken($jwtToken);
```

### StoneScriptPHP Framework

**Auth Service Clients (Backend-to-Backend):**
```php
use StoneScriptPHP\Auth\Client\MembershipClient;
use StoneScriptPHP\Auth\Client\InvitationClient;

// Create membership after payment
$memberships = new MembershipClient('http://auth:3139');
$memberships->createMembership([
    'identity_id' => $userId,
    'tenant_id' => $tenantId,
    'role' => 'premium'
], $adminToken);

// Bulk invite users
$invitations = new InvitationClient('http://auth:3139');
$invitations->bulkInvite($userList, $adminToken);
```

## Why This Separation?

### Before (Problematic)

```php
// ❌ Platform API acting as proxy
POST /api/admin/memberships/{id}/role
  ↓
MembershipClient (in gateway-client) → Auth Service
```

**Problems:**
- Gateway client has non-database responsibilities
- Platform API becomes unnecessary proxy
- Frontend could call auth service directly
- Version coupling between gateway-client and auth service

### After (Clean)

```php
// ✅ Frontend calls auth service directly
Angular → Auth Service /memberships/{id}

// ✅ Backend automation uses framework auth clients
Payment Webhook → MembershipClient (in StoneScriptPHP) → Auth Service

// ✅ Request validation uses gateway-client
API Request → TokenValidator (in gateway-client) → Validates JWT
```

**Benefits:**
- Single responsibility per library
- No unnecessary network hops
- Clear separation of concerns
- Better testability

## When to Use What

### Use GatewayClient
- ✅ Calling PostgreSQL functions
- ✅ Registering database schemas
- ✅ Migrating databases
- ✅ Multi-tenant database operations

### Use TokenValidator
- ✅ Validating JWT tokens in request middleware
- ✅ Extracting user context from tokens
- ✅ Multi-tenant context extraction

### Use Auth Clients (StoneScriptPHP)
- ✅ Backend automation (webhooks, cron jobs)
- ✅ Bulk operations (mass invitations)
- ✅ System-initiated membership changes
- ✅ CLI tools

### Call Auth Service Directly (Frontend)
- ✅ User login/registration
- ✅ User profile management
- ✅ Admin managing memberships
- ✅ Sending user invitations

## Migration Guide

If you're using the old MembershipClient/InvitationClient from gateway-client:

### Option 1: Move to Frontend (Recommended for UI operations)
```typescript
// Angular - Call auth service directly
this.http.put(`${AUTH_SERVICE}/memberships/${id}`, {
  role: 'admin'
}, {
  headers: { Authorization: `Bearer ${token}` }
})
```

### Option 2: Use StoneScriptPHP Auth Clients (Backend automation)
```php
use StoneScriptPHP\Auth\Client\MembershipClient;

$client = new MembershipClient('http://auth:3139');
$client->updateMembership($id, ['role' => 'admin'], $token);
```

## Related Documentation

- [StoneScriptPHP Framework](https://github.com/progalaxyelabs/StoneScriptPHP)
- [ProGalaxy Auth Service](https://github.com/progalaxyelabs/progalaxyelabs-auth)
- [StoneScriptDB Gateway](https://github.com/progalaxyelabs/stonescriptdb-gateway)
