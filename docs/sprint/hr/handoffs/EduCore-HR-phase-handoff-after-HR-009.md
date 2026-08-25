# EduCore HR — Phase Handoff After HR-009

- **Version:** 1.0
- **Status:** READY FOR CONTINUATION
- **Handoff Date:** 2026-08-22
- **Repository Baseline:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`
- **Current HR Architecture Baseline:** HR-001 through HR-009 + ADR-032
- **Latest Closed Phase:** Phase 2H — HR Reporting, Dashboard & Government Export Boundary

---

# 1. Purpose

Dokumen ini adalah handoff resmi setelah penutupan Phase 2H dan approval HR-009. Tujuannya memungkinkan fase berikutnya dimulai tanpa mengulang discovery atau mengubah locked architecture secara tidak sengaja.

Authority order:

1. instruksi user terbaru;
2. repository/resources EduCore terbaru;
3. ADR Accepted;
4. HR-001 through HR-009 Approved/Locked;
5. existing implementation;
6. asumsi hanya bila tidak ada authority lain.

Jika repository HEAD berbeda dari baseline handoff, lakukan resource audit dan delta/impact analysis terlebih dahulu.

---

# 2. Current Status

| Artifact | Status | Scope |
|---|---|---|
| HR-001 | APPROVED / LOCKED | Product & Business Requirement |
| ADR-032 | ACCEPTED | HR Domain Boundary & Workforce Architecture |
| HR-002 | APPROVED / LOCKED | Workforce Foundation |
| HR-003 | APPROVED / LOCKED | Recruitment, Hiring & Onboarding |
| HR-004 | APPROVED / LOCKED | Leave & Permit |
| HR-005 | APPROVED / LOCKED | Workforce Attendance |
| HR-006 | APPROVED / LOCKED | Compensation, Benefit & Payroll Input |
| HR-007 | APPROVED / LOCKED | Performance, PKG, Competency & PKB |
| HR-008 | APPROVED / LOCKED | Documents, Contract, Discipline & Offboarding |
| **HR-009** | **APPROVED / LOCKED** | **Reporting, Dashboard & Government Export** |

**Phase 2H status:** CLOSED / APPROVED.

---

# 3. Canonical Identity & HR Foundation

```text
Person
  ↓
Membership
  ↓
Employee
  ↓
Employment
```

Locked rules:

- `Person` adalah canonical human identity.
- `Membership` adalah Person × Tenant.
- `Employee` adalah HR profile.
- `User` optional dan bukan Employment account.
- satu Employee dapat mempunyai banyak Employment sepanjang sejarah;
- maksimum satu Employment ACTIVE pada satu waktu;
- rehire membuat Employment baru;
- Position adalah HR concern, bukan RBAC Role;
- organizational placement menggunakan Core `OrganizationalAssignment`;
- offboarding tidak menghapus Person/User;
- Employment end tidak otomatis menonaktifkan Membership.

---

# 4. Other Locked HR Boundaries

## Recruitment

```text
Candidate
→ Application
→ Selection
→ Hiring Approval
→ Onboarding
→ Identity Resolution
→ Person
→ Membership
→ Employee
→ Employment PLANNED
→ Activation
```

Candidate tidak otomatis Person; weak match tidak auto-merge; conversion harus idempotent.

## Leave

- balance = append-only ledger;
- final approval mengonsumsi entitlement;
- permission + scope menentukan approval;
- leave tidak menjadi owner Academic schedule.

## Attendance

Attendance bounded context terpisah.

```text
Expectation
+ Raw Event
+ Approved Leave/Permit
→ Reconciliation
→ Attendance Record
```

Raw event bukan final fact.

## Compensation / Payroll

```text
HR → compensation/benefit/payroll-input facts
Attendance → attendance facts
Academic → teaching quantity facts
Finance → payroll calculation/payment/accounting
```

## Performance / Competency

- framework/rating scale versioned;
- PKG tidak global hardcoded rubric;
- performance evidence bukan automatic promotion/salary trigger;
- training ≠ certification ≠ competency.

## Documents / Contracts

- private storage only;
- no DB BLOB;
- finalized/signed version immutable;
- Employment Agreement terpisah dari file;
- agreement expiry tidak otomatis mengakhiri Employment;
- signing provider via adapter.

## Discipline

- tenant-scoped catalog;
- tidak ada hardcoded SP1→SP2→SP3;
- disciplinary action tidak otomatis mengubah Employment/Position/Compensation/Role.

## Offboarding

```text
Employment
→ Offboarding Case
  ├── Approval
  ├── Checklist
  ├── Handover
  ├── Access Review
  ├── Exit Interview
  ├── Settlement Facts
  └── Completion
```

Employment ENDED ≠ Offboarding COMPLETED.

---

# 5. HR-009 Locked Reporting Decisions

## 5.1 Domain Boundary

```text
Canonical Domain
      ↓
Direct Query / Rebuildable Projection / Frozen Dataset
      ↓
Authorized HR Reporting
      ↓
Dashboard / Report / Export
```

- Reporting tetap capability dalam `Modules/HR`.
- Reporting bukan source of truth.
- Generic `Modules/Reporting` belum justified.
- Reporting tidak melakukan cross-domain mutation.

## 5.2 Read Model

- direct-query-first;
- projection hanya jika measured complexity/performance justified;
- purpose-specific projection, bukan generic metric/EAV store;
- persisted projection harus rebuildable/reconcilable;
- historical reporting menggunakan effective state at T;
- cross-domain reads mengikuti source-owner contracts.

## 5.3 KPI

Locked KPI families:

- workforce/headcount;
- recruitment funnel;
- leave;
- attendance (when source available);
- compensation facts;
- performance;
- competency;
- document/contract;
- discipline;
- offboarding.

Metric wajib mempunyai snapshot vs period semantics dan definition/version traceability.

Formula yang belum mempunyai authority tetap DEFERRED.

## 5.4 Authorization & Privacy

```text
Allow
=
Permission
AND Tenant Context
AND Organizational Scope
AND Sensitivity Policy
```

- Position bukan authorization source.
- Aggregate access ≠ individual detail access.
- View ≠ Export.
- Capability projection hanya UX support.
- Backend authorize setiap request.
- Sensitive data memakai least disclosure.
- Small-cohort privacy threshold belum difinalkan.

## 5.5 Freshness

```text
LIVE
PROJECTED
FROZEN
```

Projected data harus menunjukkan `source_as_of` dan state `READY/STALE/FAILED` bila relevan.

## 5.6 Audit & Operations

- transactional run evidence terpisah dari Core Audit dan operational logs;
- Core Audit tetap supplemental/fail-open;
- sensitive queue payload hanya identifier, bukan raw dataset;
- async work yang bergantung pada new DB state dijalankan after commit;
- sensitive artifacts menggunakan private storage;
- failure reporting tidak boleh membatalkan canonical HR transaction.

---

# 6. Government Export Boundary

Final external classification:

| Target | Classification |
|---|---|
| Dapodik | ACTIVE TARGET |
| EMIS / EMIS GTK | ACTIVE / PRIMARY KEMENAG TARGET |
| Simpatika | LEGACY / DO NOT BUILD NEW DIRECT INTEGRATION |

Baseline integration:

```text
Canonical EduCore
→ Versioned Mapping
→ External Validation
→ Frozen Dataset
→ Private Export Artifact
→ Authorized Official Workflow
```

Direct API synchronization belum menjadi baseline karena authoritative external write contract belum diverifikasi.

Simpatika hanya legacy compatibility/migration concern bila dibutuhkan.

Government schema tidak boleh menjadi canonical internal schema.

---

# 7. Existing Implementation Classification

| Area | Decision |
|---|---|
| `Modules/HR` | KEEP + EXTEND |
| Employee profile | KEEP |
| Employee → Membership | KEEP |
| Employee provisioning | KEEP + EXTEND |
| Core OrganizationalAssignment | KEEP / REUSE |
| Core RBAC | KEEP / REUSE |
| Core tenant-aware queue | KEEP / REUSE |
| Core Audit | KEEP as supplemental |
| `employees.jabatan` | DEPRECATE GRADUALLY |
| Academic `teacher_id → employee_id` | KEEP |
| Generic Reporting module | DEFER |
| Generic metric/EAV storage | DO NOT INTRODUCE |
| Data warehouse | DEFER |
| Direct Dapodik/EMIS API | DEFER |
| New Simpatika integration | DO NOT BUILD |

---

# 8. Known Repository Risks

## [RISK — HIGH] HR route authorization

Current HR Employee API baseline belum memiliki explicit permission + organizational scope enforcement setara target architecture.

Required direction:

```text
Authentication
+ Tenant Context
+ Permission
+ Appropriate Organizational Scope
```

Prioritaskan remediation sebelum broader production exposure, khususnya mutation endpoint.

## [CONFLICT] Persistence environment

`.env.example` pernah default `sqlite`, sementara migration terbaru menggunakan PostgreSQL-specific integrity semantics.

Jangan menurunkan integrity schema agar cocok dengan stale SQLite config.

## [CONFLICT] Filename casing

Terdapat casing mismatch Git/filesystem pada beberapa migration/ADR files yang berpotensi bermasalah di Linux/CI.

## [RISK] Audit semantics

Core Audit fail-open. Sensitive lifecycle/export harus mempunyai transactional domain evidence.

## [RISK] Queue payload privacy

Generic failed-job/watchdog infrastructure dapat merekam payload. HR sensitive jobs harus identifier-only.

## [RISK] RBAC grant provenance / Membership lifecycle

Offboarding tidak boleh otomatis menghapus seluruh role atau menonaktifkan Membership tanpa explicit policy/decision.

---

# 9. Additive Change Pending from HR-008

Ketika Finance/final-settlement integration mulai didesain:

```text
hr_payroll_input_snapshots.purpose

REGULAR_PAYROLL
FINAL_SETTLEMENT
```

Perubahan ini tidak mengubah ownership Finance/HR yang sudah dikunci.

---

# 10. Open Items Carried Forward

Open decisions dari handoff HR-008 tetap berlaku, termasuk:

1. tenant NIP policy;
2. Employment Type/Classification catalog;
3. future-effective scheduling;
4. exact leave/work calendar;
5. Attendance cutoff/finalization;
6. fingerprint/QR/GPS adapters;
7. Finance payroll implementation;
8. canonical currency;
9. statutory payroll formulas;
10. exact PKG/PKB mapping;
11. competency taxonomy;
12. document retention;
13. document numbering;
14. document type catalog;
15. e-sign provider;
16. AV/malware scanning provider;
17. disciplinary/SP policy;
18. appeal/review workflow;
19. offboarding approval chain;
20. Asset integration;
21. Finance final-settlement contract;
22. role-grant provenance;
23. Membership deactivation policy;
24. exit-interview template.

HR-009 menambahkan open items:

25. dashboard latency SLA;
26. projection freshness SLA;
27. refresh schedule;
28. large-export threshold;
29. pagination/date-range limits;
30. cache TTL if ever needed;
31. privacy cohort threshold;
32. exact masking format;
33. audit/log/export retention;
34. production storage provider;
35. centralized observability provider;
36. Dapodik field mapping;
37. EMIS GTK field mapping;
38. official import formats;
39. external identifier registry ownership;
40. government credential/submission/acknowledgement contract.

Semua tetap `[OPEN DECISION]` sampai ada authority.

---

# 11. Resource Gaps

## [RESOURCE GAP] Individual HR artifacts in repository package

Repository baseline yang diaudit belum menyimpan HR-001 sampai HR-009/ADR-032 sebagai current repository documentation set. Continuation authority saat ini berasal dari approved phase artifacts/handoff.

Recommendation: pada documentation integration step berikutnya, masukkan canonical HR requirement artifacts ke `docs/prd/`/architecture location yang sesuai agar traceability tidak bergantung pada conversation history.

## [RESOURCE GAP] Government field/API specifications

Field-level Dapodik/EMIS GTK export mapping dan public external write API contract belum cukup terverifikasi untuk dikunci.

---

# 12. Recommended Next Phase

## [REKOMENDASI] Phase 3 — HR UI/UX, Security & Deployment Readiness

Setelah HR-009 menutup reporting architecture, fase selanjutnya sebaiknya kembali mengikuti enterprise delivery workflow dan menilai seluruh HR domain HR-001–HR-009 secara lintas modul, bukan hanya dashboard reporting.

Candidate scope:

```text
PHASE 3 — HR UI/UX, Security & Deployment Readiness

3A Information Architecture & Navigation
3B HR Transaction UI/UX Requirements
3C Loading / Empty / Error / Permission States
3D Full HR Authorization Matrix & Existing Route Remediation
3E Security / Privacy / Retention Controls
3F Performance / Scalability / Backup / Recovery
3G Logging / Monitoring / Deployment / Rollback
3H Final Phase Review
```

Ini **recommendation**, belum locked. Scope final harus dimulai dengan resource audit terbaru.

Alternative jika prioritas tim berubah ke delivery implementation terlebih dahulu: buat explicit change in sequencing dan lakukan **Engineering Implementation Readiness / Sprint Planning** berdasarkan HR-001–HR-009, tanpa mendesain ulang domain.

---

# 13. Resource Audit Required Before Continuation

Pada fase berikutnya:

1. inspect latest repository HEAD;
2. compare terhadap `26b475b...`;
3. inspect new HR/Core/Attendance/Finance/Academic migrations;
4. inspect new ADR/PRD;
5. verify whether HR-009 has been integrated into repository docs;
6. inspect current HR route permission changes;
7. re-check external government interfaces only if next scope touches them;
8. classify delta as KEEP / EXTEND / REFACTOR / DEPRECATE / REPLACE;
9. record `[CONFLICT]`, `[RESOURCE GAP]`, `[RISK]`;
10. preserve locked HR-001–HR-009 decisions unless formal change request approved.

---

# 14. Change-Impact Rule

Jika requirement berubah, periksa dampak ke:

- business rules;
- user stories/acceptance criteria;
- Employee/Employment lifecycle;
- domain/module boundary;
- database/migration;
- API;
- authorization;
- UI;
- Attendance/Finance/Academic integration;
- government mapping;
- reporting projection;
- testing;
- deployment;
- documentation.

Tidak boleh mengubah satu area tanpa dependency review.

---

# 15. Definition of Continuation Readiness

Fase berikutnya dapat dimulai jika:

- latest repository sudah diaudit;
- tidak ada critical conflict yang membatalkan ADR-032/HR-001–HR-009;
- new resources direkonsiliasi;
- phase scope dinyatakan eksplisit;
- security gap existing HR API tetap terlihat dalam planning;
- open decisions tidak dianggap sebagai fakta;
- locked domain ownership dipertahankan.

---

# 16. Reviewer Handoff Assessment

**Quality Score:** 9.8/10

**Status:** READY FOR NEXT-PHASE RESOURCE AUDIT

**Critical Gap:** NONE pada architecture/specification baseline.

**Primary implementation warning:** existing HR API permission/scope enforcement harus diprioritaskan sebelum HR functionality diperluas untuk production.

**Primary architecture warning:** jangan menjadikan reporting, Finance, government mapping, external identifiers, atau access revocation sebagai duplicate source of truth.

**Continuation Rule:**

```text
Resource Audit
→ Current State Delta
→ Conflict / Gap
→ Scope
→ Design / Planning
→ Review
→ Approval
```
