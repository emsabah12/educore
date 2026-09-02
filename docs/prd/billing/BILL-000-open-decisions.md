# BILL-000 — Billing & Subscription Open Decisions

**Version**: 0.1 Draft
**Status**: OPEN DECISIONS CONFIRMED — DETAILED DESIGN NOT YET STARTED
**Date**: 2026-09-03
**Scope**: Platform-level Billing/Subscription (mengenakan biaya ke Tenant untuk memakai EduCore) — **bukan** payroll/Finance internal tenant yang sudah disinggung di `ADR-032`
**Depends On**: `ADR-014` (Membership & Tenant Boundary), `ADR-016` (Database-Backed Tenant RBAC), `ADR-032` (HR Domain Boundary — kontras terminologi "Finance")
**Architecture Authority**: `ADR-034 — Billing Domain Boundary` (companion document)

---

## 1. Tujuan

Dokumen ini mencatat keputusan bisnis awal untuk kapabilitas Billing/Subscription
platform EduCore, hasil dari sesi diskusi owner platform (2026-09-03). Dokumen
ini **belum** merupakan system/data design (setara `HR-002` dst.) — itu langkah
berikutnya setelah dokumen ini disetujui sebagai baseline.

Repository saat ini **tidak memiliki** modul Billing/Subscription/Plan/Payment
sama sekali. Tenant creation hanya bisa dilakukan Global Superadmin
(`POST /api/v1/core/tenants`, middleware `RequireGlobalSuperadmin`) atau lewat
command CLI `core:tenant-provision`. Tidak ada endpoint publik untuk registrasi
tenant baru.

---

## 2. Konteks Penting: "Finance" Sudah Dipakai untuk Konsep Lain

`ADR-032` (§3.13) sudah mendefinisikan istilah **Finance** sebagai domain masa
depan yang memiliki payroll run, payable, payment/disbursement, dan accounting
**internal per-tenant** (menggaji pegawai satu sekolah).

Untuk menghindari tabrakan istilah, kapabilitas yang dibahas di sini memakai
nama **`Modules/Billing`** — platform-level, di atas seluruh tenant, mengenakan
biaya kepada Tenant untuk penggunaan EduCore itu sendiri.

```text
Modules/Billing  (platform charges Tenant for using EduCore)
      ≠
Modules/Finance  (Tenant pays its own Employee — payroll, ADR-032 §3.13)
```

---

## 3. Open Decisions

| ID              | Keputusan                        | Hasil                                                                        | Catatan                                                                                       |
| --------------- | -------------------------------- | ---------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| **OD-BILL-001** | Model harga                      | **Hybrid**: tier dasar (Basic/Pro/Enterprise) + add-on modul terpisah        | Detail tier/harga belum ditentukan — bagian design phase                                      |
| **OD-BILL-002** | Metode pembayaran                | **Payment gateway (Midtrans/Xendit) + transfer manual**, keduanya sejak awal | Pemilihan gateway spesifik belum final                                                        |
| **OD-BILL-003** | Aktivasi tenant baru             | **Trial 14 hari** sebelum wajib bayar                                        | Durasi bisa jadi tenant-configurable di masa depan, default 14 hari                           |
| **OD-BILL-004** | Granularitas add-on              | **Level fitur granular** (bukan modul utuh)                                  | Berdampak besar ke arsitektur — lihat §4                                                      |
| **OD-BILL-005** | Dunning (pembayaran gagal/telat) | **Grace period → read-only → suspend**                                       | Durasi grace period belum ditentukan                                                          |
| **OD-BILL-006** | Faktur pajak                     | **Wajib**, breakdown PPN pada invoice                                        | Bukan e-Faktur DJP resmi — lihat OD-BILL-007                                                  |
| **OD-BILL-007** | Status PKP perusahaan            | **Belum PKP**                                                                | Sehingga tidak perlu integrasi e-Faktur DJP di fase ini; invoice cukup breakdown PPN informal |

---

## 4. Konsekuensi Arsitektur Kunci

### 4.1 Dua Lapis Otorisasi Terpisah (akibat OD-BILL-004)

Granularitas add-on per-fitur mengharuskan lapisan otorisasi baru yang
**tidak boleh** tercampur dengan RBAC (`Role`/`Permission`) yang sudah ada —
prinsip ini konsisten dengan `HR-013-BR-001` ("Permission ≠ Resource
Ownership"), diperluas menjadi:

```text
Layer 1 — ENTITLEMENT (baru, tenant-level, ditentukan Subscription)
  "Apakah Tenant ini berlangganan fitur X?"

Layer 2 — PERMISSION (sudah ada, Membership-level, RBAC)
  "Apakah User ini berwenang melakukan aksi X?"
```

Keduanya harus lolos berdua. Middleware baru diusulkan: `tenant.entitlement:<feature>`,
terpisah dari `tenant.permission:<perm>` yang sudah ada.

### 4.2 Tenant Creation Harus Dibuka untuk Self-Service

`POST /api/v1/core/tenants` saat ini terkunci `RequireGlobalSuperadmin` — ini
keputusan keamanan yang sudah ada dan **tidak boleh dilonggarkan begitu saja**.
Self-service signup membutuhkan **jalur baru** (bukan membuka endpoint lama),
dengan pengaman tambahan (verifikasi email, rate limiting, anti-abuse) yang
belum ada spesifikasinya di dokumen ini.

### 4.3 RBAC Platform-Level Belum Ada

RBAC yang sudah dibangun (`ADR-016`) scope-nya **per-tenant**
(`Role`/`Permission`/`Membership`). Tim internal platform (admin platform,
subscription manager) butuh RBAC **platform-level** yang terpisah — ini gap
baru, bukan bagian dari `ADR-016`, dan perlu didesain bersamaan dengan
`Modules/Billing`.

---

## 5. Sketsa Domain Model (Non-Final)

```text
Plan                     — tier dasar, harga, siklus billing
PlanFeature               — fitur granular yang termasuk dalam satu Plan
AddonFeature               — fitur granular tambahan di luar Plan

Subscription              — 1 Tenant × 1 Plan aktif
                             status: TRIAL | ACTIVE | GRACE | SUSPENDED | CANCELLED
SubscriptionEntitlement    — hasil gabungan Plan + Addon = fitur aktif tenant ini

Invoice                   — tagihan periodik + breakdown PPN
InvoiceLineItem             — rincian item (plan fee, addon fee, proration)
Payment                   — 1 percobaan pembayaran (gateway callback / manual + verifikasi)
```

Skema tabel, migration, dan API contract **belum** didesain — menunggu
dokumen system/data design berikutnya (setara `HR-002`).

---

## 6. Yang Masih Terbuka (Belum Diputuskan)

Item berikut **belum** dibahas dan perlu diputuskan di sesi berikutnya sebelum
system/data design dimulai:

- Durasi grace period pasti (OD-BILL-005) — berapa hari sebelum suspend penuh?
- Kebijakan retensi data untuk tenant yang di-suspend/cancel (dihapus? disimpan berapa lama?)
- Perhitungan proration saat upgrade/downgrade mid-cycle
- Desain RBAC platform-level (§4.3) — role apa saja untuk tim internal, dan
  bagaimana ini berbeda dari `is_superadmin` yang sudah ada
- Pemilihan gateway spesifik (Midtrans vs Xendit vs keduanya) dan detail
  integrasi webhook
- Rate/nominal PPN yang dipakai (perlu dikonfirmasi ke tim finance, bukan
  keputusan teknis)

---

## 7. Status

**READY FOR ADR** — cukup untuk menjadi baseline `ADR-034`, **belum** cukup
untuk system/data design (`BILL-001` dst.) sampai item §6 diputuskan.
