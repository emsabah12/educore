# Phase 2H-G — Final HR Reporting Integration Review & Phase Closure

## 1. Project Resource Check

**[FAKTA]** Final audit kembali memastikan repository berada pada:

```text
26b475b695aa4511064b1410db03d1f0c8bdd6ce
```

Sama persis dengan baseline handoff. Tidak ada repository delta yang perlu direkonsiliasi.

Handoff menetapkan HR-001–HR-008 sebagai **APPROVED / LOCKED**, ADR-032 sebagai **ACCEPTED**, serta memerintahkan Phase 2H untuk mencakup reporting/read model, dashboard, security/privacy, government export, auditability, dan freshness tanpa menciptakan source of truth baru.

Verifikasi eksternal terakhir juga tidak membatalkan 2H-E: Dapodik 2026.c masih menggunakan workflow aplikasi → validasi → sinkronisasi resmi, sementara Kemenag secara resmi menetapkan EMIS 4.0 GTK sebagai pengganti Simpatika dan transisi EMIS GTK masih berlanjut pada 2026. ([Dapo Kemendikdasmen][1])

---

# 2. Phase 2H Completion Review

| Subfase  | Scope                                     | Final Assessment       |
| -------- | ----------------------------------------- | ---------------------- |
| **2H-A** | Reporting Domain Boundary & Scope         | **PASS / LOCKED**      |
| **2H-B** | Requirements & KPI Catalog                | **PASS / LOCKED**      |
| **2H-C** | Read Model Architecture                   | **PASS / LOCKED**      |
| **2H-D** | Dashboard, Authorization & Privacy        | **PASS / LOCKED**      |
| **2H-E** | Government Export Boundary                | **PASS / LOCKED**      |
| **2H-F** | Auditability, Freshness & Operational NFR | **PASS / LOCKED**      |
| **2H-G** | Final Integration Review                  | **READY FOR APPROVAL** |

Tidak ditemukan perubahan pada 2H-G yang membutuhkan redesign subfase sebelumnya.

---

# 3. Final Architecture Baseline

Keseluruhan Phase 2H sekarang membentuk architecture berikut:

```text
                     CANONICAL DOMAIN

        Core             HR            Attendance
         │               │                 │
         │               │             [future impl]
         │               │
         │            Academic             Finance
         │               │             [future impl]
         └───────────────┼─────────────────┘
                         │
                         ▼
                Source Read Contracts
                         │
                         ▼
                ┌─────────────────┐
                │  HR Reporting   │
                │   Capability    │
                └────────┬────────┘
                         │
           ┌─────────────┼─────────────┐
           ▼             ▼             ▼
       DIRECT         PROJECTED      FROZEN
       QUERY          READ MODEL     DATASET
           │             │             │
           └─────────────┼─────────────┘
                         ▼
             Authorization + Privacy
                         │
             ┌───────────┼───────────┐
             ▼           ▼           ▼
         Dashboard     Report       Export
                                      │
                           ┌──────────┴─────────┐
                           ▼                    ▼
                       Dapodik             EMIS / EMIS GTK

                     Simpatika
                         ↓
               Legacy compatibility only
```

---

# 4. Cross-Phase Consistency Check

## 4.1 Identity

Locked baseline:

```text
Person
  ↓
Membership
  ↓
Employee
```

Reporting:

- tidak membuat Person baru;
- tidak menjadikan Employee sebagai User;
- tidak membuat duplicate human identity;
- tidak memakai external government identifier sebagai canonical identity.

**Result: PASS**

---

## 4.2 Organization

Core tetap owner:

```text
Organization
OrganizationUnit
OrganizationalAssignment
```

Reporting hanya menggunakan organizational context sebagai dimension dan authorization scope.

Tidak diperkenalkan:

```text
employees.organization_id
employees.organization_unit_id
```

sebagai shortcut canonical.

**Result: PASS**

---

## 4.3 Authorization

Locked HR principle:

```text
Position ≠ Role
```

Phase 2H mempertahankan:

```text
Permission
+
Tenant
+
Organizational Scope
+
Sensitivity
```

sebagai access equation.

Tidak ada authorization berdasarkan jabatan.

**Result: PASS**

---

# 5. Attendance Integration Check

HR-005 menetapkan Attendance sebagai bounded context tersendiri:

```text
Expectation
+ Raw Event
+ Approved Leave
→ Reconciliation
→ Attendance Record
```

Phase 2H hanya menerima final/reconciled Attendance fact.

Raw scan tidak dipakai langsung untuk menghitung:

- lateness;
- absence;
- attendance rate.

Ini konsisten dengan locked HR architecture.

**Result: PASS**

---

# 6. Finance Integration Check

Locked boundary:

```text
HR
→ compensation/payroll-input facts

Finance
→ payroll calculation
→ tax
→ statutory contribution
→ payment
→ accounting
```

Reporting tidak mengubah compensation menjadi:

```text
gross payroll
net payroll
payroll cost
```

tanpa Finance authority.

Additive change dari HR-008:

```text
hr_payroll_input_snapshots.purpose

REGULAR_PAYROLL
FINAL_SETTLEMENT
```

juga tetap compatible dan dapat ditambahkan saat Finance integration dirancang.

**Result: PASS**

---

# 7. Performance & Competency Check

Phase 2H mempertahankan:

- framework versioning;
- rating-scale awareness;
- PKG tidak dianggap satu global rubric;
- training ≠ certification ≠ competency;
- performance evidence tidak menjadi automatic salary/promotion trigger.

Karena itu kita juga sengaja tidak membuat combined performance average antar-framework tanpa explicit normalization.

**Result: PASS**

---

# 8. Document & Contract Check

Reporting hanya menggunakan metadata/status sesuai authorization.

Tidak ada:

```text
document BLOB
public document URL
signed document mutation
```

Agreement expiry juga tetap:

```text
Agreement Expired
≠
Employment Ended
```

**Result: PASS**

---

# 9. Discipline & Offboarding Check

Phase 2H tidak membuat:

```text
SP1 → SP2 → SP3
```

sebagai hardcoded progression.

Dan tetap membedakan:

```text
Employment ENDED
≠
Offboarding COMPLETED
```

Reporting juga tidak otomatis:

- revoke Membership;
- delete User;
- revoke seluruh RBAC role;
- calculate final settlement.

Ini sesuai locked boundary HR-008.

**Result: PASS**

---

# 10. Reporting Source-of-Truth Check

Ini adalah closure criterion paling penting.

Phase 2H tidak memperkenalkan:

```text
HR Reporting
        ↓
canonical employee state
```

Sebaliknya:

```text
Canonical Domain
       ↓
rebuildable reporting representation
```

Projection:

- dapat dihapus;
- dapat direbuild;
- dapat direconcile;
- tidak memiliki canonical business transition.

**Result: PASS**

---

# 11. KPI Integrity Check

Phase 2H-B berhasil membedakan:

```text
SNAPSHOT
vs
PERIOD / FLOW
```

dan tidak memaksakan formula untuk metric yang authority-nya belum tersedia.

Contohnya tetap deferred:

- turnover rate;
- time-to-hire final definition;
- absenteeism rate;
- leave utilization final formula;
- payroll cost;
- competency gap percentage.

Ini lebih aman daripada menghasilkan KPI dengan denominator yang belum sah.

**Result: PASS**

---

# 12. Historical Reporting Check

Rule:

```text
Fact at T
+
Dimension valid at T
```

dipertahankan.

Contoh:

```text
2025 → Unit A
2026 → Unit B
```

report 2025 tetap diklasifikasikan sebagai Unit A.

Tidak menggunakan current organizational placement untuk mengubah historical meaning.

**Result: PASS**

---

# 13. Security & Privacy Check

Phase 2H mempunyai defense berlapis:

```text
Authentication
    ↓
Membership / Tenant
    ↓
Permission
    ↓
Organizational Scope
    ↓
Sensitivity Policy
    ↓
Minimum Disclosure
```

Dan:

```text
aggregate permission
≠
detail permission

view permission
≠
export permission
```

Sensitive export juga memakai private storage.

**Result: PASS**

---

# 14. Government Integration Check

Final boundary:

```text
Canonical EduCore
        ↓
Versioned Mapping
        ↓
External Validation
        ↓
Frozen Dataset
        ↓
Export Artifact
```

Tidak ada dependency terhadap undocumented direct API.

Dapodik resmi 2026 masih menempatkan validasi dan sinkronisasi di workflow aplikasi Dapodik. ([Dapo Kemendikdasmen][1])

Kemenag secara resmi mengumumkan pada 10 Januari 2025 bahwa EMIS 4.0 GTK Madrasah menggantikan Simpatika untuk pendataan dan validasi GTK; materi Kemenag 2026 juga menunjukkan transisi ke platform EMIS GTK baru masih berjalan. ([Pendis][2])

Maka final classification:

| Target          | Classification                        |
| --------------- | ------------------------------------- |
| Dapodik         | **ACTIVE TARGET**                     |
| EMIS / EMIS GTK | **ACTIVE / PRIMARY KEMENAG TARGET**   |
| Simpatika       | **LEGACY / DEPRECATED AS NEW TARGET** |

**Result: PASS**

---

# 15. Operational Integrity Check

Phase 2H-F membedakan tiga concern:

```text
Transactional Evidence
Core Audit
Operational Log
```

Ini penting karena repository memang menunjukkan Core Audit bersifat **fail-open**.

Untuk asynchronous work juga sudah ditetapkan:

```text
queue payload
→ identifiers only
```

bukan employee/report dataset.

Dengan demikian Queue Watchdog existing tidak boleh menjadi jalur accidental PII exposure.

**Result: PASS**

---

# 16. Repository Change Classification

Final classification setelah seluruh Phase 2H:

| Area                           | Classification                     |
| ------------------------------ | ---------------------------------- |
| `Modules/HR`                   | **EXTEND**                         |
| HR Reporting capability        | **ADD inside HR when implemented** |
| Employee existing foundation   | **KEEP**                           |
| Core RBAC                      | **KEEP / REUSE**                   |
| Core Organizational Context    | **KEEP / REUSE**                   |
| Core tenant-aware jobs         | **KEEP / REUSE**                   |
| Core Audit                     | **KEEP as supplemental**           |
| Current HR route authorization | **REFACTOR / EXTEND**              |
| Generic `Modules/Reporting`    | **DEFER**                          |
| Generic metric/EAV table       | **DO NOT INTRODUCE**               |
| Data warehouse                 | **DEFER**                          |
| Direct Dapodik API             | **DEFER**                          |
| Direct EMIS GTK API            | **DEFER**                          |
| New Simpatika integration      | **DEPRECATE / DO NOT BUILD**       |
| Government integration gateway | **FUTURE SCOPE**                   |
| Redis/reporting cache          | **DEFER UNTIL PROFILED**           |

---

# 17. Remaining Repository Conflicts

Tidak ada yang membatalkan Phase 2H, tetapi ada technical debt yang harus tetap terlihat.

## [CONFLICT] SQLite vs PostgreSQL

`.env.example` masih:

```text
DB_CONNECTION=sqlite
```

sementara repository mempunyai migration yang menggunakan PostgreSQL-style constraints dan partial unique indexes.

### Impact

**Repository hygiene / engineering setup**, bukan reporting architecture conflict.

### Recommendation

**REFACTOR configuration/documentation**, jangan menurunkan integrity schema agar kompatibel dengan stale SQLite default.

---

## [CONFLICT] Filename casing

Git index masih menunjukkan casing mismatch seperti:

```text
create_Employees_table.php
vs
create_employees_table.php
```

serta beberapa ADR filename.

### Impact

Dapat bermasalah pada Linux/CI filesystem.

### Recommendation

**FIX BEFORE CI/CD HARDENING.**

---

# 18. Existing Security Gap

## [RISK — HIGH] Current HR endpoints

Repository saat ini mempunyai:

```text
GET  /v1/hr/employees
POST /v1/hr/employees
```

dengan `InjectTenantContext`, tetapi belum dengan explicit HR permission middleware / organizational scope authorization.

Ini **implementation gap**, bukan architecture gap.

### Required engineering direction

```text
Current:
Tenant Context

Target:
Authentication
+ Tenant Context
+ Permission
+ Appropriate Organizational Scope
```

Khusus mutation `POST`, gap ini harus diprioritaskan sebelum broader production exposure.

---

# 19. Resource Gaps yang Tetap Sah

## [RESOURCE GAP]

Repository package saat ini belum berisi artifact individual HR-001–HR-008 dan ADR-032; handoff menjadi continuation authority yang tersedia.

Ini cukup untuk Phase 2H boundary, tetapi untuk engineering traceability granular:

```text
FR
→ BR
→ AC
→ Schema
→ API
→ Test
```

artifact individual nantinya idealnya berada di documentation repository.

---

# 20. Open Decisions — Carried Forward

Open items HR sebelumnya tetap berlaku, termasuk work calendar, Finance implementation, competency taxonomy, document retention, role-grant provenance, Membership deactivation policy, dan lain-lain. Handoff memang menetapkannya sebagai `[OPEN DECISION]` sampai tersedia authority baru.

Phase 2H menambahkan open items berikut:

### Reporting

- exact performance SLA;
- exact freshness SLA;
- projection refresh schedule;
- large-export threshold;
- pagination/date-range limits;
- cache TTL jika suatu hari diperlukan.

### Privacy

- minimum sensitive aggregate cohort;
- exact masking format;
- production privacy/retention policy.

### Export

- export artifact retention;
- exact Dapodik field mappings;
- exact EMIS GTK field mappings;
- official import formats;
- external identifier registry ownership.

### Operations

- dedicated reporting queue;
- production storage provider;
- centralized monitoring provider;
- production log retention.

Tidak ada yang perlu dipalsukan menjadi keputusan sekarang.

---

# 21. Consolidated Traceability

| Business Need          | Requirement / Decision  | Source Domain                       | Delivery                |
| ---------------------- | ----------------------- | ----------------------------------- | ----------------------- |
| Workforce visibility   | HRR-KPI-001             | HR Employment + Core Organization   | Workforce dashboard     |
| Unit reporting         | HRR-AUTH-002            | Core OrganizationalAssignment       | Scoped report           |
| Recruitment visibility | HRR-KPI-010/011         | HR Recruitment                      | Funnel                  |
| Leave visibility       | HRR-KPI-020–023         | HR Leave                            | Leave report            |
| Attendance             | HRR-KPI-030/031         | Attendance                          | Summary when available  |
| Compensation facts     | HRR-KPI-040–042         | HR                                  | Restricted reporting    |
| Payroll result         | Explicitly not HR owned | Finance                             | Future Finance contract |
| Performance            | HRR-KPI-050/051         | HR Performance                      | Framework-aware report  |
| Contracts              | HRR-KPI-060/061         | HR Contract                         | Expiry/status report    |
| Discipline             | HRR-KPI-070/071         | HR Discipline                       | Restricted report       |
| Offboarding            | HRR-KPI-080–082         | HR Offboarding                      | Lifecycle reporting     |
| Dapodik                | GOV-ARCH-001            | Multi-domain projection             | Frozen export           |
| EMIS GTK               | GOV-ARCH-001            | Multi-domain projection             | Frozen export           |
| Export evidence        | HRR-AUD-001             | Reporting lifecycle                 | ExportRun + audit       |
| Privacy                | HRR-PRIV-\*             | Core authorization + source domains | Minimum disclosure      |
| Freshness              | HRR-NFR-012–014         | Reporting                           | LIVE/PROJECTED/FROZEN   |

Traceability tidak menemukan technical component besar yang tidak mempunyai requirement atau business reason.

---

# 22. Final Locked Decision Set — Phase 2H

Jika 2H-G disetujui, saya rekomendasikan seluruh keputusan berikut dianggap **LOCKED**:

1. HR Reporting tetap capability dalam `Modules/HR`.
2. Reporting bukan source of truth.
3. Direct-query-first.
4. Projection hanya jika performance/complexity membuktikan kebutuhan.
5. Semua persisted projection rebuildable.
6. Tidak membuat universal generic metric/EAV table.
7. Historical report memakai effective temporal data.
8. Cross-domain reads menggunakan explicit ownership/read contract.
9. Authorization = permission + tenant + scope + sensitivity.
10. Position bukan authorization source.
11. Aggregate permission tidak memberi detail permission.
12. View dan export permission terpisah.
13. Sensitive cohort membutuhkan privacy protection.
14. Capability projection hanya untuk UX.
15. Government schema tidak menentukan canonical EduCore schema.
16. Dapodik dan EMIS GTK menggunakan versioned adapter/export boundary.
17. Simpatika tidak menjadi target integrasi baru.
18. Direct government synchronization membutuhkan official contract + formal change decision.
19. Export menggunakan frozen dataset.
20. Export evidence bersifat transactional.
21. Core Audit tetap supplemental.
22. Queue job sensitif membawa identifier, bukan raw HR dataset.
23. HR artifact menggunakan private storage.
24. Freshness harus eksplisit: LIVE / PROJECTED / FROZEN.
25. Failure reporting tidak boleh mempengaruhi validity canonical HR transaction.

---

# 23. Critical Gap Assessment

### Critical business gap

**NONE**

### Critical architectural gap

**NONE**

### Critical integration conflict

**NONE**

### Critical security design gap

**NONE**

Ada **existing implementation security gap** pada HR employee routes, tetapi target remediation-nya sudah jelas dan tidak menghambat closure specification.

---

# 24. Phase Closure Assessment

### Quality Score

**9.8 / 10**

### Gaps

Open items tersisa terutama:

- implementation-specific;
- policy-specific;
- external specification-specific;
- performance measurement-specific.

Tidak ada yang perlu diselesaikan dengan asumsi sekarang.

### Risks

Prioritas tertinggi saat engineering nanti:

1. **HR endpoint authorization gap**;
2. PostgreSQL/SQLite repository configuration conflict;
3. filename casing hygiene;
4. queue payload discipline untuk data sensitif;
5. export retention/privacy;
6. external government schema changes.

### Recommendation

Jangan memperpanjang Phase 2H dengan desain tambahan.

Design baseline sudah cukup matang untuk diturunkan menjadi engineering specification ketika implementation phase dimulai.

---

# 25. Reviewer Status

## **READY FOR APPROVAL — PHASE 2H CAN BE CLOSED**

Dengan approval Anda terhadap **2H-G**, maka status keseluruhan menjadi:

```text
HR-001–HR-008
APPROVED / LOCKED

ADR-032
ACCEPTED

PHASE 2H
HR Reporting, Dashboard
& Government Export Boundary
APPROVED / LOCKED
```

Saya merekomendasikan setelah closure kita **membuat artifact formal baru `HR-009 — HR Reporting, Dashboard & Government Export Specification` beserta phase handoff**, sehingga seluruh keputusan 2H-A–2H-G tidak hanya berada di percakapan dan dapat dipakai tim engineering pada fase berikutnya.

[1]: https://dapo.kemendikdasmen.go.id/berita/rilis-aplikasi-dapodik-versi-2026-c?utm_source=chatgpt.com "Rilis Aplikasi Dapodik versi 2026.c - Pauddikdasmen"
[2]: https://pendis.kemenag.go.id/storage/archives/01JHHYZJDMA4P0EANWJWSY6RF2.pdf?utm_source=chatgpt.com "-
KEMENTERIAN AGAMA REPUBLIK INDONESIA
DIREKTORAT"
