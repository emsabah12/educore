# ADR-018 — Organizational Topology & Scoped Authorization

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-14
**Scope** : Phase 4B — Organizational Topology Foundation

---

> ## Decision Summary
>
> Tenant remains EduCore's customer/security/data-isolation boundary. Multi-lembaga and multi-cabang are represented by the fixed topology `Tenant → Organization → OrganizationUnit`. Membership remains `Person × Tenant`; organizational participation is modeled separately through `OrganizationalAssignment`. Organizational runtime context is subordinate to verified Tenant/Membership context. Tenant-wide role grants retain their existing `membership_roles` meaning, while organization/unit scoped role grants attach to `OrganizationalAssignment` and are evaluated through a dedicated `OrganizationalAuthorizationService`.

## Related ADR

- ADR-013 — Canonical Human Identity
- ADR-014 — Membership & Tenant Boundary
- ADR-015 — Authentication Token & Request Context
- ADR-016 — Database-Backed Tenant RBAC
- ADR-017 — Module Runtime & Bootstrap Contract
- ADR-019 — Dormitory Integration Boundary

---

# 1. Context

EduCore needs multi-lembaga and multi-cabang support without weakening the locked Tenant boundary or overloading Membership with branch-specific state.

Before Phase 4B, the project only had a directional future shape for Organization/Branch. Phase 4B audited and implemented a concrete foundation for:

- organizational ownership;
- Membership operational placement;
- runtime organizational context;
- organization/unit scoped role grants;
- effective scoped authorization.

---

# 2. Decision

## 2.1 Tenant remains the root boundary

```text
Tenant
= customer boundary
= security boundary
= data-isolation boundary
```

Organization and OrganizationUnit are subordinate topology and never replace TenantContext.

## 2.2 Topology is fixed and non-recursive

```text
Tenant
  ↓
Organization
  ↓
OrganizationUnit
```

No generic recursive organization tree is introduced.

## 2.3 Membership remains Person × Tenant

Canonical Membership uniqueness remains:

```text
UNIQUE(person_id, tenant_id)
```

Membership does not gain canonical `organization_id` or `branch_id`.

Operational placement is modeled separately:

```text
Membership
  ↓
OrganizationalAssignment
  ├── Organization
  └── OrganizationUnit?
```

A Membership may have zero or more assignments.

`organization_unit_id = NULL` means organization-level assignment. A non-null unit means exact-unit assignment.

## 2.4 Organizational topology is tenant-safe

Organization, OrganizationUnit, and OrganizationalAssignment remain explicitly tenant-aware.

Database constraints and application services must reject:

- cross-tenant Membership assignment;
- cross-tenant Organization references;
- OrganizationUnit references outside the selected Organization/Tenant.

## 2.5 OrganizationalContext is subordinate runtime state

Runtime layering:

```text
TenantContext
  ↓
MembershipContext
  ↓
OrganizationalContext
```

OrganizationalContext contains:

```text
tenantId
membershipId
assignmentId
organizationId
organizationUnitId?
```

The assignment identifier is a locator, not authority. The resolver verifies active Tenant, Membership, Assignment, Organization, and Unit before storing context.

The authentication token remains unchanged:

```text
user_id
tenant_id
membership_id
expires_at
```

No Organization/Unit claim is added.

## 2.6 Tenant-wide role semantics remain unchanged

`membership_roles` remains the tenant-wide role-grant relationship.

Existing `AuthorizationService` remains tenant-wide only.

Scoped role grants use:

```text
organizational_assignment_roles
├── organizational_assignment_id
└── role_id
```

Role and Permission remain global database-backed catalogs.

## 2.7 Scoped role inheritance is one level downward

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

Rules:

- tenant-wide role applies throughout the Tenant;
- organization-level role applies to the Organization and its Units;
- unit-level role applies only to the exact Unit;
- no upward inheritance;
- no sibling-unit inheritance;
- no DENY/priority/override semantics.

A parent Organization grant may come from a different OrganizationalAssignment than the assignment currently used as the Unit context.

## 2.8 Scoped authorization is explicit

Organization/unit-aware checks use a dedicated `OrganizationalAuthorizationService`.

The service:

1. requires current OrganizationalContext;
2. revalidates the current assignment/context before each decision;
3. combines tenant-wide and scoped roles;
4. deduplicates roles by Role identity;
5. evaluates permission through the existing global RolePermission repository.

Expected missing/stale organizational context fails closed. Infrastructure/database exceptions are not silently converted into authorization denial.

---

# 3. Consequences

## Positive

- Tenant isolation remains stable while multi-lembaga/multi-cabang become first-class.
- Membership does not become branch-specific or overloaded.
- Organizational placement is independently lifecycle-managed.
- Existing tenant-wide authorization remains backward-compatible.
- Organization and Unit role semantics are explicit and testable.
- Role/Permission catalogs remain single-source database-backed catalogs.

## Trade-offs

- Scoped checks require verified OrganizationalContext.
- Organization-level inheritance requires scope-aware role queries rather than current-assignment-only queries.
- Downstream domains must verify resource ownership in addition to checking scoped permission.

---

# 4. Architectural Invariants

```text
Tenant != Organization != OrganizationUnit

Membership = Person × Tenant

OrganizationalAssignment
= Membership operational placement
!= authorization grant
!= domain profile

TenantContext remains root context

OrganizationContext/Unit context
= subordinate verified runtime state

membership_roles
= tenant-wide role grants

organizational_assignment_roles
= organization/unit scoped role grants

Role/Permission catalogs
= global DB-backed

organization role → unit
unit role ↛ organization
unit role ↛ sibling unit

no org/unit token claims
no recursive hierarchy
no direct scoped permission assignment
```

---

# 5. Validation

Phase 4B implementation was validated through:

- Organization/OrganizationUnit persistence tests;
- OrganizationalAssignment persistence/service tests;
- OrganizationalContext resolver tests;
- scoped role persistence/grant-service tests;
- scoped authorization evaluation tests;
- existing tenant AuthorizationService regression;
- Core Feature regression;
- `migrate:fresh --seed`.

---

# 6. References

- `docs/architecture/current-architecture.md`
- `docs/architecture/architecture-principles.md`
- `docs/architecture/folder-structure.md`
- ADR-014
- ADR-016
- ADR-019
