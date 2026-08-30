# TDD-002 — Platform Authentication & Membership Context Technical Design

**Version**: 1.2
**Status**: ACCEPTED / LOCKED
**Date**: 2026-08-26
**Scope**: Controlled Refactor — Auth, BrowserSession, User Membership Context, OpenAPI & Frontend Platform Runtime
**Product Authority**: PRD-002 — Platform Authentication & Membership Context
**Architecture Authority**: ADR-033 — Global Authentication & Membership Context Establishment
**Implementation State**: NOT STARTED
**Change Size**: MEDIUM / LOCALISED PLATFORM REFACTOR

---

> ## Technical Design Summary
>
> Refactor only the **pre-Tenant authentication boundary**. Preserve the existing membership-scoped runtime after Membership selection. Introduce global User authentication, explicit identity-scoped bearer credentials for stateless clients, identity-only BrowserSession support, and a canonical identity introspection endpoint. Existing Membership discovery and `SwitchMembership` remain the bridge into the existing `user_id + membership_id + tenant_id` Tenant context.

---

# 1. Current State Baseline

## [FAKTA] Current backend login

Current request:

```json
{
  "email": "ahmad@example.com",
  "password": "...",
  "tenant_uuid": "..."
}
```

Current path:

```text
LoginTokenRequest
→ AuthenticationCredentialIssuer
→ AuthenticationRepository::findByEmailForTenant()
→ User + Person + Membership + Tenant
→ TokenManager::issueToken(user, tenant, membership)
```

Current bearer claims:

```text
user_id
tenant_id
membership_id
expires_at
```

## [FAKTA] Existing reusable foundation

Already implemented and retained:

- global `Person` and global `User` relation;
- `Membership(person_id, tenant_id)` canonical ownership;
- active Membership discovery for User;
- same-Person Membership switch validation;
- active Tenant verification;
- membership-scoped token issuance;
- `InjectAuthenticatedUser` and `InjectTenantContext` separation;
- BrowserSession server-side credential vault;
- browser Membership switch endpoint;
- tab-local Membership context architecture;
- frontend `membership-context-required` / `selection-required` states;
- database-backed Role/Permission authorization.

---

# 2. Target Architecture

```text
                    ┌──────────────────────────┐
                    │ identifier + password    │
                    └─────────────┬────────────┘
                                  │
                                  ▼
                       Global User Authentication
                                  │
                     ┌────────────┴────────────┐
                     │                         │
                  Browser                  API/Mobile
                     │                         │
            BrowserSession user_id       Identity Bearer
                     │                         │
                     └────────────┬────────────┘
                                  ▼
                         Identity Context
                                  │
                                  ▼
                        Membership Discovery
                                  │
                     ┌────────────┼─────────────┐
                     │            │             │
                     0            1            >1
                     │            │             │
                 No Tenant      Auto         Select
                                  │             │
                                  └──────┬──────┘
                                         ▼
                              SwitchMembership
                                         │
                                         ▼
                           Membership Credential
                           user + membership + tenant
                                         │
                                         ▼
                             Existing TenantContext
                                         │
                                         ▼
                       Existing Authorization / Modules
```

Design goal:

```text
PRE-TENANT boundary = changed
POST-TENANT boundary = preserved
```

---

# 3. Module Boundaries

## Auth module owns

- credential input validation;
- global User credential verification;
- identity-scoped credential issuance;
- membership-scoped token cryptographic format support;
- identity request middleware;
- Tenant request middleware composition;
- BrowserSession authentication lifecycle;
- login/logout/identity introspection transport.

## User module owns

- `my-memberships` discovery;
- Membership switch orchestration;
- membership selector transport endpoints.

## Core owns

- canonical User / Person persistence;
- Membership persistence contract;
- Tenant runtime resolution;
- RBAC and global-superadmin authorization primitives.

## Business modules

No new responsibility. They continue consuming verified Tenant/Membership context.

---

# 4. Data Design

## 4.1 Existing tables retained

No structural redesign for:

```text
persons
tenants
memberships
roles
permissions
role_permissions
membership_roles
organizational_assignments
organizational_assignment_roles
```

## 4.2 `users` extension

Add:

```text
username VARCHAR(64) NULL UNIQUE
```

Canonical storage rules:

```text
trim
lowercase
3..64 chars when present
regex: ^[a-z0-9][a-z0-9._-]{1,62}[a-z0-9]$
'@' forbidden by regex
```

Notes:

- existing rows migrate with `username = NULL`;
- no backfill is required;
- email remains globally unique;
- username is optional and does not replace email;
- application validation is still required even when database collation is case-insensitive because canonical lowercase is a cross-database invariant.

## 4.3 Model impact

`Modules/Core/Identity/Models/User.php`:

```text
EXTEND fillable/casts as needed for username
```

Human display name remains sourced from `Person`, not User.

---

# 5. Authentication Repository Refactor

## Current

```text
findByEmailForTenant(email, tenantUuid)
```

## Target contract

Conceptually:

```text
findActiveByLoginIdentifier(identifier): ?AuthenticationIdentity
```

Repository responsibilities:

1. normalize identifier;
2. resolve by email or username;
3. require `users.status = ACTIVE`;
4. join `persons` only for canonical account/person projection such as name;
5. DO NOT join Membership/Tenant for password verification;
6. return password hash only inside trusted authentication boundary.

Conceptual result:

```text
user_id
person_id
person_name
email
username
password_hash
is_superadmin
```

Membership presence must not affect whether credentials are valid.

### Change Classification

`AuthenticationRepositoryInterface` — **REFACTOR**
`AuthenticationRepository` — **REFACTOR**

---

# 6. Global Authentication Service

Replace tenant-aware issuance responsibility with two responsibilities.

## 6.1 Credential verification

Conceptual service:

```text
GlobalAuthenticationService::authenticate(identifier, password, channel)
```

Returns authenticated identity projection without Tenant context.

Failure audit event:

```text
auth.login_failed
```

Safe metadata:

```text
channel
identifier_type = email|username
```

Do not log full raw identifier unless existing privacy policy explicitly permits it. Password is never logged.

## 6.2 Identity credential issuance

For stateless client:

```text
IdentityCredentialIssuer
→ issueIdentityCredential(user_id)
```

For BrowserSession:

```text
BrowserLoginController
→ global credentials verified
→ revoke pre-login Membership credentials where applicable
→ session fixation protection / session regeneration
→ credentialVault.establishFreshIdentity(user_id)
```

No Membership credential is created during global login itself.

---

# 7. Token Manager Design

## 7.1 Interface

Refactor `TokenManagerInterface` toward explicit methods:

```text
issueIdentityToken(userId): string
issueMembershipToken(userId, tenantId, membershipId): string
validateAndExtract(token): claims|null
lifetimeInSeconds(): int
```

Names may vary during implementation, but separate issuance contracts are mandatory.

## 7.2 Identity token

```json
{
  "credential_type": "identity",
  "user_id": "<uuid>",
  "expires_at": 1234567890
}
```

## 7.3 Membership token

```json
{
  "credential_type": "membership",
  "user_id": "<uuid>",
  "tenant_id": "<uuid>",
  "membership_id": "<uuid>",
  "expires_at": 1234567890
}
```

## 7.4 Validation invariants

Common:

```text
user_id valid
credential_type known
expires_at valid
not expired
not revoked
```

Identity:

```text
tenant_id not required
membership_id not required
```

Membership:

```text
tenant_id required
membership_id required
```

Unknown/ambiguous credential type fails closed.

## 7.5 Transitional legacy token compatibility

During coordinated rollout only:

```text
credential_type missing
+ tenant_id present
+ membership_id present
→ infer legacy membership credential
```

Identity inference from missing claims is forbidden.

Compatibility may remain for at most one existing token lifetime after cutover and should then be removed.

---

# 8. Middleware Design

## 8.1 `InjectAuthenticatedUser`

**KEEP / EXTEND**.

It resolves either identity or membership bearer credential and verifies active User.

Output remains:

```text
authenticated_user_id
```

It must not create TenantContext.

## 8.2 `InjectTenantContext`

**KEEP semantics**.

Additional guard:

```text
credential_type must be membership
```

Then existing verification remains:

```text
User.person_id
Membership.id == claim membership_id
Membership.tenant_id == claim tenant_id
Membership.person_id == User.person_id
Membership ACTIVE
Tenant ACTIVE
```

Identity credential → fail closed.

## 8.3 `InjectBrowserAuthenticatedUser`

Current implementation requires one membership bearer from the vault to prove User.

Target:

```text
BrowserSession user_id
      ↓
ActiveUserResolver.findActiveById(user_id)
      ↓
set Auth Guard
set authenticated_user_id
```

It no longer requires `credentialForAuthentication()`.

Security assumptions:

- `user_id` exists only in server-side session state;
- session ID is protected by hardened cookie;
- login regenerates session ID;
- active User is revalidated per protected request;
- client cannot set server-side `user_id` directly.

`BrowserSessionAuthenticationCredentialProviderInterface` becomes candidate for **DEPRECATE** if no other consumer remains.

## 8.4 Browser Tenant middleware

`InjectBrowserTenantContext` continues resolving the requested tab Membership locator to a server-held membership credential and then applying canonical Tenant verification.

No global server-side current Tenant is introduced.

---

# 9. BrowserSession Vault

Current storage shape is retained:

```text
educore.browser_auth
├── user_id
└── membership_credentials
    ├── membership A → bearer A
    └── membership B → bearer B
```

Locked valid identity-only state:

```text
user_id = <authenticated user>
membership_credentials = []
```

Fresh browser login requires an explicit identity-establishment operation with **reset semantics**:

```text
successful password verification
→ revoke pre-login server-held Membership credentials where applicable
→ apply session-fixation protection / session regeneration
→ establish authenticated user_id
→ membership_credentials = []
```

The implementation MAY introduce a dedicated operation such as conceptually:

```text
establishFreshIdentity(user_id)
```

or an equivalent atomic API.

Fresh login MUST NOT rely on historical same-User `establishForUser()` preservation behavior. Membership credentials from the pre-login BrowserSession MUST NOT survive the fresh-login boundary, including when the authenticated User is unchanged.

Reload of an already authenticated BrowserSession does not perform fresh-login establishment. Reload reads and revalidates the existing `user_id`; the existing Membership credential inventory may remain available for ADR-023 tab-local restoration and must still pass canonical Membership/Tenant verification before context commit.

`credentialForAuthentication()` is no longer required for identity proof after middleware refactor and may be deprecated after dependency cleanup.

---

# 10. API Contract

## 10.1 Stateless login

### Request

```http
POST /api/v1/auth/login-token
Content-Type: application/json
```

```json
{
  "identifier": "ahmad@example.com",
  "password": "********"
}
```

or:

```json
{
  "identifier": "ahmad",
  "password": "********"
}
```

### Success response

```json
{
  "status": "success",
  "data": {
    "access_token": "<identity-bearer>",
    "token_type": "Bearer",
    "expires_in": 7200,
    "context_type": "identity",
    "user": {
      "id": "<uuid>",
      "name": "Ahmad",
      "email": "ahmad@example.com",
      "username": "ahmad"
    },
    "platform": {
      "is_superadmin": false
    }
  }
}
```

`is_superadmin` is a UI/bootstrap projection only. Server middleware remains final authority.

Login response does not need to query Membership count. Client performs canonical discovery next, keeping authentication responsibility separate.

## 10.2 Browser login

```http
POST /api/v1/browser/auth/login
```

Same request body:

```json
{
  "identifier": "ahmad",
  "password": "********"
}
```

Response contains no bearer credential.

Conceptual response:

```json
{
  "status": "success",
  "data": {
    "context_type": "identity",
    "user": {
      "id": "<uuid>",
      "name": "Ahmad",
      "email": "ahmad@example.com",
      "username": "ahmad"
    },
    "platform": {
      "is_superadmin": false
    }
  }
}
```

The HttpOnly BrowserSession becomes the identity credential for browser transport.

## 10.3 New global identity introspection

```http
GET /api/v1/auth/identity
```

Supports:

```text
BearerAuth: identity or membership token
BrowserSessionAuth: authenticated BrowserSession
```

Returns active global User/Person account projection and global platform flags.

It does not return a current Tenant unless a future explicitly versioned contract adds a separate optional projection.

## 10.4 Membership discovery

Existing:

```http
GET /api/v1/user/my-memberships
```

Middleware changes only as necessary to accept identity-only BrowserSession / identity bearer.

Response remains active Memberships in active Tenants.

## 10.5 Membership switch — API/mobile

Existing:

```http
POST /api/v1/user/memberships/{membership_id}/switch
```

Source credential may be identity-scoped or membership-scoped.

Response remains membership-scoped bearer:

```json
{
  "status": "success",
  "data": {
    "access_token": "<membership-bearer>",
    "token_type": "Bearer",
    "expires_in": 7200,
    "context": {
      "membership_id": "<uuid>",
      "tenant_id": "<uuid>",
      "tenant_name": "..."
    }
  }
}
```

## 10.6 Membership switch — Browser

Existing:

```http
POST /api/v1/browser/user/memberships/{membership_id}/switch
```

Uses BrowserSession `user_id`, issues membership bearer server-side, stores it in credential vault, and never returns raw bearer to browser.

## 10.7 Tenant-context introspection

Existing:

```http
GET /api/v1/auth/me
```

Remains Membership/Tenant scoped and requires verified Tenant context.

Do not weaken to nullable Membership/Tenant fields.

## 10.8 Logout

### Browser

```http
POST /api/v1/browser/auth/logout
```

- revoke all membership credentials in BrowserSession inventory;
- clear BrowserSession identity;
- invalidate/regenerate session as appropriate.

Must succeed safely even when credential inventory is empty.

### API/mobile

```http
POST /api/v1/auth/logout
```

Middleware changes from mandatory Tenant Context to authenticated bearer identity so both identity and membership credentials can be revoked.

---

# 11. Login Request Validation

Replace current `LoginTokenRequest` rules.

Target:

```text
identifier: required|string|max:255
password: required|string|min:<retain current security-compatible baseline>
```

Normalization:

```text
trim identifier
if contains @ → lowercase email
else → lowercase username
```

Repository performs semantic identifier resolution.

Temporary deployment compatibility may accept `email` as alias for `identifier`; canonical OpenAPI must document `identifier` only after cutover.

`tenant_uuid` is removed from final validation and final OpenAPI schema.

---

# 12. Membership Selection Orchestration

Client algorithm after global login:

```text
if global superadmin and target route = platform admin:
    enter platform shell
    membership discovery may be lazy/on-demand
else:
    GET /my-memberships

count = 0:
    state = no-membership

count = 1:
    call canonical switch automatically
    verify /auth/me
    state = ready

count > 1:
    state = selection-required
    wait for explicit user selection
    call canonical switch
    verify /auth/me
    state = ready
```

The switch action is reused; no shortcut writes active Tenant directly.

---

# 13. Frontend State Machine

Target auth/tenancy lifecycle:

```text
UNAUTHENTICATED
  ↓
AUTHENTICATING
  ↓
IDENTITY_AUTHENTICATED
  ├── PLATFORM_READY (global superadmin route)
  └── MEMBERSHIP_DISCOVERING
         ├── NO_MEMBERSHIP
         ├── AUTO_SELECTING
         └── SELECTION_REQUIRED
                 ↓
           CONTEXT_SWITCHING
                 ↓
           TENANT_BOOTSTRAPPING
                 ↓
                READY
```

`AUTHENTICATED` boolean alone remains insufficient.

## Fresh login

Any pre-login tab Membership restoration hint must not silently select among multiple Memberships after successful fresh login.

## Reload

Existing valid BrowserSession may reuse the tab-local restoration hint as an untrusted locator and verify it through the current BFF/context flow.

## Profile switch UI

Profile/Settings exposes:

```text
Current Institution
Switch Institution
```

Switch UI uses canonical `/my-memberships` discovery and existing switch transaction.

---

# 14. Cache & Context Isolation

ADR-023/026 remain authoritative.

On committed Tenant switch:

- increment context generation;
- stop/block business mutations during transition;
- clear/invalidate old Tenant/Workspace/capability projections;
- prevent stale old-context responses from updating active UI;
- bootstrap target `/auth/me` before rendering target Tenant as authoritative;
- preserve tab-local isolation.

Identity-only state must not reuse stale Tenant query caches as visible current data.

---

# 15. Global Superadmin Flow

Global Superadmin route middleware remains explicit:

```text
Identity authentication
→ RequireGlobalSuperadmin
```

It must not be placed behind `InjectTenantContext`.

Target route composition for global Tenant Management conceptually remains:

```text
InjectAuthenticatedUser / transport-aware identity middleware
→ RequireGlobalSuperadmin
```

Browser transport must be supported without requiring membership credential.

No implicit Tenant data access is granted by this flow.

---

# 16. Failure Modes

| Failure | Result |
| --- | --- |
| Invalid identifier/password | 401 generic authentication failure. |
| Suspended User | Stateless identity resolution returns 401 `AUTHENTICATION_REQUIRED`; browser identity resolution returns 401 `BROWSER_SESSION_AUTHENTICATION_REQUIRED` and may clear the session. |
| Valid identity-scoped token sent to Tenant endpoint | 403 `AUTHENTICATION_CONTEXT_DENIED`; global identity is valid but Membership/Tenant context is absent. |
| BrowserSession user no longer active | 401 `BROWSER_SESSION_AUTHENTICATION_REQUIRED`; session may be cleared. |
| 0 active Membership | Identity remains authenticated; no Tenant access. |
| Membership switch target not owned by Person | Denied; no context change. |
| Membership inactive | Denied; refresh discovery. |
| Tenant inactive | Denied; refresh discovery. |
| `/auth/me` verification after switch fails | Do not commit target context. |
| Browser logout with zero membership credentials | Clear BrowserSession successfully. |
| Token revocation storage failure | Fail secure; do not claim successful revocation. |


## 16.1 Canonical authentication/context error classification

The refactor MUST preserve the existing stable API error vocabulary rather than introducing a new stateless Membership-required code.

### Stateless identity boundary

When global User identity cannot be established because the credential is missing, invalid, expired, or resolves to an inactive/suspended User:

```text
HTTP 401
code = AUTHENTICATION_REQUIRED
```

This is the canonical authentication-recovery signal.

### Stateless Tenant boundary

A valid identity-scoped credential proves User identity but is intentionally insufficient for Tenant-scoped runtime.

Therefore:

```text
credential_type = identity
+ Tenant-scoped endpoint
→ HTTP 403
→ AUTHENTICATION_CONTEXT_DENIED
```

The same canonical Tenant-context error remains applicable when Membership/Tenant context cannot be safely established because claims are malformed, mismatched, inactive, cross-Person, or otherwise invalid.

No stateless `MEMBERSHIP_CONTEXT_REQUIRED` code is introduced by TDD-002.

### Browser boundary

Existing BrowserSession error contracts remain:

```text
no authenticated BrowserSession
→ 401 BROWSER_SESSION_AUTHENTICATION_REQUIRED

authenticated BrowserSession
without Membership locator
→ 403 BROWSER_MEMBERSHIP_CONTEXT_REQUIRED

Membership locator has no usable server-held Membership credential
→ 403 BROWSER_MEMBERSHIP_CONTEXT_DENIED
```

Browser-specific codes remain distinct because browser transport uses server-side BrowserSession identity and an untrusted tab-local Membership locator rather than exposing bearer credentials to React.

---

# 17. Security Requirements

1. No raw bearer in browser runtime.
2. No password/bearer/session cookie in logs or telemetry.
3. Identity token cannot satisfy TenantContext.
4. Client Membership locator never proves ownership.
5. Active User status rechecked by identity resolver/middleware.
6. Active Membership/Tenant rechecked for each canonical Tenant context establishment.
7. Global Superadmin authorization checked server-side.
8. Session ID regenerated after browser login.
9. CSRF protection remains required for browser state-changing requests.
10. Login errors remain non-enumerating.
11. Rate-limiting/brute-force controls inherit existing platform security baseline and should be verified during implementation; no weakening is allowed.

---

# 18. Source Change Map

## Direct production targets

| Path | Action | Notes |
| --- | --- | --- |
| `Modules/Core/Identity/Database/Migrations/...users...` or new additive migration | ADD | Add nullable unique `username`; do not rewrite historical migration for deployed environments. |
| `Modules/Core/Identity/Models/User.php` | EXTEND | Add username support. |
| `Modules/Auth/Http/Requests/LoginTokenRequest.php` | REFACTOR | `identifier + password`; remove Tenant requirement. |
| `Modules/Auth/Authentication/Contracts/AuthenticationRepositoryInterface.php` | REFACTOR | Global identifier lookup. |
| `Modules/Auth/Repositories/AuthenticationRepository.php` | REFACTOR | Remove Membership/Tenant joins from credential verification. |
| `Modules/Auth/Application/Services/AuthenticationCredentialIssuer.php` | REPLACE/REFACTOR | Split global authentication from context credential issuance. |
| `Modules/Auth/Application/DTO/IssuedAuthenticationCredential.php` | REFACTOR | Split identity vs membership DTOs to avoid invalid mixed state. |
| `Modules/Auth/Token/Contracts/TokenManagerInterface.php` | REFACTOR | Explicit identity and membership token issuance. |
| `Modules/Auth/Services/DeterministicTokenManager.php` | REFACTOR | Support typed credential payloads. |
| `Modules/Auth/Application/Services/AuthenticatedIdentityResolver.php` | EXTEND | Accept typed identity + membership tokens. |
| `Modules/Auth/Http/Middleware/InjectAuthenticatedUser.php` | KEEP/EXTEND | Identity resolution remains canonical. |
| `Modules/Auth/Http/Middleware/InjectTenantContext.php` | EXTEND | Require membership credential type. |
| `Modules/Auth/BrowserSession/Infrastructure/SessionCredentialVault.php` | KEEP/EXTEND | Identity-only state remains supported; add explicit fresh-login reset/revocation semantics. |
| `Modules/Auth/Http/Middleware/InjectBrowserAuthenticatedUser.php` | REFACTOR | Validate server-side session User directly; no membership bearer required. |
| `Modules/Auth/Http/Controllers/Api/v1/AuthController.php` | REFACTOR | Global login + global logout semantics. |
| `Modules/Auth/Http/Controllers/Browser/v1/BrowserLoginController.php` | REFACTOR | Establish only User identity at login. |
| new `AuthIdentityController` or equivalent | ADD | Global identity introspection. |
| `Modules/Auth/Routes/api.php` | EXTEND | Add identity introspection; adjust logout/global-superadmin browser-capable composition. |
| `Modules/User/Application/Actions/SwitchMembership.php` | KEEP / minor token API adaptation | Preserve business validation. |
| `Modules/User/Http/Controllers/Browser/v1/BrowserSwitchMembershipController.php` | KEEP | Existing server-side custody pattern. |
| `Modules/User/Routes/api.php` | KEEP/VERIFY | Global membership endpoints use identity middleware. |
| `docs/api/openapi.yaml` | REFACTOR | Canonical transport contract. |
| `frontend/src/app/auth/login-form.ts` | REFACTOR | Remove tenant UUID; identifier field. |
| `frontend/src/app/auth/LoginForm.tsx` | REFACTOR | Simplified UX. |
| `frontend/src/app/auth/login-failure.ts` | REFACTOR | Remove tenant validation mapping. |
| `frontend/src/platform/auth/*` | EXTEND | Identity-authenticated state. |
| `frontend/src/app/membership/MembershipContextProvider.tsx` | EXTEND | Post-login discovery/auto-select/selection flow. |
| `frontend/src/app/routing/LoginRouteBoundary.tsx` | EXTEND | Identity vs Membership route state. |
| Profile/Settings institution switch component | ADD | Product switch UX. |

Expected direct production change surface: approximately **20–30 files**, with most downstream module files regression-only.

---

# 19. Test Strategy

## 19.1 Backend authentication

Mandatory tests:

- login by email without Tenant;
- login by username without Tenant;
- username comparison case-insensitive;
- duplicate normalized username rejected;
- username `@` rejected;
- existing User with NULL username can login by email;
- invalid identifier/password generic response;
- User with zero Membership authenticates successfully;
- User with multiple Memberships authenticates successfully without selecting one;
- identity token claims contain no Tenant/Membership;
- identity token revocation works;
- membership token still carries Tenant/Membership;
- role/permission claims absent.

## 19.2 Middleware

- identity token accepted by `InjectAuthenticatedUser`;
- membership token accepted by `InjectAuthenticatedUser`;
- identity token rejected by `InjectTenantContext` with exact HTTP 403 + `AUTHENTICATION_CONTEXT_DENIED`;
- missing/invalid identity credential on identity middleware retains exact HTTP 401 + `AUTHENTICATION_REQUIRED`;
- malformed or invalid Membership/Tenant context retains exact HTTP 403 + `AUTHENTICATION_CONTEXT_DENIED`;
- membership token retains existing Tenant verification;
- suspended User rejects both token types;
- malformed credential type fails closed;
- legacy membership token transitional test if compatibility is enabled.

## 19.3 BrowserSession

- fresh login establishes `user_id` with empty membership credential map;
- fresh login by the same User does not preserve pre-login Membership credentials;
- pre-existing server-held Membership credentials are revoked where applicable before fresh-login inventory reset;
- reload of an already authenticated BrowserSession does not clear its credential inventory merely to revalidate identity;
- identity-protected browser endpoint works with empty map;
- browser Tenant endpoint without an authenticated BrowserSession returns exact HTTP 401 + `BROWSER_SESSION_AUTHENTICATION_REQUIRED`;
- authenticated BrowserSession without a Membership locator returns exact HTTP 403 + `BROWSER_MEMBERSHIP_CONTEXT_REQUIRED`;
- Membership locator without a usable server-held Membership credential returns exact HTTP 403 + `BROWSER_MEMBERSHIP_CONTEXT_DENIED`;
- browser Membership switch stores new server-side credential;
- browser bearer never serialized in response;
- logout with empty map clears identity;
- logout with N credentials revokes all then clears session;
- session fixation protection remains.

## 19.4 Membership

Existing tests remain plus:

- `/my-memberships` works from identity token;
- `/my-memberships` works from identity-only BrowserSession;
- switch works from identity token;
- same-Person validation unchanged;
- inactive Membership/Tenant rejection unchanged;
- old membership credential remains independent for other tab where ADR-023 expects it.

## 19.5 Global Superadmin

- Superadmin with zero Membership can access global Tenant Management;
- normal User with zero Membership cannot;
- Superadmin identity does not bypass ordinary tenant Membership validation.

## 19.6 Frontend

- login form has identifier + password only;
- 0 memberships → no-access authenticated state;
- 1 membership → auto switch;
- >1 memberships → selector after fresh login;
- last Membership hint ignored for fresh multi-Membership login;
- reload of valid session can restore current tab context;
- profile switch uses membership switch runtime;
- switch failure preserves old context;
- no bearer enters browser state/storage.

## 19.7 E2E

At minimum:

```text
email login → one membership → dashboard
username login → one membership → dashboard
login → multiple memberships → select → dashboard
login → zero memberships → no-access screen
superadmin login → platform admin without membership
profile switch A → B
refresh existing B session → B restored
fresh logout → login with multiple → selection shown again
```

---

# 20. OpenAPI Changes

Canonical schema changes:

```text
LoginTokenRequest
- email
- tenant_uuid
+ identifier
+ password unchanged
```

Add schemas for:

```text
IdentityLoginSuccess
BrowserIdentityLoginSuccess
AuthenticatedIdentitySuccess
CredentialContextType
```

Membership switch schemas remain membership-scoped.

OpenAPI tests must fail if `tenant_uuid` remains required in canonical LoginTokenRequest after cutover.

---

# 21. Implementation Sequence

## Phase A — Contract & tests

1. Add failing tests for target global-login semantics.
2. Update OpenAPI target contract in the same controlled workstream.
3. Add username migration/model tests.

## Phase B — Token/context foundation

1. Add typed identity/membership token contract.
2. Extend identity resolver.
3. Add Tenant middleware type rejection.
4. Preserve legacy membership token compatibility temporarily if needed for rollout.

## Phase C — Global authentication

1. Refactor request to identifier.
2. Refactor repository to global lookup.
3. Split authentication verification from Membership credential issuance.
4. Refactor API login.

## Phase D — Browser identity

1. Browser login establishes identity-only session.
2. Refactor Browser authenticated-user middleware.
3. Add identity introspection route.
4. Ensure Browser logout works with zero Membership credentials.

## Phase E — Membership orchestration

1. Verify identity-only access to `/my-memberships`.
2. Reuse `SwitchMembership` for one/multiple Membership flows.
3. Preserve `/auth/me` target verification.

## Phase F — Frontend

1. Simplify Login form.
2. Add identity-authenticated state.
3. Wire post-login membership discovery.
4. Auto-select exactly one Membership.
5. Complete selector for multiple Memberships.
6. Add Profile/Settings switch UI.
7. Update reload/restoration behavior.

## Phase G — Cleanup

1. Remove canonical `tenant_uuid` login references.
2. Remove temporary `email` request alias if used.
3. Remove legacy token inference after compatibility window.
4. Deprecate/remove unused Browser authentication credential-provider interface if no callers remain.
5. Update architecture/current docs and indexes.

---

# 22. Deployment & Rollback

## Deployment

Recommended coordinated order:

```text
1. additive DB migration (username nullable)
2. backend capable of new global login + temporary compatibility
3. OpenAPI/generated client update
4. frontend/mobile/client cutover
5. observe regression/security gates
6. remove compatibility behavior
```

Do not deploy a frontend requiring `identifier` against a backend that still requires `tenant_uuid`.

## Rollback

- Nullable username migration is backward-compatible and need not be dropped during emergency code rollback.
- If backend rollback cannot understand identity-scoped tokens, those sessions/credentials must be invalidated and users may need to authenticate again.
- Membership-scoped data and RBAC schema require no rollback.
- No business-module data migration is part of this refactor.

---

# 23. Observability & Audit

Recommended safe events:

```text
auth.login_succeeded
auth.login_failed
auth.identity_session_established
auth.identity_session_invalidated
membership.discovery_completed
membership.auto_select_started
membership.selection_required
membership.switch_succeeded
membership.switch_denied
auth.logout
```

Safe metadata may include non-secret IDs where privacy policy allows.

Never record:

```text
password
raw bearer token
session cookie
raw decrypted token payload
```

---

# 24. Traceability Matrix

| Product | Architecture | Technical | Verification |
| --- | --- | --- | --- |
| FR-001/002 | ADR-033 §2 | LoginTokenRequest + AuthenticationRepository | login email/username tests |
| FR-004/005 | ADR-033 §2.3 | identity token + BrowserSession identity | zero-Membership auth tests |
| FR-006/007/008/009 | ADR-033 §5–6 | ListMyMemberships + frontend orchestration | 0/1/>1 tests |
| FR-010/011 | ADR-033 §5 | SwitchMembership + Tenant middleware | ownership/status/switch tests |
| FR-012 | ADR-033 §7 | global identity middleware + RequireGlobalSuperadmin | superadmin no-Membership tests |
| FR-014/015 | ADR-033 §4 | SessionCredentialVault + Browser middleware | browser empty-credential tests |
| FR-016/017 | ADR-033 §3 | typed token manager | token-claim tests |
| FR-019/020 | ADR-033 §8 | `/auth/me` + `/auth/identity` | OpenAPI + feature tests |
| FR-023 | ADR-033 §9 | BrowserLogoutController | global browser logout tests |
| FR-024 | ADR-033 §10–11 | unchanged TenantContext consumers | business-module regression suite |

---

# 25. Change Impact Summary

| Foundation Area | Impact |
| --- | --- |
| Person | VERY LOW — no model change |
| User | LOW — username + clearer auth ownership |
| Tenant | VERY LOW — no schema/ownership change |
| Membership | LOW — discovery/switch reused |
| Role/Permission | VERY LOW — unchanged |
| Auth backend | HIGH within local boundary |
| BrowserSession | MEDIUM |
| Frontend Auth | HIGH within platform UI |
| Business modules | VERY LOW; regression only |
| Database | LOW |
| OpenAPI/tests | MEDIUM–HIGH |

The blast radius is intentionally constrained by preserving the existing membership-scoped contract after context establishment.

---

# 26. Definition of Done

TDD-002 implementation is complete only when:

- canonical login no longer requires Tenant input;
- email and optional username login are covered;
- identity-only User context works on browser and bearer clients;
- fresh browser login starts with an empty Membership credential inventory even when the same User authenticates again;
- reload of an already authenticated BrowserSession does not perform fresh-login reset semantics;
- identity credentials cannot enter Tenant endpoints;
- valid identity-scoped credential on a Tenant endpoint returns exact HTTP 403 + `AUTHENTICATION_CONTEXT_DENIED`;
- browser missing-session / missing-Membership-context failures retain their existing stable BrowserSession machine codes;
- 0/1/>1 Membership behavior passes product acceptance tests;
- fresh multi-Membership login always requires selection;
- reload restoration semantics remain safe;
- Global Superadmin works without Membership;
- Browser logout clears identity even with zero Membership credentials;
- existing switch, TenantContext, RBAC, organization, and business-module regressions pass;
- OpenAPI no longer defines `tenant_uuid` as login requirement;
- raw bearer remains absent from browser runtime;
- affected documentation/indexes are aligned;
- temporary compatibility path is either removed or explicitly time-boxed with a tracked cleanup item.

---

# 27. Reviewer Mode

**Quality Score:** 9.6/10

**Gaps:** No critical technical design gap remains for the locked scope. Exact class names for newly split authentication DTO/services may be chosen during implementation as long as boundaries in this TDD are preserved.

**Risks:** Coordinated contract migration and token-type enforcement are the principal risks. Browser logout/revocation behavior must be tested for both empty and populated credential inventories.

**Recommendations:** Implement incrementally behind regression tests; preserve `/auth/me` as tenant-scoped; add `/auth/identity`; do not alter downstream business-module contracts.

**Status:** **READY FOR IMPLEMENTATION — ACCEPTED / LOCKED**
