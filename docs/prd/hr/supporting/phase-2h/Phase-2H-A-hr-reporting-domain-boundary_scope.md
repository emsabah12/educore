# Phase 2H-A — HR Reporting Domain Boundary & Scope

## 1. Project Resource Check

**[FAKTA]** Resource audit menggunakan:

- repository `educore(3).zip`;
- HEAD `26b475b695aa4511064b1410db03d1f0c8bdd6ce`;
- handoff HR-001–HR-008;
- Core Membership/RBAC;
- Core Organization & `OrganizationalAssignment`;
- Core Audit;
- capability/read-projection pattern;
- existing `Modules/HR`;
- Academic integration yang tersedia.

Repository HEAD masih identik dengan baseline handoff, sehingga **tidak ada delta repository yang membatalkan keputusan sebelumnya**.

Handoff menetapkan Phase 2H sebagai kelanjutan resmi setelah HR-008 dan secara eksplisit mensyaratkan reporting **tidak menjadi source of truth baru**.

---

# 2. Current State

Saat ini topology relevannya adalah:

```text
Core
├── Person / Membership
├── Tenant
├── Organization / OrganizationUnit
├── OrganizationalAssignment
├── RBAC / Permission
├── Audit
└── Notification infrastructure

HR
└── Employee foundation [IMPLEMENTED]

HR specification
├── Workforce
├── Recruitment
├── Leave
├── Compensation
├── Performance
├── Competency
├── Documents / Contract
├── Discipline
└── Offboarding
    [SPECIFICATION LOCKED, implementation belum lengkap]

Academic
└── Existing

Attendance
└── Not implemented yet

Finance
└── Not implemented yet

Reporting
└── No generic module currently
```

**[FAKTA]** Repository juga sudah mempunyai pola **read projection** pada Core Authorization. Ini memberi preseden bahwa projection boleh dibuat untuk konsumsi UI, tetapi bukan authority.

---

# 3. Proposed Domain Boundary

## Keputusan utama yang saya rekomendasikan

```text
                    ┌─────────────────────┐
                    │       Core          │
                    │ Identity / Org/RBAC │
                    └─────────┬───────────┘
                              │
                              │ scope/context
                              ▼
┌─────────────┐       ┌─────────────────────┐
│ Attendance  │──────▶│                     │
└─────────────┘       │                     │
                      │   HR REPORTING      │
┌─────────────┐       │   READ CAPABILITY   │
│ Academic    │──────▶│                     │
└─────────────┘       │                     │
                      │                     │
┌─────────────┐       │                     │
│ Finance     │──────▶│                     │
└─────────────┘       └─────────┬───────────┘
                                │
┌─────────────┐                 ├── Dashboard
│ HR Domains  │────────────────▶├── Reports
└─────────────┘                 └── Export
```

### Ownership

**[REKOMENDASI]** HR Reporting **tetap menjadi capability dalam `Modules/HR`**, bukan membuat `Modules/Reporting` baru sekarang.

Alasannya:

1. seluruh use case saat ini spesifik HR;
2. belum ada kebutuhan generic enterprise BI/reporting platform;
3. menghindari generic abstraction terlalu dini;
4. ownership dan security data HR tetap lebih jelas;
5. generic Reporting module dapat diekstrak nanti jika benar-benar ada kebutuhan lintas domain.

Klasifikasi:

| Component                   | Decision                                                          |
| --------------------------- | ----------------------------------------------------------------- |
| `Modules/HR`                | **EXTEND**                                                        |
| Generic `Modules/Reporting` | **DEFER**                                                         |
| Core authorization          | **KEEP / REUSE**                                                  |
| Core organization context   | **KEEP / REUSE**                                                  |
| Core audit                  | **KEEP / REUSE**, dengan tambahan domain evidence bila diperlukan |
| Attendance data ownership   | **KEEP di Attendance**                                            |
| Finance payroll ownership   | **KEEP di Finance**                                               |
| Academic ownership          | **KEEP di Academic**                                              |

---

# 4. Boundary Rules

Saya usulkan ID berikut menjadi baseline traceability Phase 2H.

### HRR-BND-001 — Reporting is Non-Authoritative

Dashboard, report, aggregate, projection, dan export:

> tidak boleh menjadi canonical source of truth.

Contoh:

```text
employee status

SOURCE:
Employment

BUKAN:
hr_dashboard_employee_summary.status
```

---

### HRR-BND-002 — Source Domain Retains Ownership

Setiap metric harus mempunyai source domain yang eksplisit.

| Informasi              | Owner      |
| ---------------------- | ---------- |
| Employee / Employment  | HR         |
| Recruitment            | HR         |
| Leave / Permit         | HR         |
| Compensation fact      | HR         |
| Performance            | HR         |
| Competency             | HR         |
| Contract / HR Document | HR         |
| Discipline             | HR         |
| Offboarding            | HR         |
| Attendance fact        | Attendance |
| Payroll result/payment | Finance    |
| Teaching facts         | Academic   |
| Person/Membership      | Core       |
| Organization/unit      | Core       |

Reporting hanya:

```text
consume
→ derive
→ aggregate
→ present
→ export
```

Tidak mengambil ownership.

---

### HRR-BND-003 — No Cross-Domain Mutation

Reporting tidak boleh melakukan:

```text
UPDATE Employment
UPDATE Attendance
UPDATE Payroll
UPDATE OrganizationalAssignment
```

melalui dashboard/reporting workflow.

Jika user ingin mengubah data:

```text
Report
  ↓
link/navigation
  ↓
Owning Domain Use Case
```

---

### HRR-BND-004 — Projection Must Be Rebuildable

Jika Phase 2H-C nanti memutuskan memakai projection table:

```text
Canonical Data
     ↓
Projection Builder
     ↓
Reporting Read Model
```

maka read model harus dapat:

- dihapus;
- dibangun ulang;
- direkonsiliasi;

tanpa kehilangan canonical business data.

---

### HRR-BND-005 — Tenant Isolation Is Mandatory

Seluruh reporting harus minimal terisolasi berdasarkan:

```text
tenant_id
```

dan tidak boleh menghasilkan cross-tenant leakage.

Tidak diperbolehkan:

```text
GET /hr/reports?tenant_id=<arbitrary-id>
```

sebagai authority utama.

Tenant harus berasal dari verified authenticated context.

---

### HRR-BND-006 — Organizational Scope Is Authorization Boundary

Reporting harus mendukung scope:

```text
Tenant
  ↓
Organization
  ↓
Organization Unit
```

berdasarkan Core organizational context dan permission.

Contoh:

```text
Admin Yayasan
→ tenant-wide

Authorized School Management
→ organization/unit scope

Restricted HR user
→ assigned scope only
```

**Jabatan/Position tidak menjadi sumber authorization.**

---

### HRR-BND-007 — External Export Is a Projection

Government export:

```text
Canonical Domain Data
        ↓
Government Export Mapper
        ↓
Validation
        ↓
Export Artifact
```

bukan:

```text
Government schema
        ↓
menentukan canonical EduCore schema
```

Dengan demikian Dapodik/EMIS/Simpatika tidak boleh menjadi internal HR data model.

Ini sangat penting agar EduCore tidak terikat secara struktural pada format eksternal yang dapat berubah.

---

### HRR-BND-008 — Export ≠ Synchronization

Untuk Phase 2H:

```text
EXPORT
≠
DIRECT SYNC
```

Direct synchronization/API integration hanya boleh ditambahkan apabila interface resmi, authentication contract, rate limits, dan lifecycle-nya sudah diverifikasi.

Tidak akan kita asumsi sekarang.

---

### HRR-BND-009 — Data Freshness Must Be Visible

Jika dashboard memakai asynchronous/snapshot projection, hasil harus dapat menjelaskan freshness-nya.

Minimal konsep:

```text
generated_at
source_as_of
projection_version
```

Detail schema ditentukan pada 2H-C.

Tujuannya agar:

> user tidak menganggap angka snapshot sebagai realtime ketika sebenarnya belum ter-refresh.

---

### HRR-BND-010 — Sensitive Data Must Follow Least Disclosure

Reporting tidak otomatis memperoleh semua detail HR.

Contoh:

```text
Headcount report
```

tidak membutuhkan:

```text
contract file
disciplinary narrative
personal document
bank information
```

Data projection harus hanya mengandung field yang diperlukan oleh use case.

---

# 5. Scope Phase 2H

## IN SCOPE

Phase ini akan mencakup:

### Workforce

- headcount;
- active/inactive employment;
- workforce composition;
- tenant/unit workforce distribution.

### Recruitment

- application/recruitment funnel;
- hiring/onboarding summaries.

### Leave

- leave utilization;
- entitlement/balance summary;
- leave trends.

### Attendance

- attendance summary **jika source Attendance tersedia**.

### Compensation

- compensation/benefit facts;
- payroll-input reporting.

Bukan payroll result.

### Performance & Competency

- assessment summary;
- competency coverage/gap;
- training/certification summary sesuai data yang tersedia.

### Document & Contract

- document status;
- agreement/contract expiry;
- signature status.

### Discipline & Offboarding

- disciplinary summary;
- offboarding status;
- exit lifecycle reporting.

### Government Export Boundary

- export architecture;
- mapping;
- validation;
- auditability;
- versioning;
- failure handling.

---

# 6. OUT OF SCOPE

Tidak termasuk Phase 2H:

- payroll calculation;
- gross/net salary calculation;
- PPh21 calculation;
- BPJS monetary calculation;
- payment processing;
- accounting;
- attendance capture;
- QR/fingerprint/GPS;
- academic scheduling;
- performance appraisal transaction workflow;
- document signing implementation;
- generic enterprise BI platform;
- data warehouse;
- ML/predictive HR analytics;
- direct government synchronization yang belum diverifikasi.

---

# 7. FUTURE SCOPE

Candidate future capability:

```text
Enterprise Reporting / Analytics Platform

HR
Finance
Academic
Admissions
Dormitory
etc.
        ↓
Enterprise Analytics
```

Tetapi **belum justified** untuk dibuat sekarang.

Jika nantinya tiga atau lebih domain memiliki kebutuhan analytics bersama, kita dapat mengevaluasi extraction menjadi bounded capability tersendiri.

---

# 8. DEFERRED ke Subfase Berikutnya

Beberapa hal sengaja **belum kita putuskan sekarang**.

### 2H-B

Kita akan menentukan:

- KPI/report catalog;
- metric definition;
- dimensions;
- filters;
- drill-down;
- stakeholder views.

### 2H-C

Kita akan menentukan:

- on-demand query vs projection;
- read-model schema;
- projection refresh;
- reconciliation;
- indexing;
- freshness SLA.

### 2H-D

Kita akan menentukan:

- dashboard;
- permission matrix;
- tenant/unit visibility;
- sensitive-data masking.

### 2H-E

Kita akan memverifikasi:

- Dapodik;
- EMIS;
- Simpatika;
- official export/API contract;
- data mapping dan versioning.

---

# 9. Resource Gap

### [RESOURCE GAP] Detail HR-002–HR-008

Handoff memberikan keputusan arsitektur yang cukup untuk menetapkan **boundary**, tetapi artifact individual HR-002–HR-008 tidak terdapat di repository saat ini.

Dampaknya:

> kita belum sebaiknya mengarang formula KPI atau field-level mapping hanya dari ringkasan handoff.

Ini bukan blocker 2H-A.

Namun akan penting ketika kita mulai **2H-B KPI Catalog** dan **2H-C Data/Read Model**.

---

# 10. Architecture Principle yang Diusulkan Dikunci

Jika diringkas:

```text
HR Reporting
     │
     ├── reads facts
     ├── derives metrics
     ├── creates rebuildable projections
     ├── serves dashboards
     └── generates exports

BUT NEVER

     ├── owns Person
     ├── owns Organization
     ├── owns Attendance
     ├── owns Payroll
     ├── mutates source domains
     └── becomes canonical HR storage
```

Dan physical placement awal:

```text
Modules/
└── HR/
    └── Reporting/
        ├── Application
        ├── Queries
        ├── Projections
        └── Exports
```

**Catatan:** ini baru boundary/folder responsibility konseptual, **belum instruksi implementasi**.

---

# 11. Reviewer Mode — Phase 2H-A

**Quality Score:** **9.5/10**

**Gaps:** detail KPI, formula, source fields, read-model strategy, dan government mapping memang belum ditentukan karena menjadi tanggung jawab subfase berikutnya.

**Risks:** risiko terbesar adalah reporting berkembang menjadi shadow database atau melakukan direct cross-domain ownership. Boundary HRR-BND-001 sampai HRR-BND-010 secara khusus mencegah hal tersebut.

**Recommendations:** pertahankan HR Reporting di `Modules/HR` sampai ada bukti kebutuhan generic reporting platform.

**Status:** **READY FOR APPROVAL**

Jika baseline **2H-A** ini kita setujui, tahap berikutnya adalah **Phase 2H-B — HR Reporting Requirement & KPI Catalog**. Di sana kita mulai menentukan _apa yang benar-benar perlu dilihat stakeholder_, definisi setiap metric, filter/dimension, source domain, dan drill-down—sebelum menyentuh desain tabel/read model.
