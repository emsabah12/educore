# Status Proyek EduCore

- **Diperbarui:** 2026-10-10
- **Tahap aktif:** F2b — Membership & konteks (perencanaan)
- **Commit terakhir:** `9cf4060` — feat(core): F2a identitas Person dan User (+ perbaikan route untuk types:check)

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
| 2026-10-10 | Person hanya `name` + `gender`; hapus akun mandiri dihilangkan; UI starter kit diterjemahkan ke Bahasa Indonesia; F2 dipecah F2a/F2b | PRD-000 OD-05–07 |

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

**F2a — Identitas: SELESAI (2026-10-10, commit `9cf4060` + perbaikan route)**

- Tabel `persons` (name, gender) dan `users` baru di modul Core: UUIDv7, `person_id` unik, email & username huruf kecil (dijaga CHECK database), status, `is_superadmin`, kolom 2FA.
- `User` pindah ke `Modules\Core\Domain\Identity\User`; auth Laravel & Fortify diarahkan ke sana.
- Login dengan email **atau** username (`AuthenticateUser`); akun nonaktif ditolak dengan pesan umum yang sama.
- Profil mengubah nama Person + email User dalam satu transaksi (`UpdateUserProfile`); hapus akun dihilangkan.
- Data user ke browser dibuat eksplisit (tanpa kolom sensitif); nama diambil dari Person.
- Halaman auth, pengaturan, 2FA, dan menu berbahasa Indonesia; `lang/id` untuk validasi, login, reset password.
- Bukti: 71 test lulus (233 assertions); Pint PASS; Larastan level 7 tanpa error; `npm run build` & `check:fix` lulus; constraint `persons`/`users` juga diuji langsung di PostgreSQL 16.
- Perbaikan: `Route::redirect()` diganti route GET biasa karena Wayfinder gagal membuat tipe untuk route yang menerima semua HTTP method (`npm run types:check`).

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

- Kebiasaan wajib sebelum commit: `composer lint`, `composer test`, `npm run types:check`, `npm run check:fix`.
- Jangan pakai `Route::redirect()`; pakai route GET biasa (Wayfinder belum mendukung route semua-method).
- Data contoh di test wajib mematuhi aturan validasi yang sama dengan data asli (pelajaran F1: kode node 1 karakter ditolak).
- Global scope tenant (`BelongsToTenant`) belum dipasang; menunggu TenantContext dari login di F2. Sampai saat itu, query wajib memakai filter `tenant_id` eksplisit.
- Nama resmi Yayasan & Pondok Pesantren belum diberikan; seeder memakai nama sementara.

## Tugas berikutnya

1. F2b — Membership, penugasan, pilih yayasan/workspace, TenantContext, seeder akun uji.
2. F3 — RBAC berbasis pohon + penugasan fungsional + scoped settings.
3. Halaman admin pohon lembaga (setelah F3).
4. F4 — Audit, health, backup, README deploy.
5. Push repo ke GitHub agar CI berjalan.
6. Susun PRD HR baru (dari HR-001 s.d. HR-016) dan PRD Academic.
