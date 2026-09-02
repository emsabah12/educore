# ADR-034 — Billing Domain Boundary & Entitlement Architecture

**Version** : 0.1
**Status** : Proposed — NOT YET ACCEPTED
**Date** : 2026-09-03
**Scope** : Platform-level Billing/Subscription — mengenakan biaya kepada Tenant untuk penggunaan EduCore
**Baseline Repository** : commit saat sesi ini berlangsung (lihat riwayat percakapan)
**Product Requirement** : `BILL-000 — Billing & Subscription Open Decisions` (Open Decisions Confirmed)

---

> ## Decision Summary
>
> EduCore akan menambahkan `Modules/Billing` sebagai bounded context baru untuk
> **platform-level subscription dan monetisasi** — mengenakan biaya kepada
> Tenant atas penggunaan EduCore itu sendiri. Ini **berbeda total** dari
> `Modules/Finance` yang sudah disinggung `ADR-032` (payroll/financial
> settlement **internal** milik satu Tenant). `Modules/Billing` memperkenalkan
> lapisan otorisasi baru — **Entitlement** ("apakah Tenant berlangganan fitur
> ini?") — yang berjalan **terpisah dan sebelum** lapisan **Permission**
> (`ADR-016`, "apakah User ini berwenang melakukan aksi ini?"). Tenant
> creation tetap dikunci `RequireGlobalSuperadmin` untuk jalur admin-provisioned
> yang sudah ada; self-service signup akan memakai jalur publik baru yang
> secara eksplisit tidak mem-bypass proteksi tersebut, melainkan menjadi
> pintu masuk terpisah dengan pengaman anti-abuse sendiri.

---

# Related Resources

## Product Requirement

- `BILL-000 — Billing & Subscription Open Decisions` (Open Decisions Confirmed)

## Related ADR

- `ADR-014` — Membership & Tenant Boundary
- `ADR-016` — Database-Backed Tenant RBAC
- `ADR-018` — Organizational Topology & Scoped Authorization
- `ADR-032` — HR Domain Boundary & Workforce Architecture (kontras terminologi "Finance")

---

# 1. Context

## [FAKTA] Kondisi Repository Saat Ini

Hasil audit langsung ke kode (2026-09-03):

```text
Modules/
├── Academic
├── Auth
├── Core
├── Dormitory
├── HR
├── PPDB
└── User
```

Tidak ada `Modules/Billing`, `Modules/Subscription`, `Modules/Plan`, atau
`Modules/Payment`. Nol baris kode untuk kapabilitas ini.

## [FAKTA] Tenant Creation Terkunci Superadmin

```text
POST /api/v1/core/tenants
  → middleware: RequireGlobalSuperadmin
```

atau lewat command CLI `core:tenant-provision` (butuh akses server). Tidak
ada endpoint publik untuk registrasi tenant mandiri.

## [FAKTA] "Finance" Sudah Punya Definisi Berbeda

`ADR-032` §3.13 mendefinisikan `Modules/Finance` sebagai owner payroll run,
payable, payment/disbursement, reconciliation, dan accounting — **internal
ke satu Tenant** (menggaji pegawai sekolah tersebut). Kapabilitas yang
dibahas ADR ini adalah kebalikannya: platform mengenakan biaya kepada
Tenant. Nama modul harus dibedakan secara eksplisit untuk menghindari
kebingungan implementasi di kemudian hari.

## [FAKTA] RBAC Existing Bersifat Per-Tenant

`ADR-016` mendefinisikan `Role`/`Permission`/`Membership` dengan scope
tenant-wide atau organizational (via `ADR-018`). Tidak ada konsep RBAC
untuk staf internal platform (superadmin bersifat biner — `is_superadmin`
`true`/`false`, tanpa gradasi).

---

# 2. Decision Drivers

- Owner platform butuh model monetisasi hybrid (tier + add-on granular per
  fitur) untuk mendukung strategi bisnis yang sudah diputuskan di `BILL-000`.
- Self-service onboarding tidak boleh melemahkan proteksi `RequireGlobalSuperadmin`
  yang sudah menjaga `POST /api/v1/core/tenants`.
- Entitlement (langganan) dan Permission (RBAC) adalah dua concern berbeda;
  mencampurnya akan mengulang anti-pattern yang sudah eksplisit dilarang di
  `HR-013-BR-001` ("Permission ≠ Resource Ownership") untuk alasan yang sama:
  perubahan pada satu concern tidak boleh memaksa perubahan pada concern lain.
- Kewajiban faktur PPN (`OD-BILL-006`) membutuhkan domain Invoice yang
  auditable dan tidak boleh mencampuri Core Audit Trail yang sudah ada
  untuk tujuan lain.

---

# 3. Decision

## 3.1 `Modules/Billing` adalah bounded context platform-level, bukan tenant-level

`Modules/Billing` memiliki data tentang **Tenant** (siapa berlangganan apa),
tapi modul ini sendiri **bukan** tenant-scoped seperti Academic/HR — ia
beroperasi di atas seluruh tenant sekaligus, mirip posisi `Modules/Core`
`Tenancy` yang sudah ada.

## 3.2 Entitlement adalah lapisan otorisasi baru, terpisah dari Permission

```text
Request masuk
  → Layer 1: tenant.entitlement:<feature>   (Billing — apakah tenant berlangganan?)
  → Layer 2: tenant.permission:<perm>       (Core RBAC — apakah user berwenang?)
  → Controller
```

Kedua layer wajib lolos. `CheckTenantPermission` (`ADR-016`) **tidak**
diperluas untuk memahami Entitlement — middleware baru
`CheckTenantEntitlement` dibuat terpisah, dengan kode error canonical
sendiri (mis. `SUBSCRIPTION_ENTITLEMENT_DENIED`), mengikuti pola
`ApiErrorResponse` yang sudah baku.

## 3.3 Tenant creation admin-provisioned TETAP ADA; self-service adalah jalur BARU

`POST /api/v1/core/tenants` (`RequireGlobalSuperadmin`) **tidak diubah**.
Self-service signup memakai endpoint publik baru (mis.
`POST /api/v1/billing/tenants/register`) yang:

- tidak memerlukan bearer token superadmin;
- wajib verifikasi email sebelum Tenant berstatus lebih dari `PENDING_VERIFICATION`;
- membuat Tenant dengan `Subscription` berstatus `TRIAL` (`OD-BILL-003`),
  bukan `ACTIVE` langsung;
- tunduk pada rate limiting dan pengaman anti-abuse yang didesain terpisah
  (belum dispesifikasikan — lihat `BILL-000` §6).

## 3.4 Person/Membership/Tenant tetap dimiliki Core — Billing tidak menduplikasi

Sesuai pola yang sudah ditegakkan `ADR-013`/`ADR-014`/`ADR-032`: `Billing`
mereferensikan `tenant_id`, tidak menyalin data Tenant. `Subscription`
adalah fakta baru milik Billing yang menempel ke Tenant existing, bukan
Tenant versi baru.

## 3.5 RBAC platform-level adalah perluasan terpisah dari RBAC tenant-level

Role untuk staf internal (mis. `platform-billing-admin`) **tidak**
disimpan di tabel `roles`/`permissions`/`membership_roles` yang sama
dengan RBAC tenant (`ADR-016`) — itu tabel untuk Membership Person×Tenant.
Staf internal platform bukan Membership dari Tenant manapun. Desain
konkret (tabel baru vs perluasan `is_superadmin` jadi enum bertingkat)
**belum diputuskan** — dicatat sebagai open item di `BILL-000` §6.

## 3.6 Invoice/Payment adalah source of truth Billing, bukan Core Audit

Core Audit Trail (`AuditTrailServiceInterface`) tetap dipakai untuk audit
event generik (`billing.invoice.created`, dst.), tapi **tidak** menjadi
source of truth finansial. `Invoice`/`Payment` adalah tabel Billing sendiri
dengan integritas transaksional sendiri (append-only untuk `Payment`,
mengikuti pola `person_identifiers`/leave-ledger yang sudah terbukti di
`HR-004`).

---

# 4. Domain Ownership Matrix

| Concern                                           | Owner                                                                  | Catatan                            |
| ------------------------------------------------- | ---------------------------------------------------------------------- | ---------------------------------- |
| Tenant identity, subdomain, status aktif/nonaktif | `Modules/Core` (Tenancy)                                               | Tidak berubah                      |
| Person, Membership, RBAC per-tenant               | `Modules/Core` (Authorization)                                         | Tidak berubah                      |
| Plan, PlanFeature, AddonFeature                   | `Modules/Billing`                                                      | Baru                               |
| Subscription, SubscriptionEntitlement             | `Modules/Billing`                                                      | Baru                               |
| Invoice, InvoiceLineItem, Payment                 | `Modules/Billing`                                                      | Baru                               |
| RBAC platform-level (staf internal)               | Belum ditentukan — kandidat `Modules/Billing` atau `Modules/Core` baru | Open item                          |
| Payroll/financial settlement internal tenant      | `Modules/Finance` (masa depan, `ADR-032`)                              | Domain terpisah total dari Billing |

---

# 5. Consequences

## Positive

- Model monetisasi hybrid (`OD-BILL-001`) dan self-service signup bisa
  dibangun tanpa melemahkan proteksi `RequireGlobalSuperadmin` yang sudah ada.
- Pemisahan Entitlement vs Permission mencegah pencampuran concern yang
  sudah terbukti bermasalah kalau digabung (pelajaran dari `HR-013-BR-001`).
- Invoice/Payment sebagai domain terpisah memudahkan audit finansial
  terpisah dari audit operasional platform.

## Trade-offs / Negative

- Setiap endpoint yang nanti perlu entitlement check butuh **dua** middleware,
  bukan satu — sedikit lebih verbose di route definition.
- RBAC platform-level adalah gap arsitektur baru yang belum punya preseden
  di repository ini — perlu desain dari nol, tidak bisa reuse `ADR-016`
  begitu saja.
- Self-service signup memperluas attack surface (endpoint publik baru,
  perlu rate limiting/anti-abuse yang belum dispesifikasikan).

---

# 6. Alternatives Considered

## Option A — Entitlement digabung ke dalam Permission (`Role`/`Permission` existing)

Ditolak. Akan mencampur "apa yang tersedia untuk tenant" dengan "siapa
yang boleh apa" — melanggar prinsip `HR-013-BR-001` yang sudah ditegakkan
project ini, dan akan membuat perubahan billing (mis. tenant upgrade plan)
harus menyentuh baris RBAC, bukan baris subscription.

## Option B — Buka `POST /api/v1/core/tenants` untuk publik langsung

Ditolak. Melemahkan proteksi keamanan yang sudah ada tanpa pengaman
tambahan (anti-abuse, verifikasi email) yang memang dibutuhkan untuk
endpoint publik.

## Option C — `Modules/Billing` dan `Modules/Finance` digabung jadi satu modul

Ditolak. Kedua domain punya audience dan siklus hidup yang berbeda total
(platform vs internal-tenant) — menggabungkan akan mengulang anti-pattern
yang sudah dihindari `ADR-032` saat memisahkan HR dari Finance.

## Option D — `Modules/Billing` dengan Entitlement + boundary terpisah dari RBAC tenant (**Accepted candidate**)

Opsi yang direkomendasikan ADR ini.

---

# 7. Open Items (Belum Diputuskan)

Lihat `BILL-000` §6 untuk daftar lengkap. ADR ini **tidak** memblokir
finalisasi item tersebut — item itu adalah scope system/data design
(`BILL-001` dst.), bukan domain boundary.

---

# 8. Status

**Proposed** — menunggu review/approval owner platform sebelum menjadi
`Accepted` dan menjadi authority untuk `BILL-001` dst.
