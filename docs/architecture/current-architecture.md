# EduCore Current Architecture Baseline

**Status**: Locked Baseline
**Updated**: 2026-08-14
**Scope**: Core Canonical Foundation 2G + Phase 3A + Phase 4A Module Kernel Runtime Hardening + Phase 4B Organizational Topology Foundation

---

## 1. Purpose

This document consolidates the architecture that is already implemented, tested, and locked in EduCore.

It is not a proposal for new architecture. It exists so developers can distinguish the current canonical contract from historical documents written before the identity, tenancy, authentication, RBAC, and downstream-profile refactors.

When historical documentation conflicts with this baseline, this baseline and the current accepted canonical ADRs—especially ADR-013 through ADR-017—describe the current implementation contract.

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

Current module identity lookup still uses exact manifest names (`core`, `Auth`, `User`, `HR`, `Academic`, `PPDB`). The canonical target is lowercase technical slugs, but the physical lowercase cutover is not yet implemented.

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

Current authentication uses encrypted deterministic bearer tokens.

Canonical claims:

```text
user_id
tenant_id
membership_id
expires_at
```

Not included as trusted authorization claims:

```text
role
permission
person_id
```

Canonical flow:

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

Authentication and tenant authorization are separate responsibilities.

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

Canonical Phase 4A.8 integration regression baseline:

```text
Core full      189 passed / 623 assertions
Core Feature   112 passed / 447 assertions
Auth            38 passed / 135 assertions
User            15 passed / 54 assertions
HR               7 passed / 60 assertions
Academic         30 passed / 223 assertions
Entire app     272 passed / 1081 assertions
DB             MIGRATE_SEED_OK
Boot/routes    ROUTES_BOOT_OK
```

PPDB currently has no test files.

Latest Phase 4B Core Feature regression before documentation closure:

```text
Core Feature   192 passed / 588 assertions
DB             MIGRATE_SEED_OK
```

The final Phase 4B closure gate must rerun the broader regression set after documentation alignment.

This supersedes the earlier Phase 3A-only regression baseline for Module Kernel closure.

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

Dormitory is **not** a Core topology level.

Future implementation belongs to:

```text
Modules/Dormitory
```

with dependency direction:

```text
Dormitory
  ↓
Core
```

The Dormitory root is expected to be tenant-aware, owned by one Organization, and optionally owned by an OrganizationUnit. Building, Room, Bed, and ResidentPlacement remain Dormitory-domain concepts rather than `OrganizationUnit` variants.

Resident identity remains rooted in canonical Membership. Dormitory must reuse Core organizational ownership/context and scoped authorization rather than duplicate tenancy, identity, Role, or Permission catalogs.

Actual Dormitory schema and business implementation are a separate downstream phase.

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
```

Historical ADR-006, ADR-007, ADR-011, and ADR-012 remain available only as superseded context.

---

## 20. Next Architectural Work

Phase 4B architecture implementation is complete. The remaining Phase 4B work is documentation consistency and final regression closure.

After that closure, concrete Dormitory implementation may begin as a separate downstream workstream in `Modules/Dormitory`; it is not part of Core Phase 4B.
