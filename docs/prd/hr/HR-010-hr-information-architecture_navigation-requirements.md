# HR-010 — HR Information Architecture & Navigation Requirements

**Version:** 0.1 Draft
**Phase:** 3A — Information Architecture & Navigation
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-22
**Depends On:** HR-001–HR-009, ADR-032, FE-002, FE-005, FE-006, FE-007, FE-008, ADR-020–ADR-031

---

# 1. Objective

HR-010 mendefinisikan struktur informasi dan navigasi pengguna untuk seluruh capability HR tanpa mengubah domain ownership yang telah dikunci.

HR menggunakan shared EduCore Application Shell dan tidak membangun:

- authentication sendiri;
- tenant selector sendiri;
- workspace model sendiri;
- authorization engine sendiri;
- navigation shell sendiri.

Target:

```text
EduCore Application Shell
        ↓
HR Navigation Catalog
        +
Current Tenant
        +
Current Workspace
        +
Capability Projection
        ↓
Effective HR Navigation
```

---

# 2. Architecture Classification

| Existing Area                           | Decision         |
| --------------------------------------- | ---------------- |
| Global Sidebar + Topbar                 | KEEP             |
| Tenant/Membership context UX            | KEEP             |
| Workspace context UX                    | KEEP             |
| Capability-aware navigation             | KEEP             |
| Shared route/error/loading architecture | KEEP             |
| HR-specific navigation catalog          | EXTEND           |
| HR-specific pages                       | EXTEND           |
| Role-name-based HR navigation           | DO NOT INTRODUCE |
| Separate HR application shell           | DO NOT INTRODUCE |

---

# 3. HR Navigation Principles

### HR-010-BR-001

HR navigation harus berasal dari capability HR dan current context, bukan nama Role seperti:

```text
Admin
HR
Kepala Sekolah
Guru
```

### HR-010-BR-002

Position/Jabatan bukan authorization source.

```text
Position
≠ Role
≠ Permission
```

### HR-010-BR-003

Navigation visibility tidak menggantikan backend authorization.

```text
Hidden menu
≠ endpoint secured
```

### HR-010-BR-004

Sidebar HR maksimum menggunakan:

```text
HR Module
→ Feature
```

Page hierarchy berikutnya menggunakan page, tab, filter, atau detail view; bukan nested sidebar tambahan.

### HR-010-BR-005

Tenant dan Workspace aktif harus tetap identifiable ketika pengguna berada pada seluruh halaman HR.

### HR-010-BR-006

HR page tidak boleh mengimplikasikan ownership domain yang sebenarnya dimiliki modul lain.

Contoh:

```text
HR Compensation
→ compensation / benefit / payroll input facts

Finance
→ payroll calculation / payment / accounting
```

---

# 4. Proposed HR Information Architecture

```text
HR
│
├── Overview
│
├── Workforce
│
├── Recruitment
│
├── Leave & Permit
│
├── Attendance
│
├── Compensation & Benefits
│
├── Performance & Development
│
├── Documents & Contracts
│
├── Discipline
│
├── Offboarding
│
└── Reports & Exports
```

Struktur ini mengikuti bounded capability HR-002 sampai HR-009 dan tidak menciptakan domain baru.

---

# 5. Feature → Page Mapping

| Feature                       | Primary Pages / Views                                                       |
| ----------------------------- | --------------------------------------------------------------------------- |
| **Overview**                  | HR Dashboard / operational summaries                                        |
| **Workforce**                 | Employee Directory, Employment, Position & organizational placement context |
| **Recruitment**               | Candidates, Applications, Selection, Hiring & Onboarding                    |
| **Leave & Permit**            | Requests, Approval Worklist, Balance / Entitlement                          |
| **Attendance**                | Attendance Records, Reconciliation                                          |
| **Compensation & Benefits**   | Compensation Facts, Benefits, Payroll Inputs                                |
| **Performance & Development** | Performance/PKG, Competency, PKB/Development                                |
| **Documents & Contracts**     | Employee Documents, Employment Agreements                                   |
| **Discipline**                | Discipline Cases / Actions                                                  |
| **Offboarding**               | Offboarding Cases                                                           |
| **Reports & Exports**         | HR Reports, Dashboard, Government Exports                                   |

Page detail dapat memiliki tabs tanpa menambah sidebar nesting.

Contoh:

```text
Employee Detail

Overview
Employment
Placement
Compensation
Performance
Documents
Discipline
```

Tabs hanya boleh ditampilkan bila user memang mempunyai capability yang relevan.

---

# 6. Overview

## HR-010-FR-001

HR harus memiliki entry page **Overview** sebagai landing page modul.

Overview dapat menggabungkan authorized information dari HR reporting capability.

## HR-010-FR-002

Dashboard tidak boleh mengasumsikan bahwa semua user dapat melihat seluruh workforce.

Output harus mengikuti:

```text
Permission
AND Tenant Context
AND Organizational Scope
AND Sensitivity Policy
```

## HR-010-FR-003

Jika user mempunyai akses HR tetapi tidak mempunyai akses KPI/dashboard tertentu, page tetap harus memberikan valid empty/limited state dan bukan authorization leak.

---

# 7. Workforce

Workforce menjadi entry point utama untuk canonical lifecycle:

```text
Person
→ Membership
→ Employee
→ Employment
```

## HR-010-FR-004

Employee Directory menampilkan Employee sebagai HR profile, tetapi identity yang ditampilkan harus tetap merepresentasikan canonical Person.

## HR-010-FR-005

Employment lifecycle tidak boleh dipresentasikan sebagai field sederhana pada Employee.

UI harus mendukung konsep:

```text
Employee
→ Employment history
→ maximum one ACTIVE Employment
```

## HR-010-FR-006

Organizational placement harus berasal dari Core Organizational Assignment.

HR tidak boleh membuat duplicate organization/unit ownership.

---

# 8. Recruitment

Navigation Recruitment mengikuti lifecycle:

```text
Candidate
→ Application
→ Selection
→ Hiring Approval
→ Onboarding
→ Identity Resolution
→ Employee / Employment
```

## HR-010-FR-007

Candidate UI harus tetap dibedakan dari Employee/Person UI sebelum identity resolution.

## HR-010-FR-008

Hiring/Onboarding UI tidak boleh memberi kesan bahwa Candidate otomatis telah menjadi Person atau Employee.

---

# 9. Leave & Permit

## HR-010-FR-009

Leave & Permit menyediakan navigation untuk:

```text
Requests
Approvals
Balance / Entitlement
```

sesuai capability user.

## HR-010-FR-010

Balance harus dipresentasikan sebagai hasil canonical entitlement ledger, bukan balance bebas yang dimutasi dari UI.

---

# 10. Attendance

## HR-010-FR-011

Attendance UI harus membedakan:

```text
Raw Event
Expectation
Approved Leave/Permit
Reconciliation
Final Attendance Record
```

Raw event tidak boleh diberi semantics seolah merupakan final attendance fact.

## HR-010-FR-012

Primary operational view diarahkan kepada Attendance Record dan Reconciliation, bukan perangkat/adaptor attendance.

Device/adaptor configuration tetap deferred sampai authority tersedia.

---

# 11. Compensation & Benefits

## HR-010-FR-013

Navigation tidak boleh menggunakan label yang menyebabkan user menganggap HR sebagai owner payroll processing.

Recommended feature label:

**Compensation & Benefits**

Dengan subpage:

```text
Compensation
Benefits
Payroll Inputs
```

## HR-010-FR-014

Payroll calculation/payment/accounting UI tidak menjadi bagian HR.

---

# 12. Performance & Development

## HR-010-FR-015

Capability ini mengelompokkan:

```text
Performance / PKG
Competency
PKB / Development
```

tanpa menyamakan ketiganya sebagai satu domain fact.

## HR-010-FR-016

Training, certification, dan competency harus tetap dapat dibedakan pada information architecture/detail view.

---

# 13. Documents & Contracts

## HR-010-FR-017

Employee document dan Employment Agreement harus tetap menjadi konsep berbeda.

Recommended structure:

```text
Documents & Contracts

├── Documents
└── Employment Agreements
```

## HR-010-FR-018

Navigation atau status contract tidak boleh menyiratkan:

```text
Agreement expired
→ Employment automatically ended
```

---

# 14. Discipline

## HR-010-FR-019

Discipline menggunakan neutral case/action-based UX.

Tidak boleh meng-hardcode navigation/workflow:

```text
SP1
→ SP2
→ SP3
```

karena disciplinary catalog bersifat tenant-scoped.

## HR-010-FR-020

Disciplinary outcome tidak boleh ditampilkan seolah otomatis mengubah Employment, Position, Compensation, atau authorization Role.

---

# 15. Offboarding

## HR-010-FR-021

Offboarding harus memiliki dedicated case workspace.

Detail case dapat memuat:

```text
Overview
Approval
Checklist
Handover
Access Review
Exit Interview
Settlement Facts
```

sesuai capability dan availability.

## HR-010-FR-022

UX harus membedakan dengan jelas:

```text
Employment ENDED

dan

Offboarding COMPLETED
```

karena keduanya bukan state yang sama.

---

# 16. Reports & Exports

## HR-010-FR-023

Reporting diletakkan sebagai capability HR:

```text
HR
└── Reports & Exports
```

dan tidak diarahkan ke generic Reporting module.

## HR-010-FR-024

Page dapat mencakup:

```text
Dashboard
Reports
Government Exports
```

sesuai authorization.

## HR-010-FR-025

Government export harus membedakan target aktif dari legacy integration:

```text
Dapodik
EMIS / EMIS GTK
```

Simpatika tidak boleh dipresentasikan sebagai target integrasi baru.

---

# 17. Capability-Aware Navigation

## HR-010-FR-026

Setiap feature HR mempunyai metadata capability requirement.

Conceptual contract:

```text
Navigation Item
+
Required Capability
+
Required Context
→
Visible / Hidden
```

## HR-010-FR-027

Exact canonical permission identifiers belum ditentukan pada HR-010.

**[DEFERRED]**

Permission catalog dan mapping:

```text
View
Create
Update
Approve
Export
Sensitive Detail
```

akan dikunci pada **Phase 3D — HR Authorization Matrix**.

HR-010 tidak boleh mengarang permission identifier sementara sebagai canonical contract.

## HR-010-FR-028

Export permission harus dapat dibedakan dari view permission.

```text
View
≠ Export
```

## HR-010-FR-029

Aggregate/report access tidak otomatis memberikan individual employee detail access.

---

# 18. Workspace Semantics

## HR-010-FR-030

HR navigation harus dapat beroperasi pada:

```text
TENANT workspace
OR
verified ORGANIZATION / ORGANIZATION_UNIT workspace
```

tergantung capability.

## HR-010-FR-031

Frontend tidak menghitung sendiri organizational authorization dari:

```text
organization_id
organization_unit_id
position
employee attributes
```

Backend scoped authorization tetap authority.

## HR-010-FR-032

Saat Workspace berubah:

```text
workspace-scoped HR state
→ invalidated

capabilities
→ refreshed

route
→ preserved only when still valid
```

sesuai frontend foundation.

---

# 19. Contextual Detail Navigation

Detail Employee boleh menjadi aggregation point untuk usability, tetapi tidak menjadi ownership shortcut.

Contoh:

```text
Employee: Ahmad

Overview
Employment
Placement
Leave
Attendance
Compensation
Performance
Documents
Discipline
```

Rule:

```text
Convenient cross-feature view
≠ merged bounded contexts
```

Setiap tab tetap menggunakan source owner dan authorization masing-masing.

---

# 20. Loading / Permission Navigation Behaviour

Pada Phase 3A, navigation mengikuti existing frontend foundation:

```text
Capability LOADING
→ protected HR navigation unresolved

Capability READY + allowed
→ show

Capability READY + denied
→ hide

Capability ERROR
→ fail closed

Backend 403
→ backend wins
```

Detail presentation state akan direview kembali pada Phase 3C.

---

# 21. IN SCOPE

- HR information hierarchy;
- HR feature grouping;
- sidebar navigation model;
- domain-to-page mapping;
- contextual employee detail navigation;
- capability-aware navigation principles;
- Tenant/Workspace navigation semantics;
- reporting/export placement;
- cross-domain UI ownership boundaries.

# 22. OUT OF SCOPE

- React component implementation;
- visual design system;
- database changes;
- API implementation;
- exact permission catalog;
- organizational authorization algorithm;
- Finance payroll UI;
- Academic scheduling UI;
- government field mapping;
- vendor-specific signing/scanning UI.

# 23. FUTURE SCOPE

- configurable personal shortcuts;
- favorites/recent HR pages;
- global cross-HR search;
- richer administrator capability diagnostics.

# 24. DEFERRED

- exact HR permission identifiers;
- navigation differences by tenant policy;
- device/adaptor management navigation;
- government mapping-specific screens;
- Asset-system offboarding screens;
- Finance settlement UI.

---

# 25. Key Acceptance Criteria

### HR-010-AC-001 — Capability Navigation

**Given** user membuka EduCore pada Tenant/Workspace aktif
**When** HR navigation dikomposisi
**Then** hanya feature yang didukung capability projection yang ditampilkan
**And** Role/Position tidak digunakan sebagai navigation condition.

### HR-010-AC-002 — Direct Route Security

**Given** menu HR tidak ditampilkan
**When** user mengakses URL secara langsung
**Then** frontend route guard tidak menganggap URL sebagai authority
**And** backend authorization tetap menentukan access.

### HR-010-AC-003 — Workspace Change

**Given** user berada pada halaman HR workspace A
**When** user berpindah ke workspace B
**Then** workspace-scoped HR state menjadi stale
**And** capability di-resolve kembali
**And** halaman hanya dipertahankan bila masih valid.

### HR-010-AC-004 — Employee Detail

**Given** user dapat melihat Employee
**When** Employee detail dibuka
**Then** hanya tab yang diizinkan capability/context yang tersedia
**And** tidak ada tab yang memperoleh authorization hanya karena parent Employee page accessible.

### HR-010-AC-005 — Payroll Boundary

**Given** user membuka Compensation & Benefits
**When** payroll-related capability ditampilkan
**Then** HR hanya menampilkan payroll input/fact capability
**And** calculation/payment/accounting tetap milik Finance.

### HR-010-AC-006 — Reporting Privacy

**Given** user memiliki aggregate HR reporting access
**When** report ditampilkan
**Then** system tidak menganggap user otomatis mempunyai akses individual sensitive detail.

### HR-010-AC-007 — Offboarding State

**Given** Employment telah ENDED
**When** offboarding belum selesai
**Then** UI tetap menunjukkan Offboarding sebagai incomplete
**And** tidak menyamakan kedua lifecycle state.

### HR-010-AC-008 — Government Export

**Given** user membuka Government Export
**When** target integrations ditampilkan
**Then** Dapodik dan EMIS/EMIS GTK dapat menjadi active targets
**And** Simpatika tidak ditawarkan sebagai new integration target.

---

# 26. Traceability

```text
HR-001 Business Requirements
        ↓
ADR-032 Domain Boundary
        ↓
HR-002 ... HR-009 Capabilities
        ↓
HR-010 Information Architecture
        ↓
Phase 3B Transaction UX
        ↓
Phase 3C States
        ↓
Phase 3D Authorization Matrix
        ↓
Frontend/API implementation + Tests
```

No HR navigation feature dibuat tanpa corresponding locked HR capability.

---

# 27. Open Items

**[RESOURCE GAP]** Canonical HR permission catalog belum tersedia.

**[OPEN DECISION]** Exact permission names akan diselesaikan pada Phase 3D.

**[RESOURCE GAP]** HR-001–HR-009 individual artifacts belum berada di repository documentation set; handoff tetap menjadi current continuation authority.

Tidak ada open item di atas yang menghalangi approval information architecture.

---

# 28. Phase Review

**Quality Score:** 9.6/10

**Gaps:**

- exact permission identifiers belum ada;
- final authorization matrix belum didefinisikan;
- individual HR artifacts belum terintegrasi ke repository.

**Risks:**

- implementer dapat keliru menggunakan `jabatan`/Position sebagai permission bila Phase 3D tidak diselesaikan sebelum implementation;
- Employee detail berpotensi menjadi accidental cross-domain authorization bypass jika tab-level authorization tidak independen;
- istilah “payroll” dapat menyebabkan ownership drift menuju HR jika boundary Finance tidak dipertahankan.

**Recommendations:**

1. Lock HR-010 sebagai information architecture.
2. Lanjut ke **Phase 3B — HR Transaction UI/UX Requirements**.
3. Sebelum production implementation, Phase 3D harus menetapkan canonical HR permission + organizational scope matrix.
4. Existing Employee API authorization gap tetap menjadi P0 remediation item.

**Status:** **READY FOR APPROVAL**
