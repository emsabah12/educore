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

**Documentation slice status**: aligned to current implementation.

The current Dormitory Check-In use case activates an **existing matching `PLANNED` ResidentPlacement**. It does not create a new placement, perform Check-Out, transfer a resident, or expose an HTTP endpoint. The application boundary is intentionally small:

```text
CheckInResident command
        ↓
ResidentPlacementServiceInterface::checkIn(...)
        ↓
ResidentPlacementService
        ↓
Dormitory repositories + eligibility adapter
        ↓
existing ResidentPlacement: PLANNED → ACTIVE
```

## 8.1 Command boundary

`CheckInResident` is an immutable application command carrying only caller-supplied placement data:

```text
membershipId
roomId
bedId nullable
lockerId nullable
residentCategory
```

The command does **not** carry:

```text
tenantId
actorId
Organization / OrganizationUnit context
authorization result
```

The command constructor itself is only a data carrier. `ResidentPlacementService` validates the operational input before opening the database transaction:

- current Tenant must be resolvable from `TenantContextInterface`;
- Tenant, Membership, Room, and any supplied Bed/Locker identifiers must be valid UUIDv7 strings;
- `residentCategory` must map to the current `ResidentCategory` enum.

A missing tenant context is surfaced through Core's `TenantContextNotResolvedException`. Invalid identifiers or resident category values are rejected through `ResidentCheckInException` factories.

## 8.2 Service and dependency boundary

`ResidentPlacementServiceInterface` currently exposes one Dormitory placement operation:

```text
checkIn(CheckInResident $command): ResidentPlacement
```

`DormitoryServiceProvider` binds that contract to `ResidentPlacementService` and binds the service's persistence/eligibility dependencies to Dormitory-owned interfaces:

```text
ResidentPlacementService
├── TenantContextInterface                 (Core)
├── RoomRepositoryInterface                (Dormitory)
├── ResidentPlacementRepositoryInterface   (Dormitory)
└── ResidentEligibilityCheckerInterface    (Dormitory)
```

Concrete infrastructure is supplied through:

```text
RoomRepositoryInterface
→ EloquentRoomRepository

ResidentPlacementRepositoryInterface
→ EloquentResidentPlacementRepository

ResidentEligibilityCheckerInterface
→ MembershipResidentEligibilityChecker
```

This keeps the application service responsible for orchestration while query/persistence mechanics stay behind repositories and Core Membership access stays behind a Dormitory-owned eligibility boundary.

## 8.3 Current resident eligibility baseline

`MembershipResidentEligibilityChecker` is the current implementation of `ResidentEligibilityCheckerInterface`. Its baseline rule is intentionally narrow:

```text
Membership must exist as ACTIVE
for the current Tenant
```

The adapter uses Core `MembershipRepositoryInterface::findActiveMembershipByIdAndTenantForShare(...)`. A missing/inactive Membership is translated to `ResidentCheckInException::membershipNotEligible()`.

`residentCategory` is passed through the Dormitory eligibility contract, but the current Membership-backed adapter does not use the category to apply additional policy. Documentation must therefore not claim that `REGULAR_RESIDENT` and `SUPERVISOR_RESIDENT` currently have different eligibility rules.

The shared-lock behavior used by this lookup is part of the concurrency contract and is documented in Section 9.

## 8.4 Transactional application flow

After context and basic input validation, Check-In executes its state-changing work inside `DB::transaction(..., 3)`. The current application-level sequence is:

```text
1. resolve current Room in Tenant
2. reject missing/inactive Room
3. resolve and revalidate Building
4. resolve and revalidate Dormitory
5. revalidate Membership eligibility
6. reject an existing ACTIVE placement for Membership
7. load the matching PLANNED placement
8. derive requirements from current Room.capacity_basis
9. resolve/validate supplied Bed when present
10. reject Bed already referenced by an ACTIVE placement
11. resolve/validate supplied Locker when present
12. reject Locker already referenced by an ACTIVE placement
13. verify current resource-presence requirements
14. mutate the existing PLANNED placement
15. persist and return refreshed ResidentPlacement
```

This ordering describes application responsibilities only. The exact row-lock modes and serialization guarantees behind those repository calls are documented separately in Section 9.

## 8.5 Transactional revalidation

Check-In does not trust planning-time facility/resource state. Before activation, the current implementation revalidates mutable state from persistence inside the transaction:

- Room must still exist in the current Tenant and be active;
- parent Building must still exist and be active;
- parent Dormitory must still exist and be active;
- Membership must still satisfy the current eligibility baseline;
- Membership must not already have another active placement;
- a matching `PLANNED` placement must still exist for the same Tenant, Membership, Room, and resident category;
- supplied Bed/Locker must belong to the target Room/Tenant and remain active and usable;
- supplied Bed/Locker must not already be referenced by another active placement;
- required resource presence is recalculated from the Room's **current** capacity basis.

Focused regression coverage proves rejection when these conditions change before Check-In, including inactive Building/Dormitory/Membership/Room/resources, occupied resources, missing planned placement, existing active placement, missing required Bed/Locker, and changed Room capacity basis.

A failed validation path does not partially activate the placement. Tests verify that the relevant planned placement remains `PLANNED` with `checked_in_at`, Bed, and Locker state unchanged where applicable.

## 8.6 PLANNED → ACTIVE mutation

A successful Check-In mutates the **same** planned-placement row rather than inserting a replacement row:

```text
existing placement
status         PLANNED → ACTIVE
bed_id         → selected Bed id or NULL
locker_id      → selected Locker id or NULL
checked_in_at  → current application time
```

The service does not modify:

```text
planned_at
ended_at
cancelled_at
```

The placement repository persists with `saveOrFail()` and returns a refreshed model. Regression coverage verifies that the original placement identifier is preserved and no additional placement row is created for the successful activation.

## 8.7 Authorization and exposure boundary

The concrete Check-In service currently resolves Tenant context and resident eligibility, but it does **not**:

- resolve an authenticated actor;
- invoke Core `AuthorizationService` or scoped Role/Permission evaluation;
- expose an HTTP/controller/route boundary;
- derive Organization/OrganizationUnit authorization context for the caller.

This is an implementation-status statement, not a relaxation of ADR-019. When Check-In or another Dormitory operation is exposed through an authenticated application/HTTP boundary, Core authorization plus Dormitory resource-ownership validation remains required by the accepted architectural contract.

## 8.8 Responsibility boundary

Current responsibilities remain separated as follows:

| Concern | Current owner |
| --- | --- |
| Tenant resolution | Core `TenantContextInterface` consumed by application service |
| Check-In orchestration and state transition | `ResidentPlacementService` |
| Facility/resource lookup | `RoomRepositoryInterface` |
| Placement lookup and persistence | `ResidentPlacementRepositoryInterface` |
| Membership eligibility abstraction | `ResidentEligibilityCheckerInterface` |
| Current Membership-backed eligibility | `MembershipResidentEligibilityChecker` |
| Resource-presence rule | `PlacementResourceRequirements` |
| Database lifecycle/uniqueness safeguards | PostgreSQL schema |
| Exact locking/concurrency semantics | Section 9 |
| Persistence-conflict/domain-error translation details | Section 11 |

The service catches the recognized active-Membership unique conflict at its persistence boundary and converts it to the corresponding Dormitory domain error; the exact conflict-recognition strategy is intentionally deferred to Section 11.

---

# 9. Transaction, Locking & Concurrency Contract

**Documentation slice status**: aligned to current implementation.

The current concurrency contract applies to the implemented **single-Room Check-In** workflow. It combines application-level transactional revalidation, explicit PostgreSQL row locks, and database uniqueness constraints. None of those layers should be treated as sufficient in isolation.

## 9.1 Canonical Check-In lock sequence

After basic input validation and Tenant-context resolution, the state-changing flow runs inside the Check-In transaction with the following canonical lock order:

```text
BEGIN TRANSACTION

Room
  → FOR UPDATE

Building
  → FOR SHARE

Dormitory
  → FOR SHARE

Membership
  → FOR SHARE

existing ACTIVE placement for Membership
  → FOR UPDATE if present

matching PLANNED placement
  → FOR UPDATE

Bed
  → FOR UPDATE if supplied

existing ACTIVE placement for Bed
  → FOR UPDATE if present

Locker
  → FOR UPDATE if supplied

existing ACTIVE placement for Locker
  → FOR UPDATE if present

transactional revalidation
PLANNED → ACTIVE
persist

COMMIT
```

Repository methods own the persistence-specific lock queries while `ResidentPlacementService` owns their application ordering.

The order is part of the current Check-In concurrency contract. Future state-changing workflows must not arbitrarily reorder these resources if they participate in the same concurrency domain.

## 9.2 Room is the primary exclusive serialization boundary

The target Room is locked with `FOR UPDATE` before the parent facility hierarchy and resident/resource checks proceed.

Conceptually:

```text
Check-In for Room R
        ↓
Room R FOR UPDATE
        ↓
all later Check-In work for Room R
is serialized behind that lock
```

This gives the current Check-In flow a deterministic per-Room exclusive boundary.

Two Check-In transactions targeting the same Room cannot concurrently progress through Bed/Locker allocation and placement activation. The second transaction waits until the first transaction releases the Room lock, then re-runs the relevant current-state checks.

The Room lock is deliberately narrower than locking the entire Building or Dormitory exclusively.

## 9.3 Parent hierarchy uses shared locks

After the Room lock is acquired, the current Building and Dormitory are re-read using shared locks:

```text
Building
  → FOR SHARE

Dormitory
  → FOR SHARE
```

These locks protect the parent hierarchy from incompatible concurrent mutation while avoiding unnecessary serialization of independent Rooms.

For example:

```text
Building B
├── Room A
└── Room B
```

Check-In for Room A and Check-In for Room B may each hold their own Room `FOR UPDATE` lock while both hold compatible shared locks on Building B and its Dormitory.

The current implementation therefore does not use:

```text
Building  FOR UPDATE
Dormitory FOR UPDATE
```

as the normal Check-In hierarchy boundary.

That design prevents the shared parent hierarchy from becoming a global Check-In mutex.

## 9.4 Membership eligibility uses a shared lock

Dormitory eligibility delegates to Core Membership persistence through:

```text
findActiveMembershipByIdAndTenantForShare(...)
```

The current Membership lookup therefore uses a shared lock:

```text
Membership
  → FOR SHARE
```

Its purpose is eligibility stability: while Check-In relies on the Membership being active, an incompatible concurrent mutation of that Membership cannot silently invalidate the same transaction's eligibility assumption.

The Membership shared lock is **not** the exclusive resident-placement mutex.

Multiple Check-In transactions for the same Membership may hold compatible Membership shared locks if they target different Rooms. ACTIVE placement uniqueness therefore requires an additional database-level correctness boundary.

## 9.5 ACTIVE-placement checks and the zero-row limitation

After Membership eligibility is established, the service checks for an existing active placement using a lock-aware repository query:

```text
ACTIVE placement for Membership
  → FOR UPDATE if a matching row exists
```

A PostgreSQL row lock can lock an existing row, but it cannot lock the **absence** of a row.

Therefore this sequence:

```text
SELECT ACTIVE placement
...
FOR UPDATE
```

does not create a lock representing:

```text
there is currently no ACTIVE placement
```

This is the zero-row race that matters for same-Membership/different-Room Check-In.

## 9.6 Same Membership / different Room safety

Consider two transactions:

```text
Transaction A
Membership M
→ Room A

Transaction B
Membership M
→ Room B
```

They acquire different Room locks:

```text
A → Room A FOR UPDATE
B → Room B FOR UPDATE
```

and both may acquire compatible:

```text
Membership M FOR SHARE
```

If there is initially no active placement, both transactions can also observe no existing ACTIVE Membership placement because no row exists to lock.

The final correctness guard is therefore the PostgreSQL partial unique index:

```text
uq_resident_placements_active_membership

UNIQUE (tenant_id, membership_id)
WHERE status = 'ACTIVE'
```

Only one transaction can commit an ACTIVE placement for that Membership in the Tenant.

The losing transaction receives the recognized unique conflict at persistence time. The Check-In service translates that conflict into the Dormitory active-placement domain error rather than exposing the raw database exception. The detailed PostgreSQL conflict-recognition strategy is documented in Section 11.

Application checking and database uniqueness are therefore complementary:

```text
transactional ACTIVE lookup
        +
partial unique index
        =
same-Membership correctness
```

## 9.7 Same Bed / different Membership safety

A Bed belongs to one Room. Two Check-In operations competing for the same Bed therefore also compete for the same Room lock.

Conceptually:

```text
Membership A ─┐
              ├── Room R / Bed X
Membership B ─┘
```

Serialization proceeds as:

```text
Transaction A
→ Room R FOR UPDATE
→ Bed X FOR UPDATE
→ no ACTIVE Bed placement
→ activate placement
→ COMMIT

Transaction B
→ waits at Room R FOR UPDATE
→ continues after A commits
→ Bed X FOR UPDATE
→ re-check ACTIVE Bed placement
→ winner is now visible
→ bedUnavailable()
```

The second transaction does not continue based on a stale pre-wait resource snapshot. It revalidates the Bed and its active placement state inside its own transaction after obtaining the serialization boundary.

The database partial unique Bed index remains a final invariant, but the normal current same-Bed Check-In race is serialized earlier by the Room boundary.

## 9.8 Same Locker / different Membership safety

Locker concurrency follows the same structure:

```text
Membership A ─┐
              ├── Room R / Locker X
Membership B ─┘
```

Both operations must first pass:

```text
Room R FOR UPDATE
```

The losing transaction waits at the Room boundary. After the winning transaction commits, the loser revalidates the Locker and detects the active placement reference, producing the Dormitory locker-unavailable error.

Again, the partial unique Locker index is a final database invariant rather than a substitute for operation-time revalidation.

## 9.9 BED_AND_LOCKER combined-resource safety

The current Room-first lock ordering also protects a Check-In that requires both resource types.

A crossed-resource scenario can be represented as:

```text
Room R
capacity_basis = BED_AND_LOCKER

Transaction A
→ Bed A
→ Locker B

Transaction B
→ Bed B
→ Locker A
```

Without a higher serialization boundary, an unsafe design could form:

```text
A holds Bed A
→ waits for Locker B

B holds Bed B
→ waits for Locker A
```

The current Check-In flow does not allow both transactions to reach that state concurrently because both must first acquire:

```text
Room R FOR UPDATE
```

Only one transaction progresses into Bed and Locker locking/revalidation at a time for the Room. The second transaction remains blocked at the Room boundary until the first completes.

This is the current deadlock-avoidance property for the tested single-Room combined-resource scenario.

It does not prove that every future multi-resource or multi-Room workflow is automatically deadlock-safe.

## 9.10 Database uniqueness remains the final invariant layer

The current persistence layer also protects ACTIVE placement exclusivity through:

```text
uq_resident_placements_active_membership
uq_resident_placements_active_bed
uq_resident_placements_active_locker
```

Their responsibilities are:

```text
Membership index
→ at most one ACTIVE placement
  per Membership / Tenant

Bed index
→ at most one ACTIVE placement
  per Bed / Tenant

Locker index
→ at most one ACTIVE placement
  per Locker / Tenant
```

These constraints remain necessary even though the Check-In service performs explicit lock-aware revalidation.

The layers serve different purposes:

```text
row locks
→ serialize relevant mutable state

transactional revalidation
→ convert current state into domain decisions

database constraints
→ final protection if competing transactions
  still reach persistence
```

Correctness must not depend solely on an earlier application check.

## 9.11 Current concurrency proof and boundary

Current executable regression coverage includes dedicated proofs for:

```text
ParentHierarchyLockConcurrencyTest
MembershipSharedLockConcurrencyTest
SameMembershipDifferentRoomConcurrencyTest
SameBedDifferentMembershipConcurrencyTest
SameLockerDifferentMembershipConcurrencyTest
SameBedAndLockerDifferentMembershipConcurrencyTest
```

The locked Check-In concurrency milestone establishes the current contract only for the implemented single-Room Check-In workflow.

It must **not** be generalized automatically to future workflows such as:

```text
Check-Out
Transfer
Room reassignment
bulk resident movement
multi-Room allocation
```

Transfer is especially important. A future opposing workflow could require locks such as:

```text
Transaction A
Room 1 → Room 2

Transaction B
Room 2 → Room 1
```

If each transaction acquires its source Room first, the workflow could create an opposing multi-Room lock order.

A future Transfer/Reassignment implementation must therefore define a deterministic multi-Room ordering, apply transactional revalidation appropriate to that workflow, and receive its own concurrency regression audit before being considered safe.

The current Check-In contract must not be cited as proof of Transfer deadlock safety.

---

# 10. Database Invariants

**Documentation slice status**: aligned to current implementation.

PostgreSQL is part of the Dormitory correctness boundary. The application layer performs context resolution, policy evaluation, transactional revalidation, and domain-error translation, while the database independently protects persisted ownership, lifecycle shape, and ACTIVE-placement exclusivity.

The database invariants documented here are limited to constraints that exist in the current migrations. Application rules that require mutable cross-table state are intentionally not described as database guarantees.

## 10.1 Tenant-qualified facility ownership

Every persisted Dormitory facility entity carries `tenant_id`. Parent-child ownership is enforced with tenant-qualified foreign keys rather than relying only on tenant-scoped Eloquent queries.

Current hierarchy constraints are:

```text
Dormitory
(organization_id, tenant_id)
  → organizations (id, tenant_id)

Dormitory
(organization_unit_id, organization_id, tenant_id)
  → organization_units (id, organization_id, tenant_id)
  when organization_unit_id is non-null

Building
(dormitory_id, tenant_id)
  → dormitories (id, tenant_id)

Room
(building_id, tenant_id)
  → buildings (id, tenant_id)

Bed
(room_id, tenant_id)
  → rooms (id, tenant_id)

Locker
(room_id, tenant_id)
  → rooms (id, tenant_id)
```

These constraints reject a raw cross-Tenant parent identifier even when a caller bypasses normal tenant-scoped model lookup.

The Dormitory root therefore owns Organization/optional OrganizationUnit directly, while Building, Room, Bed, and Locker derive that organizational ownership through the persisted parent chain.

## 10.2 Supporting tenant-qualified identities

PostgreSQL composite foreign keys require matching unique or primary-key targets. The Dormitory migrations therefore create supporting unique identities:

```text
uq_dormitories_id_tenant
  UNIQUE (id, tenant_id)

uq_buildings_id_tenant
  UNIQUE (id, tenant_id)

uq_rooms_id_tenant
  UNIQUE (id, tenant_id)

uq_beds_id_tenant
  UNIQUE (id, tenant_id)

uq_lockers_id_tenant
  UNIQUE (id, tenant_id)
```

The ResidentPlacement migration adds stricter resource identities for nested Room ownership:

```text
uq_beds_id_room_tenant
  UNIQUE (id, room_id, tenant_id)

uq_lockers_id_room_tenant
  UNIQUE (id, room_id, tenant_id)
```

These supporting unique constraints are structural FK targets. They do not mean a Bed or Locker may belong to multiple Rooms; the row still stores one canonical `room_id`.

## 10.3 ResidentPlacement ownership and nested resource integrity

`resident_placements` is constrained to the same Tenant across its canonical identity/location references:

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

The Bed/Locker foreign keys use nullable resource ids. Under PostgreSQL `MATCH SIMPLE` semantics, a null `bed_id` or `locker_id` does not require a matching resource row, while any non-null resource id must belong to the exact Room and Tenant stored on the placement.

The database therefore rejects:

```text
Membership from another Tenant
Room from another Tenant
Bed from another Room or Tenant
Locker from another Room or Tenant
```

without requiring the application layer to reconstruct higher-level ownership manually.

## 10.4 Persisted value domains

The current Room schema restricts `capacity_basis` to:

```text
BED
LOCKER
BED_AND_LOCKER
```

ResidentPlacement uses explicit PostgreSQL CHECK constraints for its string-backed domain values:

```text
chk_resident_placements_category
  resident_category IN (
    'REGULAR_RESIDENT',
    'SUPERVISOR_RESIDENT'
  )

chk_resident_placements_status
  status IN (
    'PLANNED',
    'ACTIVE',
    'ENDED',
    'CANCELLED'
  )
```

These database constraints complement PHP enum casts; direct SQL or raw inserts cannot persist values outside the supported sets.

## 10.5 Lifecycle timestamp shape

`planned_at` is a required column for every ResidentPlacement row. The lifecycle CHECK constraint `chk_resident_placements_lifecycle` additionally enforces the nullable/non-null timestamp shape associated with each status:

| Status | `planned_at` | `checked_in_at` | `ended_at` | `cancelled_at` |
| --- | --- | --- | --- | --- |
| `PLANNED` | required | `NULL` | `NULL` | `NULL` |
| `ACTIVE` | required | required | `NULL` | `NULL` |
| `ENDED` | required | required | required | `NULL` |
| `CANCELLED` | required | `NULL` | `NULL` | required |

The database does **not** currently require `end_reason` or `cancellation_reason`, and it does not enforce chronological comparisons such as:

```text
checked_in_at >= planned_at
ended_at >= checked_in_at
cancelled_at >= planned_at
```

Those stronger semantics must not be claimed unless a future migration or application contract introduces them explicitly.

## 10.6 ACTIVE placement exclusivity

Historical placement rows are preserved, while partial PostgreSQL unique indexes enforce exclusivity only for `ACTIVE` state:

```text
uq_resident_placements_active_membership
  UNIQUE (tenant_id, membership_id)
  WHERE status = 'ACTIVE'

uq_resident_placements_active_bed
  UNIQUE (tenant_id, bed_id)
  WHERE status = 'ACTIVE'
    AND bed_id IS NOT NULL

uq_resident_placements_active_locker
  UNIQUE (tenant_id, locker_id)
  WHERE status = 'ACTIVE'
    AND locker_id IS NOT NULL
```

The resulting database guarantees are:

```text
one Membership
→ at most one ACTIVE placement per Tenant

one Bed
→ at most one ACTIVE placement per Tenant

one Locker
→ at most one ACTIVE placement per Tenant
```

The indexes intentionally do not prevent multiple historical or non-active rows. `PLANNED`, `ENDED`, and `CANCELLED` rows are outside these partial uniqueness predicates.

This distinction is essential for history preservation and for the concurrency behavior documented in Section 9.

## 10.7 Restrictive hard-delete boundaries

Dormitory ownership and hierarchy foreign keys use restrictive delete behavior. Current database relationships prevent a parent row from being hard-deleted while dependent persisted children still reference it.

Current regression coverage proves restrictive behavior for at least:

```text
Organization
  → Dormitory

Dormitory
  → Building

Building
  → Room

Room
  → Bed

Room
  → Locker
```

ResidentPlacement foreign keys similarly use restrictive deletion for Tenant, Membership, Room, Bed, and Locker references.

Facility models normally use soft deletion, but soft deletion does not remove the underlying row or bypass these referential-integrity relationships. A forced/hard delete remains subject to PostgreSQL foreign-key constraints.

## 10.8 Database indexes that support operational queries

In addition to correctness constraints, current migrations define indexes supporting tenant/status and hierarchy/resource lookup patterns, including:

```text
Dormitory
→ (tenant_id, organization_id)
→ (tenant_id, is_active)

Building
→ (dormitory_id, tenant_id)
→ (tenant_id, is_active)

Room
→ (building_id, tenant_id)
→ (tenant_id, is_active)

Bed / Locker
→ (room_id, tenant_id)
→ (tenant_id, is_active, is_usable)

ResidentPlacement
→ (tenant_id, status)
→ (membership_id, status)
→ (room_id, status)
```

These ordinary indexes support expected access paths but are not themselves business correctness guarantees. The correctness boundary comes from primary keys, unique constraints/indexes, foreign keys, NOT NULL declarations, and CHECK constraints.

## 10.9 Rules intentionally not enforced by the database

The current schema does **not** encode every Dormitory business rule. In particular, PostgreSQL does not currently enforce:

```text
Room effective capacity calculation
Room over-capacity reconciliation
Bed/Locker requirement by Room.capacity_basis
Bed.is_active = true for an ACTIVE placement
Bed.is_usable = true for an ACTIVE placement
Locker.is_active = true for an ACTIVE placement
Locker.is_usable = true for an ACTIVE placement
Dormitory/Building/Room is_active = true for Check-In
Membership status = ACTIVE for Check-In
end_reason required for ENDED
cancellation_reason required for CANCELLED
lifecycle timestamp chronology
```

Those rules depend on mutable cross-table/application state or have not been defined as database invariants. The Check-In application service currently enforces the relevant operational subset transactionally before activation.

The schema also does not impose ACTIVE resource presence from `Room.capacity_basis`. `resident_placements.bed_id` and `locker_id` remain nullable at database level; `PlacementResourceRequirements` evaluates that policy from the Room's current basis at operation time.

## 10.10 Database invariant test evidence

Current executable persistence coverage includes:

```text
DormitoryPersistenceTest
FacilityHierarchyPersistenceTest
CapacityResourcePersistenceTest
ResidentPlacementPersistenceTest
```

Those suites prove representative behavior including:

- tenant-qualified Organization/OrganizationUnit ownership;
- tenant-qualified Building/Room/Bed/Locker hierarchy rejection;
- nested Bed/Locker Room ownership rejection;
- restrictive parent hard deletes;
- allowed historical placement rows;
- rejection of second ACTIVE Membership placement;
- rejection of double ACTIVE Bed/Locker allocation;
- rejection of invalid resident category/status values;
- rejection of invalid lifecycle timestamp shape;
- valid CANCELLED historical placement representation.

Test pass counts are closure evidence, not permanent database invariants. The migration definitions remain the canonical source for the exact persisted constraints.

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
