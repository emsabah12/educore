# PRD-002 — Platform Authentication & Membership Context

**Version**: 1.0
**Status**: ACCEPTED / LOCKED
**Date**: 2026-08-26
**Scope**: Platform Foundation — Global Authentication, Membership Discovery, Tenant Context Establishment & Switching
**Implementation State**: NOT STARTED — controlled refactor required
**Supersedes Product Contract**: conflicting login clauses in `FE-003-authentication_session-prd.md`; conflicting fresh-login assumptions in `FE-004-tenant_membership-context-prd.md`
**Architecture Dependency**: ADR-013, ADR-014, ADR-016, ADR-018, ADR-022, ADR-023

---

> ## Locked Product Decision
>
> EduCore authenticates a **global User account first** using only `email/username + password`. Tenant is never a login credential. After global identity authentication, the platform discovers active Memberships owned by the User's canonical Person. Zero Membership produces an authenticated no-tenant state, one Membership is selected automatically, and more than one Membership requires explicit selection on every fresh login. Tenant switching after login is a Membership-context switch. Global Superadmin access does not require Membership.

---

# 1. Executive Summary

EduCore has already locked the following canonical identity model:

```text
Person = global human identity
User = optional global digital/authentication account
Membership = Person participation in a Tenant
Tenant = customer/security/data-isolation boundary
Role/Permission = authorization through Membership / organizational assignment
```

The current login implementation still requires:

```text
email
password
tenant_uuid
```

This contradicts the product direction because User is global while Tenant participation belongs to Membership. It also exposes a technical Tenant UUID as a login input and forces Tenant selection before global identity has been proven.

PRD-002 removes Tenant from login credentials and introduces an explicit two-stage product lifecycle:

```text
1. GLOBAL IDENTITY AUTHENTICATION
   email/username + password

2. MEMBERSHIP / TENANT CONTEXT ESTABLISHMENT
   discover Memberships
   → auto-select one
   → explicitly select when multiple
   → or remain authenticated without Tenant when none exists
```

The existing Membership discovery, Membership ownership validation, Tenant status validation, RBAC, organizational scope, Browser BFF credential custody, and tenant-scoped business runtime are retained.

---

# 2. Business Objectives

| ID | Objective |
| --- | --- |
| BO-001 | Make authentication consistent with global `Person` and global `User` ownership. |
| BO-002 | Remove technical Tenant identifiers from the login experience. |
| BO-003 | Support one User participating in multiple Tenants without duplicate User accounts. |
| BO-004 | Preserve hard Tenant isolation after Membership context is established. |
| BO-005 | Allow global platform operations, including Global Superadmin administration, without requiring a Tenant Membership. |
| BO-006 | Preserve existing downstream business-module contracts after Tenant context is established. |
| BO-007 | Establish one canonical authentication lifecycle for browser, API, mobile, and future first-party clients. |

---

# 3. Problem Statement

## [FAKTA] Current behavior

Current authentication couples credential verification to Tenant lookup:

```text
email + password + tenant_uuid
        ↓
User + Person + Membership + Tenant lookup
        ↓
membership-scoped bearer/session context
```

This creates several product and architecture problems:

- Tenant is required before User identity is proven.
- A global User with multiple Memberships must know a Tenant locator before login.
- Raw `tenant_uuid` leaks an infrastructure identifier into UX.
- A Global Superadmin still depends on a Tenant-aware login entry point even though platform administration is global.
- Browser session identity cannot currently remain authenticated without at least one Membership credential.
- The current contract obscures the distinction between authentication and Tenant authorization.

## Target behavior

```text
identifier + password
        ↓
Global User authenticated
        ↓
Membership discovery
        ↓
0 / 1 / multiple Membership handling
        ↓
optional Tenant context
```

---

# 4. Locked Domain Semantics

These semantics are not reopened by PRD-002.

| Entity / Concept | Locked Meaning |
| --- | --- |
| `Person` | Global canonical human identity. |
| `User` | Optional global digital/authentication account linked to one Person. |
| `Tenant` | Customer/security/data-isolation boundary. |
| `Membership` | Canonical participation of Person in Tenant; unique per `person_id + tenant_id`. |
| `Role` / `Permission` | Authorization state; not login credentials and not trusted from UI. |
| `OrganizationalAssignment` | Operational placement inside Tenant organization topology. |

Canonical relation:

```text
                Person
               /      \
            User     Membership
                       │
                       └── Tenant
```

`User` does not own Tenant and `Membership` does not require User to exist.

---

# 5. Stakeholders & User Personas

| ID | Persona | Need |
| --- | --- | --- |
| P-001 | Tenant User | Login without knowing Tenant UUID and enter the correct institution context. |
| P-002 | Multi-Tenant User | Select an institution after login and switch institution without re-entering password. |
| P-003 | User With No Active Membership | Keep valid account authentication while receiving a clear no-access state. |
| P-004 | Global Superadmin | Authenticate globally and access Platform Administration without Membership. |
| P-005 | Tenant Administrator | Rely on Membership/RBAC boundaries without controlling global User authentication ownership. |
| P-006 | API/Mobile Client | Use the same global authentication semantics as browser clients. |
| P-007 | Engineering / Security | Preserve fail-closed Tenant context and avoid broad changes to downstream modules. |

---

# 6. Scope

## IN SCOPE

- Global login using one `identifier` field and password.
- Login identifier may be global email or global username.
- Optional global username on User.
- Username normalization and uniqueness rules.
- Identity-authenticated state without Membership/Tenant context.
- Membership discovery after authentication.
- Automatic selection when exactly one active Membership exists.
- Explicit selection on fresh login when more than one active Membership exists.
- Authenticated no-Tenant state when no active Membership exists.
- Global Superadmin access without Membership.
- Membership switching after login.
- Browser identity-only session state.
- Identity-level bearer credential for stateless API/mobile clients.
- Membership-scoped bearer credential after context selection.
- Global logout semantics for BrowserSession.
- Canonical API/OpenAPI and frontend state-machine updates.
- Regression protection for existing Tenant/RBAC/business-module boundaries.

## OUT OF SCOPE

- Social login / OAuth / SAML / OIDC / external IdP integration.
- MFA / passkeys.
- Remember Me / refresh-token rotation.
- Global account management UI.
- Password reset redesign beyond compatibility with existing account ownership.
- Invitation/account-claim workflow.
- Tenant CRUD redesign.
- Membership administration CRUD redesign.
- Role/Permission management redesign.
- Organization/workspace redesign.
- HR/Academic/Finance/Dormitory business rules.

## FUTURE SCOPE

- MFA/passkey authentication.
- Enterprise SSO.
- Device/session management and logout-all-devices.
- Account recovery hardening.
- Trusted-device / risk-based authentication.

## DEFERRED

- Remember Me and long-lived refresh sessions until a dedicated security contract is approved.

---

# 7. Locked Login Identifier Rules

## BR-001 — Login input

Canonical login request contains only:

```text
identifier
password
```

`tenant_uuid`, `tenant_id`, organization, workspace, role, and Membership are forbidden as login credentials.

## BR-002 — Email

- Email remains global on `User`.
- Login comparison uses trimmed lowercase canonical form.
- Existing global email uniqueness remains authoritative.

## BR-003 — Username

Username is:

```text
optional
+ global
+ unique
+ case-insensitive
```

Locked validation:

- stored in canonical lowercase form;
- minimum 3 characters;
- maximum 64 characters;
- permitted characters: lowercase letters, digits, `.`, `_`, `-`;
- first and last character must be alphanumeric;
- `@` is forbidden;
- existing Users may keep `username = NULL`.

Username uniqueness is platform-global because Tenant is not known at login time.

## BR-004 — Identifier resolution

```text
identifier contains @
→ resolve as email

otherwise
→ resolve as username
```

Authentication failure must not disclose whether the identifier exists.

---

# 8. Canonical Authentication States

```text
UNAUTHENTICATED
      │
      │ identifier + password
      ▼
AUTHENTICATING
      │
      ▼
IDENTITY_AUTHENTICATED
      │
      ├── Global Superadmin
      │       ↓
      │   PLATFORM_READY
      │
      └── Normal User
              ↓
       MEMBERSHIP_DISCOVERING
              │
      ┌───────┼───────────┐
      │       │           │
      0       1          >1
      │       │           │
      ▼       ▼           ▼
NO_TENANT  AUTO_SELECT  SELECTION_REQUIRED
              │           │
              └─────┬─────┘
                    ▼
             CONTEXT_SWITCHING
                    │
                    ▼
             TENANT_BOOTSTRAPPING
                    │
                    ▼
                  READY
```

`IDENTITY_AUTHENTICATED` is a valid, durable authentication state. It is not an error or incomplete credential state.

---

# 9. User Journeys

## UJ-001 — User with exactly one active Membership

```text
Login
→ global User authenticated
→ discover Memberships
→ exactly 1 active Membership
→ automatically select it
→ verify Membership/Tenant context
→ application ready
```

No Membership selector is shown.

## UJ-002 — User with multiple active Memberships

```text
Login
→ global User authenticated
→ discover Memberships
→ more than 1 active Membership
→ show institution selector
→ User explicitly selects Membership
→ verify Membership/Tenant context
→ application ready
```

On **fresh login**, previous Tenant is not automatically reselected when more than one active Membership exists.

## UJ-003 — Reload during an existing browser session

If the same BrowserSession remains valid:

```text
reload
→ restore authenticated User
→ restore tab-local Membership hint if present
→ verify canonical Membership/Tenant context
→ continue
```

This is not a fresh login. A valid existing tab context may be restored without showing the selector again.

## UJ-004 — User with no active Membership

```text
Login succeeds
→ Membership discovery returns 0
→ User remains globally authenticated
→ show "no institution access" state
```

The UI must not report invalid credentials.

## UJ-005 — Global Superadmin

```text
Login
→ global User authenticated
→ is_superadmin authorized by backend
→ Platform Administration available without Membership
```

If the same Person also has Tenant Memberships, Tenant context may be selected later through Membership switching. Membership is not a prerequisite for Platform Administration.

## UJ-006 — Switch institution after login

```text
Current Membership A
→ Profile / Settings
→ Switch Institution
→ select Membership B
→ backend revalidates Person ownership + Membership ACTIVE + Tenant ACTIVE
→ new Membership/Tenant context established
→ old tab context replaced only after verified commit
```

Password is not requested again for normal switching while authentication remains valid.

---

# 10. User Stories & Acceptance Criteria

## US-001 — Global credential login

**As a User**, I want to sign in using email or username and password without choosing a Tenant so that authentication reflects my global account.

**Acceptance Criteria**

- **Given** an active User with valid credentials, **when** the User submits `identifier + password`, **then** global identity authentication succeeds without Tenant input.
- **Given** an invalid identifier or password, **when** login is attempted, **then** the response is generic and does not enumerate account existence.
- **Given** a request containing only a valid Tenant identifier but invalid User credentials, **then** authentication does not succeed.

## US-002 — Single Membership auto-selection

**As a User with one active Membership**, I want the platform to enter that Tenant automatically after login.

- **Given** global authentication succeeded and exactly one active Membership exists, **when** discovery completes, **then** the Membership is automatically selected.
- **Then** Tenant/Membership context is canonically verified before tenant-scoped UI becomes ready.

## US-003 — Multiple Membership selection

**As a multi-Tenant User**, I want to choose the institution after fresh login.

- **Given** more than one active Membership exists, **when** a fresh login completes, **then** an institution selector is shown.
- **Then** no Tenant is considered active until a Membership is explicitly selected and verified.
- **Then** previous-session Tenant preference does not bypass selection on fresh login.

## US-004 — No Membership state

**As a valid User without active Tenant access**, I want to know that my account is valid but I have no institution access.

- **Given** credentials are valid and Membership discovery returns zero active Memberships, **then** authentication remains valid.
- **Then** tenant-scoped endpoints remain inaccessible.
- **Then** the UI shows a no-access message rather than an invalid-credentials message.

## US-005 — Global Superadmin access

**As a Global Superadmin**, I want Platform Administration without a Tenant Membership.

- **Given** an authenticated active User is globally authorized as Superadmin, **when** no Membership is selected, **then** global platform-admin endpoints remain available.
- **Then** tenant-scoped business endpoints remain unavailable until Membership context is established.

## US-006 — Institution switching

**As a User with multiple Memberships**, I want to switch institution from Profile/Settings without logging in again.

- **Given** Membership A is active, **when** Membership B is selected, **then** the backend verifies that B belongs to the same Person and both Membership B and Tenant B are active.
- **Then** new context is committed only after successful verification.
- **If** switch fails, **then** the prior verified context remains intact.

## US-007 — Browser reload

**As a browser User**, I want reload to preserve a still-valid authenticated session.

- **Given** BrowserSession is still valid, **when** the page reloads, **then** global identity is restored without password re-entry.
- **Given** a valid tab Membership restoration hint, **then** the candidate Membership is reverified before becoming active.

## US-008 — Logout

**As a browser User**, I want Logout to end my entire authenticated BrowserSession.

- **Given** a BrowserSession contains User identity and zero or more membership credentials, **when** logout succeeds, **then** all server-held membership credentials for that BrowserSession are revoked where applicable and session identity is cleared.
- **Then** switching institution is not treated as logout.

---

# 11. Functional Requirements

| ID | Requirement |
| --- | --- |
| FR-001 | Canonical login SHALL accept `identifier` and `password` only. |
| FR-002 | `identifier` SHALL support global email and optional global username. |
| FR-003 | Username SHALL be optional, globally unique, canonical lowercase, and case-insensitive. |
| FR-004 | Successful credential verification SHALL establish global authenticated User identity before Membership/Tenant context. |
| FR-005 | Platform SHALL support an authenticated User with no active Membership context. |
| FR-006 | Platform SHALL expose canonical active-Membership discovery for authenticated Users. |
| FR-007 | Exactly one active Membership SHALL be auto-selected. |
| FR-008 | More than one active Membership SHALL require explicit selection after fresh login. |
| FR-009 | Zero active Membership SHALL result in authenticated no-Tenant state. |
| FR-010 | Membership selection SHALL validate same-Person ownership, active Membership, and active Tenant. |
| FR-011 | Tenant-scoped credential/context SHALL only exist after successful Membership selection. |
| FR-012 | Global Superadmin operations SHALL be accessible without Membership, subject to backend authorization. |
| FR-013 | Membership switching SHALL not require password re-entry during a valid authenticated session. |
| FR-014 | BrowserSession SHALL be capable of storing authenticated User identity with zero membership credentials. |
| FR-015 | Browser canonical bearer credentials SHALL remain server-side per ADR-022. |
| FR-016 | Stateless clients SHALL receive an identity-level bearer credential before Membership selection. |
| FR-017 | Membership-scoped bearer credential SHALL remain the canonical credential for tenant-scoped runtime. |
| FR-018 | Role and Permission SHALL not be embedded as trusted authentication claims. |
| FR-019 | `/auth/me` tenant-context semantics SHALL remain authoritative for verified Membership/Tenant bootstrap after selection. |
| FR-020 | A global identity introspection contract SHALL exist for identity-only state. |
| FR-021 | Browser fresh login and browser reload SHALL be distinguishable for selection behavior. |
| FR-022 | Profile/Settings SHALL expose Switch Institution when more than one selectable Membership exists. |
| FR-023 | Global browser Logout SHALL clear the entire BrowserSession identity and credential inventory. |
| FR-024 | Existing tenant-scoped business modules SHALL continue receiving verified `user_id + membership_id + tenant_id` context without needing global-login awareness. |

---

# 12. Business Rules

| ID | Rule |
| --- | --- |
| BR-005 | Tenant is never an authentication credential. |
| BR-006 | Authentication proves User; Membership selection proves Tenant participation. |
| BR-007 | User may be authenticated without Tenant context. |
| BR-008 | Membership context belongs to Person participation, not User ownership. |
| BR-009 | Fresh login with multiple Memberships always requires explicit selection. |
| BR-010 | Reload of an existing valid BrowserSession may restore a previously verified tab context. |
| BR-011 | Client-side Membership identifiers are untrusted locators only. |
| BR-012 | Global Superadmin authority must be verified server-side and must not depend on Tenant Membership. |
| BR-013 | Identity-only context cannot execute tenant-scoped business operations. |
| BR-014 | Membership-scoped context may execute global identity operations plus authorized tenant operations. |
| BR-015 | Switch Institution changes Membership/Tenant context, not User identity. |
| BR-016 | Switching Tenant never mutates global User ownership. |
| BR-017 | Browser logout ends BrowserSession; Tenant switching does not. |
| BR-018 | Existing Users are not forced to acquire usernames. |

---

# 13. Non-Functional Requirements

## Security

| ID | Requirement |
| --- | --- |
| NFR-001 | Authentication failure SHALL not disclose whether email/username exists. |
| NFR-002 | Identity-only credentials SHALL fail closed on all Tenant-context middleware. |
| NFR-003 | Browser bearer credentials SHALL never enter localStorage, sessionStorage, IndexedDB, URL, React state, logs, analytics, or telemetry. |
| NFR-004 | Browser session identifier SHALL remain hardened HttpOnly/Secure/SameSite according to ADR-022/ADR-030. |
| NFR-005 | Membership selection SHALL revalidate ownership/status at execution time; discovery data is not authorization authority. |
| NFR-006 | User suspension SHALL invalidate identity resolution on subsequent protected requests. |
| NFR-007 | Tenant/Membership suspension SHALL prevent Tenant context even if an old selector exists. |
| NFR-008 | Password and raw bearer credentials SHALL never be written to audit or application logs. |

## Correctness & Integrity

| ID | Requirement |
| --- | --- |
| NFR-009 | Exactly one canonical `User → Person` relationship remains enforced. |
| NFR-010 | Existing `UNIQUE(person_id, tenant_id)` Membership invariant remains unchanged. |
| NFR-011 | Downstream TenantContext behavior SHALL remain compatible after Membership selection. |
| NFR-012 | Context switch SHALL use prepare → verify → commit semantics defined by ADR-023. |

## Maintainability

| ID | Requirement |
| --- | --- |
| NFR-013 | Global authentication and Membership credential issuance SHALL be separate application responsibilities. |
| NFR-014 | Token issuance APIs SHALL make identity-scoped and membership-scoped credentials explicit rather than relying on nullable ambiguous parameters. |
| NFR-015 | OpenAPI SHALL remain the canonical transport contract. |

## Performance / Scalability

No new numeric SLO is invented by this PRD. Existing platform/frontend NFR baselines remain applicable. The refactor must not introduce cross-Tenant enumeration or require loading all Tenants globally to resolve login.

---

# 14. Success Metrics / Release Gates

These are correctness gates, not aspirational KPIs.

| ID | Locked Target |
| --- | --- |
| SM-001 | Canonical login requires **0 Tenant identifiers**. |
| SM-002 | Successful cross-Person Membership switch = **0**. |
| SM-003 | Successful tenant-business request using identity-only context = **0**. |
| SM-004 | Browser canonical bearer credential exposed to React/browser storage = **0**. |
| SM-005 | Fresh login with exactly one active Membership requiring manual selector interaction = **0**. |
| SM-006 | Fresh login with more than one active Membership silently auto-selecting prior Tenant = **0**. |
| SM-007 | Existing business modules requiring redesign solely because of pre-Tenant authentication refactor = **0 expected**. |

---

# 15. Error / Empty / Recovery States

| State | Required Behavior |
| --- | --- |
| Invalid credentials | Generic authentication failure. |
| Suspended User | Generic authentication failure / authentication denied. |
| 0 active Membership | Authenticated no-Tenant state; explain lack of institution access. |
| Membership becomes inactive before selection | Selection denied; refresh discovery. |
| Tenant becomes inactive before selection | Selection denied; refresh discovery. |
| Switch network failure | Preserve current verified context. |
| Identity session expires | Return to login; clear untrusted local projections. |
| Membership credential expires while identity BrowserSession remains valid | Re-discover/reselect as appropriate; do not fabricate Tenant context. |
| Global Superadmin with no Membership | Platform Administration remains available. |

---

# 16. Traceability

```text
BO-001 / BO-002
→ BR-005 / BR-006
→ FR-001 / FR-004
→ US-001
→ AC global login without Tenant
→ ADR-033 global authentication decision
→ TDD-002 authentication repository/service/request changes
→ Auth login tests + OpenAPI contract tests
```

```text
BO-003 / BO-004
→ BR-008 / BR-011
→ FR-006 / FR-010 / FR-011
→ US-002 / US-003 / US-006
→ ADR-033 Membership context establishment
→ TDD-002 discovery/switch orchestration
→ Membership + browser switch regression tests
```

```text
BO-005
→ BR-012 / BR-013
→ FR-012
→ US-005
→ ADR-033 global platform authorization boundary
→ TDD-002 identity middleware + superadmin route regression
```

---

# 17. Existing Resource Impact

| Existing Resource | Decision |
| --- | --- |
| ADR-013 Canonical Human Identity | KEEP |
| ADR-014 Membership & Tenant Boundary | KEEP |
| ADR-015 Authentication Token & Request Context | SUPERSEDE by ADR-033 |
| ADR-016 Database-Backed Tenant RBAC | KEEP |
| ADR-018 Organizational Scoped Authorization | KEEP |
| ADR-022 Browser Session Isolation | KEEP / EXTEND interpretation for identity-only BrowserSession |
| ADR-023 Membership Context Switching | KEEP / EXTEND pre-switch source credential |
| FE-003 Authentication & Session PRD | SUPERSEDED where it requires tenant-aware login |
| FE-004 Tenant/Membership Context PRD | KEEP except fresh-login selector rules are governed by PRD-002 |
| TDD-001 Frontend Foundation | UPDATE baseline references during implementation |
| OpenAPI | REVISE login, identity introspection, logout, token schemas |

---

# 18. Reviewer Mode

**Quality Score:** 9.6/10

**Gaps:** No critical product gap remains for this scope. Account-management/invitation and MFA are intentionally outside scope.

**Risks:** Authentication migration must be coordinated across backend, OpenAPI, frontend, and tests. Browser identity-only state must remain canonically revalidated and must not become a shortcut around User status.

**Recommendations:** Implement through ADR-033 and TDD-002; retain existing membership-scoped runtime after selection; do not redesign downstream modules.

**Status:** **READY FOR APPROVAL — APPROVED / LOCKED**

---

# 19. Approval Record

The following product decisions were explicitly confirmed on **2026-08-26**:

1. Username is optional, global unique, and case-insensitive; email or username may be used for login.
2. The new authentication model applies to all clients.
3. 0/1/>1 Membership behavior is locked as specified; fresh multi-Membership login requires explicit selection, reload may restore current context.
4. Global Superadmin can access Platform Administration without Membership.
5. Identity-authenticated state without Membership is valid and durable for global operations.
6. Browser Logout clears the global BrowserSession; Tenant switching is a separate operation.

**Lifecycle:** ACCEPTED / LOCKED
