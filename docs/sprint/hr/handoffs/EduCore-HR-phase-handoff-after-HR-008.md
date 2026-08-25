# EduCore HR — Phase Handoff

**Version:** 1.0  
**Status:** Ready for Continuation  
**Handoff Date:** 2026-08-22  
**Repository Baseline:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`  
**Current HR Architecture Baseline:** HR-001 through HR-008 + ADR-032

---

# 1. Purpose

Dokumen ini adalah handoff resmi untuk melanjutkan pengembangan EduCore HR pada fase berikutnya tanpa mengulang analisis dari nol.

Gunakan urutan authority berikut ketika melanjutkan:

1. instruksi user terbaru;
2. repository/resources EduCore terbaru;
3. dokumen HR yang sudah Approved/Locked;
4. ADR-032 Accepted;
5. existing implementation;
6. asumsi hanya bila tidak ada authority lain.

Jika repository berubah setelah baseline handoff ini, lakukan resource audit dan impact analysis terlebih dahulu.

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

Phase 2G selesai dan terkunci.

---

# 3. Locked Architecture Decisions

## 3.1 Canonical Human / Tenant Identity

```text
Person
  ↓
Membership
  ↓
Employee
```

- `Person` adalah canonical human identity.
- `Membership` adalah `Person × Tenant`.
- `Employee` adalah HR profile.
- `User` optional dan bukan Employment account.
- Offboarding tidak menghapus Person/User.
- Membership tidak otomatis dinonaktifkan saat Employment berakhir.

## 3.2 Workforce Foundation

```text
Employee
  ↓
Employment
  ├── Employment Type
  ├── Employment Classification
  ├── Employment Placement
  │      ↓
  │   Core OrganizationalAssignment
  └── Position Assignment
         ↓
      Position
```

- Satu Employee dapat memiliki banyak Employment sepanjang sejarah.
- Maksimal satu Employment ACTIVE pada satu waktu.
- Rehire membuat Employment baru, bukan Employee baru.
- Position adalah HR concern, bukan RBAC Role.
- Organizational placement tetap memakai Core `OrganizationalAssignment`.
- Legacy `employees.jabatan` deprecated secara gradual, bukan dihapus langsung.

## 3.3 Recruitment

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

- Candidate tidak otomatis menjadi Person.
- Weak identity match tidak auto-merge.
- Hiring conversion idempotent.
- Existing Membership harus direuse/reactivate melalui Core lifecycle contract.

## 3.4 Leave

- Leave balance adalah append-only ledger.
- Request dapat memakai lebih dari satu entitlement bucket.
- Saldo dikonsumsi saat final approval.
- Approval berbasis permission + scope, bukan jabatan.
- Leave/Permit tidak menjadi owner Academic teaching schedule.

## 3.5 Attendance

Attendance adalah bounded context terpisah: `Modules/Attendance`.

```text
Expectation
+ Raw Event
+ Approved Leave/Permit
→ Reconciliation
→ Attendance Record
```

- Raw event adalah evidence, bukan final attendance result.
- Tanpa expectation tidak boleh otomatis menentukan LATE/ABSENT.
- Academic report-card attendance adalah snapshot/projection.
- Manual/import lebih dahulu; fingerprint/QR/GPS adapter adalah future.

## 3.6 Compensation & Payroll Boundary

```text
HR → compensation/benefit/payroll-input facts
Attendance → attendance facts
Academic → teaching quantity facts
Finance → payroll calculation/payment/accounting
```

HR tidak menghitung gross/net pay, PPh21, BPJS monetary contribution, payroll deduction, payable/payment, atau accounting.

## 3.7 Performance & Competency

- Framework/template/rating scale versioned.
- PKG tidak di-hardcode sebagai satu rubric global.
- Academic assessment siswa tidak direuse untuk performance pegawai.
- Performance result adalah evidence, bukan automatic promotion/salary/role trigger.
- Training ≠ certification ≠ competency.

## 3.8 HR Documents & Contracts

```text
HRDocument
→ HRDocumentVersion
→ SignatureEnvelope
→ SignatureSigner
```

- private storage only;
- no DB BLOB;
- finalized/signed version immutable;
- Employment Agreement adalah domain record terpisah dari file;
- agreement expiry tidak otomatis mengakhiri Employment;
- e-sign provider melalui adapter;
- EduCore tidak menyimpan private signing key.

## 3.9 Discipline

- SP1/SP2/SP3 adalah tenant-scoped catalog.
- Tidak ada hardcoded `SP1 → SP2 → SP3`.
- Disciplinary action tidak otomatis mengubah Employment/Position/Compensation/Role.
- Termination recommendation harus masuk explicit Offboarding Case.

## 3.10 Offboarding

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

- `Employment ENDED` berbeda dari `Offboarding COMPLETED`.
- End Employment menutup HR Position/Placement.
- Person/User tidak dihapus.
- Membership/User tidak otomatis dinonaktifkan.
- Role grant hanya dicabut melalui explicit Access Review decision.
- Final monetary settlement tetap Finance concern.

---

# 4. Core Boundaries That Must Not Be Violated

HR tidak boleh mengambil ownership dari Core Person, Membership, User, Organization, OrganizationalAssignment, RBAC, dan Audit infrastructure.

HR tidak boleh menambahkan shortcut canonical seperti `employees.organization_id`, `employees.organization_unit_id`, `employees.role_id`, atau `employees.person_id` jika responsibility tersebut sudah dimiliki Core.

Position/jabatan tidak boleh dipakai sebagai authorization source.

---

# 5. Existing Implementation Classification

| Area | Decision |
|---|---|
| `Modules/HR` | KEEP + EXTEND |
| Employee profile | KEEP |
| Employee → Membership | KEEP |
| Employee without automatic User | KEEP |
| Employee provisioning transaction | KEEP + EXTEND |
| Core OrganizationalAssignment | KEEP / REUSE |
| Core RBAC | KEEP / REUSE |
| `employees.jabatan` | DEPRECATE GRADUALLY |
| Academic `teacher_id → employee_id` | KEEP |
| Core notification delivery infra | KEEP / REUSE |
| Core Audit | KEEP as supplemental operational audit |

---

# 6. Known Repository / Architecture Risks

## [CONFLICT] Database environment

Repository migration terbaru menggunakan PostgreSQL-specific behavior, sementara `.env.example` pernah teridentifikasi default `sqlite`. Continuation harus mengikuti persistence semantics terbaru dan memperbaiki repository hygiene, bukan menurunkan integrity agar cocok dengan stale config.

## [CONFLICT] Notification wording

Core sudah memiliki notification delivery infrastructure. Yang belum tersedia adalah HR reminder orchestration/scheduling contract. Jangan membuat notification system baru dari nol.

## [RISK] Audit semantics

Core Audit adalah best-effort/fail-open. Legal-sensitive HR lifecycle tetap membutuhkan transactional domain evidence/history.

## [RISK] RBAC grant provenance

Current role grant belum menyimpan provenance/source. Offboarding tidak boleh otomatis menghapus seluruh role hanya karena Employee berhenti.

## [RISK] Membership deactivation

Membership dapat mewakili partisipasi domain lain. Jangan menganggap Employment end = Membership end.

---

# 7. Open Items Carried Forward

Tidak ada critical gap untuk baseline yang sudah dikunci, tetapi item berikut belum final:

1. tenant NIP policy;
2. default Employment Type/Classification catalog;
3. future-effective scheduling;
4. exact leave/work calendar;
5. Attendance cutoff/finalization scheduler;
6. fingerprint/QR/GPS adapters;
7. Finance payroll implementation;
8. tenant canonical currency;
9. statutory payroll formulas;
10. exact PKG/PKB tenant/regulatory mapping;
11. default competency taxonomy;
12. document retention;
13. document numbering;
14. default HR document type catalog;
15. e-sign/PSrE provider;
16. AV/malware scanning provider;
17. disciplinary policy/SP sequence;
18. appeal/review workflow;
19. offboarding approval chain;
20. Asset module integration;
21. Finance final-settlement contract;
22. role-grant provenance;
23. safe cross-domain Membership deactivation policy;
24. structured exit-interview template.

Setiap item tetap `[OPEN DECISION]` sampai ada business/resource authority.

---

# 8. Additive Change Pending from HR-008 Approval

HR-008 mengidentifikasi additive evolution terhadap HR-006:

```text
hr_payroll_input_snapshots.purpose

REGULAR_PAYROLL
FINAL_SETTLEMENT
```

Perubahan ini tidak mengubah ownership dan dapat diformalisasi ketika Finance/final-settlement integration mulai didesain.

---

# 9. Recommended Next Phase

Belum dimulai pada handoff ini.

Recommended continuation:

```text
PHASE 2H
HR Reporting, Dashboard
& Government Export Boundary
```

Primary objectives:

- consolidated HR reporting/read model;
- tenant/unit scoped headcount;
- recruitment funnel;
- leave/attendance summaries;
- compensation facts reporting;
- performance/competency reporting;
- contract/document expiry views;
- discipline/offboarding reporting;
- Dapodik/EMIS/Simpatika export boundary;
- export auditability;
- reporting security/privacy;
- read-model freshness strategy;
- no reporting table becomes a new source of truth.

Do not design government direct synchronization before verifying official supported interfaces/contracts.

---

# 10. Resource Audit Required Before Next Phase

At start of next phase:

1. inspect latest repository HEAD;
2. compare against baseline `26b475b...`;
3. inspect any new HR/Core/Finance/Attendance/Academic migrations;
4. inspect new ADR/PRD since this handoff;
5. verify whether `Modules/Finance` or reporting infrastructure now exists;
6. verify government export/API resources;
7. re-check changed regulation only where it affects current scope;
8. classify deltas as KEEP / EXTEND / REFACTOR / DEPRECATE / REPLACE;
9. record `[CONFLICT]` and `[RESOURCE GAP]`;
10. continue from locked decisions, not from a blank design.

---

# 11. Definition of Continuation Readiness

The next phase may start when:

- latest repository has been audited;
- HR-001 through HR-008 remain consistent with current code;
- no new critical conflict invalidates ADR-032;
- new resources are reconciled;
- scope is explicitly declared;
- previous locked decisions are preserved unless a formal change request is approved.

---

# 12. Reviewer Handoff Assessment

**Quality Score:** 9.6/10

**Status:** READY FOR NEXT-PHASE RESOURCE AUDIT

**Primary warning:** do not turn reporting, Finance, government integration, document handling, or access revocation into new sources of truth that duplicate existing domain ownership.

**Continuation Rule:**

```text
Resource Audit
→ Current State Delta
→ Conflict / Gap
→ Scope
→ Design
→ Review
→ Approval
```
