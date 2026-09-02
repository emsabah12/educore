# PRD Documentation

- **Collection Status**: Current + historical documents; use each document header and collection README as lifecycle authority
- **Updated**: 2026-08-29

Folder ini menyimpan Product/Engineering Requirement Documents EduCore.

Dokumen lama tidak otomatis menjadi current implementation contract setelah
arsitektur atau requirement berevolusi.

Untuk collection yang memiliki subfolder sendiri, gunakan `README.md` pada
collection tersebut sebagai entry point, reading order, dan lifecycle authority.

---

## Current Frontend Foundation

- [`PRD-001-frontend-foundation.md`](PRD-001-frontend-foundation.md)
  — canonical consolidated Frontend Foundation PRD;
  **🔒 LOCKED / FE-0 sampai FE-9; frontend implementation ✅ COMPLETE / LOCKED at `1094dad05ec4589a9e83a40fae249eef01591b94`**.

- [`FE-000-frontend-contract-baseline_Scope-verification.md`](FE-000-frontend-contract-baseline_Scope-verification.md)
  sampai
  [`FE-008-frontend-non-functional-requirements.md`](FE-008-frontend-non-functional-requirements.md)
  — detailed phase records supporting the consolidated Frontend Foundation PRD.

Frontend architectural decisions yang menjadi authority untuk implementation
tersedia di:

[`../architecture/adr/README.md`](../architecture/adr/README.md)

khususnya ADR-020 sampai ADR-031.

Canonical implementation verification tersedia di:

[`../tdd/TDD-001-frontend-foundation.md`](../tdd/TDD-001-frontend-foundation.md)

dengan FEI-1 sampai FEI-12 **COMPLETE / LOCKED** pada checkpoint
`1094dad05ec4589a9e83a40fae249eef01591b94`.

---

## Current Human Resources

Canonical Human Resources specification tersedia di:

[`hr/README.md`](hr/README.md)

Current HR specification terdiri dari:

```text
HR-001 → HR-016
```

---

## Proposed Billing & Subscription (Not Yet Approved)

Open decisions dan proposed domain boundary tersedia di:

[`billing/README.md`](billing/README.md)

**Status: IN PROGRESS — OPEN DECISIONS ONLY.** Belum ada implementation,
belum ada system/data design. Jangan disamakan dengan `Modules/Finance`
yang disinggung `ADR-032` (payroll internal tenant) — Billing adalah
platform mengenakan biaya ke Tenant, domain yang sepenuhnya berbeda.
