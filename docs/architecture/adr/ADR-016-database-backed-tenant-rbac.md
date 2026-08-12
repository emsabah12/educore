# ADR-016 — Database-Backed Tenant RBAC

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-12
**Scope** : Core Authorization Foundation 2G + Academic grading capability

---

> **Decision Summary**
>
> EduCore menggunakan database sebagai canonical source of truth untuk tenant RBAC. Authorization diselesaikan dari verified Membership context melalui MembershipRole → Role → RolePermission → Permission dan diakses melalui `AuthorizationService`. `memberships.role`, token role claims, HR `jabatan`, static canonical-role registries, dan global Laravel Gate interception bukan authorization sources. Modules dapat memiliki authorization catalog entries miliknya sendiri, sedangkan Core menjaga generic authorization mechanism.

---

# Related ADR

- ADR-013 — Canonical Human Identity
- ADR-014 — Membership & Tenant Boundary
- ADR-015 — Authentication Token & Request Context

---

# 1. Context

EduCore membutuhkan authorization yang dapat digunakan lintas module tanpa mencampur domain classification, authentication token state, dan tenant membership persistence.

Legacy/alternative approaches seperti `memberships.role`, role claim di token, direct Query Builder authorization pada controller, atau HR `jabatan` sebagai permission source menghasilkan multiple sources of truth dan sulit diskalakan.

Canonical authorization foundation harus:

- menggunakan verified Membership context;
- menyimpan Role/Permission secara normalized;
- menyediakan satu service boundary;
- menjaga query RBAC di repository layer;
- memungkinkan module-specific capability tanpa central static manifest;
- tetap independent dari ordinary Laravel Gate policies/abilities.

---

# 2. Decision

## Database adalah RBAC source of truth

Canonical graph:

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

Canonical persistence mencakup:

```text
roles
permissions
membership_roles
role_permissions
```

Role/Permission menggunakan canonical persistence identity strategy dan relationship tables menyimpan assignment/mapping secara explicit.

---

## AuthorizationService adalah canonical service boundary

Canonical service flow:

```text
AuthorizationService
  ↓
AuthorizationContext
  ↓
MembershipRoleRepository
  ↓
RolePermissionRepository
```

Controllers/middleware tidak membuat alternate RBAC query path untuk menjawab role/permission authorization.

---

## Middleware menggunakan AuthorizationService

Supported tenant authorization patterns:

```text
tenant.role:<role>
tenant.permission:<permission>
```

Middleware memerlukan verified tenant/membership context dan menggunakan canonical AuthorizationService.

Expected authorization failures fail closed.

---

## Module owns concrete capability catalog

Core memiliki catalog entries yang memang milik Core.

Downstream module dapat memiliki role/permission capability miliknya sendiri.

Current concrete example:

```text
Academic
├── role: teacher
├── permission: academic.grades.write
└── teacher → academic.grades.write
```

Academic grading juga membuktikan distinction:

```text
Authorization capability
academic.grades.write

AND

Domain actor
Employee
```

`Employee.jabatan = GURU` tidak otomatis memberi grading permission.

---

## Laravel Gate tetap independent

Ordinary Laravel Gate/Policy authorization tidak diubah menjadi global tenant-RBAC interceptor.

Tidak ada canonical global `Gate::before` yang menyuntikkan tenant RBAC ke seluruh ability evaluation.

---

# 3. Non-Canonical Authorization Sources

Berikut bukan canonical authorization sources:

```text
memberships.role
Bearer token role claim
Bearer token permission claim
Employee.jabatan
Client-provided role/permission
Static CanonicalRoles.php / CanonicalPermissions.php registry
Global authorization manifest/synchronizer
Global Gate::before tenant RBAC interception
```

Static constants dapat digunakan secara lokal sebagai implementation convenience bila diperlukan, tetapi database state tetap menjadi authorization source of truth.

---

# 4. Rationale

Database-backed normalized RBAC memberikan:

- single source of truth;
- role/permission changes berlaku tanpa rewriting token claims;
- assignment idempotency dan referential integrity;
- query isolation pada repositories;
- module ownership untuk concrete capabilities;
- separation dari domain classifications;
- extensibility menuju future scoped authorization tanpa mengubah human identity model.

---

# 5. Architectural Rules

- Authorization harus berasal dari verified Membership context.
- Database adalah canonical RBAC source of truth.
- `memberships.role` tidak boleh diperkenalkan kembali.
- Role/Permission claims dari token tidak trusted untuk authorization.
- HR/domain classification tidak boleh dipakai sebagai implicit RBAC.
- AuthorizationService adalah canonical authorization service.
- RBAC persistence queries berada di repository boundary.
- Tenant role/permission middleware menggunakan AuthorizationService.
- Laravel Gate tetap independent dari tenant RBAC.
- Module-specific permissions dibuat hanya untuk concrete capability requirement.
- Core tidak menjadi static central registry seluruh capability aplikasi.

---

# 6. Consequences

## Positive

- Satu authorization source of truth.
- Capability dapat berubah tanpa token reissue untuk correctness.
- Domain model seperti Employee tidak tercampur dengan security role.
- Module dapat memperkenalkan capability secara modular.
- Authorization logic lebih mudah diuji dan diisolasi.
- Foundation dapat dikembangkan ke organization/branch scope pada ADR berikutnya tanpa mengubah Person/User identity.

## Negative

- Permission checks membutuhkan repository/database resolution.
- Catalog bootstrap harus dijaga idempotent.
- Future organizational scope memerlukan extension pada assignment/context semantics; tenant-wide RBAC saat ini tidak boleh diasumsikan otomatis cukup untuk semua future capabilities.

---

# 7. Alternatives Considered

## Option A — `memberships.role` enum/string

**Rejected**, karena tidak scalable untuk multiple roles/permissions dan mencampur participation dengan authorization.

---

## Option B — Role/Permission embedded in bearer token

**Rejected**, karena authorization state dapat stale dan menciptakan second source of truth.

---

## Option C — Static authorization registry/manifest as source of truth

**Rejected**, karena current requirements lebih sederhana dan database persistence already provides canonical runtime state.

---

## Option D — Database-backed RBAC through AuthorizationService (**Accepted**)

Normalized Role/Permission persistence digunakan melalui repository/service boundary dan module-owned bootstrap entries.

---

# 8. Authorization Flow

```text
Verified Request Context
         │
         ▼
     Membership
         │
         ▼
AuthorizationService
         │
         ▼
MembershipRoleRepository
         │
         ▼
       Role
         │
         ▼
RolePermissionRepository
         │
         ▼
     Permission
```

---

# 9. Current Implementation

Current implementation includes:

- global Role and Permission persistence;
- `membership_roles` and `role_permissions` mappings;
- `AuthorizationService` / interface binding;
- authorization context resolver;
- MembershipRoleRepository;
- RolePermissionRepository;
- `tenant.role` middleware;
- `tenant.permission` middleware;
- Core authorization catalog seeder;
- Academic authorization catalog seeder;
- role discovery and tenant-admin role assignment flows;
- Laravel Gate isolation tests.

---

# 10. Validation / Regression Contract

Current regression coverage includes:

- role authorization on owned active Membership;
- cross-person/cross-tenant/inactive Membership rejection;
- permission resolution through Role/Permission mapping;
- repository isolation;
- catalog seeder idempotency;
- role assignment idempotency;
- Role/Permission persistence identity;
- Laravel Gate isolation;
- Academic teacher/grading capability authorization;
- `GURU` HR classification without permission returning forbidden;
- permission without Employee grading actor returning forbidden.

---

# 11. Future Scope Note

Current RBAC is tenant-context based.

Product direction includes multi-lembaga/multi-cabang. Organizational scope is intentionally **not defined by this ADR**.

Future work must decide whether role assignments/permission evaluations need scopes such as:

```text
TENANT
ORGANIZATION
ORGANIZATION UNIT / BRANCH
```

without weakening the current verified Membership/Tenant authorization boundary.

---

# 12. Impact

ADR ini membekukan current authorization foundation.

Future modules should consume `AuthorizationService` and introduce concrete capabilities only when requirements exist. A future scoped-authorization ADR may extend assignment/context semantics, but should not reintroduce legacy role fields, token authorization claims, or domain-classification-based authorization.
