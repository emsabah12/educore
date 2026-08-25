# Phase 2H-C — HR Reporting Read Model Architecture

## 1. Project Resource Check

**[FAKTA]** Repository masih berada pada baseline:

```text
26b475b695aa4511064b1410db03d1f0c8bdd6ce
```

Tidak ditemukan implementation baru untuk `Modules/Reporting`, `Modules/Attendance`, atau `Modules/Finance`. `Modules/HR` masih berupa Employee foundation.

Dua pola existing sangat relevan:

1. Core sudah memperlakukan capability/workspace sebagai **read projection**, bukan authority.
2. Scoped projection harus menggunakan authenticated tenant + verified organizational context; identifier dari client bukan authority.

Handoff juga secara eksplisit meminta _read-model freshness strategy_ dan melarang reporting table menjadi source of truth.

Selain itu, repository sudah memiliki `BaseTenantAwareJob` untuk asynchronous processing dengan restoration dan cleanup tenant context. Ini dapat kita **reuse** jika nanti projection/export dijalankan melalui queue.

---

# 2. Existing Decision

2H-A dan 2H-B sekarang:

**APPROVED / LOCKED**

Sehingga 2H-C harus mempertahankan:

```text
Canonical Domain
      ↓
Read / Projection
      ↓
Dashboard / Report / Export

Read Model ≠ Authority
```

dan:

```text
Permission
+ Tenant Context
+ Organizational Scope
+ Data Sensitivity
```

tetap menjadi authorization boundary.

---

# 3. Architecture Decision

## HRR-ARCH-001 — Direct-Query First, Projection When Justified

**[REKOMENDASI]** EduCore HR sebaiknya memakai **hybrid read architecture**, tetapi dimulai dari **direct-query-first**.

```text
                       ┌──────────────────┐
                       │ Canonical Domain │
                       └────────┬─────────┘
                                │
                 ┌──────────────┴──────────────┐
                 │                             │
                 ▼                             ▼
        Direct Reporting Query        Async Projection
                 │                             │
                 └──────────────┬──────────────┘
                                ▼
                       Reporting Service
                                │
                    ┌───────────┼──────────┐
                    ▼           ▼          ▼
                 Dashboard    Report     Export
```

### Kenapa bukan semua projection?

Karena saat ini:

- ukuran data belum terbukti membutuhkan warehouse/materialization;
- HR implementation sendiri masih bertahap;
- projection menambah operational complexity;
- synchronization/freshness problem akan muncul;
- risiko shadow source-of-truth meningkat.

Jadi prinsipnya:

> **Jangan materialize data hanya karena dashboard mungkin akan membutuhkannya.**

---

# 4. Query Strategy Classification

Saya usulkan tiga mode.

| Mode                | Penggunaan                          | Freshness    |
| ------------------- | ----------------------------------- | ------------ |
| **DIRECT**          | operational/detail/simple aggregate | Current      |
| **PROJECTED**       | expensive/cross-domain aggregate    | Eventual     |
| **SNAPSHOT/EXPORT** | reproducible report/export          | Frozen as-of |

---

# 5. DIRECT Query

## HRR-ARCH-002

DIRECT digunakan jika query:

- berasal dari satu atau sedikit bounded context;
- indexable;
- volume masih reasonable;
- memerlukan data terkini;
- tidak membutuhkan transformasi berat.

Candidate:

```text
Active Headcount
Current Employment Distribution
Open Offboarding Cases
Expiring Agreements
Leave Request Summary
Current Document Status
```

### Example

```text
Request
  ↓
Verified Tenant Context
  ↓
Verified Organizational Scope
  ↓
HR Reporting Query
  ↓
Canonical HR/Core Data
  ↓
DTO
```

Tidak ada persistence read-model baru.

---

# 6. PROJECTED Query

## HRR-ARCH-003

Projection baru justified jika salah satu kondisi berikut terjadi:

1. aggregate lintas domain mahal;
2. query historis menjadi berat;
3. dashboard memerlukan banyak aggregate sekaligus;
4. latency direct query melampaui SLA yang nanti disetujui;
5. data source asynchronous;
6. source query terlalu kompleks untuk permintaan interaktif.

Contoh candidate ke depan:

```text
Attendance trends
Recruitment funnel trend
Performance distribution
Competency coverage
Cross-unit workforce trend
```

Tetapi:

**[CONSTRAINT]** Attendance/Finance belum diimplementasikan, sehingga projection mereka belum boleh dibuat sekarang.

---

# 7. Jangan Membuat Generic Metric Table

Saya **tidak merekomendasikan** pola seperti:

```text
report_metrics

metric_name
dimension_json
value
date
```

sebagai universal storage.

Contoh:

```text
ACTIVE_HEADCOUNT | {"unit":"A"} | 125
TURNOVER_RATE    | {"unit":"A"} | 7.8
```

## Alasannya

Model seperti ini memang fleksibel, tetapi:

- referential integrity lemah;
- type safety rendah;
- definition berubah sulit dilacak;
- filtering/indexing semakin kompleks;
- mudah menjadi pseudo-data-warehouse;
- debugging metric sulit.

### Keputusan

**HRR-ARCH-004**

> Jika projection benar-benar diperlukan, gunakan **purpose-specific read model**, bukan universal metric EAV/JSON storage.

---

# 8. Projection Granularity

Read model harus mempunyai satu grain yang jelas.

Contoh:

### Workforce snapshot

```text
Grain:
one workforce aggregate
per tenant
per organizational scope
per snapshot date
per defined dimensions
```

### Recruitment funnel

```text
Grain:
one recruitment cohort/stage aggregate
per tenant
per reporting period
```

### Attendance

```text
Grain:
determined by Attendance reporting contract
```

Bukan satu tabel besar:

```text
HR_ALL_METRICS
```

---

# 9. Metric Definition vs Metric Value

Ini harus dipisah.

```text
Metric Definition
        │
        │ defines meaning
        ▼
Metric Calculation
        │
        ▼
Metric Result
```

Metric definition yang kita lock di 2H-B mencakup konsep:

```text
metric_id
metric_type
source_domain
grain
numerator
denominator
time_semantics
sensitivity
version
```

Sedangkan calculated value adalah hasil sementara/projection.

## HRR-ARCH-005

Perubahan formula:

```text
v1 → v2
```

tidak boleh diam-diam mengubah makna historical report.

Metric/version yang relevan harus dapat diketahui.

---

# 10. Current vs Historical Query

Ini salah satu area paling kritis.

## Current-state reporting

Contoh:

```text
Active employees today
```

dapat membaca canonical effective records secara langsung.

---

## Historical reporting

Contoh:

```text
Headcount as of 31 Dec 2025
```

harus menggunakan effective history:

```text
Employment
+
Employment Placement
+
OrganizationalAssignment history
+
as_of_date
```

bukan current placement.

### HRR-ARCH-006

```text
Facts at T
must use dimensions valid at T
```

Misalnya:

```text
Employee X

Jan–Jun
Unit A

Jul–Dec
Unit B
```

Report Mei:

```text
Unit A
```

Report Oktober:

```text
Unit B
```

Ini mempertahankan business rule historis dari 2H-B.

---

# 11. Read Model Data Ownership

Projection boleh menyimpan:

- identifier untuk traceability;
- aggregate;
- normalized reporting dimensions;
- source timestamps;
- projection metadata.

Tetapi tidak seharusnya menduplikasi:

- full Person profile;
- document content;
- bank account;
- disciplinary narrative;
- exit interview narrative;
- private document payload.

### Principle

```text
Minimum Data
+
Minimum Retention
+
Minimum Sensitivity
```

---

# 12. Cross-Domain Integration

## HRR-ARCH-007 — Owner Exposes Read Contract

HR Reporting tidak sebaiknya menjadi module yang mengetahui internal schema semua module.

Preferred:

```text
HR Reporting
      │
      ├── HR Reporting Contracts
      │
      ├── Attendance Reporting Contract
      │
      ├── Academic Reporting Contract
      │
      └── Finance Reporting Contract
```

bukan:

```text
HR Reporting
  ↓
JOIN attendance.internal_table_x
JOIN finance.internal_table_y
JOIN academic.internal_table_z
```

secara bebas.

### Trade-off

Direct SQL lintas tabel mungkin lebih cepat dibuat.

Tetapi contract memberi:

- ownership lebih jelas;
- schema coupling lebih kecil;
- testability lebih baik;
- evolusi bounded context lebih aman.

**[REKOMENDASI]** gunakan contract sebagai default untuk cross-domain reads.

---

# 13. Core Data Exception

Core canonical context tetap boleh digunakan sesuai existing contract:

```text
Tenant
Organization
OrganizationUnit
OrganizationalAssignment
Authorization
```

Tetapi HR Reporting tidak boleh membuat mirror canonical Core seperti:

```text
hr_reporting_organizations
hr_reporting_memberships
```

tanpa alasan projection yang nyata.

Handoff secara eksplisit menetapkan Core sebagai owner Person, Membership, Organization, OrganizationalAssignment, dan RBAC.

---

# 14. Authorization Flow

Arsitektur query harus:

```text
Authenticated User
       ↓
Membership/Tenant Context
       ↓
Organizational Context
       ↓
Permission Check
       ↓
Reporting Scope Resolver
       ↓
Query
```

Bukan:

```text
?tenant_id=...
?unit_id=...
      ↓
trust request
```

## HRR-ARCH-008

Requested filter hanya boleh **mempersempit** authorized scope.

Secara konseptual:

```text
Effective Reporting Scope
=
Authorized Scope
∩
Requested Filter
```

Tidak pernah union.

---

# 15. Detail Drill-Down Architecture

Aggregate permission dan detail permission perlu dapat dipisahkan.

Contoh:

```text
hr.reporting.workforce.view
```

tidak otomatis berarti:

```text
hr.employee.sensitive.view
```

Maka:

```text
Dashboard Aggregate
       ↓
Drill Down Requested
       ↓
Re-authorize
       ↓
Detail Query
```

## HRR-ARCH-009

Frontend visibility bukan authorization source.

Ini konsisten dengan existing Core capability projection, yang digunakan untuk navigation/UX tetapi backend tetap harus authorize dari persistence state.

---

# 16. Freshness Model

Setiap response reporting perlu memiliki freshness semantics.

### DIRECT

```text
freshness_mode: LIVE
generated_at: T
```

Artinya dihitung dari current canonical persistence pada request tersebut.

### PROJECTED

```text
freshness_mode: PROJECTED
generated_at: T2
source_as_of: T1
```

dengan:

```text
T1 <= T2
```

### SNAPSHOT

```text
freshness_mode: FROZEN
as_of: T
```

---

# 17. Projection State

Jika projection ditambahkan, saya rekomendasikan state minimal secara konseptual:

```text
READY
BUILDING
STALE
FAILED
```

### Meaning

**READY**

Projection valid berdasarkan source watermark yang diketahui.

**BUILDING**

Rebuild/refresh sedang berjalan.

**STALE**

Source lebih baru daripada projection atau freshness threshold terlewati.

**FAILED**

Refresh gagal.

---

# 18. Jangan Menyamarkan Stale Data

## HRR-ARCH-010

Jika projection stale:

sistem tidak boleh diam-diam menampilkannya sebagai realtime.

UI/API minimal harus dapat membedakan:

```text
Data as of:
22 Aug 2026 17:45
```

dari:

```text
Updated now
```

Exact threshold freshness menjadi configuration/SLA pada tahap deployment/performance nanti.

**[OPEN DECISION]** nilai SLA dalam menit/jam belum perlu ditentukan sekarang.

---

# 19. Refresh Strategy

Saya rekomendasikan tiga trigger.

### A. On-demand direct

Tidak perlu refresh.

---

### B. Incremental projection refresh

```text
Domain Change
     ↓
Projection Update Job
```

digunakan jika event contract tersedia dan projection justified.

---

### C. Full rebuild/reconciliation

```text
Canonical Source
      ↓
Rebuild
      ↓
Replace Projection
```

dibutuhkan untuk recovery/reconciliation.

## HRR-ARCH-011

Setiap persisted projection harus mempunyai **full rebuild path**.

Jika projection hanya dapat diperbaiki secara manual:

> architecture belum cukup aman.

---

# 20. Queue Architecture

Repository sudah mempunyai:

```text
BaseTenantAwareJob
```

yang mengembalikan tenant context pada queue worker dan membersihkannya setelah lifecycle job.

**[REKOMENDASI]**

Jika projection asynchronous dibuat:

```text
HR Projection Job
      ↓
extends/reuses
BaseTenantAwareJob
```

daripada membuat tenant-aware queue infrastructure baru.

Klasifikasi:

**KEEP / REUSE**

---

# 21. Projection Failure

Projection failure tidak boleh:

```text
rollback Employment creation
rollback Leave approval
rollback Attendance reconciliation
```

karena projection bukan transactional owner.

Preferred:

```text
Canonical Transaction
     ✓

Projection Refresh
     ↓
fails
     ↓
retry / reconcile
```

### HRR-ARCH-012

> Reporting availability tidak boleh menentukan validity canonical transaction.

---

# 22. Eventual Consistency Boundary

Jika event-driven projection dipakai:

```text
Canonical commit
       ↓
event/integration trigger
       ↓
projection update
```

maka akan ada interval:

```text
Source = newer
Projection = older
```

Ini acceptable **hanya jika freshness terlihat**.

Untuk use case yang membutuhkan current truth, gunakan DIRECT.

---

# 23. Cache Boundary

Cache dapat digunakan di depan DIRECT atau PROJECTED query nanti.

Tetapi:

```text
Cache ≠ Projection Authority
Cache ≠ Authorization Authority
```

Cache key minimal harus mempertimbangkan:

```text
tenant
organizational scope
metric/report
filters
metric version
```

jika caching akhirnya dipakai.

**[DEFERRED]** cache implementation sampai ada profiling.

Repository memang telah memiliki database-backed cache configuration, tetapi itu bukan alasan untuk langsung meng-cache semua reporting.

---

# 24. Export Architecture

Detail government mapping baru di 2H-E, tetapi read-model boundary-nya dapat kita lock sekarang.

```text
Canonical Domain
       ↓
Reporting Dataset Builder
       ↓
Frozen Export Dataset
       ↓
Format Mapper
       ↓
Export Artifact
```

Kenapa perlu frozen dataset?

Supaya export dapat menjawab:

> “File yang dibuat tanggal 22 Agustus berasal dari kondisi data apa?”

bukan membangun ulang file lama menggunakan data terbaru.

---

# 25. Export Traceability

Setiap export run secara konseptual perlu memiliki:

```text
export_id
tenant_id
requested_by
report/export_type
definition_version
source_as_of
generated_at
status
filter/scope
artifact_reference
```

Belum merupakan schema migration final.

Private/sensitive exports juga tetap mengikuti permission dan retention policy.

---

# 26. Proposed Module Responsibility

Masih dalam `Modules/HR`.

```text
Modules/
└── HR/
    └── Reporting/
        ├── Application/
        │   └── reporting use cases
        │
        ├── Contracts/
        │   └── read-source contracts
        │
        ├── Queries/
        │   └── direct queries
        │
        ├── Projections/
        │   ├── builders
        │   └── projection DTO/read models
        │
        ├── Metrics/
        │   └── metric definitions/calculators
        │
        └── Exports/
            └── export dataset builders
```

### Important

Ini adalah **responsibility map**, belum berarti seluruh directory harus langsung dibuat.

Prinsip YAGNI tetap berlaku.

---

# 27. Initial Query/Projection Decision Matrix

| Reporting Area              | Initial Strategy               | Reason                     |
| --------------------------- | ------------------------------ | -------------------------- |
| Active Headcount            | **DIRECT**                     | Simple, current truth      |
| Workforce Distribution      | **DIRECT**                     | Aggregate masih manageable |
| Employment Activations/Ends | **DIRECT**                     | Period query               |
| Offboarding Gap             | **DIRECT**                     | HR-local                   |
| Recruitment Funnel          | **DIRECT → PROJECT if needed** | Bergantung volume          |
| Leave Summary               | **DIRECT**                     | HR-local                   |
| Leave Balance               | **DIRECT**                     | Ledger authority           |
| Attendance Trend            | **DEFER / PROJECT candidate**  | Attendance belum tersedia  |
| Compensation Facts          | **DIRECT, restricted**         | HR fact                    |
| Payroll Result              | **Finance contract**           | HR bukan owner             |
| Performance Distribution    | **DIRECT → PROJECT candidate** | Framework-aware            |
| Competency Coverage         | **DEFER**                      | Taxonomy belum final       |
| Agreement Expiry            | **DIRECT**                     | Date query                 |
| Discipline Summary          | **DIRECT, highly restricted**  | HR-local                   |
| Government Export           | **FROZEN SNAPSHOT**            | Reproducibility            |

Ini penting karena kita **tidak melakukan premature materialization**.

---

# 28. Conceptual API Response Metadata

Belum API specification final, tetapi reporting responses sebaiknya mempunyai metadata seperti:

```text
data
meta
 ├── metric/report definition
 ├── reporting period
 ├── as_of
 ├── generated_at
 ├── freshness_mode
 ├── source_as_of
 └── effective_scope
```

Effective scope penting agar client tahu **data mana yang sebenarnya ditampilkan**, bukan sekadar filter yang dikirim.

---

# 29. Data Integrity Rules

### HRR-BR-050

Projection tidak boleh memiliki business transition yang source domain tidak miliki.

### HRR-BR-051

Menghapus projection tidak boleh menghapus canonical data.

### HRR-BR-052

Full rebuild harus menghasilkan equivalent metric result untuk source version yang sama.

### HRR-BR-053

Unauthorized organizational data tidak boleh masuk ke response meski sudah terdapat dalam tenant-wide projection.

### HRR-BR-054

Sensitive detail harus di-authorize ulang saat drill-down.

### HRR-BR-055

Historical metric harus memakai temporal dimension yang berlaku pada waktu fakta.

---

# 30. Reconciliation

Projection yang persist perlu reconciliation process:

```text
Canonical Source
       │
       │ recompute
       ▼
Expected Projection
       │
       │ compare
       ▼
Stored Projection
       │
 ┌─────┴─────┐
 same      mismatch
  │            │
 READY      rebuild/
             alert
```

**[REKOMENDASI]** Jangan mencoba membuat complex self-healing sekarang.

Full rebuild + discrepancy detection sudah cukup sebagai baseline.

---

# 31. Observability Minimum

Jika asynchronous projection dibuat, minimal harus dapat diketahui:

- projection type;
- tenant;
- execution status;
- started/finished time;
- source watermark;
- rows/result processed;
- failure reason;
- retry state.

Tetapi metric business tidak boleh tercampur dengan operational monitoring.

---

# 32. NFR yang Mulai Terdefinisi

### HRR-NFR-001 — Consistency

Direct query menggunakan canonical source saat request.

### HRR-NFR-002 — Rebuildability

Persisted read projection harus dapat dibangun ulang dari canonical source.

### HRR-NFR-003 — Isolation

Projection/job/query wajib tenant-scoped.

### HRR-NFR-004 — Authorization

Setiap request melakukan server-side authorization.

### HRR-NFR-005 — Traceability

Report/export harus dapat menjelaskan source period/as-of dan definition version.

### HRR-NFR-006 — Privacy

Projection hanya menyimpan informasi minimum yang diperlukan.

### HRR-NFR-007 — Failure Isolation

Kegagalan reporting projection tidak boleh membatalkan valid canonical domain transaction.

---

# 33. Open Decisions

Hal-hal berikut **sengaja belum dikunci**:

**[OPEN DECISION]**

- exact dashboard response-time SLA;
- projection freshness SLA;
- refresh schedule;
- queue separation/dedicated queue;
- cache TTL;
- exact projection table schema;
- archival/retention;
- export artifact retention;
- event/outbox architecture.

Khusus event/outbox:

saya **tidak merekomendasikan membuat outbox framework hanya untuk HR reporting** sekarang.

Jika nanti arsitektur lintas domain memang membutuhkannya, keputusan tersebut seharusnya menjadi Core/platform ADR tersendiri.

---

# 34. Architecture Classification terhadap Existing Repository

| Area                               | Decision                      |
| ---------------------------------- | ----------------------------- |
| `Modules/HR`                       | **EXTEND**                    |
| Core tenant-aware queue            | **KEEP / REUSE**              |
| Core organizational context        | **KEEP / REUSE**              |
| Core authorization                 | **KEEP / REUSE**              |
| Core capability projection pattern | **KEEP AS REFERENCE PATTERN** |
| Generic Reporting module           | **DEFER**                     |
| Generic metric/EAV table           | **DO NOT INTRODUCE**          |
| Data warehouse                     | **DEFER**                     |
| Redis/cache optimization           | **DEFER UNTIL PROFILED**      |
| Event/outbox infrastructure        | **DEFER**                     |

---

# 35. Target Architecture

```text
                         ┌────────────────────┐
                         │ Authenticated User │
                         └─────────┬──────────┘
                                   │
                                   ▼
                   ┌────────────────────────────┐
                   │ Tenant + Org Authorization │
                   └──────────────┬─────────────┘
                                  │
                                  ▼
                    ┌──────────────────────────┐
                    │    HR Reporting Layer    │
                    └─────────────┬────────────┘
                                  │
               ┌──────────────────┴────────────────┐
               │                                   │
               ▼                                   ▼
       DIRECT QUERY                       PROJECTED QUERY
               │                                   │
        canonical source                 rebuildable model
               │                                   │
               └──────────────────┬────────────────┘
                                  ▼
                          Metric / Report DTO
                                  │
                       ┌──────────┼───────────┐
                       ▼          ▼           ▼
                  Dashboard     Report     Export
                                           │
                                           ▼
                                     Frozen Snapshot
```

---

# 36. Traceability Example

```text
Business Need
    ↓
HRR-FR-001
Consolidated Workforce
    ↓
HRR-KPI-001
Active Headcount
    ↓
HRR-BR-001
Snapshot Metric
    ↓
HRR-ARCH-001
Direct Query First
    ↓
Employment + OrganizationalAssignment
    ↓
Reporting Query
    ↓
HRR-AC-001 / 002 / 003
```

Tidak ada technical component yang muncul tanpa requirement.

---

# 37. Reviewer Mode — Phase 2H-C

**Quality Score: 9.6/10**

### Gaps

Belum ada kebutuhan volume/performance nyata untuk menentukan projection fisik tertentu. Attendance dan Finance source contract juga belum tersedia.

### Risks

**[RISK] Premature projection**
Membuat banyak aggregate table sekarang justru menambah synchronization complexity.

**[RISK] Direct cross-module SQL coupling**
Dapat membuat HR Reporting bergantung pada internal schema Attendance/Finance/Academic.

**[RISK] Stale authorization**
Precomputed tenant-wide projection dapat bocor jika filtering dianggap sama dengan authorization.

**[RISK] Historical drift**
Rebuilding historical report tanpa metric/version/as-of semantics dapat menghasilkan report lama dengan makna baru.

### Recommendations

Baseline yang paling aman saat ini adalah:

> **Direct-query first → profile → projection only where justified → every projection rebuildable and visibly eventual-consistent.**

### Status

**READY FOR APPROVAL**

Jika 2H-C ini disetujui, tahap berikutnya adalah **Phase 2H-D — HR Dashboard, Authorization & Privacy Design**: kita akan menetapkan dashboard per persona/use case, permission taxonomy, aggregate-vs-detail access, organizational scope, masking, empty/error/permission states, dan information architecture tanpa menyentuh UI visual terlalu dini.
