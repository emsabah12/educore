# HR-009 — HR Reporting, Dashboard & Government Export Specification

- **Document ID:** HR-009
- **Version:** 1.0
- **Status:** APPROVED / LOCKED
- **Approval Date:** 2026-08-22
- **Repository Baseline:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`
- **Architecture Authority:** ADR-032 + HR-001 through HR-008 + approved Phase 2H-A through 2H-G
- **Module:** EduCore HR
- **Primary Implementation Location:** `Modules/HR` (reporting capability; physical structure finalized during implementation planning)

---

# 1. Purpose

Dokumen ini mengonsolidasikan keputusan Phase 2H tentang HR Reporting, Dashboard, read-model architecture, authorization/privacy, government export boundary, auditability, freshness, dan operational non-functional requirements.

HR-009 adalah specification authority untuk capability reporting HR setelah HR-001 sampai HR-008. Dokumen ini **tidak menggantikan** ownership domain yang telah dikunci sebelumnya dan **tidak mengubah reporting menjadi source of truth**.

Urutan authority ketika terdapat pertentangan:

1. instruksi user terbaru;
2. repository/resource EduCore terbaru;
3. ADR yang Accepted;
4. HR-001 sampai HR-009 yang Approved/Locked;
5. existing implementation;
6. asumsi hanya ketika tidak ada authority lain.

Jika repository berubah setelah baseline dokumen ini, lakukan resource audit dan change-impact analysis sebelum implementasi.

---

# 2. Executive Summary

EduCore HR membutuhkan reporting yang memberi visibilitas workforce, recruitment, leave, attendance, compensation facts, performance, competency, contract/document, discipline, offboarding, dan kebutuhan government export tanpa membuat storage reporting menjadi authority baru.

Architecture yang disepakati adalah:

```text
Canonical Domain
      ↓
Authorized Read Contracts
      ↓
Direct Query / Rebuildable Projection / Frozen Dataset
      ↓
Reporting Service
      ↓
Dashboard / Report / Export
```

Prinsip utama:

- direct-query-first;
- projection hanya bila dibuktikan perlu;
- persisted projection harus rebuildable dan reconcilable;
- historical reporting menggunakan effective state pada waktu fakta;
- authorization menggunakan Core RBAC + tenant + organizational scope + sensitivity;
- aggregate access tidak otomatis memberi detail access;
- view dan export permission berbeda;
- sensitive data mengikuti least disclosure;
- government export memakai versioned mapping dan frozen dataset;
- Dapodik serta EMIS/EMIS GTK adalah target aktif;
- Simpatika diperlakukan sebagai legacy/transition target, bukan target integrasi baru;
- direct government synchronization tidak boleh dibangun tanpa official contract yang terverifikasi.

---

# 3. Current State

## 3.1 Repository baseline

Pada baseline `26b475b...`:

- `Modules/HR` sudah memiliki Employee foundation;
- Core sudah memiliki Person/Membership/User/Tenant/RBAC/Organization/OrganizationalAssignment/Audit/Notification primitives;
- Core sudah memiliki tenant-aware queue support;
- `Modules/Attendance` belum tersedia;
- `Modules/Finance` belum tersedia;
- generic `Modules/Reporting` belum tersedia.

## 3.2 Existing HR implementation maturity

Implementation repository belum mencakup seluruh specification HR-002 sampai HR-009. Ini merupakan **implementation gap**, bukan architecture conflict.

Classification:

| Area | Decision |
|---|---|
| Existing `Modules/HR` | KEEP + EXTEND |
| Employee foundation | KEEP |
| Core RBAC | KEEP / REUSE |
| Core Organizational Context | KEEP / REUSE |
| Core tenant-aware jobs | KEEP / REUSE |
| Core Audit | KEEP as supplemental operational audit |
| Generic Reporting module | DEFER |
| Data warehouse | DEFER |

---

# 4. Domain Boundary

## HRR-BND-001 — Reporting Is Non-Authoritative

Dashboard, report, aggregate, projection, dan export bukan canonical source of truth.

```text
Employment.status
    = source truth

reporting_workforce_snapshot.status
    ≠ source truth
```

## HRR-BND-002 — Source Domain Retains Ownership

| Data | Canonical Owner |
|---|---|
| Person / Membership / User | Core |
| Organization / OrganizationUnit / OrganizationalAssignment | Core |
| Employee / Employment / Position | HR |
| Recruitment | HR |
| Leave / Permit | HR |
| Compensation / benefit / payroll-input facts | HR |
| Performance / Competency | HR |
| HR Documents / Contract | HR |
| Discipline | HR |
| Offboarding | HR |
| Attendance facts | Attendance |
| Payroll calculation/payment/accounting | Finance |
| Teaching/academic facts | Academic |

Reporting hanya boleh `consume → derive → aggregate → present → export`.

## HRR-BND-003 — No Cross-Domain Mutation

Reporting tidak boleh mengubah canonical source domain. Perubahan data harus diarahkan ke owning-domain use case.

## HRR-BND-004 — Projection Must Be Rebuildable

Setiap persisted projection harus dapat dihapus dan dibangun ulang dari canonical source tanpa kehilangan business data.

## HRR-BND-005 — Tenant Isolation

Seluruh query/projection/export wajib tenant-scoped. Client-provided `tenant_id` tidak pernah menjadi authority.

## HRR-BND-006 — Organizational Scope

Scope reporting mengikuti Core organizational context. Position/jabatan tidak menjadi authorization source.

## HRR-BND-007 — Government Export Is a Projection

Government schema tidak boleh menentukan canonical EduCore schema.

## HRR-BND-008 — Export Is Not Synchronization

File/export generation berbeda dari direct synchronization. Direct sync hanya dapat dibuka setelah official interface contract diverifikasi.

## HRR-BND-009 — Freshness Must Be Explicit

Asynchronous reporting harus menunjukkan `generated_at`, `source_as_of`, dan projection/definition version yang relevan.

## HRR-BND-010 — Least Disclosure

Read model hanya boleh menyimpan atau mengembalikan data minimum yang diperlukan oleh use case.

---

# 5. Scope

## IN SCOPE

- consolidated workforce reporting;
- tenant/unit headcount;
- workforce distribution;
- recruitment funnel;
- leave/permit summaries;
- attendance summaries ketika Attendance domain tersedia;
- compensation/payroll-input facts reporting;
- performance and competency reporting;
- document/contract expiry and status;
- discipline reporting;
- offboarding reporting;
- dashboard authorization/privacy;
- government export boundary;
- Dapodik HR contribution;
- EMIS/EMIS GTK HR contribution;
- export auditability;
- freshness and projection recovery.

## OUT OF SCOPE

- payroll calculation;
- PPh21/BPJS monetary calculation;
- payment/accounting;
- attendance capture/device integration;
- academic scheduling;
- generic BI/data warehouse platform;
- predictive/ML HR analytics;
- unofficial government API reverse engineering;
- browser automation terhadap government systems;
- direct Dapodik/EMIS synchronization tanpa official contract.

## FUTURE SCOPE

- cross-domain Enterprise Reporting/Analytics capability;
- cross-domain Government Integration Gateway;
- Employee self-service dashboard;
- advanced privacy analytics;
- direct government integration bila official contract tersedia.

## DEFERRED

- exact KPI targets;
- exact freshness SLA;
- exact privacy cohort threshold;
- exact retention duration;
- government field-level mapping;
- external identifier registry ownership;
- cache/projection optimization sebelum profiling.

---

# 6. Reporting Personas

Persona hanya membantu menyusun information need; persona bukan authorization authority.

| Persona | Primary Reporting Need |
|---|---|
| Admin Yayasan | Consolidated multi-unit workforce |
| HR/Personalia | Operational + detailed HR reporting |
| Kepala Sekolah/Pimpinan Unit | Scoped workforce reporting |
| Bendahara/Finance | Authorized compensation/payroll-input facts |
| Guru/Tendik | Future self-service reporting |
| Auditor/Reviewer | Controlled historical/evidence reporting |

Final access selalu ditentukan oleh:

```text
Permission
+ Tenant Context
+ Organizational Scope
+ Data Sensitivity
```

---

# 7. Functional Requirements

| ID | Requirement |
|---|---|
| HRR-FR-001 | Menampilkan consolidated workforce summary sesuai tenant dan organizational scope. |
| HRR-FR-002 | Mendukung reporting period/as-of date sesuai metric semantics. |
| HRR-FR-003 | Mendukung drill-down hanya ke record yang juga diizinkan. |
| HRR-FR-004 | Menyediakan workforce composition reporting. |
| HRR-FR-005 | Menyediakan recruitment funnel reporting. |
| HRR-FR-006 | Menyediakan leave/permit summary. |
| HRR-FR-007 | Menyediakan attendance summary ketika Attendance domain tersedia. |
| HRR-FR-008 | Menyediakan compensation/payroll-input facts tanpa menghitung payroll. |
| HRR-FR-009 | Menyediakan performance dan competency summary. |
| HRR-FR-010 | Menyediakan document/contract expiry dan status reporting. |
| HRR-FR-011 | Menyediakan discipline dan offboarding summary. |
| HRR-FR-012 | Menunjukkan freshness/as-of untuk projected data. |
| HRR-FR-013 | Menghasilkan export yang authorized tanpa mengubah source data. |
| HRR-FR-014 | Sensitive report mengikuti permission dan organizational scope. |
| HRR-FR-015 | Metric dapat ditelusuri ke definition dan source domain. |

---

# 8. Metric Semantics

## HRR-BR-001 — Snapshot vs Period/Flow

Setiap metric wajib menyatakan salah satu:

- `SNAPSHOT`: state pada tanggal tertentu;
- `PERIOD/FLOW`: kejadian dalam rentang waktu.

Metric dengan semantics berbeda tidak boleh dibandingkan seolah grain waktunya sama.

---

# 9. KPI Catalog

## 9.1 Workforce

### HRR-KPI-001 — Active Headcount

**Type:** SNAPSHOT

```text
COUNT DISTINCT Employee
WHERE Employment is ACTIVE
AT as_of_date
```

`PLANNED` dan `ENDED` tidak termasuk active headcount. Karena satu Employee maksimal mempunyai satu ACTIVE Employment pada satu waktu, tidak boleh double count.

Dimensions: Organization, Organization Unit, Employment Type, Employment Classification, Position.

### HRR-KPI-002 — Workforce Distribution

```text
Active Headcount per selected dimension
÷ Total Active Headcount
× 100%
```

Jika denominator = 0, hasil `N/A`, bukan `0%`.

### HRR-KPI-003 — New Employment Activations

**Type:** PERIOD

Jumlah Employment yang activation date-nya berada di reporting period. Rehire dihitung sebagai Employment activation baru, tetapi bukan Employee baru.

### HRR-KPI-004 — Employment Ends

Jumlah Employment yang end date-nya berada di reporting period.

Employment end berbeda dari Membership deactivation, User deletion, dan Offboarding completion.

### HRR-KPI-005 — Offboarding Completion Gap

Jumlah `Employment ENDED` yang mempunyai Offboarding Case tetapi belum `COMPLETED`.

## 9.2 Recruitment

### HRR-KPI-010 — Applications

Jumlah Application yang dibuat pada reporting period.

### HRR-KPI-011 — Recruitment Funnel

Minimal stage:

```text
Application
→ Selection
→ Hiring Approval
→ Onboarding
→ Employment Activation
```

Exact denominator per stage mengikuti HR-003 lifecycle authority.

### HRR-KPI-012 — Hiring Conversion

Concept approved, exact denominator **DEFERRED** sampai detailed HR-003 lifecycle tersedia pada implementation context.

### HRR-KPI-013 — Onboarding Completion

Concept approved; exact cohort semantics tetap **OPEN DECISION**.

### Candidate — Time to Hire

**DEFERRED** sampai start/end/pause clock authority tersedia.

## 9.3 Leave & Permit

### HRR-KPI-020 — Leave Requests

Count Leave Requests menurut status/type dalam period.

### HRR-KPI-021 — Approved Leave

Hanya final-approved requests yang dianggap approved utilization.

### HRR-KPI-022 — Leave Balance

**Type:** SNAPSHOT. Source harus append-only leave ledger, bukan UI-recalculated balance.

### HRR-KPI-023 — Leave Utilization Rate

Concept:

```text
Consumed Entitlement
÷ Available Entitlement
× 100%
```

Final formula **DEFERRED** sampai leave/work calendar dan entitlement rules final.

## 9.4 Attendance

### HRR-KPI-030 — Attendance Status Distribution

Source hanya final/reconciled Attendance Record.

### HRR-KPI-031 — Attendance Rate

Concept approved; exact formula **DEFERRED** sampai expectation/cutoff/finalization contract selesai.

Raw attendance event tidak boleh dianggap final attendance fact.

## 9.5 Compensation

### HRR-KPI-040 — Compensation Coverage

Concept:

```text
Active Employees with effective compensation fact
÷ eligible Active Employees
```

Eligibility tetap **OPEN DECISION**.

### HRR-KPI-041 — Compensation Facts by Component

Authorized reporting dapat menyajikan component, employee count, aggregate amount, period, dan scope.

Tidak boleh diberi label `Gross Payroll`, `Net Payroll`, atau `Payroll Cost` tanpa Finance authority.

### HRR-KPI-042 — Payroll Input Readiness

Concept approved; exact completeness rule **DEFERRED** sampai payroll-input contract final.

## 9.6 Performance & Competency

### HRR-KPI-050 — Performance Review Completion

```text
completed assessment
÷ expected assessment
```

harus framework/version aware.

### HRR-KPI-051 — Rating Distribution

Hanya boleh digabung dalam framework/rating-scale yang comparable. Cross-framework average tidak boleh dibuat tanpa explicit normalization rule.

### HRR-KPI-052 — Competency Coverage

**DEFERRED** sampai competency taxonomy approved.

### HRR-KPI-053 — Training Participation

Training, certification, dan competency tetap konsep terpisah.

## 9.7 Contract & Document

### HRR-KPI-060 — Agreement Expiry

Expiry buckets dapat dikonfigurasi, tetapi threshold tidak di-hardcode pada specification ini.

### HRR-KPI-061 — Document Status

Aggregate hanya menggunakan lifecycle status yang benar-benar dimiliki source domain.

Agreement expiry tidak mengakhiri Employment otomatis.

## 9.8 Discipline

### HRR-KPI-070 — Disciplinary Cases

Count cases berdasarkan status/category dalam period.

### HRR-KPI-071 — Active Disciplinary Actions

Tidak ada universal numeric severity seperti `SP1=1, SP2=2, SP3=3`. Tenant-scoped disciplinary catalog tetap authority.

## 9.9 Offboarding

### HRR-KPI-080 — Open Offboarding Cases

Count Offboarding Case dengan status belum `COMPLETED`.

### HRR-KPI-081 — Offboarding Completion

Concept ratio completed versus eligible cases; exact cohort mengikuti lifecycle.

### HRR-KPI-082 — Outstanding Offboarding Tasks

Count applicable incomplete Approval, Checklist, Handover, Access Review, Exit Interview, dan Settlement Facts.

Final monetary settlement tetap Finance concern.

---

# 10. Common Dimensions & Filters

Mandatory context:

- Reporting Period;
- As-of Date;
- Organization;
- Organization Unit.

Domain-specific filters dapat mencakup:

- Employment Type;
- Employment Classification;
- Position;
- Recruitment Stage;
- Leave Type;
- Attendance Status;
- Performance Framework;
- Competency Category;
- Document Type;
- Disciplinary Category;
- Offboarding Status.

Requested filters tidak boleh memperluas authorized scope.

---

# 11. Historical Reporting

## HRR-BR-030 — Historical Placement

Historical report harus menggunakan organizational placement yang berlaku pada waktu fakta terjadi.

```text
Facts at T
must use dimensions valid at T
```

Jika Employee berada di Unit A pada 2025 dan Unit B pada 2026, report 2025 tetap mengklasifikasikan Employee pada Unit A.

---

# 12. Metric Definition Contract

Setiap KPI implementation minimal mempunyai definition metadata konseptual:

```text
metric_id
name
description
metric_type
source_domain
grain
numerator
denominator
time_semantics
dimensions
allowed_filters
sensitivity
freshness
drilldown_policy
version
```

Metric definition berbeda dari calculated metric value.

Perubahan formula wajib version-aware agar historical report tidak berubah makna secara diam-diam.

---

# 13. Read Model Architecture

## HRR-ARCH-001 — Direct-Query First

Initial strategy:

```text
Canonical Source
   ├── DIRECT QUERY
   ├── PROJECTED QUERY (only if justified)
   └── FROZEN SNAPSHOT (exports)
```

### DIRECT

Digunakan untuk current operational/simple aggregate yang indexable.

Candidate awal:

- Active Headcount;
- Workforce Distribution;
- Employment Activations/Ends;
- Offboarding Gap;
- Leave Summary;
- Leave Balance;
- Agreement Expiry;
- Discipline Summary.

### PROJECTED

Digunakan hanya jika:

- cross-domain aggregate mahal;
- historical query berat;
- dashboard membutuhkan aggregate kompleks;
- measured latency melampaui SLA;
- source asynchronous.

### FROZEN

Digunakan untuk reproducible export/report evidence.

## HRR-ARCH-004 — No Generic Metric EAV Table

Tidak diperkenalkan universal `report_metrics(metric_name, dimension_json, value, ...)` sebagai storage generic.

Jika projection diperlukan, gunakan purpose-specific read model dengan grain eksplisit.

## HRR-ARCH-007 — Cross-Domain Read Contracts

Reporting tidak boleh mengetahui internal schema semua bounded context secara bebas. Cross-domain read menggunakan contract milik owner.

Core context tetap dapat direuse melalui contract existing.

---

# 14. Projection Rules

Projection state conceptual:

```text
READY
BUILDING
STALE
FAILED
```

Setiap persisted projection harus mempunyai:

- source watermark/as-of;
- definition/projection version;
- incremental refresh bila relevan;
- full rebuild;
- reconciliation.

Projection failure tidak boleh membatalkan canonical transaction.

---

# 15. Authorization Model

## HRR-AUTH-001 — Authentication, Permission, Scope

```text
Authentication = who are you?
Permission     = what may you do?
Scope          = where may you do it?
```

Scope modes:

- `TENANT`;
- `ORGANIZATIONAL`;
- `SELF` (future self-service).

## HRR-AUTH-002 — Effective Scope

```text
Effective Scope
= Authorized Scope
∩ Requested Filter
```

Never union.

## HRR-AUTH-003 — View vs Export

`VIEW` dan `EXPORT` adalah permission berbeda.

Export memerlukan view authorization + export authorization + sensitivity policy.

## HRR-AUTH-004 — Aggregate vs Detail

Hak melihat aggregate tidak otomatis memberi hak melihat underlying individual record.

Drill-down melakukan authorization ulang terhadap owning domain.

## HRR-AUTH-005 — Capability Projection Is UX Only

Core capability projection dapat menentukan menu/button visibility, tetapi backend tetap melakukan authorization setiap request.

## HRR-AUTH-006 — Superadmin Semantics

HR Reporting mengikuti semantics Core existing dan tidak membuat superadmin bypass sendiri.

---

# 16. Permission Taxonomy

Baseline permissions:

```text
hr.reporting.workforce.view
hr.reporting.recruitment.view
hr.reporting.leave.view
hr.reporting.attendance.view
hr.reporting.compensation.view
hr.reporting.performance.view
hr.reporting.competency.view
hr.reporting.documents.view
hr.reporting.discipline.view
hr.reporting.offboarding.view
```

Export mengikuti:

```text
hr.reporting.<area>.export
```

Government export dapat berkembang menjadi target-specific permission seperti:

```text
hr.reporting.dapodik.export
hr.reporting.emis-gtk.export
```

Role name tidak di-hardcode dalam reporting. Role → Permission tetap Core concern.

---

# 17. Privacy Classification

| Level | Example |
|---|---|
| S1 — Standard | Headcount aggregate |
| S2 — Controlled | Recruitment/leave/attendance aggregate |
| S3 — Restricted | Individual performance/competency/HR metadata |
| S4 — Highly Restricted | Compensation, discipline, document content, exit interview, government export |

## HRR-PRIV-001 — Least Disclosure

Hanya field yang diperlukan use case yang boleh dikembalikan.

## HRR-PRIV-002 — Small Cohort Protection

Sensitive aggregate harus mendukung suppression/generalization ketika cohort terlalu kecil.

Exact privacy threshold tetap **OPEN DECISION**.

Suppressed value tidak boleh dikonversi menjadi `0`.

## HRR-PRIV-003 — Backend Data Minimization

Unauthorized sensitive fields tidak boleh dikirim ke client lalu sekadar disembunyikan frontend.

## HRR-PRIV-004 — Export Privacy

Export membutuhkan explicit permission, authorized scope, private storage, audit evidence, dan retention policy.

## HRR-PRIV-005 — No Existence Oracle

Unauthorized fields tidak boleh bisa ditemukan melalui search/sort/filter/autocomplete/count side channel.

---

# 18. Dashboard Information Architecture

Conceptual navigation:

```text
HR
├── Overview
├── Workforce
├── Recruitment
├── Leave & Permit
├── Attendance
├── Compensation
├── Performance
├── Competency & Development
├── Documents & Contracts
├── Discipline
├── Offboarding
└── Exports
```

Menu disusun capability-driven, bukan static universal menu.

`HR Overview` merupakan composition dari area permissions, bukan permission universal yang membuka semua metric.

---

# 19. UI / Response States

Dashboard/report harus membedakan:

- `AUTHENTICATION_REQUIRED`;
- `AUTHORIZATION_DENIED`;
- `ORGANIZATIONAL_CONTEXT_REQUIRED`;
- `ORGANIZATIONAL_CONTEXT_DENIED`;
- `EMPTY`;
- `FILTER_EMPTY`;
- `SOURCE_UNAVAILABLE`;
- `STALE`;
- `FAILED`;
- `SUPPRESSED`;
- `NO_DATA`.

`0` hanya berarti nilai valid bernilai nol. `0` tidak boleh digunakan sebagai substitute untuk unavailable/suppressed/failed.

---

# 20. Freshness Model

### LIVE

```text
freshness_mode = LIVE
generated_at = T
```

### PROJECTED

```text
freshness_mode = PROJECTED
source_as_of = T1
generated_at = T2
projection_state = READY | STALE | FAILED
```

### FROZEN

```text
freshness_mode = FROZEN
source_as_of = T
definition_version = V
```

Freshness SLA ditetapkan per projection/use case, bukan satu nilai global.

---

# 21. Government Export Boundary

## 21.1 External Target Classification

| Target | EduCore Classification |
|---|---|
| Dapodik | ACTIVE TARGET |
| EMIS / EMIS GTK | ACTIVE / PRIMARY KEMENAG TARGET |
| Simpatika | LEGACY / DEPRECATED AS NEW TARGET |

External verification baseline 2026:

- Portal resmi Dapodik masih mempublikasikan Dapodik 2026/2026.c dan workflow aplikasi/sinkronisasi.
- Surat Ditjen Pendidikan Islam No. B-16/DJ.I/Dt.I.II/HM.00/01/2025 tanggal 10 Januari 2025 menyatakan EMIS 4.0 GTK Madrasah dirilis sebagai pengganti Simpatika.
- Implementasi/pemutakhiran EMIS GTK terus dilakukan pada 2026.

**RESOURCE GAP:** belum ada authoritative public external write-API contract yang cukup untuk mengunci direct EduCore → Dapodik/EMIS GTK synchronization.

## GOV-ARCH-001 — Adapter-Based Export

```text
Canonical EduCore Domains
        ↓
Government Dataset Builder
        ↓
Frozen Dataset
        ↓
External Validation
        ↓
Versioned Target Adapter
        ↓
Private Export Artifact
```

## GOV-BR-001 — External Schema Does Not Own EduCore Model

External codes/fields dipetakan melalui versioned mapping. Jangan menambah shortcut canonical seperti `dapodik_status` atau `emis_gtk_category` ke Employee hanya karena target eksternal membutuhkannya.

## GOV-BR-002 — Export Is Derived Representation

Perubahan kode pemerintah tidak otomatis mengubah canonical HR concepts.

## GOV-BR-003 — Frozen Dataset

Export yang sudah dibuat tidak berubah ketika canonical source berubah kemudian. Data baru menghasilkan Export Run baru.

## GOV-BR-004 — No Silent Mapping Fallback

Unmapped mandatory values menghasilkan validation error kecuali specification resmi memang mendefinisikan fallback.

## GOV-BR-005 — External Readiness Is Not Canonical Validity

Employee dapat valid di EduCore tetapi belum siap untuk target government tertentu.

---

# 22. Government Integration Maturity

| Level | Capability | Status |
|---|---|---|
| 0 | Compliance preview | Allowed |
| 1 | Validated export package | **BASELINE** |
| 2 | Assisted submission/import | DEFER until official format verified |
| 3 | Direct integration/API | DEFER until official contract verified |

Tidak boleh membangun unofficial browser automation, private endpoint reverse engineering, atau direct DB modification terhadap government system.

---

# 23. Government Export Lifecycle

Conceptual model:

```text
GovernmentExportDefinition
        ↓
GovernmentExportRun
        ├── Frozen Dataset
        ├── Validation Results
        ├── Definition Version
        ├── Mapping Version
        └── Artifact
```

Candidate run states:

```text
REQUESTED
BUILDING
VALIDATION_FAILED
READY
EXPORTED
FAILED
```

`SUBMITTED`, `ACCEPTED`, dan `REJECTED` hanya boleh ditambahkan jika ada authoritative submission/acknowledgement mechanism.

`Generated ≠ Submitted ≠ Accepted`.

---

# 24. Government Mapping Semantics

Mapping result:

- `MAPPED`;
- `UNMAPPED`;
- `NOT_APPLICABLE`.

External version harus explicit, misalnya conceptual `DAPODIK_GTK_2026_2027_V1` atau `EMIS_GTK_2026_V1`. Nama final mengikuti authoritative specification saat tersedia.

External identifier tidak menggantikan EduCore primary/canonical identity.

**OPEN DECISION:** external identifier registry sebaiknya HR-specific atau generic Core/platform registry. Keputusan ditunda karena government identity juga dapat berlaku pada Student/Organization/domain lain.

---

# 25. Auditability

## HRR-NFR-010 — Evidence Layers

Pisahkan:

1. transactional lifecycle evidence;
2. Core Audit;
3. operational/application log.

Core Audit yang fail-open tidak boleh menjadi satu-satunya evidence untuk export lifecycle.

## HRR-AUD-001 — Export Run as Transactional Evidence

Setiap sensitive/government export mempunyai persistent run record.

Conceptual metadata:

```text
export_run_id
tenant_id
target/report_type
effective_scope
requested_by
definition_version
mapping_version
source_as_of
requested_at
generated_at
status
artifact_reference
```

Core Audit mencatat event supplemental seperti requested/generated/downloaded/failed.

---

# 26. Queue & Async Privacy

## HRR-PRIV-011 — Identifier-Only Sensitive Job Payload

Reporting/export jobs tidak boleh membawa raw HR dataset pada serialized queue payload.

Preferred:

```text
tenant_id
operator_id
export_run_id
projection_run_id
```

Job membaca authorized/persisted run definition setelah execution dimulai.

Hal ini penting karena generic failed-job/watchdog infrastructure dapat merekam payload pada audit/diagnostic context.

## HRR-NFR-011 — After-Commit Dispatch

Jika job bergantung pada source/run state yang baru dibuat dalam DB transaction, execution harus dimulai setelah source transaction committed.

---

# 27. Storage & Artifact Security

## HRR-NFR-017

Sensitive HR export menggunakan **private storage**.

Artifact reference/URL/token hanya locator, bukan authorization.

Download melakukan server-side authorization terhadap actor, tenant, export permission, effective scope, policy, dan artifact state.

Jika artifact sudah expired tetapi run history masih retained, UI dapat menunjukkan `EXPIRED`; regeneration membuat run baru.

---

# 28. Logging & Telemetry

## HRR-NFR-018

Operational log tidak boleh menyimpan:

- full report response;
- export contents;
- salary;
- bank detail;
- discipline narrative;
- document contents;
- raw sensitive request payload.

Allowed operational metadata dapat mencakup:

```text
request_id
tenant_id
report_type
export_run_id
projection_type
duration
result_count
status
error_code
```

Operational telemetry groups:

- request count/latency/failure;
- projection freshness/build/rebuild;
- export queue/generation/failure;
- security denials/export attempts.

Telemetry tidak boleh membawa PII yang tidak diperlukan.

---

# 29. Performance Strategy

## HRR-PERF-001

Urutan optimasi:

```text
Correct Query
→ Appropriate Index
→ Measure
→ Optimize Query
→ Projection if justified
→ Cache if justified
```

Tidak diperkenalkan Redis/materialization hanya karena tersedia secara teknis.

## HRR-NFR-019

Interactive list/detail query harus bounded dengan pagination, date range, organizational scope, atau report-specific constraints.

Exact limits tetap OPEN DECISION berdasarkan profiling.

## HRR-PERF-002

Cache hanya ditambahkan setelah profiling. Jika digunakan, cache key wajib mencakup tenant, authorized scope, metric/report, definition version, dan filter hash.

Cache harus disposable dan tidak menjadi authority.

---

# 30. Failure & Recovery

## HRR-NFR-013

Stale/failure tidak boleh diubah menjadi zero/current data.

## HRR-NFR-014

Persisted projection harus rebuildable dan reconcilable.

## HRR-NFR-015

Retry harus membedakan transient infrastructure failure dari validation/business failure.

Candidate errors:

```text
AUTHORIZATION_FAILED
SOURCE_UNAVAILABLE
SOURCE_DATA_INVALID
PROJECTION_STALE
PROJECTION_FAILED
EXPORT_VALIDATION_FAILED
EXPORT_GENERATION_FAILED
ARTIFACT_UNAVAILABLE
INTERNAL_REPORTING_ERROR
```

Government adapters dapat menambah target-specific errors.

## HRR-NFR-016

Reporting failure tidak boleh membatalkan canonical HR operation.

---

# 31. Deployment & Rollback

Versioned read-model/metric deployment sebaiknya menggunakan pattern:

```text
V1 active
→ deploy V2 builder
→ build/reconcile V2
→ switch reader
→ retire V1 when safe
```

Jika metric v2 bermasalah dan compatibility memungkinkan, reader dapat rollback ke v1. Historical export tetap merujuk version yang digunakan saat generation.

---

# 32. Security Gap in Existing HR API

## [RISK — HIGH]

Pada repository baseline, current HR Employee routes masih terutama tenant-scoped dan belum memiliki explicit HR permission + organizational-scope enforcement yang sebanding dengan target architecture.

Classification:

```text
Existing HR API authorization
→ REFACTOR / EXTEND
```

Target conceptual:

```text
Authentication
+ Tenant Context
+ Permission
+ Appropriate Organizational Scope
```

Khusus employee mutation, gap ini harus diprioritaskan sebelum broader production exposure.

---

# 33. Repository Conflicts / Technical Debt

## [CONFLICT] SQLite vs PostgreSQL

`.env.example` masih pernah teridentifikasi default `sqlite`, sementara migration terbaru menggunakan PostgreSQL-specific integrity semantics.

Recommendation: perbaiki environment/documentation baseline; jangan menurunkan integrity schema hanya agar kompatibel dengan stale SQLite config.

## [CONFLICT] Filename Casing

Terdapat casing mismatch pada beberapa file Git/filesystem. Ini dapat menimbulkan masalah pada Linux/CI case-sensitive filesystem.

Recommendation: perbaiki repository hygiene sebelum CI/CD hardening.

---

# 34. Open Decisions

Item berikut tetap terbuka dan **tidak boleh berubah menjadi fakta tanpa authority**:

## Reporting

1. exact dashboard latency SLA;
2. exact freshness SLA per projection;
3. projection refresh schedule;
4. sync-vs-async export threshold;
5. page-size/max-date-range;
6. cache TTL jika diperlukan.

## Privacy & Retention

7. privacy minimum cohort threshold;
8. exact masking format;
9. production privacy/retention policy;
10. audit/log retention;
11. export artifact retention;
12. backup retention.

## Government Integration

13. exact Dapodik field mapping;
14. exact EMIS GTK field mapping;
15. official import formats;
16. external identifier registry ownership;
17. government credential model;
18. direct submission workflow;
19. acknowledgement/reconciliation contract.

## Operations

20. production storage provider;
21. centralized logging/monitoring provider;
22. dedicated reporting queue;
23. incident escalation policy.

Open items dari HR-001–HR-008 tetap berlaku kecuali secara eksplisit ditutup oleh authority baru.

---

# 35. Acceptance Criteria

## HRR-AC-001 — Headcount

**Given** Employee mempunyai ACTIVE Employment pada tanggal report  
**When** authorized user membuka active headcount  
**Then** Employee dihitung satu kali pada organizational scope yang berlaku.

## HRR-AC-002 — Historical Organization

**Given** Employee berpindah Unit A ke Unit B  
**When** report diminta untuk tanggal sebelum perpindahan  
**Then** Employee diklasifikasikan pada Unit A.

## HRR-AC-003 — Authorization Scope

**Given** user hanya authorized untuk Unit A  
**When** user meminta consolidated workforce report  
**Then** response tidak mengandung data Unit B.

## HRR-AC-004 — Attendance Authority

**Given** raw attendance event ada tetapi Attendance Record belum final  
**When** HR attendance reporting dibuat  
**Then** raw event tidak dianggap final attendance status.

## HRR-AC-005 — Compensation Boundary

**Given** HR menyediakan compensation/payroll-input facts  
**When** dashboard mengagregasi facts tersebut  
**Then** hasil tidak diberi label gross/net payroll tanpa Finance authority.

## HRR-AC-006 — Performance Framework

**Given** dua assessments memakai rating scales yang tidak equivalent  
**When** performance summary dibuat  
**Then** combined average tidak dihasilkan tanpa explicit normalization rule.

## HRR-AC-007 — Drill-Down

**Given** user dapat melihat aggregate  
**When** user meminta detail  
**Then** source-domain authorization dievaluasi ulang sebelum detail ditampilkan.

## HRR-AC-008 — Stale Projection

**Given** projected data melewati freshness policy  
**When** report tetap dapat disajikan  
**Then** response menunjukkan status STALE dan `source_as_of`.

## HRR-AC-009 — Sensitive Export

**Given** user mempunyai view permission tetapi tidak export permission  
**When** user meminta sensitive export  
**Then** request ditolak.

## HRR-AC-010 — Export Reproducibility

**Given** export run sudah READY berdasarkan source state pada T  
**When** canonical data berubah setelah T  
**Then** artifact/run lama tetap merepresentasikan frozen state T.

## HRR-AC-011 — Mapping Error

**Given** mandatory government field tidak mempunyai mapping  
**When** export divalidasi  
**Then** export menjadi validation failure, bukan silent fallback.

## HRR-AC-012 — Projection Recovery

**Given** persisted projection hilang atau corrupt  
**When** full rebuild dijalankan dari canonical source/version yang sama  
**Then** equivalent reporting result dapat dibangun kembali.

---

# 36. Traceability Matrix

| Business Need | Requirement / Decision | Source | Delivery |
|---|---|---|---|
| Workforce visibility | HRR-FR-001 / KPI-001 | HR Employment + Core Organization | Workforce dashboard |
| Unit reporting | HRR-AUTH-002 | Core OrganizationalAssignment | Scoped report |
| Recruitment visibility | KPI-010/011 | HR Recruitment | Funnel |
| Leave visibility | KPI-020–023 | HR Leave | Leave report |
| Attendance | KPI-030/031 | Attendance | Summary when available |
| Compensation facts | KPI-040–042 | HR | Restricted reporting |
| Payroll result | Not HR-owned | Finance | Future Finance contract |
| Performance | KPI-050/051 | HR Performance | Framework-aware report |
| Contract expiry | KPI-060/061 | HR Contract/Document | Expiry/status |
| Discipline | KPI-070/071 | HR Discipline | Restricted report |
| Offboarding | KPI-080–082 | HR Offboarding | Lifecycle report |
| Dapodik | GOV-ARCH-001 | Multi-domain projection | Frozen export |
| EMIS GTK | GOV-ARCH-001 | Multi-domain projection | Frozen export |
| Export evidence | HRR-AUD-001 | Reporting lifecycle | ExportRun + audit |
| Privacy | HRR-PRIV-* | Core auth + source domains | Minimum disclosure |
| Freshness | HRR-NFR-012–014 | Reporting | LIVE/PROJECTED/FROZEN |

---

# 37. Architecture Classification

| Area | Decision |
|---|---|
| `Modules/HR` | EXTEND |
| HR Reporting capability | ADD inside HR when implemented |
| Core RBAC | KEEP / REUSE |
| Core Organizational Context | KEEP / REUSE |
| Core tenant-aware jobs | KEEP / REUSE |
| Core Audit | KEEP as supplemental |
| Existing HR route authorization | REFACTOR / EXTEND |
| Generic Reporting module | DEFER |
| Universal metric/EAV table | DO NOT INTRODUCE |
| Data warehouse | DEFER |
| Direct Dapodik API | DEFER |
| Direct EMIS GTK API | DEFER |
| New Simpatika integration | DEPRECATE / DO NOT BUILD |
| Government Integration Gateway | FUTURE SCOPE |
| Redis/reporting cache | DEFER UNTIL PROFILED |
| New auth framework inside HR | DO NOT INTRODUCE |

---

# 38. Final Locked Decision Set

1. HR Reporting tetap capability di `Modules/HR`.
2. Reporting bukan source of truth.
3. Direct-query-first.
4. Projection hanya jika justified oleh complexity/performance.
5. Persisted projection rebuildable dan reconcilable.
6. Tidak membuat universal generic metric/EAV table.
7. Historical report memakai effective temporal state.
8. Cross-domain reads menghormati owner/read contract.
9. Authorization = permission + tenant + scope + sensitivity.
10. Position bukan authorization source.
11. Aggregate access tidak otomatis memberi detail access.
12. View dan export permission berbeda.
13. Sensitive aggregates membutuhkan privacy protection.
14. Capability projection hanya UX support.
15. Government schema tidak menentukan canonical EduCore schema.
16. Dapodik dan EMIS/EMIS GTK menggunakan versioned export/adapter boundary.
17. Simpatika tidak menjadi target integrasi baru.
18. Direct government sync memerlukan official contract dan formal change decision.
19. Export memakai frozen dataset.
20. Export lifecycle mempunyai transactional evidence.
21. Core Audit bersifat supplemental.
22. Sensitive queue jobs membawa identifier, bukan raw dataset.
23. HR export menggunakan private storage.
24. Freshness explicit: LIVE / PROJECTED / FROZEN.
25. Reporting failure tidak boleh mempengaruhi validity canonical HR transaction.

---

# 39. External Verification References

External system status di atas diverifikasi terhadap sumber resmi pada 2026-08-22:

1. **Portal Dapodik — Berita/Rilis Aplikasi Dapodik 2026/2026.c**, Direktorat terkait Kemendikdasmen: `https://dapo.kemendikdasmen.go.id/berita`
2. **Surat Ditjen Pendidikan Islam No. B-16/DJ.I/Dt.I.II/HM.00/01/2025, 10 Januari 2025 — Peralihan Aplikasi Simpatika ke EMIS 4.0 GTK Madrasah**: `https://pendis.kemenag.go.id/storage/archives/01JHHYZJDMA4P0EANWJWSY6RF2.pdf`
3. **Kementerian Agama Kota Depok, 30 Juli 2026 — Implementasi EMIS GTK**: `https://depok.kemenag.go.id/kakankemenag-depok-buka-implementasi-emis-gtk-dorong-penguatan-validitas-data-madrasah`

External field-level/API contracts tetap RESOURCE GAP sampai authoritative specification tersedia.

---

# 40. Reviewer Assessment

**Quality Score:** 9.8/10

**Gaps:** remaining items bersifat implementation-specific, external-specification-specific, policy-specific, dan performance-measurement-specific.

**Risks:** current HR route authorization gap; stale SQLite config versus PostgreSQL semantics; filename casing hygiene; queue payload privacy; export retention/privacy; external government format change.

**Recommendations:** gunakan HR-009 sebagai locked architecture/specification baseline; jangan menambah projection/cache/integration baru tanpa traceable requirement dan change-impact review.

**Status:** **APPROVED / LOCKED — PHASE 2H CLOSED**

---

# 41. Continuation Rule

Untuk fase berikutnya:

```text
Resource Audit
→ Current State Delta
→ Conflict / Gap
→ Scope
→ Design / Planning
→ Review
→ Approval
```

Jangan mengulang desain HR-001–HR-009 dari nol. Perubahan terhadap locked decision harus diperlakukan sebagai formal change request dan diperiksa dampaknya terhadap business rules, data, API, authorization, UI, integration, migration, test, deployment, dan documentation.
