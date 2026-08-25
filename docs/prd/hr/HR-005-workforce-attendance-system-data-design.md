# HR-005 — Workforce Attendance System/Data Design

**Version:** 1.0  
**Status:** Approved — Locked  
**Phase:** 2D — System Architecture & Data Design  
**Primary Module:** `Modules/Attendance` (new business module)  
**Baseline Repository:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`  
**Depends On:** HR-001 (Approved), ADR-032 (Accepted), HR-002 (Approved), HR-003 (Approved), HR-004 (Approved)

---

# 1. Executive Summary

Dokumen ini mendesain **Workforce Attendance** untuk EduCore dengan prinsip bahwa Attendance adalah bounded context terpisah dari HR. HR tetap menjadi source of truth untuk employment, placement, leave/permit, dan workforce lifecycle; Attendance menjadi source of truth untuk **expected attendance session, raw attendance evidence, reconciliation result, lateness/absence metrics, dan attendance correction history**.

Canonical flow:

```text
HR Employment ACTIVE
        +
HR EmploymentPlacement (optional session scope)
        ↓
Attendance Expectation
(expected work session)
        ↓
Raw Attendance Event(s)
(manual/import initially)
        +
Approved HR Leave/Permit overlap
        ↓
Attendance Reconciliation
        ↓
Workforce Attendance Record
        ├── presence state
        ├── late minutes
        ├── early-departure minutes
        ├── worked minutes
        └── excused minutes
        ↓
HR reporting / Finance payroll input / future Academic integration
```

Phase 2D **tidak** membangun fingerprint, QR, GPS/geofencing, student attendance, payroll calculation, atau Academic schedule. Capability awal adalah canonical workforce attendance + manual/import fallback sebagaimana sudah dikunci pada HR-001.

---

# 2. Project Resource Audit

## 2.1 Resources reviewed

Resource yang diverifikasi ulang:

- `Modules/Core/*`
- `Modules/HR/*`
- `Modules/Academic/*`
- `Modules/Dormitory/*`
- seluruh `module.yaml`
- `docs/architecture/folder-structure.md`
- `docs/architecture/architecture-principles.md`
- `docs/architecture/adr/ADR-013-canonical-human-identity.md`
- `docs/architecture/adr/ADR-027-capability-aware-navigation_authorization-ux.md`
- `docs/prd/FE-002-application-shell_navigation-ux-requirements.md`
- `Modules/Academic/Services/ReportCardAggregationService.php`
- `Modules/Academic/Database/Migrations/2026_07_19_000001_create_academic_report_cards_table.php`
- HR-001, ADR-032, HR-002, HR-003, HR-004.

## 2.2 Existing facts

**[FAKTA]** Repository belum memiliki `Modules/Attendance`.

**[FAKTA]** Architecture existing secara eksplisit memperlakukan Attendance sebagai business module downstream dan melarang Core bergantung pada Attendance.

**[FAKTA]** Frontend requirement menampilkan `Attendance` di grouping HR, tetapi dokumen frontend juga menegaskan grouping navigasi tidak menentukan business ownership. Karena itu grouping HR tidak bertentangan dengan Attendance sebagai module terpisah.

**[FAKTA]** HR-001 sudah mengunci:

- `BR-012`: Attendance adalah integrated workforce fact dan HR tidak mengambil alih ownership Attendance domain;
- `BR-013`: teaching assignment/schedule tetap Academic concern;
- `BR-015`: payroll input harus traceable ke employment/attendance/benefit/approved adjustment;
- `FR-014`: HR harus dapat mengonsumsi rekap attendance pegawai;
- `FR-015`: manual/import attendance adalah initial operational fallback;
- `FR-016`: teaching attendance direkonsiliasi dengan Academic + Attendance tanpa duplicate Academic schedule di HR;
- `FR-017`: lateness, absence, dan attendance exception harus dapat dipakai untuk reporting/payroll input;
- `NFR-012`: integration failure Attendance/Academic/Finance tidak boleh merusak canonical HR employment data.

**[FAKTA]** HR-002 menetapkan `Employment` sebagai canonical employment episode dan `EmploymentPlacement` sebagai HR historical reference ke Core `OrganizationalAssignment`.

**[FAKTA]** HR-004 menetapkan Leave/Permit sebagai HR-owned domain dan approved leave/permit dapat menjadi input Attendance; HR tidak menulis Attendance records.

**[FAKTA]** Academic saat ini mempunyai `academic_report_cards.attendance_sick`, `attendance_permission`, dan `attendance_absent`. `ReportCardAggregationService` menerima `attendanceData` dari caller dan menyimpan angka tersebut ke rapor.

**[REKOMENDASI]** Field attendance pada report card dipertahankan sebagai **report snapshot/projection**, bukan canonical Attendance source. Future Academic aggregation dapat mengambil summary dari Attendance sebelum report card di-lock/publish.

**[FAKTA]** Academic repository saat ini belum mempunyai canonical teaching schedule/teaching assignment/substitute-teacher model.

**[FAKTA]** Core `Tenant` belum mempunyai typed timezone field; application default timezone adalah UTC dan `Tenant.settings` bersifat flexible JSON.

**[FAKTA]** Repository terbaru menggunakan PostgreSQL-specific partial unique indexes/check constraints pada beberapa module.

**[CONFLICT]** `.env.example` masih default SQLite. Seperti HR-002/HR-004, desain Phase 2D mengikuti PostgreSQL semantics yang sudah nyata pada current implementation.

---

# 3. Scope

## 3.1 IN SCOPE — Phase 2D

1. Bounded context/module Attendance terpisah dari HR.
2. Workforce attendance expectation/session.
3. Manual attendance event entry.
4. Attendance event import dengan idempotency dan row-level result.
5. Raw attendance evidence yang immutable secara business semantics.
6. Reconciliation expectation + attendance events + approved leave/permit.
7. Presence/absence state.
8. Lateness, early-departure, worked-minutes, dan excused-minutes metrics.
9. Attendance site/location catalog untuk multi-location operational context.
10. Historical link ke HR Employment dan EmploymentPlacement.
11. Authorized correction dengan revision history.
12. Self-service attendance read.
13. Scoped workforce attendance read/report.
14. API, authorization, audit, error, concurrency, migration, dan test contract.
15. Integration boundary ke HR Leave, future Academic, dan future Finance.

## 3.2 OUT OF SCOPE

- Student attendance source of truth.
- Dormitory resident attendance.
- Fingerprint vendor integration.
- QR attendance token/generator.
- GPS/geofence verification.
- Facial recognition/biometric processing.
- Academic timetable ownership.
- Teacher substitution workflow.
- Generic shift/rostering engine.
- Payroll calculation.
- Leave entitlement/approval logic.
- Generic workflow engine.
- Organization topology ownership.
- User authentication ownership.

## 3.3 FUTURE SCOPE

- Device adapters (fingerprint/card reader).
- QR check-in/out.
- GPS/geofence evidence.
- Student attendance subdomain.
- Academic teaching-session attendance integration.
- Automated recurring expectation generation from workforce schedule/shift policy.
- Real-time attendance device ingestion.
- Notification/reminder.
- Automatic scheduled reconciliation.

## 3.4 DEFERRED

- Exact regulatory retention for attendance evidence.
- Exact attendance cutoff/grace policy defaults.
- Organization/tenant default timezone model at Core level.
- Attendance correction approval workflow.
- Complex overnight/multi-day shift policy.

---

# 4. Proposed Design Decisions

| ID | Decision | Status |
|---|---|---|
| ATT-001 | Attendance dibangun sebagai `Modules/Attendance`, bukan subfolder canonical milik HR. | Proposed |
| ATT-002 | Phase 2D hanya workforce attendance; student attendance dapat menjadi subdomain Attendance kemudian. | Proposed |
| ATT-003 | Workforce attendance selalu direkonsiliasi terhadap `Employment`; optional scope historis menggunakan `EmploymentPlacement`. | Proposed |
| ATT-004 | Raw attendance event adalah immutable evidence; perubahan hasil attendance dilakukan melalui reconciliation/correction revision, bukan overwrite raw event. | Proposed |
| ATT-005 | Lateness/absence hanya boleh ditentukan jika ada concrete Attendance Expectation. Event tanpa expectation tidak boleh otomatis dianggap terlambat/alpa. | Proposed |
| ATT-006 | Phase awal memakai concrete expectation instances; generic recurring shift/calendar engine belum dibuat. | Proposed |
| ATT-007 | Timestamp canonical disimpan UTC; expectation/site menyimpan IANA timezone snapshot untuk interpretasi tanggal/jam lokal. | Proposed |
| ATT-008 | Attendance mengonsumsi approved Leave/Permit melalui HR contract. HR tidak menulis canonical Attendance record. | Proposed |
| ATT-009 | Attendance tidak bergantung pada Academic. Future Academic boleh mengonsumsi Attendance atau mengirim source context ke Attendance sehingga dependency graph tetap acyclic. | Proposed |
| ATT-010 | Manual/import adalah ingestion mode awal. Fingerprint/QR/GPS hanya adapter future dan tidak mengubah canonical event/record model. | Proposed |
| ATT-011 | `academic_report_cards.attendance_*` tetap sebagai snapshot/projection, bukan source of truth. | Proposed |
| ATT-012 | Historical data scope menggunakan `employment_placement_id` snapshot; record tanpa placement hanya dapat diakses tenant-wide kecuali self-service owner. | Proposed |
| ATT-013 | Authorized correction menghasilkan append-only Attendance Record Revision dan wajib menyimpan reason + actor. | Proposed |
| ATT-014 | Attendance summary yang dipakai payroll/HR harus traceable ke finalized attendance record dan source evidence. | Proposed |

---

# 5. Module Boundary & Dependency

## 5.1 Proposed module

```text
Modules/Attendance
```

Proposed `module.yaml` dependency direction:

```text
Attendance → Core
Attendance → HR
Attendance → Auth
```

Attendance **tidak** bergantung pada Academic.

Future dependency yang aman:

```text
Academic → Attendance
Finance  → Attendance
```

karena:

```text
Attendance → HR
Academic   → HR
```

masih acyclic.

## 5.2 Ownership matrix

| Concern | Owner |
|---|---|
| Person / Membership | Core |
| Organization / Unit | Core |
| Organizational authorization | Core |
| Employee / Employment | HR |
| EmploymentPlacement | HR |
| Leave/Permit | HR |
| Attendance expectation | Attendance |
| Attendance event/evidence | Attendance |
| Attendance reconciliation result | Attendance |
| Attendance correction history | Attendance |
| Teaching assignment/schedule | Academic |
| Academic report card attendance snapshot | Academic projection |
| Payroll calculation/payment | Finance |

---

# 6. Aggregate Model

```text
AttendanceSite
    │
    └── WorkforceAttendanceExpectation
            │
            ├── Employment (HR)
            ├── EmploymentPlacement? (HR)
            │
            ├── WorkforceAttendanceEvent(s)
            │
            └── WorkforceAttendanceRecord
                    │
                    ├── Leave/Permit Link(s)
                    └── AttendanceRecordRevision(s)

AttendanceImportBatch
    └── AttendanceImportRow(s)
            └── Event or Expectation
```

Interpretation:

- **Expectation** = apa yang seharusnya terjadi.
- **Event** = evidence bahwa sesuatu benar-benar dicatat/terjadi.
- **Record** = hasil rekonsiliasi business logic.
- **Revision** = perubahan authorized terhadap hasil, bukan perubahan raw evidence.

---

# 7. Data Dictionary & Schema

> Nama tabel masih logical specification. Migration implementation tetap harus mengikuti repository naming convention dan PostgreSQL constraint pattern existing.

## 7.1 `attendance_sites`

Purpose: physical/operational attendance location. Site bukan Organization dan tidak menggantikan Core organization topology.

| Column | Type | Rule |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK Tenant |
| `code` | varchar(50) | required, tenant-unique |
| `name` | varchar(150) | required |
| `organization_id` | UUID nullable | contextual Core Organization |
| `organization_unit_id` | UUID nullable | contextual Core Unit |
| `timezone` | varchar(64) | required IANA timezone |
| `status` | varchar(20) | `ACTIVE/INACTIVE` |
| timestamps | | |

Rules:

1. Jika `organization_unit_id` ada, unit harus berada pada `organization_id` dan tenant yang sama.
2. Site dapat tenant-level tanpa organization/unit.
3. Site bukan geofence. GPS geometry disimpan di future adapter table.

## 7.2 `workforce_attendance_expectations`

Purpose: concrete expected attendance session.

| Column | Type | Rule |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | required |
| `employment_id` | UUID | required HR Employment |
| `employment_placement_id` | UUID nullable | HR historical scope |
| `attendance_site_id` | UUID nullable | operational site |
| `attendance_date` | date | local business date snapshot |
| `expected_start_at` | timestamptz | UTC instant |
| `expected_end_at` | timestamptz | UTC instant |
| `timezone` | varchar(64) | IANA timezone snapshot |
| `late_grace_minutes` | integer | >= 0 |
| `early_departure_grace_minutes` | integer | >= 0 |
| `source_type` | varchar(30) | `MANUAL/IMPORT/POLICY/ACADEMIC` |
| `source_reference` | varchar(150) nullable | upstream idempotent reference |
| `status` | varchar(20) | `SCHEDULED/CANCELLED` |
| timestamps | | |

Rules:

- `expected_end_at > expected_start_at`.
- Employment harus valid terhadap tenant.
- Jika placement ada, placement harus milik Employment yang sama.
- `attendance_date` adalah date pada `timezone` snapshot, bukan date UTC.
- Source `POLICY/ACADEMIC` disiapkan untuk future generator/integration; Phase awal hanya `MANUAL/IMPORT` yang aktif secara operasional.

## 7.3 `workforce_attendance_events`

Purpose: immutable raw attendance evidence.

| Column | Type | Rule |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | required |
| `employment_id` | UUID | required |
| `employment_placement_id` | UUID nullable | scope snapshot when known |
| `expectation_id` | UUID nullable | concrete expectation when deterministically resolved |
| `attendance_site_id` | UUID nullable | event site |
| `event_type` | varchar(20) | `IN/OUT` |
| `occurred_at` | timestamptz | canonical UTC instant |
| `source_type` | varchar(30) | Phase initial: `MANUAL/IMPORT` |
| `source_event_key` | varchar(191) nullable | upstream/idempotency key |
| `recorded_by_membership_id` | UUID nullable | operator for manual entry |
| `import_batch_id` | UUID nullable | source import |
| `created_at` | timestamptz | evidence ingestion time |

No `updated_at` is required for business mutation semantics; event business facts are immutable after acceptance.

Rules:

1. Employment must match tenant.
2. Placement, expectation, dan site—jika ada—harus tenant-consistent.
3. Event `occurred_at` tidak boleh diedit setelah accepted.
4. Kesalahan event diselesaikan melalui correction/revision atau compensating evidence, bukan overwrite.
5. Duplicate import/device event dicegah melalui `source_event_key`.

## 7.4 `workforce_attendance_records`

Purpose: current derived/finalized attendance result per expectation.

| Column | Type | Rule |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | required |
| `expectation_id` | UUID | required, unique |
| `employment_id` | UUID | required |
| `employment_placement_id` | UUID nullable | copied from expectation as historical scope snapshot |
| `record_status` | varchar(20) | `OPEN/FINALIZED` |
| `attendance_state` | varchar(30) nullable | `PRESENT/ABSENT/EXCUSED/INCOMPLETE` |
| `first_in_at` | timestamptz nullable | derived |
| `last_out_at` | timestamptz nullable | derived |
| `worked_minutes` | integer | >= 0 |
| `late_minutes` | integer | >= 0 |
| `early_departure_minutes` | integer | >= 0 |
| `excused_minutes` | integer | >= 0 |
| `revision_no` | integer | >= 1 |
| `calculated_at` | timestamptz | last reconciliation/correction |
| `finalized_at` | timestamptz nullable | finalization time |
| timestamps | | |

Interpretation:

- `PRESENT` dapat mempunyai `late_minutes > 0`.
- Lateness bukan mutually exclusive presence state.
- `EXCUSED` digunakan jika approved Leave/Permit menutup seluruh expected work window.
- Partial Leave/Permit menambah `excused_minutes` dan mengurangi unexcused expected time; record dapat tetap `PRESENT` atau `INCOMPLETE`.
- `INCOMPLETE` digunakan untuk evidence yang tidak cukup/ambiguous, misalnya IN tanpa OUT sesuai policy.

## 7.5 `workforce_attendance_record_leave_links`

Purpose: trace approved Leave/Permit yang berkontribusi pada reconciliation.

| Column | Type | Rule |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | required |
| `attendance_record_id` | UUID | required |
| `leave_request_id` | UUID | HR Leave Request |
| `overlap_minutes` | integer | > 0 |
| timestamps | | |

Rules:

- Satu record dapat mereferensikan lebih dari satu approved leave/permit apabila time windows memang overlap.
- Link harus dapat direbuild oleh reconciliation dari HR source.
- Tidak boleh menjadi source of truth status Leave; HR tetap authority.

## 7.6 `workforce_attendance_record_revisions`

Purpose: append-only correction/revision snapshot.

| Column | Type | Rule |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | required |
| `attendance_record_id` | UUID | required |
| `revision_no` | integer | required |
| `attendance_state` | varchar(30) | resulting snapshot |
| `first_in_at` | timestamptz nullable | resulting snapshot |
| `last_out_at` | timestamptz nullable | resulting snapshot |
| `worked_minutes` | integer | >= 0 |
| `late_minutes` | integer | >= 0 |
| `early_departure_minutes` | integer | >= 0 |
| `excused_minutes` | integer | >= 0 |
| `reason` | varchar(500) | required |
| `changed_by_membership_id` | UUID | required actor |
| `created_at` | timestamptz | required |

Rules:

- `(attendance_record_id, revision_no)` unique.
- Correction tidak menghapus revision lama.
- Core Audit merekam action metadata; domain revision menyimpan attendance correction evidence yang diperlukan.

## 7.7 `attendance_import_batches`

Purpose: idempotent import execution and operational trace.

| Column | Type | Rule |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | required |
| `import_type` | varchar(30) | `EXPECTATION/EVENT` |
| `source_name` | varchar(100) | required |
| `idempotency_key` | varchar(191) | required tenant-unique |
| `content_hash` | varchar(128) nullable | duplicate/warning evidence |
| `status` | varchar(30) | `PROCESSING/COMPLETED/PARTIAL/FAILED` |
| `total_rows` | integer | >= 0 |
| `accepted_rows` | integer | >= 0 |
| `rejected_rows` | integer | >= 0 |
| `duplicate_rows` | integer | >= 0 |
| `created_by_membership_id` | UUID | required |
| timestamps | | |

## 7.8 `attendance_import_rows`

Purpose: per-row import outcome without retaining unnecessary raw input payload.

| Column | Type | Rule |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | required |
| `batch_id` | UUID | required |
| `row_number` | integer | > 0 |
| `status` | varchar(20) | `ACCEPTED/REJECTED/DUPLICATE` |
| `source_reference` | varchar(191) nullable | source row/event reference |
| `target_type` | varchar(30) nullable | `EXPECTATION/EVENT` |
| `target_id` | UUID nullable | created/resolved entity |
| `error_code` | varchar(80) nullable | safe machine code |
| `error_message` | varchar(500) nullable | sanitized, no unnecessary personal data |
| `created_at` | timestamptz | required |

**[REKOMENDASI]** Jangan menyimpan seluruh raw spreadsheet row di table ini karena memperbesar privacy surface tanpa kebutuhan canonical.

---

# 8. Required Indexes & Database Guards

## 8.1 Sites

- unique `(tenant_id, code)`;
- index `(tenant_id, status)`;
- index `(tenant_id, organization_id, organization_unit_id)`.

## 8.2 Expectations

- unique `(id, tenant_id)` supporting composite FK;
- index `(tenant_id, attendance_date)`;
- index `(tenant_id, employment_id, attendance_date)`;
- index `(tenant_id, employment_placement_id, attendance_date)`;
- partial unique `(tenant_id, source_type, source_reference)` where `source_reference IS NOT NULL`;
- CHECK `expected_end_at > expected_start_at`;
- CHECK grace values >= 0.

**[REKOMENDASI]** Jangan membuat unique `(employment_id, attendance_date)` karena split shift/multiple sessions pada hari yang sama harus tetap memungkinkan.

## 8.3 Events

- index `(tenant_id, employment_id, occurred_at)`;
- index `(tenant_id, expectation_id, occurred_at)`;
- partial unique `(tenant_id, source_type, source_event_key)` where key is not null;
- CHECK event type `IN/OUT`.

## 8.4 Records

- unique `expectation_id`;
- index `(tenant_id, employment_id)`;
- index `(tenant_id, employment_placement_id)`;
- index `(tenant_id, record_status, attendance_state)`;
- CHECK all minute metrics >= 0;
- CHECK `finalized_at IS NOT NULL` when `record_status = FINALIZED`.

## 8.5 Leave links

- unique `(attendance_record_id, leave_request_id)`;
- CHECK `overlap_minutes > 0`.

## 8.6 Revisions

- unique `(attendance_record_id, revision_no)`;
- index `(tenant_id, changed_by_membership_id, created_at)`.

## 8.7 Import

- unique `(tenant_id, idempotency_key)` on batches;
- unique `(batch_id, row_number)` on rows.

---

# 9. Lifecycle & Business Invariants

## INV-ATT-001 — Active/valid Employment context

Attendance expectation untuk workforce harus mereferensikan Employment yang valid untuk relevant date/time. Future-effective PLANNED Employment tidak boleh otomatis menghasilkan attendance record aktif sebelum effective activation policy terpenuhi.

## INV-ATT-002 — Placement is context, not ownership

Attendance tidak menciptakan HR EmploymentPlacement. Ia hanya mereferensikan placement yang sudah dimiliki HR.

## INV-ATT-003 — Raw event immutability

Accepted event tidak diubah untuk mengganti `occurred_at`, `event_type`, atau source identity.

## INV-ATT-004 — No expectation, no lateness/absence conclusion

Event tanpa concrete expectation boleh disimpan sebagai evidence/exception, tetapi sistem tidak boleh menyimpulkan `LATE` atau `ABSENT` tanpa expected session.

## INV-ATT-005 — One canonical record per expectation

Satu expectation mempunyai maksimal satu current attendance record.

## INV-ATT-006 — Present and late can coexist

Lateness disimpan sebagai metric, bukan mutually exclusive state.

## INV-ATT-007 — Approved leave is external authority

Attendance hanya memperhitungkan Leave/Permit yang dinyatakan approved oleh HR source pada reconciliation time.

## INV-ATT-008 — Leave cancellation requires re-reconciliation

Jika HR Leave/Permit berubah setelah record direkonsiliasi, Attendance record harus dapat direkonsiliasi ulang. Attendance tidak mengubah HR Leave.

## INV-ATT-009 — Historical scope preservation

Record menyimpan `employment_placement_id` dari expectation sehingga historical scoped reports tidak bergantung pada current placement employee.

## INV-ATT-010 — Correction does not erase evidence

Manual correction menghasilkan revision baru; raw events dan revision lama tidak dihapus.

## INV-ATT-011 — Import idempotency

Retry import dengan idempotency key sama tidak menghasilkan duplicate batch canonical effects.

## INV-ATT-012 — Device independence

Canonical model tidak mempunyai vendor fingerprint/QR/GPS fields sebagai mandatory attendance identity.

## INV-ATT-013 — Academic ownership preserved

Attendance tidak membuat subject/class/teaching schedule sebagai canonical Academic data.

## INV-ATT-014 — Payroll is downstream

Attendance menghasilkan facts/summary; tidak menghitung salary, deduction, atau payable.

---

# 10. Time Semantics

Semua instant disimpan sebagai UTC `timestamptz`.

Attendance business date tidak boleh dihitung dari application default timezone saja.

Setiap expectation menyimpan:

```text
attendance_date
expected_start_at UTC
expected_end_at UTC
timezone = IANA snapshot
```

Contoh:

```text
attendance_date    = 2026-08-22
expected_start_at  = 2026-08-21T23:00:00Z
expected_end_at    = 2026-08-22T08:00:00Z
timezone           = Asia/Jakarta
```

**[REKOMENDASI]** Site mempunyai timezone default, tetapi expectation menyimpan snapshot agar perubahan timezone/site configuration tidak menulis ulang history.

**[RESOURCE GAP]** Global Tenant timezone belum typed. Phase 2D tidak membutuhkan perubahan Core karena timezone dapat ditentukan pada Attendance Site/Expectation.

---

# 11. Reconciliation Rules

## 11.1 Input

Reconciliation membaca:

1. Attendance Expectation;
2. raw events yang terkait/deterministically match;
3. approved HR Leave/Permit overlap;
4. current Attendance correction policy;
5. persisted existing record/revision.

## 11.2 Base outcome

Conceptual rules:

```text
Expectation exists
│
├─ approved leave covers entire expected window
│      → EXCUSED
│
├─ no qualifying event after finalization cutoff
│      → ABSENT
│
├─ incomplete evidence (e.g. IN only where OUT required)
│      → INCOMPLETE
│
└─ sufficient IN/OUT evidence
       → PRESENT
       + late_minutes
       + early_departure_minutes
       + worked_minutes
```

## 11.3 Lateness

Conceptual:

```text
unexcused_expected_start
+ late_grace_minutes
```

Jika first IN setelah threshold, selisih yang tidak ditutupi approved Leave/Permit menjadi `late_minutes`.

## 11.4 Early departure

Early departure dihitung terhadap unexcused expected end setelah memperhitungkan approved partial Leave/Permit dan configured grace.

## 11.5 Partial Leave/Permit

Approved partial leave tidak otomatis mengubah state menjadi `EXCUSED`.

Contoh:

```text
Expectation: 08:00–16:00
Approved permit: 08:00–10:00
Actual IN: 10:02
```

`late_minutes` harus dihitung terhadap effective unexcused start, bukan 08:00.

Record menyimpan `excused_minutes` dan leave link agar perhitungan dapat diaudit.

## 11.6 Finalization cutoff

**[OPEN DECISION]** Exact waktu kapan missing event berubah menjadi `ABSENT` belum dikunci karena bergantung operational policy (mis. expected_end + X menit/hari).

Foundation harus menyediakan explicit reconciliation/finalization command sehingga tidak hardcode satu global cutoff.

---

# 12. Event-to-Expectation Matching

Phase awal manual/import sebaiknya mengirim `expectation_id` atau source reference yang dapat resolve secara deterministic.

Jika tidak ada expectation id:

1. filter Employment yang sama;
2. cari expectation dengan window/site yang applicable;
3. jika tepat satu match, event dapat diterima dengan expectation resolved sebelum insert;
4. jika nol match, event disimpan sebagai unmatched evidence/exception;
5. jika lebih dari satu candidate, fail/flag ambiguous—jangan memilih secara fuzzy.

**[REKOMENDASI]** Jangan mengandalkan “attendance_date saja” untuk matching karena split shift/multiple sessions pada hari yang sama valid.

---

# 13. Leave/Permit Integration Contract

Attendance membutuhkan narrow HR query contract, bukan direct ad-hoc query ke internal HR tables.

Proposed conceptual contract:

```text
ApprovedLeaveQueryInterface
```

Input minimum:

```text
tenant_id
employment_id
window_start
window_end
```

Output minimum:

```text
leave_request_id
category (LEAVE/PERMIT)
starts_at
ends_at
status = APPROVED
```

Rules:

- Attendance boleh membuat FK/reference ke stable HR Leave Request setelah HR-004 implementation tersedia.
- Status authority tetap HR.
- Cancellation/changes akan tercermin pada next reconciliation.
- HR tidak membutuhkan dependency ke Attendance untuk melakukan Leave lifecycle.

Karena current Core belum mempunyai generic Event Bus, Phase 2D **tidak mengasumsikan** real-time `LeaveApproved` integration event.

Future event-driven propagation dapat ditambahkan setelah platform event infrastructure benar-benar tersedia.

---

# 14. Academic Integration Boundary

## 14.1 Current Academic

Current Academic report card menyimpan:

```text
attendance_sick
attendance_permission
attendance_absent
```

sebagai snapshot pada rapor.

**KEEP.**

Tidak perlu migration breaking.

## 14.2 Future integration

Future Academic dapat:

```text
Attendance summary query
        ↓
ReportCardAggregationService
        ↓
academic_report_cards attendance snapshot
```

Attendance tidak membaca/menguasai Academic report card.

## 14.3 Teaching attendance

Karena canonical TeachingAssignment/Schedule belum ada, Phase 2D tidak mengarang FK ke Academic tables yang belum tersedia.

Future pattern yang direkomendasikan:

```text
Academic Teaching Session
        ↓
Attendance public contract/API
        ↓
Attendance Expectation / source_reference snapshot
```

Sehingga dependency adalah:

```text
Academic → Attendance
```

bukan:

```text
Attendance → Academic
```

Ini mencegah dependency cycle ketika Academic juga mengonsumsi attendance summary.

---

# 15. Service Boundaries

## 15.1 Attendance application services

### `AttendanceSiteService`

Responsibilities:

- create/update/deactivate site;
- validate organization/unit reference;
- timezone validation.

### `WorkforceAttendanceExpectationService`

Responsibilities:

- create/import expectation;
- validate Employment/Placement/date window;
- cancel future expectation;
- preserve history.

### `WorkforceAttendanceEventService`

Responsibilities:

- manual/import event ingestion;
- source idempotency;
- expectation resolution;
- reject tenant/employment mismatch;
- preserve immutable evidence.

### `WorkforceAttendanceReconciliationService`

Responsibilities:

- lock expectation/record as required;
- read raw events;
- read approved leave overlaps via HR contract;
- compute current record;
- upsert record deterministically;
- generate revision when authorized correction/recalculation semantics require it.

### `WorkforceAttendanceCorrectionService`

Responsibilities:

- authorize correction;
- require reason;
- create append-only revision;
- update current projection transactionally;
- audit.

### `AttendanceImportService`

Responsibilities:

- create idempotent batch;
- validate each row independently;
- produce accepted/rejected/duplicate row outcomes;
- avoid retaining unnecessary raw sensitive row payload.

### `WorkforceAttendanceQueryService`

Responsibilities:

- self-service query;
- scoped workforce reporting;
- lateness/absence summary;
- payroll-ready factual projection (no payroll calculation).

## 15.2 Cross-module services consumed

```text
Core Tenant Context
Core Organizational Authorization
Core Audit Trail
HR Employment query/validation
HR EmploymentPlacement validation
HR ApprovedLeaveQuery
```

Attendance must not directly mutate those sources.

---

# 16. Transaction & Concurrency Contract

## 16.1 Event ingestion

Transaction:

```text
validate tenant/employment
→ validate optional placement/site/expectation
→ check source_event_key idempotency
→ insert immutable event
→ commit
```

Database unique key is final duplicate protection.

## 16.2 Reconciliation

Recommended sequence:

```text
lock expectation
→ lock/create attendance record
→ read relevant events
→ query approved leave facts
→ compute metrics deterministically
→ persist leave links
→ persist current record
→ commit
```

If two reconciliation requests run concurrently for the same expectation, record unique constraint + row locking must prevent duplicate canonical records.

## 16.3 Correction

```text
lock current record
→ re-check authorization
→ validate correction reason
→ create revision N+1
→ update current projection
→ audit
→ commit
```

Raw event rows remain unchanged.

## 16.4 Import

Batch is idempotent by `(tenant_id, idempotency_key)`.

Row failures must not roll back all valid rows unless request explicitly uses all-or-nothing mode. Default recommendation is **partial acceptance with row-level result**, because attendance import commonly contains operational data quality issues.

---

# 17. API Specification — Phase 2D

API names are proposed and should follow existing `/api/v1` patterns.

## 17.1 Sites

```text
GET   /api/v1/attendance/sites
POST  /api/v1/attendance/sites
PATCH /api/v1/attendance/sites/{siteId}
POST  /api/v1/attendance/sites/{siteId}/deactivate
```

## 17.2 Expectations

```text
GET  /api/v1/attendance/workforce/expectations
POST /api/v1/attendance/workforce/expectations
POST /api/v1/attendance/workforce/expectations/{id}/cancel
```

Filters:

```text
employment_id
employment_placement_id
attendance_date_from
attendance_date_to
site_id
status
```

## 17.3 Events

```text
GET  /api/v1/attendance/workforce/events
POST /api/v1/attendance/workforce/events/manual
```

Manual event request minimum:

```text
employment_id
expectation_id? 
employment_placement_id?
attendance_site_id?
event_type
occurred_at
idempotency_key
```

## 17.4 Import

```text
POST /api/v1/attendance/workforce/imports
GET  /api/v1/attendance/workforce/imports/{batchId}
GET  /api/v1/attendance/workforce/imports/{batchId}/rows
```

`import_type`:

```text
EXPECTATION
EVENT
```

## 17.5 Reconciliation

```text
POST /api/v1/attendance/workforce/reconcile
POST /api/v1/attendance/workforce/expectations/{id}/reconcile
```

Batch reconcile filters must be scope-authorized and bounded by a server-side max window/row count.

## 17.6 Records

```text
GET /api/v1/attendance/workforce/records
GET /api/v1/attendance/workforce/records/{recordId}
POST /api/v1/attendance/workforce/records/{recordId}/correct
```

## 17.7 Self service

```text
GET /api/v1/attendance/self/workforce/records
GET /api/v1/attendance/self/workforce/summary
```

Self-service actor is resolved from authenticated Membership → Employee → applicable Employment; caller may not supply arbitrary employee id.

## 17.8 Summary/report

```text
GET /api/v1/attendance/workforce/summary
```

Potential dimensions:

```text
date range
organization/unit scope
employment
attendance state
site
late threshold
```

Report API returns factual attendance metrics only; no salary amount.

---

# 18. Authorization Catalog

Proposed permissions:

```text
attendance.workforce.self.read

attendance.workforce.read
attendance.workforce.expectation.manage
attendance.workforce.event.manual
attendance.workforce.import
attendance.workforce.reconcile
attendance.workforce.correct

attendance.site.read
attendance.site.manage
```

Rules:

1. Position/jabatan tidak memberikan permission otomatis.
2. Manual event mutation lebih sensitif daripada read dan harus permission terpisah.
3. Correction adalah high-risk capability dan tidak digabung ke generic `manage`.
4. Import dan reconciliation juga terpisah agar operational delegation dapat granular.

---

# 19. Access & Data-Scoping Rules

## 19.1 Self-service

`attendance.workforce.self.read` hanya dapat membaca record milik Membership/Employee authenticated sendiri.

## 19.2 Tenant-wide actor

Tenant-wide `attendance.workforce.read` dapat membaca workforce attendance seluruh tenant sesuai existing Core capability semantics.

## 19.3 Organization/unit scoped actor

Record/expectation dengan `employment_placement_id` menggunakan placement tersebut untuk historical scope resolution.

**[REKOMENDASI]** Jangan menggunakan **current** Employee placement untuk menentukan hak baca terhadap historical attendance karena pegawai dapat sudah mutasi.

## 19.4 Unplaced workforce

Expectation/record tanpa `employment_placement_id` tidak boleh terlihat ke actor scoped-only. Hanya tenant-wide actor atau self-service owner yang dapat membaca.

## 19.5 Site scope

Site organization/unit reference dapat digunakan sebagai additional query filter, tetapi workforce record authorization harus tetap berakar pada employee placement context—not merely physical site.

---

# 20. Audit Contract

Wajib audit:

- site create/update/deactivate;
- expectation manual create/cancel;
- manual attendance event create;
- import batch start/outcome;
- correction;
- privileged reconciliation batch;
- export/report bila mengandung sensitive workforce detail sesuai platform audit policy.

Audit metadata minimum:

```text
tenant_id
actor membership/user reference where available
action
target id
relevant scope id
outcome
reason code when applicable
```

Jangan menyalin:

- full spreadsheet content;
- unnecessary personal data;
- future GPS coordinates;
- biometric templates;

ke Core Audit metadata.

---

# 21. Error Contract

Suggested stable codes:

```text
ATTENDANCE_EMPLOYMENT_NOT_ACTIVE
ATTENDANCE_PLACEMENT_MISMATCH
ATTENDANCE_SITE_SCOPE_MISMATCH
ATTENDANCE_INVALID_TIME_WINDOW
ATTENDANCE_EXPECTATION_NOT_FOUND
ATTENDANCE_EXPECTATION_AMBIGUOUS
ATTENDANCE_EVENT_DUPLICATE
ATTENDANCE_EVENT_IMMUTABLE
ATTENDANCE_RECORD_NOT_RECONCILABLE
ATTENDANCE_RECORD_ALREADY_FINALIZED
ATTENDANCE_CORRECTION_REASON_REQUIRED
ATTENDANCE_IMPORT_DUPLICATE
ATTENDANCE_IMPORT_ROW_INVALID
ATTENDANCE_SCOPE_FORBIDDEN
ATTENDANCE_LEAVE_SOURCE_UNAVAILABLE
```

Integration failure dengan HR Leave harus fail predictably. Sistem tidak boleh diam-diam mengklasifikasikan employee `ABSENT` jika required leave authority tidak dapat diverifikasi untuk reconciliation yang membutuhkannya.

**[REKOMENDASI]** Jika Leave query unavailable, record tetap OPEN/needs-reconciliation daripada menghasilkan false absence.

---

# 22. Data Privacy & Security

1. Attendance data adalah workforce behavioral data dan harus least-privilege.
2. Raw event access lebih sensitif dibanding aggregate summary.
3. Future biometric template tidak boleh disimpan di canonical attendance tables tanpa separate security/privacy review.
4. GPS/geofence evidence future harus data-minimized dan retention-limited.
5. Import error message tidak boleh echo full NIK/name/contact unnecessarily.
6. Export menghormati scope yang sama dengan screen/API source.
7. Correction reason harus informative tetapi tidak menjadi tempat menyimpan sensitive narrative yang tidak perlu.

---

# 23. Read Model Strategy

## 23.1 Employee attendance view

Recommended projection:

```text
Employee identity (Core Person via HR)
Employment
Placement
Date/session
Expected start/end
Actual first in/last out
Presence state
Late minutes
Early departure minutes
Worked minutes
Excused minutes
Site
Record status
```

## 23.2 HR summary

Attendance API menyediakan summary yang dapat dikonsumsi HR UI tanpa HR menyimpan competing attendance tables.

## 23.3 Payroll input

Future Finance/HR payroll-input process membaca finalized attendance facts:

```text
employment_id
period
worked/absence/late metrics
source record ids
revision numbers
```

sehingga payroll material input dapat ditelusuri kembali.

---

# 24. Academic Report Card Compatibility

Current:

```text
academic_report_cards.attendance_sick
academic_report_cards.attendance_permission
academic_report_cards.attendance_absent
```

Classification:

**KEEP — projection/snapshot.**

Future improvement:

1. Academic requests student attendance summary from Attendance;
2. `ReportCardAggregationService` receives verified summary;
3. Academic stores snapshot at aggregation/lock time;
4. locking report card preserves historical published result even jika Attendance later dikoreksi, kecuali Academic explicitly reopens/reissues according to its own policy.

Phase 2D does not change current Academic implementation because student attendance is out of scope.

---

# 25. Migration & Rollout Strategy

## Step 1 — Lock HR-005 decisions

No code change before architecture approval.

## Step 2 — Repository hygiene

Resolve case-only Git rename/deletion issue and PostgreSQL-vs-SQLite environment conflict before reliable migration verification.

## Step 3 — Add Attendance module skeleton

Add `Modules/Attendance` with dependency:

```text
core
HR
Auth
```

No Core dependency to Attendance.

## Step 4 — Add attendance site + expectation schema

Create tenant-safe constraints and PostgreSQL checks.

## Step 5 — Add event + record + revision schema

Raw event immutability and record uniqueness must be database-supported.

## Step 6 — Add import batch schema

Implement idempotent manual/import operational fallback.

## Step 7 — Add narrow HR Leave query contract

Attendance consumes approved Leave/Permit status through public HR contract.

## Step 8 — Add authorization capabilities

Seed/declare Attendance permissions through existing Core RBAC mechanism.

## Step 9 — Implement manual/import + reconciliation

No device adapter yet.

## Step 10 — Add self/scoped reporting

HR frontend may display Attendance capability through API composition without backend HR ownership.

## Step 11 — Future Academic/Finance integration

Add only after their corresponding source contracts are stable.

---

# 26. Test Contract

## 26.1 Persistence

- tenant-safe FK tests;
- invalid employment/placement mismatch rejected;
- site unit must belong to organization/tenant;
- invalid time windows rejected;
- unique source references/idempotency enforced.

## 26.2 Expectation

- multiple expectations same employee/date allowed;
- split shift supported;
- cancelled expectation excluded from normal absence finalization;
- timezone/date snapshot correct.

## 26.3 Event

- manual event requires permission;
- duplicate source event rejected/idempotently resolved;
- cross-tenant event rejected;
- accepted event cannot be edited.

## 26.4 Reconciliation

- full leave → EXCUSED;
- no events → ABSENT only after explicit finalization rules;
- valid IN/OUT → PRESENT;
- late IN → PRESENT + late minutes;
- missing OUT → INCOMPLETE according policy;
- partial leave adjusts excused/late metrics;
- unavailable Leave authority does not silently create false ABSENT;
- concurrent reconciliation yields one record.

## 26.5 Correction

- raw event unchanged;
- correction requires permission + reason;
- revision N+1 appended;
- old revisions preserved;
- scoped unauthorized correction denied.

## 26.6 Import

- same idempotency key does not duplicate effects;
- partial rows accepted while invalid rows reported;
- error rows do not expose unnecessary personal data;
- same source event key is duplicate, not second attendance event.

## 26.7 Authorization/scope

- self only sees own record;
- organization/unit scoped actor sees only historical placement scope;
- unplaced employee hidden from scoped-only reader;
- tenant-wide reader sees applicable tenant records;
- position name alone does not authorize.

## 26.8 Regression

- existing HR Employee behavior remains unchanged;
- HR-002/HR-003/HR-004 canonical models remain authority;
- Academic report card migration/service behavior unchanged in Phase 2D;
- Core has no Attendance dependency.

---

# 27. Traceability

| Source Requirement | Design Mapping |
|---|---|
| BR-012 | ATT-001, module boundary, Attendance ownership |
| BR-013 | ATT-009, Academic boundary |
| BR-015 | ATT-014, finalized traceable records |
| FR-014 | WorkforceAttendanceQueryService + summary API |
| FR-015 | Event manual API + import batches/rows |
| FR-016 | Academic integration direction without schedule duplication |
| FR-017 | Attendance record metrics + summary/report API |
| FR-023/024 | Future payroll-ready attendance facts with record traceability |
| FR-042 | Attendance reporting projection |
| FR-048/049 | Core scoped authorization; no position-derived permission |
| FR-050 | Attendance audit + revision history |
| NFR-001/002 | Tenant-safe FKs + scope resolution |
| NFR-003/004 | data minimization + audit rules |
| NFR-006 | idempotent import/reconciliation transaction contract |
| NFR-007 | `/api/v1` + existing auth/error conventions |
| NFR-009/015 | report/export scope rules |
| NFR-012 | fail-safe HR Leave integration |

---

# 28. Change Impact Classification

| Existing Component | Decision |
|---|---|
| `Modules/HR` Employee/Employment | **KEEP — CONSUME via contract** |
| HR EmploymentPlacement | **KEEP — REFERENCE** |
| HR Leave/Permit | **KEEP — CONSUME approved facts** |
| Core Person/Membership | **KEEP** |
| Core Organization | **KEEP — contextual reference only** |
| Core RBAC | **KEEP — REUSE** |
| Core Audit | **KEEP — REUSE** |
| Academic report-card attendance columns | **KEEP as projection** |
| Academic report aggregation API | **KEEP now; EXTEND future for Attendance source** |
| New `Modules/Attendance` | **ADD** |
| Fingerprint/QR/GPS adapters | **DEFER** |
| Student attendance | **FUTURE SCOPE** |

---

# 29. Open Decisions / Resource Gaps

## RG-ATT-001 — Recurring work calendar / shift generation

Repository belum memiliki canonical work schedule. Phase 2D menggunakan concrete expectation instances. Recurring shift/calendar generator diputuskan pada fase berikut saat requirement nyata tersedia.

## RG-ATT-002 — Absence finalization cutoff

Exact cutoff belum dikunci. Harus configurable, bukan global hardcode.

## RG-ATT-003 — Correction approval workflow

Foundation mendukung privileged direct correction + immutable revision. Jika yayasan membutuhkan requester→approver correction workflow, desain terpisah diperlukan.

## RG-ATT-004 — Teaching schedule identifiers

Belum tersedia canonical Academic teaching schedule/assignment id.

## RG-ATT-005 — Device adapters

Fingerprint/QR/GPS vendor/protocol belum diverifikasi dan tetap future scope.

## RG-ATT-006 — Attendance retention

Exact legal/operational retention raw events, revisions, dan imports memerlukan compliance decision.

## RG-ATT-007 — Overnight/complex shifts

Concrete UTC windows mendukung overnight secara data model, tetapi detailed business rules untuk cross-day overtime/breaks belum ditetapkan.

## RG-ATT-008 — Automatic reconciliation scheduler

Core scheduler masih future capability; Phase 2D menyediakan explicit command/API semantics dan tidak mengasumsikan scheduler yang belum ada.

---

# 30. Risks

**[RISK] R-ATT-001 — False absence due integration failure.**  
Mitigation: jika Leave authority unavailable, jangan silently finalize false ABSENT.

**[RISK] R-ATT-002 — Attendance becomes HR god-submodule.**  
Mitigation: new standalone Attendance bounded context.

**[RISK] R-ATT-003 — Device vendor coupling.**  
Mitigation: canonical event independent dari vendor/device.

**[RISK] R-ATT-004 — Historical scope leakage after employee transfer.**  
Mitigation: store employment placement snapshot on expectation/record and authorize against historical scope.

**[RISK] R-ATT-005 — Raw event tampering.**  
Mitigation: immutable event semantics + corrections via revisions.

**[RISK] R-ATT-006 — Academic dependency cycle.**  
Mitigation: Attendance does not depend on Academic; future Academic depends on Attendance/public contract.

**[RISK] R-ATT-007 — Ambiguous split-shift matching.**  
Mitigation: expectation id/source reference preferred; ambiguous matching fails closed.

**[RISK] R-ATT-008 — Privacy expansion from GPS/biometrics.**  
Mitigation: defer adapters pending dedicated privacy/security design.

---

# 31. Reviewer Assessment

**Quality Score:** 9.5/10

**Gaps:**

- recurring work schedule generator belum didesain;
- absence cutoff belum dikunci;
- correction approval workflow belum ditetapkan;
- Academic teaching schedule contract belum tersedia;
- device adapter dan retention policy belum tersedia.

Gaps tersebut tidak menghalangi canonical manual/import workforce attendance foundation.

**Risks:**

- false absence ketika Leave integration unavailable;
- historical scope leakage;
- device coupling;
- mutable attendance evidence;
- Academic dependency cycle.

Semua mempunyai explicit mitigation pada desain ini.

**Recommendations:**

1. Approve ATT-001 s.d. ATT-014 sebagai Phase 2D foundation.
2. Jangan membuat device adapter sebelum canonical manual/import path green.
3. Jangan memindahkan attendance tables ke `Modules/HR` hanya karena frontend menampilkannya di HR navigation grouping.
4. Pertahankan Academic attendance report-card fields sebagai snapshot sampai Student Attendance subdomain dibangun.
5. Setelah Phase 2D locked, lanjutkan ke Compensation/Payroll Input boundary, bukan langsung Finance payment execution.

**Status:** `READY FOR APPROVAL`

---

# 32. Recommended Next Phase After Approval

Setelah HR-005 approved dan locked:

```text
FASE 2E — Compensation, Benefit & Payroll Input Design
```

Fase 2E akan menggunakan source yang sudah jelas:

```text
Employment              → HR
Position/Placement       → HR/Core
Approved Leave/Permit    → HR
Finalized Attendance     → Attendance
Compensation/Benefit     → HR
                         ↓
Traceable Payroll Input
                         ↓
Finance Payroll Run / Payment / Accounting (downstream)
```

Dengan urutan tersebut, Finance tidak perlu menginterpretasikan ulang raw attendance atau HR lifecycle.
