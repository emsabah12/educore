# ADR-033 — Global Authentication & Membership Context Establishment

**Version**: 1.1
**Status**: ACCEPTED / LOCKED
**Date**: 2026-08-26
**Scope**: Core/Auth/User Platform Foundation
**Supersedes**: ADR-015 — Authentication Token & Request Context
**Extends / Aligns**: ADR-013, ADR-014, ADR-016, ADR-018, ADR-030
**Amends**: ADR-022 browser login/bootstrap assumptions; ADR-023 pre-switch source-context assumptions
**Product Authority**: PRD-002 — Platform Authentication & Membership Context

---

> ## Decision Summary
>
> EduCore authenticates `User` globally before any Tenant context exists. Canonical login uses `identifier + password`, where identifier is global email or optional global username. Authentication supports two explicit credential/context classes: **Identity Context** and **Membership Context**. Identity Context proves an active global User and may access only global identity/platform operations. Membership Context additionally carries `tenant_id + membership_id` and is required for Tenant-scoped operations. Browser clients represent Identity Context through a hardened server-side BrowserSession; stateless API/mobile clients receive an identity-scoped bearer credential. Membership selection exchanges authenticated identity for the existing membership-scoped bearer/context contract. Tenant remains a security boundary and all business-module authorization remains database-backed.

---

# 1. Context

ADR-013 and ADR-014 established:

```text
Person = global human identity
User = global optional digital account
Membership = Person participation in Tenant
Tenant = security/data-isolation boundary
```

ADR-015 improved the old `users.tenant_id` model but still locked a bearer token whose canonical claims always include:

```text
user_id
tenant_id
membership_id
expires_at
```

The current implementation consequently verifies login by joining User → Person → Membership → Tenant and requires `tenant_uuid` before authentication succeeds.

This is now inconsistent with the locked product requirement because:

- User authentication is global;
- a valid User may have zero, one, or many Memberships;
- Global Superadmin is a global authority;
- Tenant selection belongs after identity proof;
- browser session must support an authenticated User with no Membership credential.

A new ADR is required because this changes the public authentication architecture contract. Historical ADR-015 must remain in the repository but becomes superseded.

---

# 2. Decision

## 2.1 Global authentication is Tenant-independent

Canonical credential input:

```text
identifier
password
```

Forbidden login inputs:

```text
tenant_uuid
tenant_id
membership_id
organization_id
workspace_id
role
permission
```

Global authentication resolves:

```text
identifier
   ↓
User
   ↓
Person
```

It does not prove Tenant participation.

---

## 2.2 Identifier strategy

`identifier` is resolved deterministically:

```text
contains '@' → email
otherwise    → username
```

Username rules:

```text
optional
global unique
canonical lowercase
case-insensitive
'@' forbidden
```

Username is global because no Tenant context exists during login.

---

## 2.3 Two explicit authentication contexts

EduCore recognizes two canonical states:

### Identity Context

Proves:

```text
active User
+ canonical User → Person relation
```

Does **not** prove:

```text
Membership
Tenant
Role
Permission
Organization
Workspace
```

Permitted categories include:

```text
identity introspection
my memberships
membership switch preparation
global account operations
browser logout
global platform administration when separately authorized
```

### Membership Context

Proves:

```text
active User
+ User → Person
+ active Membership owned by same Person
+ active Tenant
```

Membership Context remains the prerequisite for Tenant-scoped business operations.

---

# 3. Bearer Credential Contract

## 3.1 Explicit credential types

Token APIs must make context type explicit.

### Identity-scoped bearer

Canonical claims:

```text
credential_type = identity
user_id
expires_at
```

It MUST NOT contain fake/null Tenant identifiers to satisfy old interfaces.

### Membership-scoped bearer

Canonical claims:

```text
credential_type = membership
user_id
tenant_id
membership_id
expires_at
```

Role and Permission remain forbidden as trusted claims.

## 3.2 Token API design rule

Token issuance must use explicit operations such as conceptually:

```text
issueIdentityCredential(user_id)
issueMembershipCredential(user_id, tenant_id, membership_id)
```

A single ambiguous API with nullable `tenant_id` / `membership_id` is rejected because it makes invalid states easier to construct.

## 3.3 Resolution semantics

Canonical identity middleware accepts either credential type because both prove User identity.

Canonical Tenant middleware accepts **Membership Context only** and must reject identity-scoped credentials fail-closed.

---

# 4. Browser Session Decision

ADR-022 remains accepted.

Canonical BrowserSession remains:

```text
HttpOnly hardened session cookie
        ↓
server-side session state
```

Server-side state may validly contain:

```text
user_id = authenticated User
membership_credentials = []
```

Therefore:

```text
Authenticated BrowserSession
≠ requires Membership credential
```

Credential-vault custody and independent per-Membership credential storage semantics are retained.

A **fresh successful browser login** establishes a new Identity Context boundary. After session-fixation protection is applied, the resulting BrowserSession MUST contain:

```text
user_id = authenticated User
membership_credentials = []
```

Membership credentials from any pre-login BrowserSession state MUST NOT survive the fresh-login boundary, including when the authenticated `user_id` is the same User. Existing server-held Membership credentials SHOULD be revoked where applicable before their inventory is discarded.

A reload of an already authenticated BrowserSession is different from fresh login. Reload MUST NOT invoke fresh-login establishment semantics merely to prove identity. It revalidates the existing server-side `user_id`; existing Membership credentials may remain available for tab-local restoration, but any restored Membership/Tenant context remains an untrusted candidate until canonically reverified.

Therefore, preservation behavior of the historical `SessionCredentialVault::establishForUser()` operation is not authoritative for fresh login. Implementation may introduce an explicit fresh-identity establishment operation or otherwise provide equivalent atomic reset semantics.

Browser identity middleware must verify the stored User against canonical active-User persistence rather than requiring a membership-scoped bearer credential solely to prove session ownership.

Browser canonical bearer credentials remain server-side only.

---

# 5. Membership Discovery & Establishment

After global authentication:

```text
Authenticated User
      ↓
User → Person
      ↓
active Memberships in active Tenants
```

The existing canonical discovery contract remains:

```http
GET /api/v1/user/my-memberships
```

Discovery is a projection, not authorization authority.

Selection uses canonical switch semantics:

```text
User identity
      ↓
target Membership locator
      ↓
verify active User
verify same Person ownership
verify Membership ACTIVE
verify Tenant ACTIVE
      ↓
issue Membership Context credential
```

Existing `SwitchMembership` semantics are retained.

---

# 6. 0 / 1 / Multiple Membership Semantics

## Zero

```text
Identity Context remains valid
Tenant Context = absent
```

Tenant-scoped endpoints fail closed.

## Exactly one

Client orchestration automatically selects the only active Membership using the canonical switch operation.

## More than one

Fresh login requires explicit Membership selection.

Last-used Membership MUST NOT silently bypass selection after a fresh login.

During reload of an already authenticated BrowserSession, a tab-local restoration hint may be used as a locator and must be canonically reverified before commit, preserving ADR-022/023.

---

# 7. Global Superadmin Boundary

Global Superadmin authorization is a platform/global concern.

Canonical rule:

```text
Identity Context
+ backend verifies User.is_superadmin / canonical global authorization
→ Platform Administration allowed
```

Membership is not required.

If the same Person also has Memberships, Tenant context can be entered through the normal Membership switch flow.

Global Superadmin status does not create implicit Tenant Membership or bypass Tenant membership checks for normal tenant-scoped business operations unless a separate explicit architecture decision later defines impersonation/support access.

---

# 8. Endpoint Boundary

## Global identity endpoints

Must require Identity Context, not Tenant Context.

Canonical examples:

```text
POST /api/v1/auth/logout
GET  /api/v1/auth/identity        (new canonical identity introspection)
GET  /api/v1/user/my-memberships
POST /api/v1/user/memberships/{id}/switch
```

Browser variants use BrowserSession identity.

## Tenant endpoints

Continue requiring Membership Context:

```text
GET /api/v1/auth/me
GET /api/v1/user/my-workspaces
GET /api/v1/core/authorization/capabilities
business module APIs
```

`/api/v1/auth/me` remains the canonical verified **Membership/Tenant** context bootstrap and is intentionally not weakened into an ambiguous nullable Tenant response.

This separation reduces regression risk and preserves existing tenant-runtime semantics.

---

# 9. Logout Decision

## Browser

Browser Logout is global to the current BrowserSession:

```text
revoke all server-held membership bearer credentials where applicable
clear User identity from BrowserSession
invalidate/regenerate session as required
clear tab-local restoration state in frontend
```

Switching Membership is not logout.

## Stateless API/mobile

Logout revokes the presented bearer credential. Cross-device/global logout-all is outside this ADR.

Identity-scoped credentials must be revocable through the same canonical revocation mechanism as membership-scoped credentials.

---

# 10. Request Context Rules

The current verified request attributes remain valid after Membership selection:

```text
authenticated_user_id
authenticated_membership_id
authenticated_tenant_id
```

Identity-only routes receive only:

```text
authenticated_user_id
```

Downstream business modules must not be changed to accept nullable Tenant context. They remain behind Tenant middleware.

Request lifecycle safety rules from ADR-015 remain carried forward:

- request-dependent resolvers use the current Request instance;
- no stale request context is retained;
- raw client Tenant locators are never trusted as authority.

---

# 11. Authorization Rules Carried Forward

ADR-016 and ADR-018 remain unchanged.

```text
Authentication ≠ Authorization
```

Role/Permission state remains database-backed.

Neither identity nor membership bearer credentials may become a second authorization source.

Membership-scoped authorization continues to resolve current database state so role changes take effect without embedding role lists in credentials.

---

# 12. Security Decisions

- Login errors do not reveal whether email/username exists.
- Identity credential cannot be used on Tenant middleware.
- Membership selector from browser is an untrusted locator.
- Browser bearer tokens never enter React runtime or browser storage.
- Browser session fixation protection remains mandatory at login.
- User active status is revalidated on protected identity operations.
- Membership and Tenant active status are revalidated on Tenant-context operations.
- Global Superadmin routes use explicit global authorization middleware.
- Password, raw bearer, and session cookie values are forbidden in audit/log/telemetry.

---

# 13. Alternatives Considered

## Option A — Keep tenant-aware login

**Rejected.** It contradicts global User ownership and forces Tenant selection before identity proof.

## Option B — Put `tenant_id = null` inside the existing token shape

**Rejected.** It weakens the contract and risks accidental acceptance by Tenant middleware.

## Option C — Create a mutable global "current Tenant" in server session

**Rejected.** It breaks ADR-022/023 multi-tab isolation because tabs may require different Membership contexts.

## Option D — One explicit Identity Context + explicit Membership Context

**Accepted.** It preserves domain boundaries, keeps business runtime unchanged, and minimizes blast radius.

## Option E — Auto-select last Tenant after every fresh multi-Membership login

**Rejected by product decision.** Fresh login with multiple Memberships requires explicit selection.

---

# 14. Consequences

## Positive

- Login matches global User semantics.
- Raw Tenant UUID disappears from user-facing login.
- Zero-Membership and Global Superadmin states become valid without fake Tenant context.
- Existing Tenant/RBAC/business runtime remains intact after Membership selection.
- Browser BFF architecture remains compatible.
- API/mobile gain the same canonical lifecycle.
- Authentication and authorization boundaries become easier to reason about and test.

## Costs

- Token Manager and token schema become dual-context.
- Identity introspection endpoint is added.
- Browser identity middleware must no longer depend on a Membership bearer for proof.
- Frontend login bootstrap gains Membership discovery orchestration.
- OpenAPI and existing login tests require coordinated change.

---

# 15. Compatibility & Migration Rules

Canonical final contract is not dual-mode.

A temporary implementation compatibility window may accept legacy inputs/tokens during deployment only:

- old `email` request may be mapped to canonical `identifier`;
- legacy membership tokens without `credential_type` may be inferred as membership-scoped only when valid `tenant_id + membership_id` claims exist;
- `tenant_uuid` from legacy clients may be ignored during the temporary window and MUST NOT affect authentication outcome.

Compatibility behavior is transitional implementation detail and must be removed after coordinated client migration. It must never become the documented canonical contract.

---

# 16. Impact Classification

| Area | Classification |
| --- | --- |
| Person model | KEEP |
| User ownership | KEEP |
| User schema | EXTEND (`username`) |
| Tenant | KEEP |
| Membership | KEEP |
| RBAC | KEEP |
| Organization/scoped authorization | KEEP |
| Token Manager | REFACTOR / EXTEND |
| Authentication repository/service | REFACTOR |
| BrowserSession vault | KEEP / EXTEND semantics |
| Browser identity middleware | REFACTOR |
| Membership discovery | KEEP |
| Membership switch | KEEP |
| Tenant middleware | KEEP with identity-token rejection tests |
| Frontend login/runtime | REFACTOR / EXTEND |
| Business modules | KEEP; regression only |

---

# 17. Documentation Impact

- ADR-015 becomes **Superseded** by ADR-033.
- ADR-022 remains **Accepted**, but ADR-033 supersedes its §18 assumption that browser login must immediately create a Membership bearer and its identity-bootstrap wording where `/auth/me` was the only possible post-login bootstrap. Credential custody, shared BrowserSession, multi-tab isolation, and logout semantics remain unchanged.
- ADR-023 remains **Accepted**; ADR-033 only extends the valid pre-switch source context so switching may originate from Identity Context or an existing Membership Context. Its prepare → verify → commit, tab isolation, and restoration rules remain unchanged.
- FE-003 tenant-aware login clauses become historical/superseded.
- FE-004 remains valid for switch transaction semantics; PRD-002 governs fresh-login selection behavior.
- TDD-001 backend contract baseline requires alignment note after implementation.
- OpenAPI must become the transport source of truth for the new contract.

---

# 18. Reviewer Mode

**Quality Score:** 9.7/10

**Gaps:** No critical architecture gap remains. Refresh token, SSO, MFA, and account management are intentionally separate decisions.

**Risks:** The highest risk is accidental acceptance of identity credentials by Tenant middleware; this is mitigated by explicit credential typing and mandatory regression tests. Coordinated rollout is required because the login request contract changes.

**Recommendations:** Keep `/auth/me` tenant-scoped; add separate identity introspection; preserve current `SwitchMembership` and post-selection context shape; use explicit token-issuance methods.

**Status:** **ACCEPTED / LOCKED**
