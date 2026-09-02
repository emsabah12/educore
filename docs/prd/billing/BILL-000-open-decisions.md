# BILL-000 — Billing & Subscription Open Decisions

**Version**: 0.1 Draft
**Status**: OPEN DECISIONS CONFIRMED — DETAILED DESIGN NOT YET STARTED
**Date**: 2026-09-03
**Scope**: Platform-level Billing/Subscription (mengenakan biaya ke Tenant untuk memakai EduCore) — **bukan** payroll/Finance internal tenant yang sudah disinggung di `ADR-032`
**Depends On**: `ADR-014` (Membership & Tenant Boundary), `ADR-016` (Database-Backed Tenant RBAC), `ADR-032` (HR Domain Boundary — kontras terminologi "Finance")
**Architecture Authority**: `ADR-034 — Billing Domain Boundary & Entitlement/Quota Architecture` (companion document, v0.2)

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

| ID              | Keputusan                        | Hasil                                                                                                                           | Catatan                                                                                       |
| --------------- | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| **OD-BILL-001** | Model harga                      | **Hybrid**: tier dasar (Basic/Pro/Enterprise) + add-on modul terpisah                                                           | Detail tier/harga belum ditentukan — bagian design phase                                      |
| **OD-BILL-002** | Metode pembayaran                | **Payment gateway (Midtrans/Xendit) + transfer manual**, keduanya sejak awal                                                    | Pemilihan gateway spesifik belum final                                                        |
| **OD-BILL-003** | Aktivasi tenant baru             | **Trial 14 hari** sebelum wajib bayar                                                                                           | Durasi bisa jadi tenant-configurable di masa depan, default 14 hari                           |
| **OD-BILL-004** | Granularitas add-on              | **Level fitur granular** (bukan modul utuh)                                                                                     | Berdampak besar ke arsitektur — lihat §4                                                      |
| **OD-BILL-005** | Dunning (pembayaran gagal/telat) | **Grace period → read-only → suspend**                                                                                          | Durasi grace period belum ditentukan                                                          |
| **OD-BILL-006** | Faktur pajak                     | **Wajib**, breakdown PPN pada invoice                                                                                           | Bukan e-Faktur DJP resmi — lihat OD-BILL-007                                                  |
| **OD-BILL-007** | Status PKP perusahaan            | **Belum PKP**                                                                                                                   | Sehingga tidak perlu integrasi e-Faktur DJP di fase ini; invoice cukup breakdown PPN informal |
| **OD-BILL-008** | Limit/kuota per plan             | **Record-count + usage-based**, warning 80%/100% → grace period singkat → hard block, bisa ditambah lewat add-on kuota terpisah | Berdampak besar ke arsitektur — lihat §4.4 (layer baru: Quota)                                |

---

## 4. Konsekuensi Arsitektur Kunci

### 4.1 Tiga Lapis Otorisasi Terpisah (akibat OD-BILL-004 + OD-BILL-008)

Granularitas add-on per-fitur (OD-BILL-004) dan kebutuhan limit/kuota
(OD-BILL-008) bersama-sama mengharuskan **tiga** lapisan yang berjalan
berurutan, masing-masing **tidak boleh** tercampur satu sama lain — prinsip
ini konsisten dengan `HR-013-BR-001` ("Permission ≠ Resource Ownership"),
diperluas menjadi:

```text
Layer 1 — ENTITLEMENT (Billing, tenant-level)
  "Apakah Tenant ini berlangganan fitur X sama sekali?"
  on/off, ditentukan Subscription + PlanFeature/AddonFeature

Layer 2 — QUOTA (Billing, tenant-level, BARU dari OD-BILL-008)
  "Apakah Tenant ini masih di bawah batas pemakaian fitur X?"
  numerik, ditentukan Plan/QuotaAddon vs UsageCounter berjalan

Layer 3 — PERMISSION (Core RBAC, Membership-level, sudah ada)
  "Apakah User ini berwenang melakukan aksi X?"
  ditentukan Role/Permission (ADR-016)
```

Ketiganya harus lolos berurutan. Middleware yang diusulkan:
`tenant.entitlement:<feature>` → `tenant.quota:<metric>` → `tenant.permission:<perm>`.

**Catatan**: `ADR-034` (v0.2) sudah direvisi untuk memasukkan layer Quota
ini — lihat §3.2 dan §3.7 di ADR tersebut.

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

### 4.4 Dua Jenis Add-on yang Berbeda Perilaku (akibat OD-BILL-008)

OD-BILL-004 (fitur granular) dan OD-BILL-008 (kuota bisa ditambah terpisah)
sama-sama disebut "add-on", tapi keduanya **bukan konsep yang sama** dan
harus dimodelkan sebagai entitas berbeda:

```text
FeatureAddon   — toggle on/off untuk 1 fitur (Layer 1: Entitlement)
                 contoh: "aktifkan modul Leave Management"

QuotaAddon     — penambahan angka ke limit yang sudah ada (Layer 2: Quota)
                 contoh: "tambah kuota 100 siswa" — tidak menyalakan fitur
                 apapun, cuma menaikkan angka batas record-count
```

Mencampur keduanya jadi satu tabel `Addon` generik berisiko mengulang
kesalahan yang sama seperti percobaan awal katalog permission HR (`GAP-003`)
yang harus di-rename karena penamaan tidak selaras kontrak resmi — di sini
risikonya lebih besar karena `FeatureAddon` dan `QuotaAddon` punya _behavior_
berbeda (on/off vs akumulatif), bukan cuma nama field yang beda.

### 4.5 Usage-Based Quota Butuh Mekanisme Tracking Terpisah dari Record-Count

Dua jenis metric di OD-BILL-008 punya cara pengecekan berbeda:

```text
Record-count (mis. jumlah siswa)
  → dicek SYNCHRONOUS saat write (COUNT query atau counter cache),
    sebelum record baru dibuat

Usage-based (mis. jumlah API call, storage, notifikasi terkirim)
  → perlu counter INCREMENTAL yang di-reset per siklus billing,
    idealnya lewat mekanisme yang sama seperti Queue Watchdog
    (GAP-021) — tidak boleh blocking request utama untuk sekadar
    mencatat angka pemakaian
```

Reset periodik (bulanan, mengikuti billing cycle Subscription) adalah
scheduled job baru — bukan bagian dari `ADR-016`/`ADR-018` manapun.

### 4.6 Warning 80%/100% Bisa Reuse Notification Module yang Sudah Ada

OD-BILL-008 meminta warning otomatis saat mendekati/mencapai limit. Ini
**tidak perlu infrastruktur notifikasi baru** — `Modules/Core`
`NotificationChannelContractInterface` dan `SendAsynchronousNotificationJob`
(sudah ada, sudah di-hardening di `GAP-021`) bisa dipakai langsung. Trigger
threshold (80%/100%) adalah logic baru di Billing yang **memanggil**
kontrak notifikasi yang sudah ada, bukan membangun ulang.

---

## 5. Sketsa Domain Model (Non-Final)

```text
Plan                     — tier dasar, harga, siklus billing
PlanFeature               — fitur granular yang termasuk dalam satu Plan
FeatureAddon               — fitur granular tambahan di luar Plan (Layer 1: Entitlement)

Subscription              — 1 Tenant × 1 Plan aktif
                             status: TRIAL | ACTIVE | GRACE | SUSPENDED | CANCELLED
SubscriptionEntitlement    — hasil gabungan Plan + FeatureAddon = fitur aktif tenant ini

QuotaDefinition            — batas default per metric per Plan (mis. NATIONAL_ID_COUNT: 500)
QuotaAddon                 — penambahan angka ke QuotaDefinition, dibeli terpisah (Layer 2: Quota)
UsageCounter                — nilai berjalan per Tenant per metric, direset per siklus billing
                             (untuk metric usage-based; record-count dihitung langsung dari tabel domain)

Invoice                   — tagihan periodik + breakdown PPN
InvoiceLineItem             — rincian item (plan fee, addon fee, quota-addon fee, proration)
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
- **[Baru — OD-BILL-008]** Daftar metric kuota final per modul (mis. apa saja
  yang record-count vs usage-based untuk Academic/HR/Dormitory) — perlu
  ditentukan bersamaan dengan katalog fitur tiap modul, bukan generik di sini
- **[Baru — OD-BILL-008]** Durasi grace period spesifik untuk Quota (mungkin
  beda dari grace period Dunning di OD-BILL-005 — perlu diputuskan apakah
  sama atau terpisah)
- **[Baru — OD-BILL-008]** Apakah warning 80%/100% dikirim ke semua User di
  tenant, atau hanya ke Membership dengan Role tertentu (mis. admin tenant)?

---

## 7. Status

**READY FOR ADR** — cukup untuk menjadi baseline `ADR-034`, **belum** cukup
untuk system/data design (`BILL-001` dst.) sampai item §6 diputuskan.
