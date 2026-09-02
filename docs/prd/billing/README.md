# EduCore Billing & Subscription Documentation

- **Collection Status:** IN PROGRESS — OPEN DECISIONS ONLY, NOT YET APPROVED/LOCKED
- **Module:** Billing (proposed — platform-level, distinct from `Modules/Finance` in `ADR-032`)
- **Architecture Authority:** `ADR-034 — Billing Domain Boundary` (Proposed)
- **Primary Product Authority:** `BILL-000 — Billing & Subscription Open Decisions`
- **Current Specification Range:** `BILL-000` only
- **Updated:** 2026-09-03

---

## 1. Purpose

Folder ini merupakan canonical specification collection untuk kapabilitas
Billing/Subscription platform EduCore — mengenakan biaya kepada Tenant untuk
penggunaan platform, **bukan** payroll/Finance internal tenant yang sudah
disinggung `ADR-032`.

Dokumen di dalam collection ini **belum lengkap**. Saat ini baru mencakup:

- open decisions bisnis (model harga, metode pembayaran, aktivasi tenant,
  granularitas add-on, dunning, faktur pajak);
- sketsa domain model non-final;
- daftar item yang masih terbuka.

Belum mencakup: system/data design, authorization matrix, API contract,
UI/UX requirements, security/privacy/retention controls — dokumen-dokumen ini
akan menyusul mengikuti pola yang sama seperti `../hr/` (`HR-001` s.d.
`HR-016`) setelah open decisions di §6 `BILL-000` diputuskan.

---

## 2. Documentation Authority

```text
Latest approved project decision
        ↓
Accepted ADR (ADR-034, saat ini masih Proposed)
        ↓
BILL-000 Open Decisions (saat ini)
        ↓
BILL-001 dst. — System/Data Design (belum ada)
        ↓
Current Repository Implementation (belum ada — Modules/Billing belum dibuat)
```

---

## 3. Perbedaan Terminologi Penting

| Istilah                                  | Domain                                         | Status                                      |
| ---------------------------------------- | ---------------------------------------------- | ------------------------------------------- |
| `Modules/Billing`                        | Platform mengenakan biaya ke Tenant            | Diusulkan di sini, belum diimplementasi     |
| `Modules/Finance` (disebut di `ADR-032`) | Tenant menggaji Employee-nya sendiri (payroll) | Belum diimplementasi, domain terpisah total |

Jangan menyamakan kedua istilah ini saat membaca dokumentasi HR maupun Billing.
