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

**Documentation slice status**: pending detailed alignment.

Section ini akan mendokumentasikan current dependency direction dan Core integration contract tanpa menduplikasi rationale ADR-019.

Canonical boundary yang tidak boleh berubah secara implisit:

```text
Dormitory → Core
Core ↛ Dormitory
```

Canonical resident identity tetap berasal dari Core `Membership`. Dormitory, Building, Room, Bed, Locker, dan ResidentPlacement tetap merupakan konsep downstream Dormitory domain.

---

# 4. Facility Hierarchy & Persistence Ownership

**Documentation slice status**: pending detailed alignment.

Section ini akan mendokumentasikan:

- Dormitory root ownership;
- Building → Room → Bed/Locker hierarchy;
- Tenant/Organization/OrganizationUnit constraints;
- inherited descendant ownership;
- tenant-qualified composite foreign-key safeguards.

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
