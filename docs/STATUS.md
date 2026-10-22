# Status Proyek EduCore

- **Diperbarui:** 2026-10-21
- **Tahap aktif:** P3 — salin mapel & pengampu dari kelas lain (kode siap, menunggu verifikasi owner)
- **Commit terakhir:** `1d076ba` — feat(academic): P2 buka kembali pendaftaran

## Keputusan penting

| Tanggal    | Keputusan                                                                                                                                                                                                                                          | Dokumen                    |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------- |
| 2026-10-09 | Rebuild dari nol sebagai simplified modular monolith; dokumen lama jadi referensi domain                                                                                                                                                           | ADR-001                    |
| 2026-10-09 | Frontend Inertia 3 + React (starter kit resmi), auth Fortify, satu jalur session                                                                                                                                                                   | ADR-001 §2.1               |
| 2026-10-09 | Pohon lembaga fleksibel (maks 6 tingkat); induk melihat turunan, tidak sebaliknya                                                                                                                                                                  | PRD-000 §4, §7             |
| 2026-10-09 | Lembaga formal di dalam unit = anak unit; koordinator di bawah Biro pusat dengan penugasan fungsional (filter jenjang)                                                                                                                             | PRD-000 §4.4               |
| 2026-10-09 | Aturan berjenjang: terdekat menang, kecuali induk mengunci                                                                                                                                                                                         | PRD-000 §8                 |
| 2026-10-09 | Modul awal: Core, HR, Academic                                                                                                                                                                                                                     | ADR-001 §2.2               |
| 2026-10-10 | Fitur auth starter kit: registrasi & verifikasi email OFF, 2FA & konfirmasi password ON, passkey OFF                                                                                                                                               | PRD-000 OD-04              |
| 2026-10-10 | Susunan tenant pertama: 4 Biro pusat + Ponpes dengan Unit 1 (MTs, MA), Unit 2 (MTs, MA), Unit 3 (SMP, SMK), masing-masing + MDA, Bahasa, Al-Qur'an                                                                                                 | PRD-000 §4.3               |
| 2026-10-10 | F1 tanpa halaman admin; halaman pohon lembaga dibuat setelah F3 (butuh login & RBAC)                                                                                                                                                               | PRD-000 §10                |
| 2026-10-10 | Person hanya `name` + `gender`; hapus akun mandiri dihilangkan; UI starter kit diterjemahkan ke Bahasa Indonesia; F2 dipecah F2a/F2b                                                                                                               | PRD-000 OD-05–07           |
| 2026-10-10 | Penugasan tunggal otomatis dipilih; tanpa penugasan → konteks Seluruh Yayasan; akun uji hanya local/testing; penugasan fungsional boleh di tingkat Yayasan (`organization_id` kosong)                                                              | PRD-000 OD-08–10, §5       |
| 2026-10-10 | Katalog role & permission di kode + `educore:sync-access`; superadmin tanpa jalan pintas; di luar cakupan 404, di dalam cakupan tanpa izin 403                                                                                                     | PRD-000 OD-11–13, §7.1     |
| 2026-10-10 | Halaman admin pohon lembaga: tambah, ubah nama (kode tetap), pindah, nonaktifkan, aktifkan kembali; nonaktif disembunyikan kecuali diminta                                                                                                         | PRD-000 §4.6, OD-14        |
| 2026-10-13 | Akun pertama production lewat perintah interaktif `educore:create-admin` & `educore:create-superadmin`                                                                                                                                             | PRD-000 OD-19              |
| 2026-10-13 | HR dibangun bertahap mulai HR-1 Data Pegawai; model disederhanakan (pegawai → periode kepegawaian + penempatan); data pribadi minimal tanpa NIK; kelola pegawai satu pintu di Admin SDM                                                            | PRD-001 §3–§5, OD-HR-01–03 |
| 2026-10-14 | Impor pegawai Excel/CSV: template + pratinjau, simpan semua atau tidak, orang ganda = peringatan + konfirmasi; library openspout ~5.3 (PHP 8.3)                                                                                                    | PRD-001 §6.2, OD-HR-05     |
| 2026-10-17 | Peringatan orang ganda (impor & form) dipicu nama sama, tidak hanya nama + tanggal lahir                                                                                                                                                           | PRD-001 §6, §6.2           |
| 2026-10-17 | Academic tahap 1 = data siswa + kelas; data siswa minimal + NISN + wali (tanpa NIK); tahun ajaran default yayasan, lembaga boleh beda                                                                                                              | PRD-002 OD-AC-01–03        |
| 2026-10-17 | Data siswa dikelola Operator Akademik per lembaga; orang tua/wali = Person tanpa akun; wali kelas lihat kelasnya di AC-3; impor siswa AC-1e                                                                                                        | PRD-002 OD-AC-04–08        |
| 2026-10-20 | Impor siswa: satu file = satu lembaga; NISN + nama cocok = siswa lama (hanya didaftarkan); kolom ayah/ibu/wali + kontak utama + kelas; dipecah AC-1e-a/b                                                                                           | PRD-002 AC-1e              |
| 2026-10-20 | AC-1d dipecah d-a (kelas & wali kelas) dan d-b (anggota kelas); kelas tahun ajaran lewat hanya dilihat; tingkat 1–12 tanpa batas per jenjang                                                                                                       | PRD-002 §11 (AC-1d-a)      |
| 2026-10-19 | Orang tua/wali: cari HP hanya di antara orang tua/wali tercatat; orang tua yang juga pegawai/siswa hanya bisa diubah hubungannya; lepas kontak utama wajib pilih pengganti                                                                         | PRD-002 §11 (AC-1c-b2)     |
| 2026-10-18 | AC-1c-b dipecah b1 (data diri & pendaftaran, termasuk ubah pendaftaran) dan b2 (orang tua/wali); cari orang tua lewat HP cocok persis tanpa nama anak                                                                                              | PRD-002 §11, §4.5          |
| 2026-10-18 | Data diri siswa boleh diubah Operator Akademik lembaga mana pun tempat siswa aktif (+ Admin Yayasan); lembaga tetap melihat alumninya                                                                                                              | PRD-002 OD-AC-09, §5.3     |
| 2026-10-14 | Satu lembaga kerja aktif; halaman tingkat Yayasan hanya dari "Seluruh Yayasan"; tanpa prefetch di navigasi                                                                                                                                         | PRD-000 §6                 |
| 2026-10-14 | Kelola akses dari detail pegawai, hanya Admin Yayasan; akun dibuat dengan kata sandi sementara tampil sekali + wajib ganti; Kepala Lembaga tidak lagi kelola anggota; akun Superadmin & akun lintas yayasan tidak bisa diatur dari halaman yayasan | PRD-000 §4.7, OD-20–22     |
| 2026-10-13 | Audit log aksi penting (append-only, IP & user agent, masa simpan 1 tahun); halaman log audit untuk Admin Yayasan; README mengasumsikan VPS Linux, script backup Linux & Windows                                                                   | PRD-000 OD-15–18           |

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

**Akun pertama untuk production: SELESAI (2026-10-13, commit `cee8158`)**

- `CreateAccount` (Person + User dalam satu transaksi, validasi email/username/kata sandi mengikuti `Password::defaults()`, tercatat `account.created`).
- `php artisan educore:create-admin <KODE>`: akun + keanggotaan + role Admin Yayasan sekaligus; akun yang sudah ada bisa dinaikkan setelah konfirmasi.
- `php artisan educore:create-superadmin`: akun Superadmin baru, atau menaikkan akun yang ada (`account.superadmin_granted`).
- Kata sandi hanya lewat input tersembunyi. Bila katalog akses belum disinkronkan, tidak ada yang dibuat dan muncul petunjuk.
- Bukti: 199 test lulus (1007 assertions); Pint PASS; Larastan level 7 tanpa error; uji manual `create-admin` & `create-superadmin` berhasil, login di browser lancar.
- Pelajaran: Larastan menolak `$x ?? …` bila menurut analisisnya `$x` pasti terisi (`nullCoalesce.variable`). Pakai cabang eksplisit, mis. siapkan closure per cabang `if/else`.

## Modul HR

**PRD-001 HR: v0.3 (2026-10-14)** — HR-1 Data Pegawai dirinci; HR-2 s.d. HR-6 digariskan. Keputusan: data pribadi minimal tanpa NIK (OD-HR-01); hanya Admin SDM/Admin Yayasan yang mengelola pegawai (OD-HR-02); halaman kelola akses Core dibuat sesudah HR-1, sebelum HR-3 (OD-HR-03).

**HR-1a — Katalog SDM & skema kepegawaian: SELESAI (2026-10-13, commit `cd2dc2d`)**

- Migrasi: kolom data pribadi di `persons` (tempat/tanggal lahir, HP, alamat); tabel `hr_employment_types`, `hr_employment_classifications`, `hr_positions`, `hr_employees`, `hr_employment_periods`, `hr_placements` dengan FK gabungan per yayasan, partial unique index (satu periode terbuka, satu penempatan utama, penempatan tidak dobel), dan CHECK format (diuji langsung di PostgreSQL 16).
- `HrAccess`: 4 permission HR + role **Admin SDM**; Admin Yayasan, Pimpinan, Kepala Lembaga, Koordinator diperluas sesuai PRD-001 §5.2.
- Katalog SDM: `CreateCatalogItem`, `UpdateCatalogItem` (ubah nama, nonaktif/aktif), `HrCatalogSeeder` (isi awal 3 + 4 + 9, aman diulang), halaman `/pegawai/katalog` + menu **Katalog SDM**.
- Label aksi modul didaftarkan ke Core lewat `AuditAction::registerLabels()` (Core tetap tidak mengenal HR).
- Bukti: 215 test lulus (1132 assertions); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual di browser & HP lancar.
- Perbaikan: test memakai akun `guru` (dua penugasan) lupa memilih lembaga kerja → 302, bukan 403. Tab katalog di HP terpotong (geser samping) → diganti grid `auto-fit` yang otomatis turun baris.
- Pelajaran: (1) akun uji dengan lebih dari satu penugasan (`guru`, `koordinator.mda`) wajib `DevAccounts::chooseWorkspace()` dulu di test; (2) **aturan UI**: tab/filter/tombol berderet di HP memakai grid yang otomatis turun baris, bukan scroll horizontal.

**HR-1b — Tambah pegawai: SELESAI (2026-10-13, commit `740249c`)**

- Model `Employee`, `EmploymentPeriod`, `Placement` (+ enum status, pendidikan, alasan berhenti).
- `CreateEmployee`: orang baru (Person + Membership) **atau** anggota yayasan yang sudah ada → Pegawai → periode pertama → penempatan utama, dalam satu transaksi; pemeriksaan lembaga/katalog dilakukan setelah Person dibuat sehingga kegagalan membatalkan semuanya.
- Peringatan orang ganda (nama + tanggal lahir sama, hanya di yayasan yang sama) dengan konfirmasi; nomor induk & NUPTK unik dengan pesan ramah; nomor HP/NUPTK/nomor induk dirapikan otomatis.
- Halaman `/pegawai/tambah` (tombol sementara di Katalog SDM); audit `hr.employee_created` tanpa nilai data pribadi.
- `BusinessClock` + `EDUCORE_TIMEZONE` (default Asia/Jakarta) untuk aturan "tidak boleh di masa depan".
- Bukti: 223 test lulus (1245 assertions); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual (orang baru, anggota, peringatan orang ganda, nomor induk ganda, tanggal masa depan, audit, tampilan HP) lancar.

**HR-1c — Daftar & detail pegawai: SELESAI (2026-10-13, commit `e8faca0`)**

- `EmployeeVisibility`: siapa melihat pegawai mana (PRD-001 §5.3) sebagai saringan SQL sebelum paginasi; pemegang izin tingkat Yayasan melihat semua termasuk pegawai tanpa penempatan.
- `EmployeeDirectory` + `EmployeeFilters`: cari nama/nomor induk, saringan lembaga (ikut turunan), jabatan, jenis, status kepegawaian, aktif/berhenti; 25 per halaman (maks 100).
- `EmployeeProfile` + `ServiceLength`: detail pegawai, riwayat periode & penempatan, masa kerja; data pribadi hanya dengan `hr.employees.view_personal`.
- Halaman `/pegawai` (menu **Pegawai**, tabel di desktop, kartu di HP) dan `/pegawai/{id}`; tombol Tambah pegawai pindah dari Katalog SDM ke daftar; setelah tambah langsung ke detail.
- Bukti: 232 test lulus (1380 assertions); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual 4 akun (Admin, Kepala Unit 1, Koordinator MDA, Guru) dan tampilan HP lancar.
- Perbaikan: Larastan menolak `whereRaw()` dengan string rakitan → helper SQL diberi tipe `literal-string`.
- Pelajaran: SQL mentah di `whereRaw()` hanya dirakit dari teks tetap di kode (`@param/@return literal-string`); nilai dari pengguna selalu lewat binding `?`.

**HR-1d — Aksi di detail pegawai: SELESAI (2026-10-14, commit `d72eb3d`) → HR-1 Data Pegawai SELESAI**

- Service: `UpdateEmployeeProfile`, `ChangeEmploymentStatus`, `ManagePlacements` (tambah, akhiri, mutasi, jadikan utama), `EndEmployment`, `RehireEmployee`; pemeriksaan bersama di `EmployeeGuard` (kunci baris pegawai, status aktif, rujukan aktif, tanggal tidak di masa depan).
- `EmployeeActionController` + 8 route aksi; tombol aksi & form di detail pegawai (`components/hr/employee-actions.tsx`), tombol tersusun grid otomatis di HP.
- 8 aksi audit baru (`hr.employee_updated` … `hr.employee_rehired`); ubah profil hanya mencatat nama field.
- Bukti: 242 test lulus (1504 assertions); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual 10 langkah (termasuk HP) lancar.
- Perbaikan: Larastan menolak `->locale('id')->translatedFormat()` (method Carbon dua fungsi) dan `array_values()` pada array yang sudah berurutan.
- Pelajaran: hindari method getter/setter ganda (mis. Carbon `locale()`) di tengah rantai; nama bulan Indonesia disusun sendiri.

**HR-1e — Impor pegawai dari Excel/CSV: SELESAI (2026-10-17, commit `b5cc66f` + `7902d3a` + `6a419f1`)**

- Library `openspout/openspout` ~5.3.0 (versi terakhir yang mendukung PHP 8.3); butuh ekstensi `zip`, `xmlreader`, `dom`.
- `EmployeeImportTemplate` (lembar Pegawai berformat Teks/tanggal, Petunjuk, Kode milik yayasan), `EmployeeImportReader` (xlsx/csv, pemisah `,`/`;`, UTF-8/Windows-1252, maks. 1.000 baris), `EmployeeImportValidator` (aturan sama dengan form + kode, dobel di file, NUPTK/HP berupa angka ditolak), `PendingImportStore` (file privat, ID di session, `hr:prune-imports` tiap jam), `ImportEmployees` (periksa ulang, satu transaksi lewat `CreateEmployee`, audit `hr.employees_imported`).
- Halaman `/pegawai/impor` (tombol **Impor** di daftar pegawai): unduh template → unggah → pratinjau Siap/Peringatan/Salah → Simpan (konfirmasi bila ada peringatan) / Batalkan.
- Bukti: 328 test lulus sebelum perbaikan peringatan (perbaikan menambah 1 test → 329); Pint PASS; Larastan level 7 tanpa error; build lulus; uji manual (xlsx, csv `;`, peringatan + konfirmasi, simpan) lancar.
- Perbaikan: Larastan menolak `instanceof` yang pasti benar pada nilai dari library bertipe ketat; peringatan orang ganda tidak muncul saat uji owner → kini juga berdasarkan **nama saja** dengan tanggal lahir orang lama ditampilkan.
- Lanjutan (2026-10-17): form **Tambah pegawai** ikut memperingatkan bila nama sama (tanggal lahir sama, beda, atau kosong); pesan menyebut tanggal lahir orang lama. Bukti: **330 test lulus (2305 assertions)**; Pint PASS; Larastan level 7 tanpa error; uji manual owner lancar.
- Pelajaran: (1) Excel bisa membaca tanggal berbeda menurut format regional Windows (12/04 ↔ 04/12) — pengaman orang ganda tidak boleh hanya bergantung pada tanggal lahir; (2) pilih versi library sesuai PHP CI (`~5.3.0`), jangan otomatis terbaru; (3) jangan menambah `instanceof` untuk nilai dari library yang sudah bertipe ketat.

## Kelola akses (PRD-000 §4.7)

**F5a — Akun login: SELESAI (2026-10-14, commit `0d26a2c`)**

- Migrasi: `users.email` boleh kosong (wajib email **atau** username, dijaga CHECK `users_login_identifier_check`), kolom `must_change_password`.
- `ManageLoginAccount`: buat akun (username wajib, email opsional), atur ulang sandi, nonaktifkan, aktifkan; pengaman akun Superadmin / akun yang aktif di yayasan lain; admin tidak bisa menonaktifkan diri sendiri.
- `TemporaryPassword` (12 karakter acak kriptografis, semua golongan karakter, tanpa karakter mirip); dikirim sekali lewat flash Inertia `issuedPassword`, tidak masuk audit log.
- `UserSessions`: hapus sesi database + ganti token "ingat saya" saat atur ulang sandi / nonaktifkan.
- Middleware `EnsureAccountUsable` (grup web): akun nonaktif dikeluarkan di request berikutnya; akun bersandi sementara hanya boleh ke `/akun/ganti-sandi` dan Keluar.
- `LoginAccountController` (4 route `akses/anggota/{membership}/akun…`), `PasswordChangeController`, bagian **Akses login** di detail pegawai (`components/core/login-access.tsx`), halaman `auth/change-password`.
- Audit baru: `account.password_reset`, `account.deactivated`, `account.activated`; `account.created` kini juga dari admin (tercatat di log yayasan).
- Kepala Lembaga dilepas dari `core.memberships.manage`; profil boleh tanpa email bila punya username.
- Bukti: 262 test lulus (1941 assertions); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual 11 langkah (incognito, HP) lancar.

**F5b — Penugasan & role: SELESAI (2026-10-14, commit `5f7ef8c`) → F5 Kelola akses SELESAI**

- `AccessCatalog::offerAsTenantRole/offerAsAssignmentRole`: pilihan role di halaman ditentukan kode (tingkat Yayasan: Admin Yayasan, Pimpinan, Admin SDM dari modul HR; penugasan: Pimpinan, Kepala Lembaga, Koordinator, Staf).
- `ManageMemberAccess`: pasang/cabut role tingkat Yayasan (tidak bisa mencabut Admin Yayasan milik sendiri; Admin Yayasan terakhir dijaga dengan kunci baris), tambah penugasan struktural/fungsional wajib satu role (penugasan yang pernah dicabut diaktifkan kembali), cabut penugasan (role dilepas, status nonaktif, audit `access.assignment_revoked`).
- `MemberAccessSummary` + `MemberAccessController` (4 route `akses/anggota/{membership}/role-yayasan…`, `…/penugasan…`); panel Role tingkat Yayasan & Penugasan di bagian Akses login detail pegawai (`components/core/member-access.tsx`), cabut selalu dengan konfirmasi.
- Perbaikan saat uji manual: (1) menu Katalog SDM & Log audit hanya tampil di lembaga kerja **Seluruh Yayasan** (sebelumnya muncul di lembaga kerja penugasan lalu ditolak server); (2) **prefetch** link navigasi dimatikan dan cache halaman Inertia dibersihkan setelah ganti yayasan/lembaga kerja — sebelumnya halaman simpanan dari lembaga kerja lama tampil selama 30 detik sehingga konteks terlihat "pindah sendiri"; (3) test audit tidak lagi bergantung urutan `created_at` (presisi detik).
- Bukti: 283 test lulus (2093 assertions); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual (incognito, pindah lembaga kerja, HP) lancar.
- Pelajaran: (1) data bergantung konteks kerja → jangan pakai prefetch/cache halaman; (2) menu adalah petunjuk, tetapi harus memakai aturan yang sama dengan server (izin **dan** tingkat lembaga kerja); (3) di test, cari baris audit berdasarkan isi/ID, bukan urutan waktu.

## Modul Academic

**AC-1 Data Siswa & Kelas: SELESAI (2026-10-20)** — AC-1a s.d. AC-1e, commit terakhir `1938694`.

**PRD-002 Academic: v1.4 (2026-10-21)** — AC-1 Data Siswa & Kelas disetujui owner (OD-AC-01–09); rincian AC-1a s.d. AC-1e; rincian AC-2 di §15; rincian AC-3 (a–d) di §16; rincian AC-4 di §17; penyempurnaan P1–P4 di §18 (v1.4).

**AC-1a — Skema, Operator Akademik, kalender akademik: SELESAI (2026-10-17, commit `ccc183b`)**

- Migrasi 8 tabel `ac_*` sekaligus: kalender/tahun ajaran/semester, siswa (NISN), orang tua/wali, pendaftaran per lembaga, kelas, anggota kelas. Constraint (satu pendaftaran terbuka per lembaga, satu tahun berjalan per kalender, kelas & pendaftaran di lembaga yang sama, satu kelas terbuka per tahun ajaran) diuji langsung di PostgreSQL 16.
- `AcademicAccess`: 5 permission + role **Operator Akademik** (ditawarkan di Kelola akses sebagai role penugasan); Admin Yayasan, Pimpinan, Kepala Lembaga, Koordinator diperluas.
- Kalender: `ManageCalendars` (Kalender Yayasan bawaan, tambah, ubah nama), `SaveAcademicYear` (tahun ajaran + 1–4 semester), `SetCurrentYear`, `AssignCalendar` (aturan berjenjang `academic.calendar`), `AcademicCalendarResolver`, `CalendarOverview`.
- Halaman `/akademik/kalender` (menu **Kalender akademik**, hanya Seluruh Yayasan); seeder `AcademicCalendarSeeder` (semua lingkungan) & `AcademicDevSeeder` (akun `operator.smp` + contoh tahun ajaran, local/testing).
- Bukti: 363 test lulus (33 test baru); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual 10 langkah (termasuk HP) lancar.

**AC-1b — Tambah siswa: SELESAI (2026-10-18, commit `759ac3d` bersama AC-1c-a)**

- Model `Student`, `Enrollment` (OwnedByOrganization), `StudentGuardian` + enum jenis masuk, alasan keluar, hubungan.
- `CreateStudent`: orang baru **atau** orang yang sama (dipilih dari calon nama sama, `DuplicateCandidates`) → profil siswa → pendaftaran → 0–4 orang tua/wali, satu transaksi; NISN unik per yayasan, nomor induk tidak dipakai siswa lain di lembaga itu; orang tua dengan nama + HP sama dipakai ulang.
- Halaman `/siswa/tambah` (menu **Siswa** sementara); lembaga dicek lewat Gate (di luar cakupan 404, tanpa izin 403).
- Audit `academic.student_created`, `academic.enrollment_created`, `academic.guardian_linked` (nama field data pribadi saja).
- Pesan kesalahan dikumpulkan sekaligus per isian + ringkasan di atas tombol Simpan (permintaan owner 2026-10-18).
- Bukti: 390 test lulus (27 test baru); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual owner lancar.
- Catatan pengiriman: paket perbaikan diberi nama baru (`-v2`) agar tidak tertukar dengan unduhan lama yang bernama sama.
- Pelajaran: Larastan menolak pemeriksaan `!== null` yang sudah pasti benar karena dicek beberapa baris sebelumnya (`notIdentical.alwaysTrue`); setelah nilai dipastikan, jangan diperiksa lagi.

**AC-1c-a — Daftar & detail siswa: SELESAI (2026-10-18, commit `759ac3d`)**

- `StudentVisibility` (saringan SQL sebelum paginasi; pendaftaran terbuka maupun ditutup di node terlihat), `StudentFilters`, `StudentDirectory`, `StudentProfile`.
- Halaman `/siswa` (menu **Siswa** untuk `academic.students.view`, tabel di desktop, kartu di HP) dan `/siswa/{id}` (data pribadi & orang tua/wali hanya dengan `view_personal`; semua pendaftaran tampil); tambah siswa kini langsung ke detail.
- Bukti: 397 test lulus (2883 assertions, 7 test baru); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual owner lancar.
- Pelajaran test: `actingAs()` di `beforeEach` tetap berlaku untuk semua request di test itu. Untuk menguji "tamu", panggil `$this->app['auth']->forgetGuards()` dulu.

**AC-1c-b1 — Ubah data diri & pendaftaran: SELESAI (2026-10-19, commit `1b572e8`)**

- `UpdateStudentProfile` (data diri + NISN, OD-AC-09), `ManageEnrollments` (daftarkan ke lembaga lain, ubah, tutup; dipakai juga `CreateStudent`), `StudentManageAccess` (siapa boleh apa).
- `StudentActionController` + route `PATCH siswa/{id}`, `POST siswa/{id}/pendaftaran`, `PATCH …/pendaftaran/{id}`, `POST …/pendaftaran/{id}/tutup`.
- Detail siswa: tombol **Ubah data diri**, **Daftarkan ke lembaga lain**, dan per pendaftaran **Ubah** / **Tutup** (hanya bagi yang berhak); form menampilkan ringkasan kesalahan.
- Audit `academic.student_updated`, `academic.enrollment_updated`, `academic.enrollment_closed`.
- Bukti: 410 test lulus (14 test baru); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual owner lancar.
- Pelajaran test: untuk menimpa isian di test pakai `array_merge($dasar, $timpa)`, bukan `$dasar + $timpa` (operator `+` mempertahankan nilai kiri, sehingga timpaan diam-diam diabaikan).
- `composer test` kini memakai `Composer\Config::disableProcessTimeout`: dengan 400+ test, durasinya mendekati batas bawaan Composer 300 detik dan sempat terhenti.

**AC-1c-b2 — Orang tua/wali di detail siswa: SELESAI (2026-10-20, commit `dd5744d`)**

- `ManageGuardians` (cari HP, tambah baru/dari pencarian, ubah, lepas, kontak utama); aturan "nama + HP sama dipakai ulang" kini satu tempat dan dipakai juga `CreateStudent`.
- Kontrak publik HR pertama: `Modules\HR\Contracts\EmployeeLookup` (+ `EloquentEmployeeLookup`, dipasang di `HRServiceProvider::register`).
- `StudentGuardianController` + route `GET siswa/{id}/orang-tua/cari` (JSON, throttle 30/menit), `POST/PATCH/DELETE siswa/{id}/orang-tua…`, `POST …/kontak-utama`.
- Detail siswa: tombol Tambah orang tua/wali, dan per orang Ubah / Jadikan kontak utama / Lepas.
- Bukti: 420 test lulus (10 test baru); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual owner lancar.

**AC-1d-a — Kelas & wali kelas: SELESAI (2026-10-20, commit `0fb2643`)**

- Kontrak HR `ActiveEmployees` (+ `EloquentActiveEmployees`): pegawai aktif dengan penempatan berjalan di lembaga, cek wali kelas, nama + status aktif.
- `SchoolClass` (model `ac_classes`, OwnedByOrganization), `ManageClasses` (tambah, ubah, nonaktif/aktif, hapus kelas kosong), `ClassDirectory` (pilihan tahun ajaran, daftar dengan jumlah L/P).
- `ClassController` + route `GET/POST kelas`, `PATCH/DELETE kelas/{id}`, `POST kelas/{id}/status`; halaman `/kelas`, menu **Kelas**.
- Bukti: 429 test lulus (9 test baru); Pint PASS; Larastan level 7 tanpa error; `types:check`, `check:fix`, build lulus; uji manual owner lancar.

**AC-1d-b — Anggota kelas: SELESAI (2026-10-20, commit `c7f7dd8`)**

- `ClassMember` (model `ac_class_members`, OwnedByOrganization), `ManageClassMembers` (calon anggota, masukkan banyak sekaligus, pindah, keluarkan, masuk kelas dari form Tambah siswa).
- `ClassMemberController` + route `GET kelas/{id}`, `POST kelas/{id}/anggota`, `POST kelas/anggota/{id}/pindah|keluar`; halaman `/kelas/{id}`.
- Detail siswa: bagian **Kelas** dengan Pindah/Keluarkan; form Tambah siswa: pilihan **Kelas**.
- Bukti: 10 test baru (target 439 test); uji manual owner 10 langkah lancar.

**AC-1e-a — Impor siswa (template, unggah, pratinjau): SELESAI (2026-10-20, commit `e09a3f3`)**

- Core `Support/Import`: `SpreadsheetReader` (dipindah dari HR, dipakai HR & Academic), `ImportFileException`, `ImportCell`, `PendingImportFiles`. `EmployeeImportReader` HR kini memakai reader Core (perilaku sama).
- Academic `Application/Import`: `StudentImportColumns`, `StudentImportReader`, `StudentImportValidator` (+ `StudentImportDraft`), `StudentImportRow`, `StudentImportPreview`, `StudentImportTemplate`, `PendingStudentImport`; perintah `academic:prune-imports` (tiap jam).
- `StudentImportController` + route `GET/POST siswa/impor`, `GET siswa/impor/template?lembaga=`, `POST siswa/impor/batal`; halaman `/siswa/impor`, tombol **Impor** di daftar siswa.
- Bukti: 464 test lulus (25 test baru, termasuk 15 dataset kolom) setelah perbaikan Larastan `identical.alwaysFalse` (pemeriksaan ulang variabel yang sudah pasti terisi); uji manual owner lancar.

**AC-1e-b — Simpan impor siswa: SELESAI (2026-10-20, commit `1938694`)**

- `ImportStudents` (baca & periksa ulang, konfirmasi peringatan, semua baris lewat `CreateStudent` dalam satu transaksi), `ImportSaveException`; audit `academic.students_imported`.
- Route `POST siswa/impor/simpan`; tombol **Simpan N siswa ke {lembaga}** + centang konfirmasi peringatan di halaman impor.
- Bukti: 6 test baru (target 470 test); uji manual owner lancar (simpan, konfirmasi peringatan, semua-atau-tidak).

**AC-2 — Kenaikan kelas & kelulusan: SELESAI (2026-10-21, commit `ece7aa6`)**

- `YearEndProcess` (Classroom): tahun tujuan = tahun berikutnya di kalender yang sama; pilihan bawaan per siswa; simpan naik/tinggal (`ManageClassMembers::placeNewEnrollment`) & lulus (`ManageEnrollments::close`, alasan LULUS) dalam satu transaksi, semua-atau-tidak.
- `ClassYearEndController` + route `GET/POST kelas/{class}/akhir-tahun`; halaman `/kelas/{id}/akhir-tahun`; tombol **Proses akhir tahun** di detail kelas (`canYearEnd`, juga untuk tahun yang sudah lewat).
- Audit `academic.class_year_end_processed`.
- Bukti: 477 test lulus (7 test baru, `YearEndTest`); uji manual owner lancar.

**AC-3 Mata pelajaran & jadwal: SELESAI (2026-10-21)** — AC-3a `9a0b128`, AC-3b `26d3594`, AC-3c `2e58909`, AC-3d `6878d84`.

**AC-3a — Mata pelajaran: SELESAI (2026-10-21, commit `9a0b128`)**

- Tabel `ac_subjects` (migrasi `2026_10_21_200005`); model `Domain/Subject/Subject`; `Application/Subject/ManageSubjects`, `SubjectData`, `SubjectDirectory`; `SubjectException`.
- `SubjectController` + route `GET/POST akademik/mapel`, `PATCH/DELETE akademik/mapel/{subject}`, `POST akademik/mapel/{subject}/status`; halaman `/akademik/mapel`, menu **Mata pelajaran**.
- Audit `academic.subject_created|updated|deactivated|reactivated|deleted`.
- Bukti: 487 test lulus (10 test baru, `SubjectsTest`) setelah perbaikan test akun `guru` (wajib memilih lembaga kerja dulu); uji manual owner lancar.

**AC-3b — Mapel kelas & guru pengampu: SELESAI (2026-10-21, commit `26d3594`)**

- Tabel `ac_class_subjects` (migrasi `2026_10_21_200006`; ikut terhapus bila kelas kosong dihapus); model `Domain/Classroom/ClassSubject`; `Application/Classroom/ManageClassSubjects` (`available`, `add`, `setTeacher`, `remove`); `ClassDirectory::subjects`.
- `ClassSubjectController` + route `POST kelas/{class}/mapel`, `PATCH/DELETE kelas/mapel/{classSubject}`; bagian **Mata pelajaran** di detail kelas (`components/academic/class-subject-actions.tsx`).
- Mapel yang sudah dipakai di kelas tidak bisa dihapus (`ManageSubjects::delete`, `deletable` di daftar mapel).
- Audit `academic.class_subjects_added`, `class_subject_teacher_changed`, `class_subject_removed`.
- Bukti: 494 test lulus (7 test baru, `ClassSubjectsTest`); uji manual owner lancar.

**AC-3c — Jadwal pelajaran: SELESAI (2026-10-21, commit `2e58909`)**

- Tabel `ac_schedule_entries` (migrasi `2026_10_21_200007`; FK gabungan ke kelas & mapel kelas, ikut terhapus bila mapel dilepas atau kelas kosong dihapus); model `Domain/Classroom/ScheduleEntry`.
- `Application/Classroom/ManageSchedule` (tambah/ubah/hapus), `ScheduleData` (jam "HH:MM"), `ScheduleConflicts` (bentrok kelas; bentrok guru seluruh yayasan pada tahun ajaran yang beririsan; kunci `pg_advisory_xact_lock` per guru). `ManageClassSubjects::setTeacher` memeriksa bentrok guru baru; `remove` mencatat jumlah jadwal yang ikut terhapus.
- `ScheduleController` + route `POST kelas/{class}/jadwal`, `PATCH/DELETE kelas/jadwal/{entry}`; bagian **Jadwal pelajaran** di detail kelas (`components/academic/schedule-actions.tsx`).
- Audit `academic.schedule_entry_added|updated|removed`.
- Bukti: 501 test lulus (7 test baru, `ScheduleTest`) setelah perbaikan Larastan `noUnnecessaryCollectionCall` (pakai `->first([...])` pada query, bukan `->get()->first()`); uji manual owner lancar.

**AC-3d — Kelas saya: SELESAI (2026-10-21, commit `6878d84`)**

- Kontrak HR `EmployeeLookup::employeeIdForMembership` (pegawai milik akun yang login).
- `Application/Classroom/MyClasses` (kelas tahun berjalan sebagai wali kelas/pengampu di lembaga tempat ia aktif; siswa; kontak utama untuk wali kelas; jadwal mengajar). `MyClassesController` + route `GET kelas-saya`, `GET kelas-saya/{class}`; halaman `/kelas-saya`, menu **Kelas saya** (prop bersama `academic.myClasses` dari `AcademicServiceProvider`).
- Tanpa permission baru: akses dari data penugasan mengajar; kelas yang tidak dipegang → 404. Catatan form kelas & docblock `ManageClasses` disesuaikan dengan §7 baru.
- Bukti: 506 test lulus (5 test baru, `MyClassesTest`); uji manual owner lancar.

**AC-4 Presensi siswa: SELESAI (2026-10-21)** — AC-4a `5fceb18`, AC-4b `887a384`, AC-4c `98a3aac`.

**AC-4a — Presensi harian: SELESAI (2026-10-21, commit `5fceb18`)**

- Tabel `ac_attendance_sessions` & `ac_attendance_records` (migrasi `2026_10_21_200008`; satu sesi harian per kelas per tanggal); `Domain/Attendance` (`AttendanceSession`, `AttendanceStatus` H/S/I/A, `AttendanceException`).
- `Application/Attendance/ManageAttendance` (rentang tanggal wali kelas 7 hari / operator seluruh tahun ajaran, daftar siswa per tanggal, simpan semua sekaligus, koreksi).
- `AttendanceController` + route `GET/POST kelas/{class}/presensi` (operator) dan `GET/POST kelas-saya/{class}/presensi` (wali kelas); halaman `academic/attendance/sheet`; tombol **Presensi** di detail kelas dan **Isi presensi** di Kelas saya.
- Audit `academic.attendance_recorded` (jumlah per kode, tanda koreksi; tanpa isi keterangan).
- Bukti: 512 test lulus (6 test baru, `AttendanceTest`); uji manual owner lancar. Perbaikan fix1: `ExecutionTime::atLeast` di controller impor siswa & pegawai.

**AC-4b — Rekap presensi: SELESAI (2026-10-21, commit `887a384`)**

- `Application/Attendance/AttendanceRecap` (periode tahun ajaran / semester / bulan yang sudah mulai; bawaan bulan berjalan; jumlah H/S/I/A & % hadir per siswa; siswa yang pindah tetap tampil dengan presensi selama di kelas).
- Route `GET kelas/{class}/rekap-presensi` (izin lihat siswa) dan `GET kelas-saya/{class}/rekap-presensi` (wali kelas); halaman `academic/attendance/recap`; tombol **Rekap presensi** di detail kelas & Kelas saya.
- Bukti: 515 test lulus (3 test baru, `AttendanceRecapTest`) setelah perbaikan test (persen bulat terbaca sebagai bilangan bulat di props); uji manual owner lancar.

**AC-4c — Presensi per pertemuan & mode presensi: SELESAI (2026-10-21, commit `98a3aac`)**

- Aturan berjenjang `academic.attendance_mode` (`AttendanceModeSetting`, HARIAN/PER_PERTEMUAN, bawaan HARIAN); bagian **Mode presensi** di Kalender akademik (`AttendanceModeOverview`, route `POST akademik/kalender/mode-presensi`, hanya Admin Yayasan).
- Migrasi `2026_10_21_200009`: sesi menyimpan `class_subject_id`, `start_time`, `end_time`; unik harian (kelas+tanggal, tanpa mapel) & per pertemuan (baris jadwal+tanggal). Mapel kelas yang sudah punya presensi tidak bisa dilepas.
- `ManageAttendance` melayani harian & pertemuan (tanggal wajib hari jadwal; mode lembaga menentukan jenis yang dibuka). `AttendanceController`: route `GET/POST kelas/jadwal/{entry}/presensi` (operator) dan `kelas-saya/jadwal/{entry}/presensi` (pengampu).
- Rekap: pilihan Harian/Per pertemuan + saringan mapel; pengampu (bukan wali kelas) hanya pertemuan mapelnya. Tautan presensi di Jadwal mengajar (Kelas saya) & baris jadwal detail kelas.
- Bukti: 521 test lulus (6 test baru, `LessonAttendanceTest`); uji manual owner lancar.

**Penyempurnaan (PRD-002 §18)**

**P1 — Ekspor daftar siswa per kelas: SELESAI (2026-10-22, commit `3027bb4`)**

- `Application/Classroom/ClassRosterExport` (openspout; kolom dasar + tempat/tanggal lahir & kontak utama sesuai hak).
- Route `GET kelas/{class}/unduh` (izin lihat siswa; data pribadi & kontak bagi izin lihat data pribadi) dan `GET kelas-saya/{class}/unduh` (wali kelas: kontak utama tanpa tempat/tanggal lahir); tombol **Unduh daftar (.xlsx)** di detail kelas & Kelas saya.
- Bukti: 524 test lulus (3 test baru, `ClassRosterExportTest`); uji manual owner lancar.

**P2 — Buka kembali pendaftaran: SELESAI (2026-10-22, commit `1d076ba`)**

- `ManageEnrollments::reopen` (alasan wajib; tolak bila ada pendaftaran berjalan di lembaga itu; nomor induk diperiksa ulang sebagai pengaman; kelas yang tertutup pada tanggal keluar sama dibuka lagi bila kelas aktif, tahun belum lewat, belum ada kelas terbuka lain). `StudentManageAccess::canReopen` (izin kelola siswa tingkat Yayasan = Admin Yayasan).
- Route `POST siswa/{student}/pendaftaran/{enrollment}/buka-kembali`; tombol **Buka kembali** + form alasan di riwayat pendaftaran detail siswa.
- Audit `academic.enrollment_reopened` (tanpa isi alasan).
- Bukti: 528 test lulus (4 test baru, `ReopenEnrollmentTest`; fix1 memperbaiki skenario tes, bukan fitur); uji manual owner lancar.

**P3 — Salin mapel & pengampu dari kelas lain: KODE SIAP (2026-10-22, menunggu verifikasi)**

- `ManageClassSubjects::copySources` (kelas lain di lembaga yang sama yang punya mapel, tahun terbaru dulu; saran = kelas setingkat tahun ajaran sebelumnya di kalender yang sama, utamakan nama sama) dan `copyFrom` (mapel aktif, cocok tingkat, belum ada; pengampu ikut bila masih aktif di lembaga itu; jadwal tidak disalin; ditolak bila tidak ada yang bisa disalin).
- Route `POST kelas/{class}/mapel/salin` (`academic.class-subjects.copy`, `academic.classes.manage`); tombol **Salin dari kelas lain** di bagian Mata pelajaran detail kelas; `?salin=1#mapel` membuka formnya.
- Halaman proses akhir tahun: bagian **Mata pelajaran kelas tujuan** berisi tautan ke tiap kelas tujuan. Teks peringatan lulus disesuaikan dengan P2 (Admin Yayasan bisa membuka kembali).
- Audit `academic.class_subjects_added` dengan `disalin_dari`, `dilewati`, `pengampu_ikut`.
- Target bukti: 531 test (3 test baru, `CopyClassSubjectsTest`).

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
- Jangan panggil `set_time_limit()` langsung; pakai `Modules\Core\Support\ExecutionTime::atLeast()` (hanya menaikkan batas). `set_time_limit(300)` di controller impor sempat memasang batas 300 detik ke seluruh proses `composer test` sehingga test sesudahnya gagal "Maximum execution time" (AC-4a, 2026-10-21).

## Tugas berikutnya

1. Verifikasi P3 (PANDUAN-P3.md), commit & push.
2. P4 kalender hari libur (PRD-002 §18).
3. Sesudahnya: AC-5 Penilaian & rapor, HR-2, atau lainnya.
