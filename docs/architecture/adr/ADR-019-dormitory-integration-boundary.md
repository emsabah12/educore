# ADR-019 — Dormitory Integration Boundary

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-14
**Scope** : Phase 4B — Dormitory Integration Boundary

---

> ## Decision Summary
>
> Dormitory is a downstream business domain, not a new Core organizational-topology level. Its future implementation belongs to `Modules/Dormitory` with dependency direction `Dormitory → Core`. Dormitory reuses Tenant, Organization, OrganizationUnit, Membership, verified organizational context, and scoped authorization from Core. Building, Room, Bed, and resident placement remain Dormitory-domain concepts. Concrete Dormitory schema and business workflows are intentionally deferred to a separate implementation phase.

## Related ADR

- ADR-013 — Canonical Human Identity
- ADR-014 — Membership & Tenant Boundary
- ADR-016 — Database-Backed Tenant RBAC
- ADR-017 — Module Runtime & Bootstrap Contract
- ADR-018 — Organizational Topology & Scoped Authorization

---

# 1. Context

EduCore's product direction includes integrated dormitory management.

The architectural risk is to treat Dormitory, Building, Room, or Bed as generic organizational units, duplicate Person/Student identity inside a Dormitory module, or make Core depend on a downstream residential domain.

Phase 4B locks only the integration boundary. It does not implement the Dormitory module.

---

# 2. Decision

## 2.1 Dormitory is a business module

Future ownership:

```text
Modules/Dormitory
├── Dormitory
├── Building
├── Room
├── Bed
├── ResidentPlacement
└── Dormitory business rules
```

Dependency direction:

```text
Dormitory
  ↓
Core
```

Core must not depend on Dormitory.

## 2.2 Dormitory does not extend Core organizational hierarchy

Core topology remains:

```text
Tenant
  ↓
Organization
  ↓
OrganizationUnit
```

Dormitory, Building, Room, and Bed are not OrganizationUnit variants.

No Dormitory/Room/Bed identifiers are added to OrganizationalContext.

No DormitoryContext is introduced without a concrete runtime requirement.

## 2.3 Dormitory root consumes organizational ownership

The future Dormitory root is expected to be:

```text
tenant-aware
owned by one Organization
optionally owned by one OrganizationUnit
```

Conceptually:

```text
tenant_id
organization_id
organization_unit_id nullable
```

`organization_unit_id = NULL` means Organization-level ownership.

A non-null OrganizationUnit must belong to the same Organization and Tenant. Database constraints should prevent cross-tenant/mismatched topology references.

This is an integration contract, not a finalized Dormitory migration.

## 2.4 Resident identity reuses Membership

Canonical human/tenant identity remains:

```text
Person
  ↓
Membership
```

Dormitory must not create duplicate resident Person identity.

Student is not the canonical resident identity because a future resident may be Student, Employee, Teacher/Staff, or another eligible Membership.

Resident placement is a separate Dormitory-domain relationship, conceptually:

```text
Membership
  ↓
ResidentPlacement
  ↓
Dormitory / Room / Bed
```

`OrganizationalAssignment` may be used as an eligibility/policy input, but it is not the canonical resident identity and is not the resident-placement aggregate itself.

## 2.5 Dormitory reuses Core authorization

Dormitory permissions remain entries in the global Permission catalog, for example:

```text
dormitory.view
dormitory.manage
dormitory.room.manage
dormitory.resident.assign
```

Roles remain global Role catalog entries.

Scoped grants reuse ADR-018 semantics:

- tenant-wide role;
- organization-level role;
- exact-unit role.

Dormitory must not introduce a duplicate `dormitory_roles` authorization system merely to scope access.

Permission is necessary but not sufficient: Dormitory resource ownership must also match the verified Tenant/Organization/Unit context.

## 2.6 Core topology deactivation does not mass-mutate Dormitory history

When Tenant/Organization/Unit or Membership state changes, Core must not hard-delete or mass-rewrite downstream Dormitory historical records.

Dormitory operational services evaluate current parent topology and Membership state according to their domain workflows.

---

# 3. Consequences

## Positive

- Core remains fundamental and independent from a residential business domain.
- Dormitory can evolve independently behind a clear module boundary.
- Human identity is not duplicated.
- Multi-lembaga/multi-cabang authorization is reused rather than reinvented.
- Building/Room/Bed remain meaningful facility concepts instead of polluting organizational topology.

## Trade-offs

- Dormitory operations need both authorization and resource-ownership validation.
- Concrete occupancy/lifecycle rules remain intentionally undefined until the Dormitory implementation phase.
- Some Dormitory descendant tables may require explicit tenant projections for security/query reasons, but that decision must be made per table rather than copied mechanically.

---

# 4. Architectural Invariants

```text
Dormitory != Organization
Dormitory != OrganizationUnit
Building/Room/Bed != OrganizationUnit

Dormitory → Core
Core ↛ Dormitory

resident identity → Membership
resident identity ↛ duplicate Person
resident identity ↛ Student-only identity

OrganizationalContext
does not contain Dormitory/Room/Bed

Dormitory authorization
reuses global Role/Permission + ADR-018 scopes

permission check
+
resource ownership check
= required operation boundary

concrete Dormitory schema
= separate implementation phase
```

---

# 5. Deferred Work

This ADR does not define final:

- `dormitories` schema;
- Building/Room/Bed schema;
- capacity rules;
- gender/age/residency policy;
- room/bed allocation lifecycle;
- check-in/check-out workflow;
- resident history semantics;
- Dormitory HTTP/API contract;
- Dormitory module manifest/dependencies beyond `Dormitory → Core`.

Those decisions belong to the future `Modules/Dormitory` implementation workstream.

---

# 6. References

- `docs/architecture/current-architecture.md`
- `docs/architecture/architecture-principles.md`
- `docs/architecture/folder-structure.md`
- ADR-013
- ADR-014
- ADR-018
