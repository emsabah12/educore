# Status Proyek EduCore

- **Diperbarui:** 2026-10-10
- **Tahap aktif:** Perintah akun pertama untuk production (kode siap, menunggu verifikasi di Laragon)
- **Commit terakhir:** `01309f6` — fix: format nilai di halaman log audit (di-push ke GitHub `emsabah12/educore`; CI GitHub Actions hijau)

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
| 2026-10-10 | Penugasan tunggal otomatis dipilih; tanpa penugasan → konteks Seluruh Yayasan; akun uji hanya local/testing; penugasan fungsional boleh di tingkat Yayasan (`organization_id` kosong) | PRD-000 OD-08–10, §5 |
| 2026-10-10 | Katalog role & permission di kode + `educore:sync-access`; superadmin tanpa jalan pintas; di luar cakupan 404, di dalam cakupan tanpa izin 403 | PRD-000 OD-11–13, §7.1 |
| 2026-10-10 | Halaman admin pohon lembaga: tambah, ubah nama (kode tetap), pindah, nonaktifkan, aktifkan kembali; nonaktif disembunyikan kecuali diminta | PRD-000 §4.6, OD-14 |
| 2026-10-13 | Audit log aksi penting (append-only, IP & user agent, masa simpan 1 tahun); halaman log audit untuk Admin Yayasan; README mengasumsikan VPS Linux, script backup Linux & Windows | PRD-000 OD-15–18 |

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

**F2a — Identitas: SELESAI (2026-10-10, commit `9cf4060` + `f7f67da`)**

- Tabel `persons` (name, gender) dan `users` baru di modul Core: UUIDv7, `person_id` unik, email & username huruf kecil (dijaga CHECK database), status, `is_superadmin`, kolom 2FA.
- `User` pindah ke `Modules\Core\Domain\Identity\User`; auth Laravel & Fortify diarahkan ke sana.
- Login dengan email **atau** username (`AuthenticateUser`); akun nonaktif ditolak dengan pesan umum yang sama.
- Profil mengubah nama Person + email User dalam satu transaksi (`UpdateUserProfile`); hapus akun dihilangkan.
- Data user ke browser dibuat eksplisit (tanpa kolom sensitif); nama diambil dari Person.
- Halaman auth, pengaturan, 2FA, dan menu berbahasa Indonesia; `lang/id` untuk validasi, login, reset password.
- Bukti: 73 test lulus (237 assertions); Pint PASS; Larastan level 7 tanpa error; `npm run build`, `types:check` & `check:fix` lulus; constraint `persons`/`users` juga diuji langsung di PostgreSQL 16.
- Perbaikan: `Route::redirect()` diganti route GET biasa karena Wayfinder gagal membuat tipe untuk route yang menerima semua HTTP method (`npm run types:check`).

**F2b — Membership & konteks kerja: SELESAI (2026-10-10)**

- Tabel `memberships` (orang × yayasan) dan `organizational_assignments` (struktural, fungsional di node, fungsional tingkat Yayasan). Database menjamin membership & lembaga dari yayasan yang sama dan penugasan tidak dobel.
- `WorkContextResolver` + middleware `work.context`: konteks yayasan & lembaga kerja dibentuk dan **divalidasi ulang setiap request**; pilihan dari browser hanya diterima bila milik pengguna sendiri (selain itu 404).
- Halaman: Pilih yayasan, Pilih lembaga kerja, Akun belum terdaftar, Panel platform (Superadmin, masih kosong). Pengalih konteks di sidebar.
- `TenantContext` + trait `BelongsToTenant`: data lembaga & penugasan otomatis tersaring ke yayasan aktif.
- `DevAccountsSeeder`: 6 akun uji per peran (password `password`), hanya local/testing.
- Bukti: 103 test lulus (446 assertions); Pint PASS; Larastan level 7 tanpa error; `npm run build`, `types:check` & `check:fix` lulus; constraint juga diuji langsung di PostgreSQL 16.
- Perbaikan: 4 test sempat gagal ("Unable to locate file in Vite manifest") karena test dijalankan sebelum `npm run build`; diatasi dengan `withoutVite()` di `tests/TestCase.php`.

**F3 — RBAC berbasis pohon & aturan berjenjang: SELESAI (2026-10-10, commit `ae4d595`)**

- Tabel `permissions`, `roles`, `role_permission`, `membership_roles` (role tenant-wide), `organizational_assignment_roles` (role per penugasan), `scoped_settings`. Database menjamin semuanya dari yayasan yang sama.
- `AccessCatalog` + `CoreAccess`: 5 permission Core dan 5 role bawaan didefinisikan di kode; `php artisan educore:sync-access` menyalinnya ke database.
- `AuthorizationService` + `Gate::before`: cakupan dihitung dari closure table (struktural & fungsional), dibatasi lembaga kerja aktif, dihitung ulang tiap request. `visibleNodeIds()` untuk menyaring query modul.
- Pilihan lembaga kerja **Seluruh Yayasan** untuk pemegang role tenant-wide.
- Scoped settings: `SettingRegistry` (definisi dari modul), `ScopedSettingResolver` (kunci teratas → terdekat → default, aturan per jenjang), `SetScopedSetting` (hak akses §8.3, validasi nilai, tolak bila dikunci dari atas).
- Daftar permission di lembaga kerja dikirim ke browser sebagai petunjuk menu (`permissions`).
- `DevAccountsSeeder`: 8 akun uji dengan role (tambahan `pimpinan.ponpes`, `kepala.smk`).
- Bukti: 152 test lulus (685 assertions), termasuk 10 skenario wajib §7.3; Pint PASS; Larastan level 7 tanpa error; `npm run build`, `types:check` & `check:fix` lulus; SQL cakupan & constraint juga diuji langsung di PostgreSQL 16.
- Perbaikan: Larastan menolak `$x?->value ?? ...` (nullsafe di kiri `??` tidak perlu); cukup `$x->value ?? ...`.

**Admin pohon lembaga: SELESAI (2026-10-10, commit `6136d7f`)**

- Halaman `/lembaga` (menu **Lembaga**): pohon sesuai cakupan, panel detail, form tambah/ubah nama/pindah/nonaktifkan/aktifkan kembali; responsif dengan kondisi kosong & pesan error.
- `OrganizationController` + service baru `RenameOrganization`, `ReactivateOrganization`; label Bahasa Indonesia untuk jenis & kategori node.
- Pengaman: tujuan pindah wajib di dalam izin kelola; lembaga kerja yang sedang dipakai tidak bisa dinonaktifkan dari dalamnya.
- Bukti: 172 test lulus (823 assertions); Pint PASS; Larastan level 7 tanpa error; `npm run build`, `types:check` & `check:fix` lulus.

**F4 — Operasional: SELESAI (2026-10-13, commit `b7ad82d`)**

- Tabel `audit_logs` append-only (trigger database menolak UPDATE/DELETE/TRUNCATE; hanya `educore:prune-audit` boleh menghapus log yang melewati masa simpan, dijadwalkan harian 02:00).
- `AuditLogger` + pencatatan di semua service Core (pohon lembaga, anggota, penugasan, role, katalog, aturan berjenjang), event login/gagal/keluar, dan observer keamanan akun (kata sandi, email, 2FA).
- Halaman **Log audit** (`/audit`, permission baru `core.audit.view` untuk Admin Yayasan) dengan filter tanggal, pelaku, jenis aksi.
- `/up` ikut memeriksa database; bila gagal hanya pesan umum.
- `scripts/backup/` (Linux `.sh` & Windows `.ps1`): backup harian + rotasi, uji restore ke DB sementara; `docs/operations/BACKUP-RESTORE.md`; README baru (instal, jalan, deploy VPS Linux).
- Perbaikan bug F2a: konfirmasi password Fortify gagal karena mencari kolom `login`; sekarang memakai callback sendiri (+ test). Batas login per IP ditambahkan.
- Bukti: 191 test lulus (933 assertions); Pint PASS; Larastan level 7 tanpa error; build & `types:check` lulus; uji manual di browser lancar; `backup-db.ps1` + `restore-test.ps1` berhasil (tenants=1, organizations=23, users=8, audit_logs=51); script `.sh` diuji di PostgreSQL 16.
- Pelajaran: nilai `option()` Artisan cukup diperlakukan sebagai teks (`(string) $option`); Larastan menolak pengecekan tipe yang berlebihan.

**Akun pertama untuk production: KODE SIAP**

- `CreateAccount` (Person + User dalam satu transaksi, validasi email/username/kata sandi mengikuti `Password::defaults()`, tercatat `account.created`).
- `php artisan educore:create-admin <KODE>`: akun + keanggotaan + role Admin Yayasan sekaligus; akun yang sudah ada bisa dinaikkan setelah konfirmasi.
- `php artisan educore:create-superadmin`: akun Superadmin baru, atau menaikkan akun yang ada (`account.superadmin_granted`).
- Kata sandi hanya lewat input tersembunyi. Bila katalog akses belum disinkronkan, tidak ada yang dibuat dan muncul petunjuk.
- Bukti sementara: 8 test Pest baru menunggu dijalankan di Laragon (target 199).

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
- `tests/TestCase.php` memakai `withoutVite()`: test backend tidak bergantung pada `npm run build` (pelajaran F2b).
- Jangan pakai `Route::redirect()`; pakai route GET biasa (Wayfinder belum mendukung route semua-method).
- Data contoh di test wajib mematuhi aturan validasi yang sama dengan data asli (pelajaran F1: kode node 1 karakter ditolak).
- Filter tenant otomatis (`BelongsToTenant`) hanya aktif bila konteks kerja ada (request web lewat `work.context`). Konsol/seeder tetap wajib memakai filter `tenant_id` eksplisit.
- Halaman frontend tetap di `resources/js/pages/` (konvensi starter kit), bukan `resources/js/core/` seperti sketsa ADR-001 §2.2.
- Nama resmi Yayasan & Pondok Pesantren belum diberikan; seeder memakai nama sementara.
- Setiap ada permission/role baru di kode: jalankan `php artisan educore:sync-access` (juga saat deploy).
- Untuk permission katalog, `Gate::before` memberi jawaban akhir; policy modul dengan nama ability yang sama tidak dijalankan. Aksi terhadap data tertentu wajib mengirim datanya ke Gate.
- Pencatatan perubahan aturan ke audit log menunggu F4.

## Tugas berikutnya

1. Verifikasi perintah akun pertama di Laragon dan commit.
2. Susun PRD HR baru (dari HR-001 s.d. HR-016).
3. Susun PRD Academic (termasuk satu orang bisa menjadi siswa SMP sekaligus santri MDA, PRD-000 §12).
