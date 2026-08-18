# ADR-019 — Dormitory Integration Boundary

**Version** : 1.1
**Status** : Accepted
**Date** : 2026-08-14
**Last Reviewed** : 2026-08-19
**Scope** : Phase 4B — Dormitory Integration Boundary

---

> ## Decision Summary
>
> Dormitory is a downstream business domain, not a new Core organizational-topology level. Its implementation belongs to `Modules/Dormitory` with dependency direction `Dormitory → Core`. Dormitory reuses Tenant, Organization, OrganizationUnit, Membership, verified organizational context, and scoped authorization contracts from Core. Building, Room, Bed, Locker, and ResidentPlacement remain Dormitory-domain concepts. Since this ADR was accepted, concrete Dormitory persistence, capacity primitives, resident placement persistence, Check-In orchestration, and Check-In concurrency safeguards have been implemented downstream without changing the Core boundary established here.

## Related ADR

- ADR-013 — Canonical Human Identity
- ADR-014 — Membership & Tenant Boundary
- ADR-016 — Database-Backed Tenant RBAC
- ADR-017 — Module Runtime & Bootstrap Contract
- ADR-018 — Organizational Topology & Scoped Authorization

---

# 1. Context

EduCore's product direction includes integrated dormitory management.

The architectural risk is to treat Dormitory, Building, Room, Bed, or Locker as generic organizational units, duplicate Person/Student identity inside a Dormitory module, or make Core depend on a downstream residential domain.

Phase 4B originally locked only the integration boundary and did not implement the Dormitory module at that time. Since this ADR was accepted, the downstream implementation has been created under `Modules/Dormitory`. That implementation must continue to preserve the boundary defined by this ADR rather than promoting Dormitory concepts into Core.

---

# 2. Decision

## 2.1 Dormitory is a business module

Current ownership:

```text
Modules/Dormitory
├── Dormitory
├── Building
├── Room
├── Bed
├── Locker
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

Dormitory, Building, Room, Bed, and Locker are not OrganizationUnit variants.

No Dormitory/Building/Room/Bed/Locker identifiers are added to `OrganizationalContext`.

No `DormitoryContext` is introduced without a concrete runtime requirement.

## 2.3 Dormitory root consumes organizational ownership

The Dormitory root is:

```text
tenant-aware
owned by one Organization
optionally owned by one OrganizationUnit
```

Current root ownership fields are conceptually:

```text
tenant_id
organization_id
organization_unit_id nullable
```

`organization_unit_id = NULL` means Organization-level ownership.

A non-null OrganizationUnit must belong to the same Organization and Tenant. The current persistence layer enforces tenant/topology consistency with database constraints rather than relying only on application checks.

Building, Room, Bed, and Locker inherit their organizational ownership through the Dormitory facility hierarchy. Descendant persistence must not create a competing organizational identity model.

## 2.4 Resident identity reuses Membership

Canonical human/tenant identity remains:

```text
Person
  ↓
Membership
```

Dormitory must not create duplicate resident Person identity.

Student is not the canonical resident identity because a resident may be a Student, Employee, Teacher/Staff, or another eligible Membership.

Resident placement is a separate Dormitory-domain relationship:

```text
Membership
  ↓
ResidentPlacement
  ↓
Room
```

Dormitory and Building location are derived from the Room hierarchy rather than duplicated as resident identity.

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

Permission is necessary but not sufficient: Dormitory resource ownership must also match the verified Tenant/Organization/Unit context at an exposed operation boundary.

The current Check-In application service resolves tenant context and resident eligibility but does not itself resolve an actor or invoke Core scoped authorization. That does not supersede this ADR. Authorization remains required when a Dormitory operation is exposed through an authenticated application/HTTP boundary. A Dormitory HTTP/API boundary is not implemented yet.

## 2.6 Core topology deactivation does not mass-mutate Dormitory history

When Tenant/Organization/Unit or Membership state changes, Core must not hard-delete or mass-rewrite downstream Dormitory historical records.

Dormitory operational services evaluate current parent topology and Membership state according to their domain workflows.

The implemented Check-In flow follows this rule by transactionally revalidating the relevant facility hierarchy and Membership state before activating a planned placement.

---

# 3. Consequences

## Positive

- Core remains fundamental and independent from a residential business domain.
- Dormitory evolves independently behind a clear module boundary.
- Human identity is not duplicated.
- Multi-lembaga/multi-cabang authorization contracts are reused rather than reinvented.
- Building/Room/Bed/Locker remain meaningful facility concepts instead of polluting organizational topology.
- Concrete Dormitory persistence and Check-In concurrency behavior can evolve downstream without widening Core with residential concepts.

## Trade-offs

- Dormitory operations need both authorization and resource-ownership validation when exposed to an authenticated caller.
- The application layer must revalidate mutable facility and Membership state transactionally for state-changing workflows such as Check-In.
- Some concurrency guarantees are workflow-specific. The implemented single-Room Check-In locking design must not be assumed to make future multi-Room Transfer/Reassignment workflows safe automatically.
- Advanced residency policy and the remaining placement lifecycle require separate domain decisions rather than being inferred from the current Check-In implementation.

---

# 4. Architectural Invariants

```text
Dormitory != Organization
Dormitory != OrganizationUnit
Building/Room/Bed/Locker != OrganizationUnit

Dormitory → Core
Core ↛ Dormitory

resident identity → Membership
resident identity ↛ duplicate Person
resident identity ↛ Student-only identity

OrganizationalContext
does not contain Dormitory/Building/Room/Bed/Locker

Dormitory authorization
reuses global Role/Permission + ADR-018 scopes

permission check
+
resource ownership check
= required exposed-operation boundary

Dormitory persistence/workflows
remain owned by Modules/Dormitory
```

---

# 5. Implementation Status Since Acceptance

The following work is now implemented under `Modules/Dormitory` and is no longer deferred by this ADR:

- module manifest/provider with dependency `Dormitory → Core`;
- Dormitory, Building, Room, Bed, and Locker persistence;
- Tenant/Organization/optional OrganizationUnit ownership for the Dormitory root;
- tenant-qualified facility hierarchy constraints;
- room capacity basis and effective-capacity domain primitives;
- resident placement persistence using canonical Core Membership identity;
- resident placement lifecycle persistence for `PLANNED`, `ACTIVE`, `ENDED`, and `CANCELLED` states;
- active-placement database invariants for Membership, Bed, and Locker;
- resource-requirement policy for `BED`, `LOCKER`, and `BED_AND_LOCKER` rooms;
- Check-In application orchestration from an existing planned placement;
- transactional hierarchy/resource/Membership revalidation for Check-In;
- Check-In locking and conflict translation needed for current single-Room concurrency scenarios.

This implementation does not change the dependency or identity decisions in Sections 2 and 4.

---

# 6. Deferred / Unfinished Work

This ADR still does not define or claim completion of:

- advanced gender/age/residency eligibility policy;
- a complete planning/allocation creation workflow beyond persisted placement primitives;
- Check-Out workflow;
- Transfer / Room reassignment workflow;
- deterministic multi-Room locking required by future Transfer/Reassignment concurrency;
- bulk resident movement workflows;
- complete END/CANCEL application workflows and their business reasons/policies;
- Dormitory HTTP/API contract;
- authenticated Dormitory endpoint integration with Core scoped authorization;
- additional Dormitory module dependencies beyond the currently required `Dormitory → Core`, unless introduced by a future explicit requirement.

Those decisions remain downstream Dormitory work and require their own implementation/design review where they alter current invariants.

---

# 7. Current Implementation Evidence

Representative implementation locations:

- `Modules/Dormitory/module.yaml`
- `Modules/Dormitory/Database/Migrations/`
- `Modules/Dormitory/Models/`
- `Modules/Dormitory/Domain/`
- `Modules/Dormitory/Application/`
- `Modules/Dormitory/Infrastructure/`
- `Modules/Dormitory/Tests/`

Current architecture summaries must describe these as implemented downstream capabilities while preserving this ADR as the canonical integration-boundary decision.

---

# 8. References

- `docs/architecture/current-architecture.md`
- `docs/architecture/architecture-principles.md`
- `docs/architecture/folder-structure.md`
- ADR-013
- ADR-014
- ADR-018
