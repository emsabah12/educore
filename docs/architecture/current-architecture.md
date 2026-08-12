# EduCore Current Architecture Baseline

**Status**: Locked Baseline
**Updated**: 2026-08-12
**Scope**: Core Canonical Foundation 2G + Downstream Human/Profile Canonicalization 3A

---

## 1. Purpose

This document consolidates the architecture that is already implemented, tested, and locked in EduCore.

It is not a proposal for new architecture. It exists so developers can distinguish the current canonical contract from historical documents written before the identity, tenancy, authentication, RBAC, and downstream-profile refactors.

When historical documentation conflicts with this baseline, this baseline and the accepted canonical ADRs (ADR-013 through ADR-016) describe the current implementation contract.

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

Canonical regression baseline at Phase 3A closure included passing Academic, HR, Auth, User, Core feature tests and `migrate:fresh --seed`.

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
- Membership → Employee grading actor resolution.

---

## 17. Not Yet Locked: Multi-Lembaga / Multi-Cabang

EduCore's product direction includes:

- multi-tenant;
- multi-lembaga;
- multi-cabang;
- integrated dormitory management.

The following direction is intentionally **not yet a current contract**:

```text
Tenant
  ↓
Organization / Lembaga
  ↓
Organization Unit / Branch
```

Before implementation, the project must audit and lock:

1. Organization topology.
2. Membership organizational assignment.
3. Organizational request/context isolation.
4. Tenant-vs-Organization-vs-Branch authorization scope.

Dormitory should consume this foundation as a downstream domain rather than redefine human identity or tenancy.

---

## 18. Next Architectural Work

The next architecture work should begin with:

```text
Organizational Topology Audit
```

Authorization coverage for additional Academic/HR endpoints should be designed after organizational scope semantics are understood, so permissions are not prematurely locked as tenant-wide when they may need organization/branch scope.

---

## 19. Canonical ADR Mapping

The locked foundation summarized by this document is formally captured by:

```text
ADR-013 — Canonical Human Identity
ADR-014 — Membership & Tenant Boundary
ADR-015 — Authentication Token & Request Context
ADR-016 — Database-Backed Tenant RBAC
```

Historical ADR-011 and ADR-012 remain available only as superseded context.

Future Organization/Branch topology is intentionally excluded from these ADRs and requires a separate architectural decision after its dedicated audit.
