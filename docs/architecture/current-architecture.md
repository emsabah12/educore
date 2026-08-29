# EduCore Current Architecture Baseline

**Status**: Locked Baseline
**Updated**: 2026-08-29
**Scope**: Core Canonical Foundation 2G + Phase 3A + Phase 4A Module Kernel Runtime Hardening + Phase 4B Organizational Topology Foundation + Foundation 6B-6D + Frontend Foundation FEI-1 through FEI-12 + Dormitory Check-In Foundation through 3.8.3

---

## 1. Purpose

This document consolidates the architecture that is already implemented, tested, and locked in EduCore.

It is not a proposal for new architecture. It exists so developers can distinguish the current canonical contract from historical documents written before the identity, tenancy, authentication, RBAC, and downstream-profile refactors.

When historical documentation conflicts with this baseline, this baseline, the current accepted canonical ADRs—especially ADR-013 through ADR-031—the accepted Frontend Foundation TDD, and the executable public HTTP contract describe the current implementation contract.

---

## 2. Platform Shape

EduCore is a Modular Monolith.

```text
Application
│
├── Core
│   ├── Platform Module Kernel
│   ├── Person / Human Identity
│   ├── User Identity
│   ├── Tenancy
│   ├── Organization / Organizational Context
│   ├── Authorization
│   ├── Audit / Governance
│   └── Shared platform capabilities
│
├── Auth
├── User
├── Academic
├── HR
├── Dormitory
└── downstream modules
```

Core is a **stable foundation/public contract** for downstream modules. Downstream modules must align to Core rather than introduce compatibility fields into Core.

### Organizational topology inside Tenant

The locked topology is:

```text
Tenant
  ↓
Organization
  ↓
OrganizationUnit
```

Canonical responsibility:

```text
Tenant             = customer/security/data-isolation boundary
Organization       = lembaga/institution inside a Tenant
OrganizationUnit   = branch/campus/operational unit inside an Organization
Membership         = Person × Tenant
OrganizationalAssignment
                   = where a Membership participates operationally
```

`Membership` does not gain canonical `organization_id` or `branch_id`. A Membership may have `0..N` `OrganizationalAssignment` records. `organization_unit_id = NULL` means organization-level participation; a non-null unit means exact-unit participation.

`Organization`, `OrganizationUnit`, and `OrganizationalAssignment` remain explicitly tenant-aware. Cross-tenant or mismatched Organization/Unit references fail closed.

---

## 2A. Module Runtime & Bootstrap Contract

EduCore is a modular monolith, not a runtime plugin engine.

Canonical bootstrap lifecycle:

```text
PHYSICALLY PRESENT
        ↓
DISCOVERED
        ↓
MANIFEST VALID
        ↓
DEPENDENCIES VALID
        ↓
BOOTSTRAPPABLE
        ↓
PROVIDERS REGISTERED
        ↓
BOOTED
```

### Organizational scoped authorization

Tenant-wide authorization remains available through the existing `AuthorizationService`.

Organization/unit-aware checks use a dedicated `OrganizationalAuthorizationService` and a verified `OrganizationalContext`.

Effective roles are:

```text
Organization context:
TenantRoles
∪ OrganizationRoles

Unit context:
TenantRoles
∪ OrganizationRoles
∪ ExactUnitRoles
```

Organization-level grants inherit downward to units in the same Organization. Unit grants apply only to the exact Unit and never inherit upward or across sibling units.

Scoped grants are persisted through:

```text
OrganizationalAssignment
  ↓
OrganizationalAssignmentRole
  ↓
Role
```

Role and Permission catalogs remain global database-backed catalogs. There is no direct scoped-permission assignment.

The in-memory OrganizationalContext is a runtime locator, not stale authority. Scoped authorization revalidates the current assignment/context before evaluation and fails closed when the context is missing or invalid.

Core is the mandatory bootstrap root.

Current dependency graph:

```text
Core      → []
Auth      → Core
User      → Core, Auth
HR        → Core, Auth
Academic  → Core, HR, Auth
Dormitory → Core
PPDB      → Core
```

Non-Core providers:

```text
module.yaml.providers
        ↓
manifest/provider validation
        ↓
dependency ordering
        ↓
ModuleProviderRegistrar
        ↓
Laravel provider registration
```

Canonical invariants:

- manifest providers are the sole non-Core provider activation source;
- missing dependency, dependency cycle, invalid provider, dan provider registration failure fail fast;
- no provider naming-convention guessing;
- no persisted `ModuleStateRepository` / `modules.json` bootstrap state;
- no `module:enable` / `module:disable` runtime lifecycle;
- `module:list` and `module:status` are read-only metadata commands;
- no global reflection/filesystem event auto-discovery;
- event/integration registration is explicit and owner-controlled;
- Core must not depend on Auth or business-module implementations.

Separation:

```text
module bootstrap composition
≠ tenant feature / entitlement availability
≠ authorization
```

Current module identity lookup still uses exact manifest names (`core`, `Auth`, `User`, `HR`, `Academic`, `Dormitory`, `PPDB`). The canonical target is lowercase technical slugs, but the physical lowercase cutover is not yet implemented.

See ADR-017.

---

## 3. Canonical Human Identity

```text
Person
  │
  ├── User
  │
  └── Membership
        │
        ├── Student
        ├── Guardian
        └── Employee
```

### Person

`Person` is the canonical global human identity.

Human data belongs to Person or Person-owned supporting records, such as contacts, addresses, identifiers, citizenships, and lifecycle events.

### User

`User` is an optional digital/authentication account associated with one Person.

Canonical rule:

```text
User → Person
```

Not:

```text
User → Tenant
```

`User` is not the source of tenant ownership and is not the canonical source of a person's display name.

### Membership

`Membership` represents a Person participating in a Tenant.

```text
Person
  ↓
Membership
  ↓
Tenant
```

Canonical uniqueness:

```text
UNIQUE(person_id, tenant_id)
```

Legacy concepts intentionally removed:

```text
memberships.user_id
memberships.role
```

---

## 4. Tenant Boundary

`Tenant` is the current customer/security/data-isolation boundary.

The database strategy remains:

```text
Single Database
+ Shared Schema
+ explicit tenant ownership
```

Tenant isolation is defense-in-depth:

- tenant-aware Eloquent models may use `BelongsToTenant`;
- Query Builder/repository paths must include explicit tenant predicates;
- cross-tenant relationship resolution must fail closed;
- duplicated tenant projections must agree where the schema intentionally stores them.

A request does not become authorized for a Tenant merely because a tenant identifier was supplied by a client-facing locator such as a header, host, or subdomain.

---

## 5. Authentication Contract

EduCore supports two authentication transports over the same canonical backend identity, tenancy, and authorization model.

Canonical backend bearer credentials remain encrypted deterministic tokens with claims:

```text
user_id
tenant_id
membership_id
expires_at
```

They do not contain trusted authorization claims such as:

```text
role
permission
person_id
```

For trusted API clients, canonical Bearer authentication remains:

```text
Login
  ↓
Token Manager
  ↓
Bearer Token
  ↓
Authenticated User
  ↓
Tenant/Membership Context Verification
```

The first-party React SPA uses a separate browser transport:

```text
Browser
  ↓
HttpOnly BrowserSession cookie
  ↓
Laravel Browser Authentication BFF / Session Broker
  ↓
server-side canonical Membership-scoped bearer credential
  ↓
canonical /api/v1 resource processing
```

The React runtime never receives, persists, reconstructs, or manually sends the canonical bearer credential.

The BrowserSession cookie represents the authenticated browser session. It does not represent one globally active Membership or Tenant. Active Membership selection remains tab-local and is revalidated against server-side credential custody.

Therefore:

```text
BearerAuth
≠
BrowserSessionAuth
```

Both transports converge on the same canonical backend identity, Tenant/Membership verification, and authorization boundaries.

Authentication and tenant authorization remain separate responsibilities.

---

## 6. Tenant Context Resolution

Canonical verification path:

```text
user_id
  ↓
User
  ↓
User.person_id
  ↓
membership_id
  ↓
Membership.person_id == User.person_id
Membership.tenant_id == token tenant_id
Membership is ACTIVE
  ↓
Tenant is ACTIVE
  ↓
TenantContext
```

The verified request context exposes canonical identifiers such as:

```text
authenticated_user_id
authenticated_membership_id
authenticated_tenant_id
```

Membership-context resolution must read the **current request instance** rather than retain a stale Request object across request lifecycles.

---

## 6A. Frontend Bootstrap, Tenant Switching & Workspace Transport

Public JSON resources continue to use the canonical `/api/v1` namespace.

The first-party browser authentication control plane is:

```text
GET  /api/v1/browser/session/csrf
POST /api/v1/browser/auth/login
POST /api/v1/browser/auth/logout
POST /api/v1/browser/user/memberships/{membership_id}/switch
```

These browser-safe operations never expose the canonical bearer credential to React.

Authenticated frontend bootstrap remains:

```text
GET /api/v1/auth/me
```

The transitional `/api/v1/browser/auth/me` endpoint is retired and is not part of the current contract.

`/api/v1/auth/me` supports the canonical protected-resource contract for both Bearer clients and the first-party BrowserSession transport.

For BrowserSession requests, tab-local Membership context is identified using:

```text
X-EduCore-Membership-Id
```

This header is an untrusted locator only. It is not an authentication credential, Tenant authority, or authorization claim.

Browser Tenant switching uses:

```text
POST /api/v1/browser/user/memberships/{membership_id}/switch
```

The browser switch follows prepare → verify → commit semantics. The target bearer remains in server-side Browser Session custody, `/api/v1/auth/me` verifies the target Membership/Tenant context, and only then may the frontend atomically commit the new tab-local context. A failed switch preserves the previously authoritative context.

The canonical bearer Membership-switch endpoint remains valid for supported non-browser clients:

```text
POST /api/v1/user/memberships/{membership_id}/switch
```

Its bearer-returning response is not consumed directly by the first-party React SPA.

Workspace discovery remains:

```text
GET /api/v1/user/my-workspaces
```

Workspace-scoped requests additionally use `X-EduCore-Organizational-Assignment-Id`. Membership and organizational-assignment headers are locators only; backend context resolution and authorization remain authoritative.

Frontend context-sensitive requests are fenced by session/Membership/Workspace context identity or generation. Cancellation alone is not a correctness boundary; superseded responses must not mutate the current interactive context.

---

## 6B. Frontend Application Runtime Boundary

The canonical production frontend application lives under:

```text
frontend/
```

Its source boundary is:

```text
frontend/src/
├── app/
├── platform/
├── shared/
└── modules/
```

`app` owns application composition, providers, shell, and router composition. `platform` owns shared API/browser transport, authentication/session runtime, Membership/Tenant context, Workspace context, authorization projection, routing/navigation support, and observability. `shared` remains domain-neutral. Business code belongs under `modules` and consumes platform/shared/public contracts instead of duplicating platform infrastructure.

There is one `QueryClient` per running SPA/tab, authenticated query cache is not persisted to browser storage, and server state remains owned by TanStack Query.

The Laravel `resources/js` scaffold is not a second production React application source of truth.

---

## 7. Authorization / RBAC Contract

Authorization is database-backed.

```text
Membership
  ↓
MembershipRole
  ↓
Role
  ↓
RolePermission
  ↓
Permission
```

Canonical service boundary:

```text
AuthorizationService
  ↓
AuthorizationContext
  ↓
MembershipRoleRepository
  ↓
RolePermissionRepository
```

Tenant authorization middleware uses the canonical authorization service.

Supported patterns include:

```text
tenant.role:<role>
tenant.permission:<permission>
```

Laravel Gate remains independent from tenant RBAC.

The following are not canonical authorization sources:

```text
memberships.role
HR jabatan
bearer token role claim
client-provided role/permission
```

---

## 8. Authorization Catalog Ownership

Core owns only capabilities that belong to Core.

Downstream modules may own their own concrete authorization catalog entries.

Current concrete example:

```text
Academic
├── role: teacher
├── permission: academic.grades.write
└── teacher → academic.grades.write
```

The database is the authorization source of truth. EduCore does not use a global static `CanonicalRoles.php` / `CanonicalPermissions.php` registry as the canonical RBAC source.

---

## 8A. Frontend Capability Projection

Frontend capability responses are read projections for navigation and UX, not authorization sources.

Current foundation endpoints are:

```text
GET /api/v1/core/authorization/capabilities
GET /api/v1/core/authorization/workspace-capabilities
```

Tenant capability projection resolves effective tenant permissions from the canonical database-backed authorization boundary. Workspace capability projection additionally requires a verified OrganizationalContext and delegates scoped evaluation to the canonical organizational authorization boundary.

The client may use returned permission/capability names to show or hide navigation and actions, but protected backend operations must authorize again from current persistence state. Capability values are never written into bearer-token claims and are never accepted back from the client as authority.

---

## 8B. Canonical Public API Error Contract

Foundation HTTP errors use a stable machine-readable envelope:

```json
{
  "status": "error",
  "code": "STABLE_MACHINE_READABLE_CODE",
  "message": "Safe user-facing message."
}
```

Validation errors additionally expose field-specific `errors` while retaining the canonical `VALIDATION_FAILED` code.

Frontend logic may depend on HTTP status and stable `code`; it must not parse arbitrary exception text. Raw SQL errors, stack traces, internal paths, exception classes, bearer tokens, credentials, and other sensitive implementation details are not public API contract.

---

## 9. Downstream Human Profiles

### Student

```text
Person
  ↓
Membership
  ↓
Student
```

Student does not own duplicated human identity fields and does not automatically create a User account or default password.

### Guardian

```text
Person
  ↓
Membership
  ↓
Guardian
```

Guardian contact data is Person-owned supporting data where applicable.

### Employee

```text
Person
  ↓
Membership
  ↓
Employee
```

Employee HR classification such as `jabatan` is domain data, not RBAC authorization.

---

## 10. Guardian ↔ Student Relationship

The Academic relationship uses explicit canonical profile identifiers:

```text
Guardian
  │
guardian_student
  │
Student
```

Legacy request vocabulary such as `walisantri_id`, `santri_id`, `hubungan`, or `relation` is not canonical.

Relationship operations are tenant-scoped and fail closed on cross-tenant/corrupted projections.

---

## 11. Teacher / Grading Identity

Teacher is a **role/capability**, not a separate human/profile entity.

No canonical Teacher table/model/repository exists.

Grading requires both:

```text
Authorization capability
academic.grades.write

AND

Domain actor
Employee
```

Canonical actor resolution:

```text
authenticated_membership_id
+ authenticated_tenant_id
  ↓
Employee
  ↓
employee.id
  ↓
student_grades.teacher_id
```

`auth()->id()` / User ID must not be stored as `teacher_id`.

Client-supplied `teacher_id` is prohibited.

---

## 12. UUID Strategy

Canonical/refactored foundation identities use UUIDv7.

The baseline migrations for Core canonical entities use UUIDv7 identifiers.

When a domain area is subsequently canonicalized, UUID consistency should be evaluated explicitly rather than silently mixing identity semantics.

Known Academic UUID consistency work outside Phase 3A remains a separate future workstream.

---

## 13. Database & Migration Policy

The project is currently in a development/refactor stage with a resettable database.

Canonical policy:

```text
known final schema
  ↓
edit baseline migration
  ↓
migrate:fresh --seed
```

Do not accumulate transitional ALTER migrations merely to preserve a development-only historical schema when the final contract is already known.

---

## 14. Transaction Boundaries

Multi-record provisioning/orchestration must be atomic where partial persistence would violate the aggregate contract.

Examples include:

- Person + Membership + Student;
- Person + Membership + Guardian (+ optional contact);
- Person + Membership + Employee;
- Tenant + Membership + admin role assignment;
- bulk grading orchestration.

---

## 15. Testing Contract

Critical HTTP/security behavior is tested through production routes and real middleware/context boundaries.

Avoid false-green patterns such as:

```text
HTTP request fails
  ↓
direct repository fallback
  ↓
test still passes
```

Current Foundation 6D regression evidence is:

```text
Full foundation regression   399 passed / 3586 assertions
```

That gate includes canonical route namespace, API validation/error semantics, capability projection, OpenAPI route coverage, OpenAPI schema/operation contracts, and OpenAPI integrity checks in addition to the existing module regressions. Exact pass/assertion counts are phase evidence rather than permanent architecture; future changes must preserve zero failures and update the relevant contract gates deliberately.

Database/schema changes must continue to pass the project migration/seeding gate on a disposable environment.

---

## 15A. Executable Public HTTP Contract

The foundation transport contract is represented by:

```text
docs/api/openapi.yaml
```

The document uses OpenAPI 3.1 and is executable through repository tests. At this baseline the public `/api/v1` inventory is enforced as:

```text
32 public operations
= 15 documented foundation operations
+ 17 explicitly deferred Academic/HR operations
```

Deferred Academic/HR operations remain public Laravel routes, but they are marked explicitly as domain-API hardening debt rather than being represented as already-hardened foundation contracts.

Executable OpenAPI gates verify route coverage, Laravel route-name linkage, unique operation IDs, schema components, request/response wiring, security/context parameters, and local reference integrity. A new or changed public operation must therefore update implementation and transport contract together rather than silently drifting.

---

## 16. Current Foundation Freeze

The following contracts are frozen unless a new concrete requirement justifies an explicit architectural change:

- Person as canonical human identity;
- User → Person;
- Person → Membership → Tenant;
- token claim vocabulary;
- verified TenantContext;
- database-backed RBAC;
- AuthorizationService boundary;
- Student/Guardian/Employee profile identity;
- Teacher-as-capability semantics;
- Membership → Employee grading actor resolution;
- Core as mandatory Module Kernel bootstrap root;
- manifest-driven, dependency-ordered non-Core provider registration;
- fail-fast dependency/provider bootstrap behavior;
- no mutable Module Kernel enable/disable state;
- explicit provider-owned event/integration registration;
- Tenant → Organization → OrganizationUnit topology;
- Membership remaining Person × Tenant;
- OrganizationalAssignment as a separate Membership participation layer;
- verified OrganizationalContext subordinate to Tenant/Membership context;
- tenant-wide `membership_roles` retaining their original meaning;
- organization/unit scoped role grants through OrganizationalAssignment;
- dedicated OrganizationalAuthorizationService semantics;
- canonical `/api/v1` public JSON namespace;
- canonical `/api/v1/auth/me` protected frontend bootstrap;
- first-party SPA authentication through BrowserSession/BFF with server-side bearer custody;
- browser-safe Membership switching through `/api/v1/browser/user/memberships/{membership_id}/switch`;
- `X-EduCore-Membership-Id` as an untrusted tab-local Membership locator;
- BearerAuth remaining available for supported non-browser API clients;
- frontend context generation/fencing preventing superseded Tenant or Workspace responses from mutating current UI;
- workspace discovery as a read projection rather than a Core persistence entity;
- request-scoped organizational context transport through `X-EduCore-Organizational-Assignment-Id`;
- canonical API error envelope with stable machine-readable codes;
- tenant/workspace capability projections as UX hints rather than authorization sources;
- OpenAPI-backed foundation HTTP contract/discoverability with explicit deferred-domain inventory;
- Dormitory as a downstream business domain that depends on Core rather than extending Core topology.

---

## 17. Organizational Topology & Scoped Authorization

Phase 4B locks the following Core topology:

```text
Tenant
  ↓
Organization
  ↓
OrganizationUnit
```

`Tenant` remains the root security/data-isolation boundary. `Organization` and `OrganizationUnit` are subordinate organizational topology, not replacements for Tenant.

Membership remains:

```text
Person × Tenant
```

Operational participation is represented separately:

```text
Membership
  ↓
OrganizationalAssignment
  ├── Organization
  └── OrganizationUnit?
```

Runtime context is layered:

```text
TenantContext
  ↓
MembershipContext
  ↓
OrganizationalContext
```

OrganizationalContext never grants authority by itself. Scoped authorization combines tenant-wide roles with matching Organization and exact Unit grants after revalidating the current context.

See ADR-018.

---

## 18. Dormitory Integration Boundary

Dormitory is **not** a Core topology level. Concrete downstream implementation exists under:

```text
Modules/Dormitory
```

with dependency direction:

```text
Dormitory
  ↓
Core
```

Core production code must not depend on Dormitory.

### Current ownership and resident model

The Dormitory root is tenant-aware, owned by exactly one Organization, and optionally scoped to one OrganizationUnit from the same Tenant and Organization. Its facility hierarchy is:

```text
Dormitory
  ↓
Building
  ↓
Room
  ├── Bed
  └── Locker
```

Descendant facility resources inherit organizational ownership through their parent hierarchy and remain Dormitory-domain concepts rather than `OrganizationUnit` variants. Tenant-qualified database constraints protect the persisted hierarchy from cross-tenant or mismatched parent references.

Resident identity remains rooted in canonical Core Membership. Resident placement is a separate Dormitory-domain relationship whose canonical resident location is Room:

```text
Membership
  ↓
ResidentPlacement
  ↓
Room
```

Dormitory and Building location are derived through the Room hierarchy rather than duplicated on resident placement. Current placement persistence supports `PLANNED`, `ACTIVE`, `ENDED`, and `CANCELLED` lifecycle states and preserves historical records.

### Current capacity and Check-In foundation

Current room capacity bases are:

```text
BED
LOCKER
BED_AND_LOCKER
```

Effective capacity is derived from usable Bed/Locker counts according to the room basis; `BED_AND_LOCKER` uses the smaller usable-resource count rather than adding both resource counts. Resource requirement policy is revalidated when a planned resident placement is checked in.

The implemented Check-In application service:

- resolves the current Tenant through `TenantContext`;
- validates resident eligibility through a Dormitory-owned interface backed by Core Membership persistence;
- transactionally revalidates Room, parent hierarchy, Membership, planned placement, and supplied Bed/Locker state;
- transitions the matching planned placement to `ACTIVE`;
- relies on database invariants as final guards against duplicate active Membership/Bed/Locker placement.

Current Check-In concurrency uses Room as the primary exclusive serialization boundary, with Building, Dormitory, and Membership protected using shared locks where appropriate. Active-placement/resource checks are repeated inside the same transaction. The active Membership partial unique constraint remains the final guard for the same-Membership/different-Room zero-row race, and recognized PostgreSQL unique conflicts are translated into Dormitory domain errors rather than leaking raw database exceptions.

The locked 3.8.3 closure evidence is:

```text
Dormitory suite             78 passed / 453 assertions
Focused concurrency suite   12 passed / 212 assertions
```

These counts are phase evidence rather than permanent architecture. Future changes must preserve the documented invariants and deliberately update the relevant regression gates.

### Authorization and unfinished lifecycle boundary

Dormitory continues to reuse Core Role/Permission and organizational authorization contracts rather than creating a parallel authorization system. However, the current Check-In application service does **not** resolve an actor or invoke Core scoped authorization itself, and no Dormitory HTTP/API boundary is implemented yet. Authorization plus resource-ownership validation remains required when Dormitory operations are exposed through an authenticated application/HTTP boundary.

Check-Out, Transfer/Room reassignment, bulk movement, complete END/CANCEL application workflows, and Dormitory HTTP/API integration remain unfinished downstream work. The current single-Room Check-In locking contract must not be assumed to make a future multi-Room Transfer/Reassignment workflow deadlock-safe; such workflows require their own deterministic multi-Room locking design and concurrency audit.

See ADR-019.

---

## 19. Canonical ADR Mapping

The locked foundation summarized by this document is formally captured by:

```text
ADR-013 — Canonical Human Identity
ADR-014 — Membership & Tenant Boundary
ADR-015 — Authentication Token & Request Context
ADR-016 — Database-Backed Tenant RBAC
ADR-017 — Module Runtime & Bootstrap Contract
ADR-018 — Organizational Topology & Scoped Authorization
ADR-019 — Dormitory Integration Boundary

Frontend Foundation:
ADR-020 — Frontend Framework & Rendering Strategy
ADR-021 — Frontend Modular Application Architecture
ADR-022 — Authentication Credential Storage & Browser Session Isolation
ADR-023 — Tenant / Membership Context Switching
ADR-024 — Workspace / Organizational Context Management
ADR-025 — API Client, OpenAPI & Canonical Error Handling
ADR-026 — Server-State & Client-State Ownership
ADR-027 — Capability-Aware Navigation & Authorization UX
ADR-028 — Routing & Code-Splitting Strategy
ADR-029 — Frontend Testing Strategy
ADR-030 — Frontend Security Baseline
ADR-031 — Frontend Observability & Performance Strategy
```

Historical ADR-006, ADR-007, ADR-011, and ADR-012 remain available only as superseded context.

---

## 20. Next Architectural Work

The Core/domain foundation, canonical API/OpenAPI boundary, and Frontend Foundation FEI-1 through FEI-12 are current locked baselines. The first-party SPA has an implemented React application boundary, BrowserSession/BFF authentication transport, context-safe Membership/Workspace runtime, capability-aware authorization UX, static routing/module isolation, centralized error handling, security/build gates, observability foundation, and browser E2E coverage. Documentation/regression closure must align to those contracts rather than reopen them implicitly.

Dormitory Check-In through concurrency milestone 3.8.3 is a locked downstream baseline under ADR-019. Remaining Dormitory lifecycle/API work—especially Check-Out, Transfer/Reassignment, authenticated HTTP exposure, and multi-Room concurrency—requires explicit downstream design and regression work rather than being inferred from the current Check-In contract. Academic/HR operations that are still listed as explicit OpenAPI deferred routes remain separate domain API-hardening work and must not be silently promoted into the foundation contract.

Future architectural changes should enter through a concrete requirement and explicit ADR/workstream when they alter a frozen contract.
