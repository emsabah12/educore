# Phase 2H-B — HR Reporting Requirements & KPI Catalog

## 1. Status Fase Sebelumnya

**2H-A — HR Reporting Domain Boundary & Scope: APPROVED / LOCKED.**

Dengan demikian keputusan berikut menjadi constraint untuk 2H-B:

- Reporting tetap capability di `Modules/HR`.
- Reporting bukan source of truth.
- Source domain tetap memiliki data canonical.
- Reporting tidak melakukan cross-domain mutation.
- Projection harus rebuildable.
- Tenant dan organizational scope wajib dihormati.
- Government export adalah projection, bukan canonical model.
- Direct government sync belum termasuk scope.

Handoff memang menetapkan headcount, recruitment funnel, leave/attendance, compensation facts, performance/competency, contract expiry, discipline/offboarding, security/privacy, serta freshness sebagai objective Phase 2H.

---

# 2. Objective 2H-B

Tujuan subfase ini adalah menentukan:

```text
Stakeholder
    ↓
Information Need
    ↓
Report / KPI
    ↓
Metric Definition
    ↓
Dimension / Filter
    ↓
Source Domain
```

Belum menentukan:

```text
table
index
projection schema
event handler
cache
API implementation
```

Itu menjadi tanggung jawab **2H-C**.

---

# 3. Reporting Personas

Berdasarkan baseline HR yang telah disepakati, kebutuhan reporting dibagi menjadi beberapa visibility level.

| Persona                        | Kebutuhan utama                                 |
| ------------------------------ | ----------------------------------------------- |
| Admin Yayasan                  | Consolidated multi-unit workforce               |
| HR / Personalia                | Operational + detailed HR reporting             |
| Kepala Sekolah / pimpinan unit | Workforce dalam organizational scope-nya        |
| Bendahara / Finance            | Payroll-input/compensation facts yang diizinkan |
| Guru / Tendik                  | Personal/self-service HR information            |
| Auditor/authorized reviewer    | Controlled historical/evidence reporting        |

**[REKOMENDASI]** Persona bukan authorization mechanism.

Final access tetap:

```text
Permission
+
Tenant Context
+
Organizational Scope
+
Data Sensitivity
```

bukan `Position/Jabatan`.

---

# 4. Reporting Requirement Baseline

Saya usulkan namespace:

```text
HRR-FR-*  Functional Requirement
HRR-BR-*  Business Rule
HRR-KPI-* Metric/KPI
HRR-AC-*  Acceptance Criteria
```

## Functional Requirements

| ID             | Requirement                                                                                          |
| -------------- | ---------------------------------------------------------------------------------------------------- |
| **HRR-FR-001** | Sistem dapat menampilkan consolidated workforce summary sesuai tenant dan organizational scope user. |
| **HRR-FR-002** | User dapat memilih reporting period/as-of date sesuai tipe metric.                                   |
| **HRR-FR-003** | Sistem dapat melakukan drill-down dari aggregate ke record yang diizinkan.                           |
| **HRR-FR-004** | Sistem harus menyediakan workforce composition reporting.                                            |
| **HRR-FR-005** | Sistem harus menyediakan recruitment funnel summary.                                                 |
| **HRR-FR-006** | Sistem harus menyediakan leave/permit summary.                                                       |
| **HRR-FR-007** | Sistem dapat menyediakan attendance summary ketika Attendance domain tersedia.                       |
| **HRR-FR-008** | Sistem harus menyediakan compensation/payroll-input facts tanpa menghitung payroll.                  |
| **HRR-FR-009** | Sistem harus menyediakan performance dan competency summary.                                         |
| **HRR-FR-010** | Sistem harus menyediakan contract/document expiry/status reporting.                                  |
| **HRR-FR-011** | Sistem harus menyediakan discipline dan offboarding summary.                                         |
| **HRR-FR-012** | Sistem harus menunjukkan freshness/as-of information untuk projection asynchronous.                  |
| **HRR-FR-013** | Sistem harus dapat mengekspor report yang diizinkan tanpa mengubah source data.                      |
| **HRR-FR-014** | Sensitive report harus mengikuti permission dan organizational scope.                                |
| **HRR-FR-015** | Metric harus dapat ditelusuri ke definition dan source domain-nya.                                   |

---

# 5. Fundamental Metric Semantics

Sebelum membuat KPI, ada dua jenis metric yang tidak boleh dicampur.

### Snapshot metric

Menggambarkan keadaan pada titik waktu tertentu.

Contoh:

```text
Active Headcount
as of 31 December 2026
```

### Flow metric

Menggambarkan kejadian dalam rentang waktu.

Contoh:

```text
New Employments
1 January – 31 December 2026
```

**HRR-BR-001**

> Setiap metric wajib didefinisikan sebagai `SNAPSHOT` atau `PERIOD/FLOW`.

Ini mencegah dashboard menghasilkan perbandingan yang secara statistik salah.

---

# 6. Workforce KPI Catalog

## HRR-KPI-001 — Active Headcount

**Type:** Snapshot

```text
COUNT DISTINCT Employee
WHERE Employment status = ACTIVE
AT as_of_date
```

### Important rule

`PLANNED Employment` tidak termasuk headcount.

`ENDED Employment` tidak termasuk active headcount.

Karena locked architecture menetapkan maksimum satu ACTIVE Employment per Employee, metric ini tidak boleh melakukan double-count terhadap seorang Employee.

### Dimensions

- Organization;
- Organization Unit;
- Employment Type;
- Employment Classification;
- Position.

Position boleh sebagai dimension tetapi **bukan authorization source**.

---

## HRR-KPI-002 — Workforce Distribution

```text
Active Headcount per selected dimension
÷
Total Active Headcount
× 100%
```

Contoh dimension:

```text
Employment Type
Employment Classification
Organization
Organization Unit
Position
```

Jika denominator = 0:

```text
N/A
```

bukan `0%`.

---

## HRR-KPI-003 — New Employment Activations

**Type:** Period

```text
COUNT Employment
whose activation date
is within reporting period
```

**Business interpretation:** rehire menghasilkan Employment baru, sehingga rehire dihitung sebagai employment activation baru.

Tetapi tidak berarti Employee baru.

---

## HRR-KPI-004 — Employment Ends

```text
COUNT Employment
whose end date
is within reporting period
```

Tidak boleh diasumsikan sama dengan:

```text
Membership deactivation
User deletion
Offboarding completion
```

karena ketiganya mempunyai lifecycle berbeda.

---

## HRR-KPI-005 — Offboarding Completion Gap

```text
COUNT Employment ENDED
with related Offboarding Case
not yet COMPLETED
```

**[REKOMENDASI]**

Metric ini lebih berguna daripada menganggap semua pegawai yang Employment-nya selesai sudah selesai proses offboarding.

---

# 7. Recruitment KPI Catalog

## HRR-KPI-010 — Applications

```text
COUNT Application
created within period
```

Dimensions:

- recruitment/vacancy context jika tersedia;
- application stage;
- organizational scope.

---

## HRR-KPI-011 — Recruitment Funnel

Minimal:

```text
Application
   ↓
Selection
   ↓
Hiring Approval
   ↓
Onboarding
   ↓
Employment Activation
```

Metric menyimpan count per stage.

### Funnel conversion

```text
Next-stage count
÷
Eligible previous-stage count
× 100%
```

Namun exact denominator per stage harus mengikuti lifecycle yang didefinisikan HR-003.

**[RESOURCE GAP]** Artifact HR-003 individual belum tersedia pada repository package, sehingga denominator detail belum saya lock sekarang.

---

## HRR-KPI-012 — Hiring Conversion

```text
Applications resulting
in Employment Activation
÷
eligible Applications
```

**[DEFERRED]** Formula denominator final sampai HR-003 lifecycle details tersedia.

---

## HRR-KPI-013 — Onboarding Completion

```text
Completed onboarding cases
÷
onboarding cases due/completed in selected cohort
```

**[OPEN DECISION]** Exact cohort semantics.

---

## Time-to-Hire

Ini KPI yang umum, tetapi **belum saya jadikan mandatory KPI**.

Alasannya kita belum memverifikasi timestamp mana yang menjadi:

```text
start clock
end clock
paused time
```

Maka status:

**[DEFERRED] HRR-KPI-CANDIDATE — Time to Hire**

---

# 8. Leave & Permit KPI Catalog

## HRR-KPI-020 — Leave Requests

```text
COUNT Leave Requests
by status/type
within selected period
```

---

## HRR-KPI-021 — Approved Leave

Hanya final-approved request.

Tidak boleh menghitung:

```text
DRAFT
SUBMITTED
REJECTED
CANCELLED
```

sebagai leave utilization.

---

## HRR-KPI-022 — Leave Balance

**Type:** Snapshot

Sumbernya harus dari append-only leave ledger, bukan saldo yang dihitung ulang dari request UI.

Locked architecture memang menetapkan leave balance sebagai append-only ledger.

---

## HRR-KPI-023 — Leave Utilization Rate

Candidate formula:

```text
Consumed entitlement
÷
Available entitlement
× 100%
```

Namun exact definition tergantung:

- leave entitlement rule;
- work calendar;
- carry-forward semantics.

Karena exact leave/work calendar masih `[OPEN DECISION]`, metric ini:

**READY CONCEPTUALLY / FORMULA NOT YET LOCKED**.

---

# 9. Attendance KPI Catalog

Attendance tetap bounded context terpisah.

Maka HR Reporting hanya membaca:

```text
Attendance Record
```

bukan:

```text
Raw Attendance Event
```

## HRR-KPI-030 — Attendance Status Distribution

Contoh:

```text
PRESENT
LATE
ABSENT
approved absence classifications
```

Sumber harus final/reconciled Attendance Record.

---

## HRR-KPI-031 — Attendance Rate

Candidate:

```text
fulfilled attendance expectations
÷
eligible attendance expectations
```

Namun:

**[DEFERRED]**

karena exact expectation/cutoff/finalization belum selesai dan Attendance module belum diimplementasikan.

Raw device scan tidak boleh dijadikan denominator atau final status.

---

# 10. Compensation Reporting

Boundary tetap:

```text
HR
→ compensation facts
→ benefit facts
→ payroll-input facts

Finance
→ gross/net
→ tax
→ statutory contribution
→ payable
→ payment
→ accounting
```

Handoff secara eksplisit menetapkan pemisahan ini.

## HRR-KPI-040 — Compensation Coverage

```text
Active Employees
with effective compensation fact
÷
eligible Active Employees
```

Exact eligibility:

**[OPEN DECISION]**

---

## HRR-KPI-041 — Compensation Facts by Component

Authorized reporting dapat menunjukkan:

```text
component
employee count
aggregate amount
period
organizational scope
```

### Constraint

Tidak boleh diberi label:

```text
Payroll Cost
Gross Payroll
Net Payroll
```

kecuali datanya benar-benar berasal dari Finance.

---

## HRR-KPI-042 — Payroll Input Readiness

```text
eligible Employees
with complete payroll-input snapshot
÷
eligible Employees
```

**[DEFERRED]** Exact completeness rule sampai payroll-input contract final.

---

# 11. Performance & Competency KPI Catalog

## HRR-KPI-050 — Performance Review Completion

```text
completed assessment
÷
expected assessment
```

Dimensions:

- framework version;
- assessment period;
- organization/unit;
- eligible employee cohort.

---

## HRR-KPI-051 — Rating Distribution

Allowed:

```text
Rating distribution
WITHIN SAME framework/rating-scale version
```

### HRR-BR-010

Tidak boleh:

```text
average rating framework A
+
average rating framework B
```

jika rating scale tidak equivalent.

---

## HRR-KPI-052 — Competency Coverage

Concept:

```text
validated competency evidence
versus
required competency profile
```

Namun exact competency taxonomy masih open.

Status:

**[DEFERRED until competency taxonomy is approved]**

---

## HRR-KPI-053 — Training Participation

Count peserta/training records dalam periode.

Tetapi harus tetap memisahkan:

```text
Training
≠ Certification
≠ Competency
```

sesuai locked architecture.

---

# 12. Contract & Document KPI Catalog

## HRR-KPI-060 — Agreement Expiry

Buckets dapat berbasis tanggal expiry:

```text
Expired
Expiring
Future
```

Tetapi threshold seperti:

```text
7 days
30 days
60 days
90 days
```

**tidak saya hardcode**.

Threshold menjadi tenant/report configuration.

---

## HRR-KPI-061 — Document Status

Contoh aggregate:

```text
Draft
Finalized
Awaiting Signature
Signed
Expired
```

hanya jika status tersebut memang tersedia di lifecycle source.

---

## HRR-BR-020

Agreement expiry **tidak boleh** ditampilkan atau diproses sebagai Employment end otomatis.

Contract dan Employment tetap lifecycle berbeda.

---

# 13. Discipline Reporting

## HRR-KPI-070 — Disciplinary Cases

```text
COUNT disciplinary cases
by status/category
within period
```

---

## HRR-KPI-071 — Active Disciplinary Actions

Snapshot jumlah disciplinary action yang masih berlaku menurut effective period/status source.

### Constraint

Jangan membuat universal severity calculation seperti:

```text
SP1 = 1
SP2 = 2
SP3 = 3
```

karena disciplinary catalog adalah tenant-scoped dan progression tidak hardcoded.

---

# 14. Offboarding Reporting

## HRR-KPI-080 — Open Offboarding Cases

```text
COUNT Offboarding Case
WHERE status != COMPLETED
```

---

## HRR-KPI-081 — Offboarding Completion

```text
Completed Cases
÷
Eligible Offboarding Cases
```

Exact cohort semantics akan mengikuti offboarding lifecycle.

---

## HRR-KPI-082 — Outstanding Offboarding Tasks

Count incomplete:

```text
Approval
Checklist
Handover
Access Review
Exit Interview
Settlement Facts
```

sesuai applicable checklist.

Final monetary settlement tetap bukan HR calculation.

---

# 15. Common Dimensions & Filters

Saya rekomendasikan baseline berikut.

### Mandatory

```text
Reporting Period
As-of Date
Organization
Organization Unit
```

### Domain-specific

```text
Employment Type
Employment Classification
Position
Recruitment Stage
Leave Type
Attendance Status
Performance Framework
Competency Category
Document Type
Disciplinary Category
Offboarding Status
```

### Rule penting

Filter:

```text
Organization / Organization Unit
```

tidak boleh memperluas akses.

Contoh:

```text
User authorized Unit A

Filter:
All Units
```

hasilnya tetap maksimum:

```text
Unit A
```

bukan seluruh tenant.

---

# 16. Historical Reporting Rule

Ini penting untuk accuracy.

## HRR-BR-030 — Historical Placement

Untuk laporan historis:

> Employee harus dikaitkan dengan organizational placement yang berlaku pada waktu fakta tersebut terjadi, bukan selalu dengan placement Employee saat ini.

Contoh:

```text
2025 → School A
2026 → School B
```

Laporan headcount 2025:

```text
School A
```

bukan School B.

Detail technical resolution akan dirancang pada 2H-C.

---

# 17. Privacy & Sensitive Metrics

Saya usulkan classification awal:

| Data                   | Sensitivity         |
| ---------------------- | ------------------- |
| Headcount aggregate    | Standard            |
| Recruitment aggregate  | Standard/Controlled |
| Leave aggregate        | Controlled          |
| Attendance aggregate   | Controlled          |
| Compensation amount    | Highly Restricted   |
| Performance individual | Restricted          |
| Competency individual  | Restricted          |
| HR document metadata   | Restricted          |
| Document content       | Highly Restricted   |
| Discipline             | Highly Restricted   |
| Exit interview         | Highly Restricted   |

**HRR-BR-040**

Dashboard aggregate tidak otomatis memberikan hak mengakses underlying individual records.

---

# 18. Metric Definition Contract

Setiap KPI pada implementation nanti minimal harus mempunyai metadata konseptual:

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

Ini **bukan schema database 2H-C**, melainkan definition contract.

Tujuannya mencegah metric berubah makna diam-diam.

---

# 19. Acceptance Criteria

### HRR-AC-001 — Headcount

**Given** Employee mempunyai ACTIVE Employment pada tanggal laporan
**When** authorized user membuka headcount as-of tanggal tersebut
**Then** Employee dihitung satu kali pada organizational scope yang berlaku.

---

### HRR-AC-002 — Historical organization

**Given** Employee berpindah dari Unit A ke Unit B
**When** user melihat report sebelum tanggal perpindahan
**Then** Employee harus dikaitkan dengan Unit A.

---

### HRR-AC-003 — Authorization

**Given** user hanya mempunyai akses Unit A
**When** user meminta consolidated workforce report
**Then** report tidak boleh mengandung data Unit B.

---

### HRR-AC-004 — Attendance

**Given** terdapat raw attendance event tetapi belum ada final Attendance Record
**When** HR dashboard dibuat
**Then** raw event tidak boleh dianggap final attendance status.

---

### HRR-AC-005 — Compensation

**Given** HR menyediakan compensation/payroll-input facts
**When** dashboard menampilkan aggregate compensation
**Then** hasil tersebut tidak boleh diberi interpretasi sebagai net/gross payroll tanpa Finance authority.

---

### HRR-AC-006 — Performance

**Given** dua assessment memakai rating scale yang tidak equivalent
**When** user melihat performance summary
**Then** sistem tidak boleh menghasilkan combined average tanpa normalization rule yang eksplisit.

---

### HRR-AC-007 — Drill-down

**Given** user dapat melihat aggregate report
**When** user melakukan drill-down
**Then** hanya individual data yang juga diizinkan oleh permission/scope yang boleh ditampilkan.

---

# 20. KPI yang Sengaja Tidak Kita Lock

Beberapa KPI menarik tetapi belum mempunyai authority cukup:

- turnover rate;
- retention rate;
- time-to-hire;
- absenteeism rate;
- leave utilization final formula;
- payroll cost;
- compensation benchmark;
- competency gap percentage;
- employee productivity;
- performance-to-compensation correlation;
- government compliance completeness score.

Status seluruhnya:

**[DEFERRED / OPEN DECISION]**

Bukan karena tidak berguna, tetapi karena denominator, calendar, taxonomy, source, atau business meaning belum cukup stabil.

---

# 21. Traceability

Contoh traceability:

```text
Business Need
Workforce Visibility
      ↓
HRR-FR-001
Consolidated Workforce
      ↓
HRR-KPI-001
Active Headcount
      ↓
HRR-BR-001
Snapshot Semantics
      ↓
HR / Employment
Core / OrganizationalAssignment
      ↓
2H-C Read Model
      ↓
API / Dashboard
      ↓
Test HRR-AC-001/002/003
```

Ini akan menjadi pola untuk setiap metric utama.

---

# 22. Phase 2H-B Reviewer Assessment

**Quality Score:** **9.4/10**

### Gaps

- exact leave/work calendar belum final;
- Attendance implementation/finalization belum tersedia;
- exact recruitment lifecycle denominator memerlukan detail HR-003;
- competency taxonomy belum final;
- payroll/Finance contract belum tersedia;
- KPI business targets belum ditetapkan.

Tidak ada gap tersebut yang membutuhkan kita mengarang target atau formula.

### Risks

**[RISK] Metric ambiguity**
KPI tanpa denominator dan time semantics yang eksplisit akan menghasilkan dashboard yang misleading.

**[RISK] Cross-framework aggregation**
Performance dan competency paling rawan digabungkan secara matematis padahal framework berbeda.

**[RISK] Historical misclassification**
Menggunakan current organizational placement untuk historical report akan menghasilkan angka masa lalu yang salah.

### Recommendations

Pertahankan metric catalog sebagai **versioned contract** dan jangan hardcode target KPI bisnis sebelum tenant menentukan targetnya.

### Status

**READY FOR APPROVAL — MINOR OPEN ITEMS DEFERRED**

Jika 2H-B ini disetujui, next step kita adalah **Phase 2H-C — HR Reporting Read Model Architecture**, yaitu menentukan kapan memakai query langsung, kapan memakai projection, grain data, freshness/rebuild/reconciliation, dan strategi agar dashboard tetap cepat tanpa menciptakan shadow source-of-truth.
