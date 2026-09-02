# ADR-034 — Billing Domain Boundary & Entitlement/Quota Architecture

**Version** : 0.2
**Status** : Proposed — NOT YET ACCEPTED
**Date** : 2026-09-03 (revised same day — v0.2 menambahkan layer Quota dari OD-BILL-008)
**Scope** : Platform-level Billing/Subscription — mengenakan biaya kepada Tenant untuk penggunaan EduCore
**Baseline Repository** : commit saat sesi ini berlangsung (lihat riwayat percakapan)
**Product Requirement** : `BILL-000 — Billing & Subscription Open Decisions` (Open Decisions Confirmed, v0.2)

---

> ## Decision Summary
>
> EduCore akan menambahkan `Modules/Billing` sebagai bounded context baru untuk
> **platform-level subscription dan monetisasi** — mengenakan biaya kepada
> Tenant atas penggunaan EduCore itu sendiri. Ini **berbeda total** dari
> `Modules/Finance` yang sudah disinggung `ADR-032` (payroll/financial
> settlement **internal** milik satu Tenant). `Modules/Billing` memperkenalkan
> **dua** lapisan otorisasi baru yang berjalan berurutan **sebelum**
> lapisan **Permission** (`ADR-016`, "apakah User ini berwenang melakukan
> aksi ini?"): **Entitlement** ("apakah Tenant berlangganan fitur ini sama
> sekali?") dan **Quota** ("apakah Tenant masih di bawah batas pemakaian
> fitur ini?"). Ketiganya adalah concern terpisah dengan model data terpisah
> (`FeatureAddon` untuk Entitlement, `QuotaAddon`/`UsageCounter` untuk Quota)
> dan tidak boleh saling tercampur. Tenant creation tetap dikunci
> `RequireGlobalSuperadmin` untuk jalur admin-provisioned yang sudah ada;
> self-service signup akan memakai jalur publik baru yang secara eksplisit
> tidak mem-bypass proteksi tersebut, melainkan menjadi pintu masuk terpisah
> dengan pengaman anti-abuse sendiri.

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

## 3.2 Entitlement dan Quota adalah dua lapisan otorisasi baru, terpisah dari Permission dan dari satu sama lain

```text
Request masuk
  → Layer 1: tenant.entitlement:<feature>   (Billing — apakah tenant berlangganan fitur ini sama sekali?)
  → Layer 2: tenant.quota:<metric>          (Billing — apakah tenant masih di bawah batas pemakaian?)
  → Layer 3: tenant.permission:<perm>       (Core RBAC — apakah user berwenang?)
  → Controller
```

Ketiga layer wajib lolos berurutan. `CheckTenantPermission` (`ADR-016`)
**tidak** diperluas untuk memahami Entitlement maupun Quota — dua
middleware baru dibuat terpisah:

- `CheckTenantEntitlement`, kode error `SUBSCRIPTION_ENTITLEMENT_DENIED`
- `CheckTenantQuota`, kode error `SUBSCRIPTION_QUOTA_EXCEEDED`

Keduanya mengikuti pola `ApiErrorResponse` yang sudah baku. Layer Quota
**hanya** relevan untuk operasi tulis yang menambah record baru (`store`),
bukan untuk operasi baca — kecuali metric usage-based tertentu (mis. API
rate limit) yang memang menghitung baca juga.

## 3.7 Entitlement dan Quota punya model data berbeda — jangan digabung jadi satu tabel `Addon`

```text
FeatureAddon   — toggle on/off untuk 1 fitur (Layer 1: Entitlement)
                 contoh: "aktifkan modul Leave Management"

QuotaAddon     — penambahan angka ke limit yang sudah ada (Layer 2: Quota)
                 contoh: "tambah kuota 100 siswa" — tidak menyalakan fitur
                 apapun, cuma menaikkan batas record-count
```

Kedua entitas punya _lifecycle_ dan _behavior_ berbeda (on/off vs
akumulatif angka), sehingga digabung menjadi satu tabel generik `Addon`
akan memaksa logic bercabang berdasarkan tipe — anti-pattern yang sama
persis dengan alasan `HR-013-BR-001` memisahkan Permission dari Resource
Ownership. `UsageCounter` (nilai berjalan per Tenant per metric untuk
metric usage-based) juga entitas terpisah dari `QuotaAddon` (yang cuma
mendefinisikan batas, bukan mencatat pemakaian).

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
| Plan, PlanFeature, FeatureAddon                   | `Modules/Billing`                                                      | Baru                               |
| QuotaDefinition, QuotaAddon, UsageCounter         | `Modules/Billing`                                                      | Baru — v0.2                        |
| Subscription, SubscriptionEntitlement             | `Modules/Billing`                                                      | Baru                               |
| Invoice, InvoiceLineItem, Payment                 | `Modules/Billing`                                                      | Baru                               |
| RBAC platform-level (staf internal)               | Belum ditentukan — kandidat `Modules/Billing` atau `Modules/Core` baru | Open item                          |
| Payroll/financial settlement internal tenant      | `Modules/Finance` (masa depan, `ADR-032`)                              | Domain terpisah total dari Billing |

---

# 5. Consequences

## Positive

- Model monetisasi hybrid (`OD-BILL-001`) dan self-service signup bisa
  dibangun tanpa melemahkan proteksi `RequireGlobalSuperadmin` yang sudah ada.
- Pemisahan Entitlement vs Quota vs Permission mencegah pencampuran concern
  yang sudah terbukti bermasalah kalau digabung (pelajaran dari
  `HR-013-BR-001`) — masing-masing bisa berubah independen (mis. Plan baru
  tanpa menyentuh RBAC, kenaikan kuota tanpa menyentuh Entitlement).
- Warning threshold (`OD-BILL-008`) bisa reuse Notification module yang
  sudah ada (`GAP-021`) tanpa infrastruktur baru.
- Invoice/Payment sebagai domain terpisah memudahkan audit finansial
  terpisah dari audit operasional platform.

## Trade-offs / Negative

- Setiap endpoint yang nanti perlu entitlement/quota check butuh **tiga**
  middleware berurutan, bukan satu — lebih verbose di route definition
  dibanding sebelum Quota ditambahkan (v0.1: dua middleware).
- Usage-based Quota butuh scheduled job untuk reset counter per siklus
  billing — komponen operasional baru yang tidak ada preseden di
  `ADR-016`/`ADR-018`.
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

## Option D — `Modules/Billing` dengan Entitlement + boundary terpisah dari RBAC tenant (v0.1)

Direvisi menjadi Option F (v0.2) setelah `OD-BILL-008` menambahkan
kebutuhan Quota.

## Option E — Quota digabung ke dalam Entitlement (satu tabel `FeatureAddon` berisi flag on/off DAN angka limit)

Ditolak. `FeatureAddon` (on/off) dan `QuotaAddon` (akumulatif angka) punya
_behavior_ berbeda — menggabung keduanya memaksa kolom `limit_value` yang
selalu `NULL` untuk fitur non-kuota, dan logic bercabang di setiap tempat
yang membaca tabel ini. Lihat §3.7.

## Option F — `Modules/Billing` dengan tiga layer terpisah (Entitlement, Quota, Permission) (**Accepted candidate**, v0.2)

Opsi yang direkomendasikan ADR ini.

---

# 7. Open Items (Belum Diputuskan)

Lihat `BILL-000` §6 untuk daftar lengkap (termasuk item baru terkait Quota
dari v0.2: daftar metric final per modul, durasi grace period Quota,
target penerima warning notifikasi). ADR ini **tidak** memblokir finalisasi
item tersebut — item itu adalah scope system/data design (`BILL-001` dst.),
bukan domain boundary.

---

# 8. Status

**Proposed (v0.2)** — sudah mencakup layer Quota (`OD-BILL-008`), menunggu
review/approval owner platform sebelum menjadi `Accepted` dan menjadi
authority untuk `BILL-001` dst.
