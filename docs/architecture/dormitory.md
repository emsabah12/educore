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

**Documentation slice status**: pending detailed alignment.

Section ini akan mendokumentasikan:

- `RoomCapacityBasis`;
- `BED`, `LOCKER`, dan `BED_AND_LOCKER` semantics;
- effective-capacity calculation;
- usable-resource semantics;
- non-destructive over-capacity behavior.

---

# 6. Resident Placement Model & Lifecycle

**Documentation slice status**: pending detailed alignment.

Section ini akan mendokumentasikan:

- canonical resident/location references;
- `ResidentCategory`;
- `PlacementStatus`;
- lifecycle timestamps;
- historical-record preservation;
- active-placement uniqueness invariants.

---

# 7. Placement Resource Requirements

**Documentation slice status**: pending detailed alignment.

Section ini akan mendokumentasikan resource requirements berdasarkan current room capacity basis dan bagaimana requirement tersebut direvalidasi pada Check-In.

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
