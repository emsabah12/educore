# Status Proyek EduCore

- **Diperbarui:** 2026-10-10
- **Tahap aktif:** F2 — Identitas & login global (perencanaan)
- **Commit terakhir:** `d5f6498` — feat(core): F1 tenant dan pohon lembaga dengan closure table

## Keputusan penting

| Tanggal | Keputusan | Dokumen |
|---|---|---|
| 2026-10-09 | Rebuild dari nol sebagai simplified modular monolith; dokumen lama jadi referensi domain | ADR-001 |
| 2026-10-09 | Frontend Inertia 3 + React (starter kit resmi), auth Fortify, satu jalur session | ADR-001 §2.1 |
| 2026-10-09 | Pohon lembaga fleksibel (maks 6 tingkat); induk melihat turunan, tidak sebaliknya | PRD-000 §4, §7 |
| 2026-10-09 | Lembaga formal di dalam unit = anak unit; koordinator di bawah Biro pusat dengan penugasan fungsional (filter jenjang) | PRD-000 §4.4 |
| 2026-10-09 | Aturan berjenjang: terdekat menang, kecuali induk mengunci | PRD-000 §8 |
| 2026-10-09 | Modul awal: Core, HR, Academic | ADR-001 §2.2 |
| 2026-10-10 | Fitur auth starter kit: registrasi & verifikasi email OFF, 2FA & konfirmasi password ON, passkey OFF | PRD-000 OD-04 |
| 2026-10-10 | Susunan tenant pertama: 4 Biro pusat + Ponpes dengan Unit 1 (MTs, MA), Unit 2 (MTs, MA), Unit 3 (SMP, SMK), masing-masing + MDA, Bahasa, Al-Qur'an | PRD-000 §4.3 |
| 2026-10-10 | F1 tanpa halaman admin; halaman pohon lembaga dibuat setelah F3 (butuh login & RBAC) | PRD-000 §10 |

## Fitur yang sudah ada

**F0 — Setup: SELESAI (2026-10-10, commit `733ff35`)**

- Laravel 13 + Inertia React di Laragon, PostgreSQL `educore` & `educore_testing`.
- Kerangka `modules/Core`, `modules/HR`, `modules/Academic`; arch test batas modul; CI dengan PostgreSQL 16.
- Bukti: 39 test lulus; Pint PASS; Larastan level 7 tanpa error.

**F1 — Tenant & pohon lembaga: SELESAI (2026-10-10, commit `d5f6498`)**

- Tabel `tenants`, `organizations`, `organization_closure` (UUIDv7).
- Integritas di database: induk wajib di tenant yang sama (FK gabungan), kode unik per tenant, CHECK untuk type/category/status/atribut lembaga, closure lintas tenant ditolak.
- Service `CreateOrganization`, `MoveOrganization`, `DeactivateOrganization` dengan validasi siklus, kedalaman maks 6, induk nonaktif, anak aktif; perubahan pohon diantrekan per tenant (row lock).
- `OrganizationTree`: query closure (level, tinggi, induk, turunan) — dipakai lagi di F3 untuk otorisasi.
- `FirstTenantSeeder`: 23 node sesuai PRD-000 §4.3, aman dijalankan berulang.
- Bukti: 57 test lulus (184 assertions), termasuk 18 test pohon lembaga; Pint PASS; Larastan level 7 tanpa error; SQL closure & constraint juga diuji langsung di PostgreSQL 16.

## Struktur file utama

```text
modules/Core/
├── Application/Organization/   CreateOrganization, MoveOrganization, DeactivateOrganization,
│                               OrganizationTree, NewOrganizationData, LocksTenantTree
├── Domain/Organization/        Organization, OrganizationType/Category/Status, Jenjang, Exceptions/
├── Domain/Tenancy/             Tenant, TenantStatus
├── Database/Migrations/        tenants, organizations, organization_closure
├── Database/Seeders/           FirstTenantSeeder
└── Support/ModuleServiceProvider.php
tests/Architecture/ModuleBoundaryTest.php
tests/Feature/Core/FoundationSetupTest.php
tests/Feature/Core/Organization/OrganizationTreeTest.php
```

## Catatan teknis

- Kebiasaan wajib: `composer lint` sebelum commit.
- Data contoh di test wajib mematuhi aturan validasi yang sama dengan data asli (pelajaran F1: kode node 1 karakter ditolak).
- Tabel `users` bawaan starter kit masih punya kolom `name` dan ID bigint; disesuaikan di F2 (User → Person, UUIDv7).
- Global scope tenant (`BelongsToTenant`) belum dipasang; menunggu TenantContext dari login di F2. Sampai saat itu, query wajib memakai filter `tenant_id` eksplisit.
- Nama resmi Yayasan & Pondok Pesantren belum diberikan; seeder memakai nama sementara.

## Tugas berikutnya

1. F2 — Identitas & login global (Person, User, Membership, pilih workspace).
2. F3 — RBAC berbasis pohon + penugasan fungsional + scoped settings.
3. Halaman admin pohon lembaga (setelah F3).
4. F4 — Audit, health, backup, README deploy.
5. Push repo ke GitHub agar CI berjalan.
6. Susun PRD HR baru (dari HR-001 s.d. HR-016) dan PRD Academic.
