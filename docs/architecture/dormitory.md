# EduCore Dormitory — Current Architecture & Implementation

- **Version**: 0.1
- **Status**: Draft — Documentation Alignment
- **Updated**: 2026-08-19
- **Baseline**: Dormitory Check-In Foundation through 3.8.3
- **Canonical Boundary ADR**: ADR-019 — Dormitory Integration Boundary

## Purpose

Dokumen ini adalah target canonical current-state reference untuk implementasi `Modules/Dormitory`.

Dokumen ini menjelaskan **bagaimana Dormitory saat ini diimplementasikan** setelah boundary arsitekturalnya dikunci oleh ADR-019. Dokumen ini tidak menggantikan ADR-019 dan tidak mengubah keputusan architectural boundary yang telah diterima.

Gunakan dokumen ini untuk memahami current Dormitory implementation contract, terutama:

- module dependency dan ownership boundary;
- facility hierarchy dan persistence ownership;
- room capacity model;
- resident placement lifecycle;
- Check-In application flow;
- transactional revalidation dan locking;
- database invariants;
- domain-error behavior;
- concurrency guarantees yang sudah dibuktikan;
- testing evidence;
- unfinished/future Dormitory boundaries.

Dokumen ini tetap berstatus **Draft — Documentation Alignment** sampai seluruh section current-state di bawah selesai diaudit terhadap source code dan tests. Setelah final documentation closure gate, status dapat dinaikkan menjadi `Current / Locked`.

---

# 1. Documentation Ownership & Source of Truth

Current documentation responsibilities dibagi sebagai berikut:

| Document | Responsibility |
| --- | --- |
| `docs/architecture/adr/ADR-019-dormitory-integration-boundary.md` | Architectural decision dan invariant boundary `Dormitory → Core` |
| `docs/architecture/current-architecture.md` | Repository-wide concise current architecture baseline |
| `docs/architecture/dormitory.md` | Detailed current Dormitory implementation contract |
| `Modules/Dormitory/**` | Executable implementation source of truth |
| `Modules/Dormitory/Tests/**` | Executable regression/concurrency evidence |

Jika dokumentasi ini berbeda dari executable implementation/tests, perbedaan tersebut harus diperlakukan sebagai **documentation drift** dan diaudit sebelum dokumentasi atau implementation contract diubah.

Historical PRD/sprint material tidak boleh mengalahkan Accepted ADR atau current executable implementation.

---

# 2. Current Scope

Current locked Dormitory foundation mencakup:

```text
Dormitory module boundary
        ↓
Facility persistence
        ↓
Room capacity primitives
        ↓
ResidentPlacement persistence
        ↓
Placement resource requirements
        ↓
Check-In application transaction
        ↓
Transactional revalidation
        ↓
Check-In concurrency safety through 3.8.3
```

Current implementation scope **belum** mencakup complete Dormitory product lifecycle.

Explicit unfinished boundaries antara lain:

- Check-Out workflow;
- Transfer / Room reassignment;
- bulk resident movement;
- complete END/CANCEL application workflows;
- authenticated Dormitory HTTP/API exposure;
- future multi-Room concurrency design.

Detail unfinished boundary akan didokumentasikan pada section khusus setelah current implemented contract selesai diaudit.

---

# 3. Architectural Boundary

**Documentation slice status**: aligned to current implementation.

ADR-019 remains the canonical architectural decision for the Dormitory integration boundary. The current implementation preserves that decision rather than extending Core with residential concepts.

Canonical dependency direction:

```text
Dormitory → Core
Core ↛ Dormitory
```

`Modules/Dormitory/module.yaml` declares only `core` as a module dependency and registers `DormitoryServiceProvider` through the manifest-driven module runtime. Dormitory is therefore a downstream business module that consumes Core contracts and models without becoming part of Core organizational topology.

The reverse dependency is explicitly prohibited: Core production code must not reference the `Modules\Dormitory\` namespace. This boundary is covered by `DormitoryModuleArchitectureTest`.

Dormitory may consume Core concepts that are already canonical platform primitives, including:

- Tenant and tenant context;
- Organization and OrganizationUnit ownership;
- canonical Membership identity;
- Core UUIDv7 support;
- Core contracts required by a Dormitory-owned adapter or application service.

Dormitory-specific concepts remain owned by `Modules/Dormitory`, including:

```text
Dormitory
Building
Room
Bed
Locker
ResidentPlacement
RoomCapacityBasis
PlacementStatus
ResidentCategory
Dormitory Check-In application behavior
```

These concepts must not be promoted into `OrganizationalContext`, modeled as new Core topology levels, or introduced as reverse Core dependencies merely because Dormitory consumes Core identity or organizational ownership.

Canonical resident identity remains Core `Membership`. Dormitory does not create a duplicate Person, User, Student, or employee identity for residency. `ResidentPlacement` is the Dormitory-owned relationship that associates a Membership with its residential placement.

The current Dormitory module also does not introduce a separate `DormitoryContext`. Tenant context is consumed from Core where required by application/persistence behavior. Any future authenticated Dormitory boundary must continue to reuse Core authorization contracts rather than create a parallel Role/Permission system; the concrete current Check-In service does not itself resolve an actor or invoke scoped authorization.

---

# 4. Facility Hierarchy & Persistence Ownership

**Documentation slice status**: aligned to current implementation.

## 4.1 Canonical facility hierarchy

The persisted facility hierarchy is:

```text
Tenant
└── Organization
    └── OrganizationUnit?
        └── Dormitory
            └── Building
                └── Room
                    ├── Bed
                    └── Locker
```

`OrganizationUnit` is optional ownership metadata on the Dormitory root; it is not a mandatory physical level between Organization and Dormitory. A Dormitory may therefore be owned directly at Organization level by storing `organization_unit_id = NULL`.

The canonical physical parent chain is:

```text
Dormitory → Building → Room → Bed / Locker
```

Each descendant stores only its immediate physical parent reference plus `tenant_id`. Higher-level organizational ownership is derived through the parent hierarchy rather than duplicated across descendant tables.

## 4.2 Dormitory root ownership

The `dormitories` table stores:

```text
id
tenant_id
organization_id
organization_unit_id nullable
name
code nullable
is_active
timestamps
deleted_at
```

Current persistence guarantees:

- `tenant_id` must reference an existing Tenant;
- `(organization_id, tenant_id)` must reference an Organization in the same Tenant;
- when `organization_unit_id` is present, `(organization_unit_id, organization_id, tenant_id)` must reference an OrganizationUnit belonging to that same Organization and Tenant;
- cross-tenant Organization ownership and mismatched OrganizationUnit ownership are rejected by database constraints;
- the model uses the Core `BelongsToTenant` boundary, UUIDv7 identifiers, and soft deletion.

This means the Dormitory root is the only facility entity that directly stores Organization/OrganizationUnit ownership.

## 4.3 Descendant persistence shape

Current descendant ownership columns are intentionally narrow:

| Entity | Tenant projection | Immediate parent | Higher ownership columns intentionally absent |
| --- | --- | --- | --- |
| Building | `tenant_id` | `dormitory_id` | `organization_id`, `organization_unit_id` |
| Room | `tenant_id` | `building_id` | `dormitory_id`, `organization_id`, `organization_unit_id` |
| Bed | `tenant_id` | `room_id` | `building_id`, `dormitory_id`, `organization_id`, `organization_unit_id` |
| Locker | `tenant_id` | `room_id` | `building_id`, `dormitory_id`, `organization_id`, `organization_unit_id` |

`tenant_id` is deliberately retained on each persisted facility entity. It is a tenant-qualified persistence/security projection, not a competing organizational ownership model. The organizational owner remains derived from the Dormitory root.

Models `Building`, `Room`, `Bed`, and `Locker` use Core tenant scoping and UUIDv7 support. They also use soft deletion, matching the Dormitory root.

## 4.4 Tenant-qualified parent integrity

Parent-child references are tenant-qualified composite foreign keys:

```text
Building (dormitory_id, tenant_id)
    → Dormitory (id, tenant_id)

Room (building_id, tenant_id)
    → Building (id, tenant_id)

Bed (room_id, tenant_id)
    → Room (id, tenant_id)

Locker (room_id, tenant_id)
    → Room (id, tenant_id)
```

Supporting unique `(id, tenant_id)` identities exist on the facility tables so PostgreSQL can enforce those composite references. The result is that a descendant cannot point at a parent from another Tenant even if a raw identifier is supplied outside the normal tenant-scoped Eloquent path.

Bed and Locker additionally receive tenant-and-Room-qualified unique identities later in the placement migration so `ResidentPlacement` can prove nested resource ownership. The detailed placement/resource foreign-key contract is documented in the Resident Placement and Database Invariants sections rather than duplicated here.

## 4.5 Deletion boundary

Facility foreign keys use restrictive delete behavior. Normal model deletion is soft deletion, while a hard delete of a parent with persisted children is rejected by the database. Current regression coverage proves at least:

- Organization with a Dormitory cannot be hard-deleted;
- Dormitory with Buildings cannot be hard-deleted;
- Building with Rooms cannot be hard-deleted;
- Room with Beds or Lockers cannot be hard-deleted.

This protects hierarchy integrity and prevents parent hard deletion from silently orphaning downstream Dormitory records.

---

# 5. Room Capacity Model

**Documentation slice status**: aligned to current implementation.

Room capacity is modeled as a Dormitory-domain calculation rather than as a single numeric `capacity` column. A Room persists the resource basis that determines which resource count constrains occupancy, while the `RoomCapacity` value object evaluates an operational capacity snapshot supplied by its caller.

## 5.1 Capacity basis

`RoomCapacityBasis` defines exactly three current modes:

```text
BED
LOCKER
BED_AND_LOCKER
```

`rooms.capacity_basis` is required persistence state and is cast by the `Room` model to `RoomCapacityBasis`. The migration does not define a generic integer room-capacity column or a default capacity basis; callers creating a Room must choose one of the supported values.

The basis determines effective capacity as follows:

| Basis | Effective capacity | Meaning |
| --- | --- | --- |
| `BED` | `usableBeds` | Bed count constrains capacity |
| `LOCKER` | `usableLockers` | Locker count constrains capacity |
| `BED_AND_LOCKER` | `min(usableBeds, usableLockers)` | Both resource types are required, so the scarcer resource constrains capacity |

`BED_AND_LOCKER` never adds Bed and Locker counts. For example, 20 usable Beds and 16 usable Lockers produce an effective capacity of 16, not 36.

## 5.2 Capacity snapshot value object

`RoomCapacity` is an immutable domain value object with four inputs:

```text
basis
usableBeds
usableLockers
activeOccupancy
```

All three numeric counts must be non-negative. Negative capacity or occupancy inputs are rejected with `InvalidArgumentException`.

The value object exposes the following calculations:

```text
effectiveCapacity
  = basis-specific resource capacity

availableCapacity
  = max(0, effectiveCapacity - activeOccupancy)

isOverCapacity
  = activeOccupancy > effectiveCapacity

overCapacityBy
  = max(0, activeOccupancy - effectiveCapacity)
```

`availableCapacity` therefore never becomes negative. If active occupancy exceeds the currently effective capacity, the room is represented as over-capacity while available capacity remains zero.

## 5.3 Resource availability persistence

Bed and Locker are persisted as Room resources with separate operational flags:

```text
is_active
is_usable
```

Both columns default to `true` in the current migrations. The flags have distinct purposes: a resource can remain part of the persisted facility history while being inactive or temporarily unusable. Current Check-In validation requires a supplied Bed or Locker to be both active and usable before it can participate in an activation.

The capacity domain model intentionally does not encode an automatic database query for these flags. `RoomCapacity` receives already-calculated `usableBeds` and `usableLockers` counts; it does not query `beds`, `lockers`, or resident placements itself. The current module does not expose a dedicated database-backed service that assembles a complete `RoomCapacity` snapshot automatically.

This distinction is part of the current contract:

```text
persistence
  → stores Room basis and Bed/Locker operational state

RoomCapacity
  → evaluates supplied counts

caller/read model
  → responsible for assembling those counts when such a workflow is implemented
```

Documentation must therefore describe the effective-capacity **calculation semantics** as implemented, without claiming that a general persisted capacity read model already exists.

## 5.4 Non-destructive over-capacity behavior

Capacity reduction is treated as operational state, not destructive reconciliation. If a later capacity snapshot has fewer usable resources than active occupancy, `RoomCapacity` reports the room as over-capacity and reports the excess through `overCapacityBy()`.

Example:

```text
basis             = BED_AND_LOCKER
usableBeds         = 20
usableLockers      = 16
activeOccupancy    = 17

effectiveCapacity = 16
availableCapacity = 0
isOverCapacity     = true
overCapacityBy     = 1
```

The domain calculation does not remove, move, cancel, or otherwise mutate resident placements to make occupancy fit the new capacity. Existing resident history is preserved. Any future reconciliation or resident-movement workflow is a separate application-domain decision and must not be inferred from `RoomCapacity`.

## 5.5 Mutable Room basis and operation-time policy

`capacity_basis` is current Room state rather than immutable historical configuration. A Room may therefore have a different basis when a later operation executes than when an earlier placement was planned. Current Check-In behavior re-reads and revalidates the Room's current basis inside its transaction before activating a placement.

The exact Bed/Locker requirement for each basis and its Check-In enforcement are documented in Sections 7 and 8. This section defines only the capacity calculation and resource-state semantics.

---

# 6. Resident Placement Model & Lifecycle

**Documentation slice status**: aligned to current implementation.

`ResidentPlacement` is the Dormitory-owned persistence model for a Membership's residential placement. It preserves canonical Core identity, stores the Room as the canonical residential location, optionally references Bed/Locker resources, and records placement lifecycle state without duplicating higher facility or organizational identity.

## 6.1 Canonical placement fact

The `resident_placements` table stores:

```text
id
tenant_id
membership_id
room_id
bed_id nullable
locker_id nullable
resident_category
status
planned_at
checked_in_at nullable
ended_at nullable
cancelled_at nullable
end_reason nullable
cancellation_reason nullable
created_at
updated_at
```

`planned_at` is required database state. It is not nullable in the current migration. `checked_in_at`, `ended_at`, and `cancelled_at` are lifecycle timestamps whose required/null combinations depend on `status`.

Canonical identity and location are intentionally narrow:

```text
Core Membership
      ↓
ResidentPlacement
      ↓
Room
```

Dormitory and Building location are derived through the Room hierarchy. Bed and Locker are optional resource references attached to the placement; they do not replace Room as the canonical residential location.

The table intentionally does not duplicate inherited identity/location columns such as:

```text
student_id
building_id
dormitory_id
organization_id
organization_unit_id
```

The model uses UUIDv7 and Core tenant scoping. Unlike the facility models, `ResidentPlacement` does not use soft deletion and the table has no `deleted_at` column. Historical residency is represented by lifecycle rows rather than by soft-deleting the placement record.

## 6.2 Resident category

`ResidentCategory` currently defines exactly:

```text
REGULAR_RESIDENT
SUPERVISOR_RESIDENT
```

The model casts `resident_category` to this enum, and PostgreSQL enforces the same value set through `chk_resident_placements_category`.

Resident category classifies the Dormitory placement. It is not a replacement for Core Membership identity, Role/Permission authorization, or a generalized person type. Advanced resident-eligibility policy remains outside this persistence enum.

## 6.3 Placement lifecycle

`PlacementStatus` currently defines:

```text
PLANNED
ACTIVE
ENDED
CANCELLED
```

PostgreSQL constrains the timestamp shape for each status:

| Status | `planned_at` | `checked_in_at` | `ended_at` | `cancelled_at` | Meaning in current persistence |
| --- | --- | --- | --- | --- | --- |
| `PLANNED` | required | `NULL` | `NULL` | `NULL` | Placement exists but has not been checked in |
| `ACTIVE` | required | required | `NULL` | `NULL` | Resident has been checked in and placement is current |
| `ENDED` | required | required | required | `NULL` | Previously active placement has ended |
| `CANCELLED` | required | `NULL` | `NULL` | required | Planned placement was cancelled before check-in |

The database also constrains `status` to those four enum-compatible values through `chk_resident_placements_status`.

`end_reason` and `cancellation_reason` are nullable descriptive fields. The current lifecycle CHECK constraint does not require a reason for `ENDED` or `CANCELLED`, and it does not enforce chronological ordering between lifecycle timestamps. Documentation and future services must not claim stricter database semantics than the migration currently provides.

## 6.4 Historical-record preservation

Historical placement rows are intentionally retained. Partial uniqueness rules apply only to `ACTIVE` rows, so an ended or cancelled placement does not erase the Membership's prior residential history and does not prevent a later active placement.

Current persistence therefore supports a history such as:

```text
Membership M
├── Placement A  ENDED
└── Placement B  ACTIVE
```

The current application layer does not yet provide complete planning, Check-Out/END, or cancellation workflows. Persistence can represent `ENDED` and `CANCELLED`, but the concrete lifecycle application behavior implemented today is Check-In of an existing matching `PLANNED` row into `ACTIVE`.

## 6.5 ACTIVE uniqueness invariants

The database is the final guard for current active-placement exclusivity through PostgreSQL partial unique indexes:

```text
uq_resident_placements_active_membership
  UNIQUE (tenant_id, membership_id)
  WHERE status = 'ACTIVE'

uq_resident_placements_active_bed
  UNIQUE (tenant_id, bed_id)
  WHERE status = 'ACTIVE' AND bed_id IS NOT NULL

uq_resident_placements_active_locker
  UNIQUE (tenant_id, locker_id)
  WHERE status = 'ACTIVE' AND locker_id IS NOT NULL
```

These constraints mean:

- one Membership may have at most one `ACTIVE` placement per Tenant;
- one Bed may be referenced by at most one `ACTIVE` placement;
- one Locker may be referenced by at most one `ACTIVE` placement;
- historical `PLANNED`, `ENDED`, and `CANCELLED` rows remain possible because the uniqueness rules are status-filtered.

The concurrency role of these indexes is documented in Section 9. This section records only the persisted lifecycle/exclusivity contract.

## 6.6 Tenant and nested resource ownership

Placement ownership is enforced with tenant-qualified foreign keys:

```text
(membership_id, tenant_id)
    → memberships (id, tenant_id)

(room_id, tenant_id)
    → rooms (id, tenant_id)

(bed_id, room_id, tenant_id)
    → beds (id, room_id, tenant_id)

(locker_id, room_id, tenant_id)
    → lockers (id, room_id, tenant_id)
```

The nested Bed/Locker references prove that an allocated resource belongs to the same Room and Tenant recorded by the placement. Cross-tenant Membership/Room references and Bed/Locker references from another Room are rejected by the database.

`bed_id` and `locker_id` remain nullable schema fields. The lifecycle CHECK constraint does not make either resource mandatory merely because a placement is `ACTIVE`; the exact resource requirement depends on the Room's current `RoomCapacityBasis` and is an application-domain policy documented in Section 7.

## 6.7 Current lifecycle application boundary

The current repository contract supports the lock-aware queries required by Check-In:

```text
findPlannedForMembershipInRoomForUpdate(...)
findActiveForMembershipForUpdate(...)
findActiveForBedForUpdate(...)
findActiveForLockerForUpdate(...)
save(...)
```

This is not a generic placement lifecycle manager. The implemented service path currently activates an existing matching `PLANNED` placement; full placement planning, Check-Out/END, cancellation, Transfer, and reassignment workflows remain separate downstream work.

---

# 7. Placement Resource Requirements

**Documentation slice status**: aligned to current implementation.

`PlacementResourceRequirements` is a small immutable Dormitory-domain value object that translates the current `RoomCapacityBasis` into the Bed/Locker presence required for a placement operation. It keeps resource-presence policy separate from persistence, availability checks, and placement lifecycle state.

## 7.1 Requirement matrix

`PlacementResourceRequirements::fromBasis(...)` maps the three current Room capacity bases as follows:

| Room capacity basis | Bed required | Locker required | Satisfied examples |
| --- | --- | --- | --- |
| `BED` | yes | no | Bed only; Bed + Locker |
| `LOCKER` | no | yes | Locker only; Bed + Locker |
| `BED_AND_LOCKER` | yes | yes | Bed + Locker only |

Canonical rule:

```text
BED
  → requires Bed
  → Locker optional

LOCKER
  → Bed optional
  → requires Locker

BED_AND_LOCKER
  → requires Bed
  → requires Locker
```

The value object exposes:

```text
requiresBed()
requiresLocker()
isSatisfiedBy(hasBed, hasLocker)
```

`isSatisfiedBy(...)` evaluates only whether the required resource types are present. It intentionally allows an additional optional resource. For example, a `BED` room is still requirement-satisfied when both a Bed and Locker are supplied because Bed is required while Locker is optional.

## 7.2 Presence policy is not resource-validity policy

`PlacementResourceRequirements` does not query persistence and does not determine whether a supplied resource is usable, active, belongs to the target Room/Tenant, or is already referenced by another active placement. Those checks are separate application/persistence responsibilities.

The current Check-In path therefore separates the concerns conceptually as:

```text
current Room.capacity_basis
        ↓
PlacementResourceRequirements
        ↓
required resource presence

provided Bed / Locker
        ↓
Room repository + placement repository
        ↓
Room/Tenant ownership, active/usable state,
and active-placement availability
```

This separation means `isSatisfiedBy(...)` must not be interpreted as a complete resource-validity or occupancy check.

## 7.3 Optional supplied resources are still validated

A resource that is optional for the current basis may still be supplied. The requirement policy accepts that extra resource, but the current Check-In service still validates any supplied Bed or Locker before activation.

For example:

```text
Room basis = BED
Bed supplied = yes
Locker supplied = yes

requirement policy
  → satisfied

Locker validity/availability
  → still must pass Check-In validation
```

The same principle applies symmetrically to a supplied Bed in a `LOCKER` room. The policy therefore answers **which resource types must be present**, not **which optional resources should be ignored**.

## 7.4 Operation-time basis revalidation

Resource requirements are derived from the Room's current `capacity_basis` during the Check-In transaction. They are not permanently copied from an earlier planning-time Room configuration.

If the Room basis changes after a placement was planned, the current basis governs the activation attempt. For example:

```text
planning time
Room basis = BED

check-in time
Room basis = BED_AND_LOCKER

Bed supplied    = yes
Locker supplied = no

result
→ requirement not satisfied
→ placement remains PLANNED
```

Current regression coverage explicitly proves that Check-In rejects this case rather than relying on stale planning-time capacity policy.

## 7.5 Enforcement boundary

The database intentionally keeps `resident_placements.bed_id` and `resident_placements.locker_id` nullable and does not encode a basis-specific CHECK constraint such as `BED → bed_id IS NOT NULL`. Resource presence is an operation-time Dormitory policy because it depends on the target Room's current mutable capacity basis.

Current Check-In enforcement is:

```text
Room current capacity basis
        ↓
PlacementResourceRequirements::fromBasis(...)
        ↓
validate supplied Bed/Locker when present
        ↓
isSatisfiedBy(
    hasBed: resolved Bed exists,
    hasLocker: resolved Locker exists,
)
        ↓
false
→ ResidentCheckInException::resourceRequirementsNotSatisfied()
```

A failed requirement check does not activate or partially mutate the planned placement; the transaction leaves the placement in its prior `PLANNED` state.

The broader Check-In orchestration, validation order, transaction boundary, and locking behavior are documented in Sections 8 and 9.

---

# 8. Check-In Application Flow

**Documentation slice status**: pending detailed alignment.

Section ini akan mendokumentasikan:

- `CheckInResident` command boundary;
- `ResidentPlacementServiceInterface`;
- tenant-context resolution;
- resident eligibility adapter;
- transactional state revalidation;
- `PLANNED → ACTIVE` transition;
- persistence responsibilities.

Current documentation tidak boleh mengklaim actor/scoped-authorization invocation di concrete Check-In service selama behavior tersebut belum terdapat pada implementation.

---

# 9. Transaction, Locking & Concurrency Contract

**Documentation slice status**: pending detailed alignment.

Section ini akan mendokumentasikan current single-Room Check-In concurrency contract, termasuk:

- deterministic lock sequence;
- Room exclusive serialization boundary;
- parent/Membership shared-lock behavior;
- active placement/resource revalidation;
- same-Membership/different-Room race handling;
- same Bed/Locker serialization behavior;
- combined `BED_AND_LOCKER` safety;
- database unique constraints sebagai final race guards.

Concurrency guarantee pada section ini hanya berlaku untuk workflow yang telah diuji. Future Transfer/Reassignment tidak boleh mengasumsikan contract single-Room Check-In otomatis deadlock-safe.

---

# 10. Database Invariants

**Documentation slice status**: pending detailed alignment.

Section ini akan mendokumentasikan database constraints yang menjadi bagian dari current correctness boundary, termasuk ownership, lifecycle, resource exclusivity, dan active-placement invariants.

---

# 11. Domain Errors & Persistence Conflict Translation

**Documentation slice status**: pending detailed alignment.

Section ini akan mendokumentasikan current `ResidentCheckInException` behavior dan translation boundary untuk recognized PostgreSQL persistence conflicts tanpa mengekspos raw database exceptions sebagai Dormitory-domain contract.

---

# 12. Testing & Runtime Evidence

**Documentation slice status**: pending detailed alignment.

Section ini akan mendokumentasikan regression suites dan concurrency proofs yang mendukung current locked Dormitory baseline. Test/pass counts akan dicatat sebagai closure evidence, bukan permanent architectural invariant.

---

# 13. Deferred & Future Boundaries

**Documentation slice status**: pending detailed alignment.

Section ini akan membedakan current implemented contract dari future work agar dokumentasi tidak menjanjikan capability yang belum ada.

Future workflow yang mengubah current locking/lifecycle invariants harus melewati design, implementation, regression, dan documentation audit tersendiri.

---

# 14. Current Implementation References

Current source areas yang menjadi basis audit dokumen ini:

```text
Modules/Dormitory/module.yaml
Modules/Dormitory/Application/
Modules/Dormitory/Contracts/
Modules/Dormitory/Database/Migrations/
Modules/Dormitory/Domain/
Modules/Dormitory/Infrastructure/
Modules/Dormitory/Models/
Modules/Dormitory/Providers/
Modules/Dormitory/Tests/
```

Relevant Core integration contract untuk resident eligibility/locking harus direferensikan hanya ketika benar-benar dikonsumsi oleh Dormitory; Core tidak boleh memperoleh dependency balik ke Dormitory.

---

# 15. Documentation Closure Criteria

Dokumen ini hanya boleh dinaikkan dari:

```text
Draft — Documentation Alignment
```

menjadi:

```text
Current / Locked
```

setelah seluruh kondisi berikut terpenuhi:

1. setiap current implementation section selesai diaudit terhadap source code;
2. database invariants cocok dengan migrations;
3. lifecycle dan service behavior cocok dengan tests;
4. concurrency statements memiliki executable regression evidence;
5. current vs unfinished boundaries dibedakan secara eksplisit;
6. tidak ada capability yang diklaim implemented hanya karena terdapat pada ADR/future design;
7. `docs/README.md` / `docs/architecture/README.md` di-align untuk menunjuk dokumen ini sebagai current Dormitory implementation reference;
8. final documentation drift audit clean.
