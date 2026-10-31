# Status Proyek EduCore

- **Diperbarui:** 2026-10-30
- **Tahap aktif:** Keuangan lanjutan — Kas umum & pengeluaran (PRD-004): KS-1 s.d. KS-5 SELESAI; perapian tampilan UI-1 (menu berkelompok + halaman Keuangan) dibangun 2026-10-31, menunggu uji owner (tagihan siswa PRD-003 FN-1 s.d. FN-5 SELESAI)
- **Commit terakhir:** `1693032` — feat(finance): KS-5 persetujuan pengeluaran berjenjang

## Keputusan penting

| Tanggal    | Keputusan                                                                                                                                                                                                                                                                                                       | Dokumen                                                  |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| 2026-10-09 | Rebuild dari nol sebagai simplified modular monolith; dokumen lama jadi referensi domain                                                                                                                                                                                                                        | ADR-001                                                  |
| 2026-10-09 | Frontend Inertia 3 + React (starter kit resmi), auth Fortify, satu jalur session                                                                                                                                                                                                                                | ADR-001 §2.1                                             |
| 2026-10-09 | Pohon lembaga fleksibel (maks 6 tingkat); induk melihat turunan, tidak sebaliknya                                                                                                                                                                                                                               | PRD-000 §4, §7                                           |
| 2026-10-09 | Lembaga formal di dalam unit = anak unit; koordinator di bawah Biro pusat dengan penugasan fungsional (filter jenjang)                                                                                                                                                                                          | PRD-000 §4.4                                             |
| 2026-10-09 | Aturan berjenjang: terdekat menang, kecuali induk mengunci                                                                                                                                                                                                                                                      | PRD-000 §8                                               |
| 2026-10-09 | Modul awal: Core, HR, Academic                                                                                                                                                                                                                                                                                  | ADR-001 §2.2                                             |
| 2026-10-10 | Fitur auth starter kit: registrasi & verifikasi email OFF, 2FA & konfirmasi password ON, passkey OFF                                                                                                                                                                                                            | PRD-000 OD-04                                            |
| 2026-10-10 | Susunan tenant pertama: 4 Biro pusat + Ponpes dengan Unit 1 (MTs, MA), Unit 2 (MTs, MA), Unit 3 (SMP, SMK), masing-masing + MDA, Bahasa, Al-Qur'an                                                                                                                                                              | PRD-000 §4.3                                             |
| 2026-10-10 | F1 tanpa halaman admin; halaman pohon lembaga dibuat setelah F3 (butuh login & RBAC)                                                                                                                                                                                                                            | PRD-000 §10                                              |
| 2026-10-10 | Person hanya `name` + `gender`; hapus akun mandiri dihilangkan; UI starter kit diterjemahkan ke Bahasa Indonesia; F2 dipecah F2a/F2b                                                                                                                                                                            | PRD-000 OD-05–07                                         |
| 2026-10-10 | Penugasan tunggal otomatis dipilih; tanpa penugasan → konteks Seluruh Yayasan; akun uji hanya local/testing; penugasan fungsional boleh di tingkat Yayasan (`organization_id` kosong)                                                                                                                           | PRD-000 OD-08–10, §5                                     |
| 2026-10-10 | Katalog role & permission di kode + `educore:sync-access`; superadmin tanpa jalan pintas; di luar cakupan 404, di dalam cakupan tanpa izin 403                                                                                                                                                                  | PRD-000 OD-11–13, §7.1                                   |
| 2026-10-10 | Halaman admin pohon lembaga: tambah, ubah nama (kode tetap), pindah, nonaktifkan, aktifkan kembali; nonaktif disembunyikan kecuali diminta                                                                                                                                                                      | PRD-000 §4.6, OD-14                                      |
| 2026-10-13 | Akun pertama production lewat perintah interaktif `educore:create-admin` & `educore:create-superadmin`                                                                                                                                                                                                          | PRD-000 OD-19                                            |
| 2026-10-13 | HR dibangun bertahap mulai HR-1 Data Pegawai; model disederhanakan (pegawai → periode kepegawaian + penempatan); data pribadi minimal tanpa NIK; kelola pegawai satu pintu di Admin SDM                                                                                                                         | PRD-001 §3–§5, OD-HR-01–03                               |
| 2026-10-14 | Impor pegawai Excel/CSV: template + pratinjau, simpan semua atau tidak, orang ganda = peringatan + konfirmasi; library openspout ~5.3 (PHP 8.3)                                                                                                                                                                 | PRD-001 §6.2, OD-HR-05                                   |
| 2026-10-17 | Peringatan orang ganda (impor & form) dipicu nama sama, tidak hanya nama + tanggal lahir                                                                                                                                                                                                                        | PRD-001 §6, §6.2                                         |
| 2026-10-17 | Academic tahap 1 = data siswa + kelas; data siswa minimal + NISN + wali (tanpa NIK); tahun ajaran default yayasan, lembaga boleh beda                                                                                                                                                                           | PRD-002 OD-AC-01–03                                      |
| 2026-10-17 | Data siswa dikelola Operator Akademik per lembaga; orang tua/wali = Person tanpa akun; wali kelas lihat kelasnya di AC-3; impor siswa AC-1e                                                                                                                                                                     | PRD-002 OD-AC-04–08                                      |
| 2026-10-20 | Impor siswa: satu file = satu lembaga; NISN + nama cocok = siswa lama (hanya didaftarkan); kolom ayah/ibu/wali + kontak utama + kelas; dipecah AC-1e-a/b                                                                                                                                                        | PRD-002 AC-1e                                            |
| 2026-10-20 | AC-1d dipecah d-a (kelas & wali kelas) dan d-b (anggota kelas); kelas tahun ajaran lewat hanya dilihat; tingkat 1–12 tanpa batas per jenjang                                                                                                                                                                    | PRD-002 §11 (AC-1d-a)                                    |
| 2026-10-19 | Orang tua/wali: cari HP hanya di antara orang tua/wali tercatat; orang tua yang juga pegawai/siswa hanya bisa diubah hubungannya; lepas kontak utama wajib pilih pengganti                                                                                                                                      | PRD-002 §11 (AC-1c-b2)                                   |
| 2026-10-18 | AC-1c-b dipecah b1 (data diri & pendaftaran, termasuk ubah pendaftaran) dan b2 (orang tua/wali); cari orang tua lewat HP cocok persis tanpa nama anak                                                                                                                                                           | PRD-002 §11, §4.5                                        |
| 2026-10-18 | Data diri siswa boleh diubah Operator Akademik lembaga mana pun tempat siswa aktif (+ Admin Yayasan); lembaga tetap melihat alumninya                                                                                                                                                                           | PRD-002 OD-AC-09, §5.3                                   |
| 2026-10-14 | Satu lembaga kerja aktif; halaman tingkat Yayasan hanya dari "Seluruh Yayasan"; tanpa prefetch di navigasi                                                                                                                                                                                                      | PRD-000 §6                                               |
| 2026-10-14 | Kelola akses dari detail pegawai, hanya Admin Yayasan; akun dibuat dengan kata sandi sementara tampil sekali + wajib ganti; Kepala Lembaga tidak lagi kelola anggota; akun Superadmin & akun lintas yayasan tidak bisa diatur dari halaman yayasan                                                              | PRD-000 §4.7, OD-20–22                                   |
| 2026-10-13 | Audit log aksi penting (append-only, IP & user agent, masa simpan 1 tahun); halaman log audit untuk Admin Yayasan; README mengasumsikan VPS Linux, script backup Linux & Windows                                                                                                                                | PRD-000 OD-15–18                                         |
| 2026-10-23 | Presensi dibagi dua: kehadiran kerja harian di HR-4; presensi mengajar per jadwal di tiap lembaga, jadwal piket, dan guru pengganti di Akademik AC-6 (ADR-001: HR tidak bergantung pada Academic)                                                                                                               | PRD-002 §20, PRD-001 §14.5                               |
| 2026-10-23 | NIK menjadi penanda unik orang (mengganti "tanpa NIK"): terenkripsi + fingerprint di `person_identifiers`; wajib untuk pegawai baru; nanti juga siswa & orang tua (opsional). Impor pegawai: kolom `nik` + `lembaga_tambahan` (guru di beberapa lembaga cukup satu baris)                                       | PRD-001 OD-HR-01 §4.5 §6.2, PRD-002 OD-AC-02, PRD-000 §5 |
| 2026-10-23 | Libur mingguan HR tetap terpisah dari kalender akademik (opsi A); Pengaturan cuti & presensi menampilkan libur kalender akademik sebagai pembanding + tanda ⚠ bila berbeda                                                                                                                                      | PRD-001 §13.2, OD-HR-07                                  |
| 2026-10-24 | "HR selesai" = inti: HR-2 Dokumen & SK → HR-5 komponen gaji (tetap, honor JP, potongan presensi, tunjangan lain; rekap input .xlsx) → HR-6a laporan; file dokumen di disk server privat, file tidak diganti (batalkan + unggah baru)                                                                            | PRD-001 §3, §15, OD-HR-10                                |
| 2026-10-25 | Gaji (HR-5): honor mengajar memakai **jam mati** = JP per minggu dari jadwal (durasi ÷ menit per JP per jenjang/lembaga); tarif JP per jenjang/lembaga; penerima GTT/honorer; potongan presensi dalam rupiah; data gaji hanya Admin SDM/Admin Yayasan (`hr.payroll.manage`)                                     | PRD-001 §16, OD-HR-11                                    |
| 2026-10-27 | Gaji: komponen **Menurut jabatan** (tarif per jabatan, semua lembaga atau lembaga tertentu; rangkap dijumlahkan); setup gaji di menu **Gaji pegawai** terpisah dari detail pegawai; slip gaji (jabatan + nominal) untuk Admin SDM & bagian terkait, pegawai hanya miliknya; honor JP jam mati **dan** jam hidup | PRD-001 §16, OD-HR-11, OD-HR-12                          |
| 2026-10-27 | Tarif gaji (honor JP, potongan presensi) diubah Admin Yayasan & Admin SDM (termasuk keuangan); slip & rekap gaji untuk pemilik `hr.payroll.manage`, tanpa role Staf Keuangan                                                                                                                                    | PRD-001 §16.2–16.3, OD-HR-12                             |
| 2026-10-28 | Nomor HP orang tua/wali **unik** (satu HP = satu orang tua/wali); pencarian orang tua/wali tercatat lewat sebagian nama/HP dengan nama anak + lembaga, untuk Admin Yayasan & Operator Akademik                                                                                                                  | PRD-002 §21.5, OD-AC-19, OD-AC-20                        |
| 2026-10-28 | Modul **Keuangan** (`modules/Finance`): tagihan & pembayaran siswa; Bendahara per lembaga (role baru) + Admin Keuangan tingkat Yayasan (role baru, terpisah dari Admin SDM); pembayaran dicatat petugas; tarif per lembaga/tahun ajaran/tingkat; kelebihan bayar ditolak; tanpa denda                           | PRD-003, OD-FN-01 s.d. OD-FN-08                          |
| 2026-10-30 | Keuangan lanjutan pertama = **kas umum & pengeluaran**: kas per lembaga + kas Yayasan; pengeluaran wajib diajukan → disetujui (Kepala Lembaga / Pimpinan Yayasan) → direalisasi; pembayaran siswa otomatis masuk buku kas; saldo tidak boleh minus                                                              | PRD-004, OD-KS-01 s.d. OD-KS-08                          |
| 2026-10-30 | Urutan keuangan lanjutan: persetujuan berjenjang (batas nominal → naik ke Yayasan) → tabungan siswa → payment gateway **Midtrans** (akun sudah ada) → pembukuan                                                                                                                                                 | PRD-004 §11, §16, OD-KS-06                               |
| 2026-10-31 | **Pedoman tampilan**: menu samping berkelompok (Untuk saya, Akademik, Kepegawaian, Keuangan siswa, Kas & pengeluaran, Pengaturan); satu tampilan = satu fungsi utama — bagian berbeda jadi **tab** (`?tab=`), form tambahan di **jendela pop-up**, bukan menumpuk di halaman                                    | STATUS § Pedoman tampilan                                |
| 2026-10-28 | Akun orang tua/wali dibuat Operator Akademik/Admin Yayasan dari detail siswa; username = nomor HP + sandi sementara; hanya untuk orang yang murni orang tua/wali; portal **Anak saya** hanya baca, akses dari tautan orang tua/wali (tanpa role)                                                                | PRD-002 §21, OD-AC-16 s.d. OD-AC-18                      |

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

**PRD-002 Academic: v1.5 (2026-10-22)** — AC-1 Data Siswa & Kelas disetujui owner (OD-AC-01–09); rincian AC-1a s.d. AC-1e; rincian AC-2 di §15; rincian AC-3 (a–d) di §16; rincian AC-4 di §17; penyempurnaan P1–P4 di §18 (v1.4); rincian AC-5 di §19 (v1.5).

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

**P3 — Salin mapel & pengampu dari kelas lain: SELESAI (2026-10-22, commit `84a5053`)**

- `ManageClassSubjects::copySources` (kelas lain di lembaga yang sama yang punya mapel, tahun terbaru dulu; saran = kelas setingkat tahun ajaran sebelumnya di kalender yang sama, utamakan nama sama) dan `copyFrom` (mapel aktif, cocok tingkat, belum ada; pengampu ikut bila masih aktif di lembaga itu; jadwal tidak disalin; ditolak bila tidak ada yang bisa disalin).
- Route `POST kelas/{class}/mapel/salin` (`academic.class-subjects.copy`, `academic.classes.manage`); tombol **Salin dari kelas lain** di bagian Mata pelajaran detail kelas; `?salin=1#mapel` membuka formnya.
- Halaman proses akhir tahun: bagian **Mata pelajaran kelas tujuan** berisi tautan ke tiap kelas tujuan. Teks peringatan lulus disesuaikan dengan P2 (Admin Yayasan bisa membuka kembali).
- Audit `academic.class_subjects_added` dengan `disalin_dari`, `dilewati`, `pengampu_ikut`.
- Bukti: 531 test lulus (3 test baru, `CopyClassSubjectsTest`); uji manual owner lancar.

**P4a — Hari libur: data & pengelolaan: SELESAI (2026-10-22, commit `b7683df`)**

- Migrasi `2026_10_22_200010`: kolom `ac_academic_calendars.weekly_off_days` (jsonb, bawaan `[7]` = Ahad, maks. 6 hari) dan tabel `ac_holidays` (tahun ajaran, nama, tanggal mulai–selesai).
- `Domain/Calendar/Holiday`; `Application/Calendar/ManageHolidays` (`setWeeklyOffDays`, `add`, `update`, `remove`, `weeklyOffDaysOf`); `SaveAcademicYear::update` menolak tanggal baru yang membuat hari libur tertinggal di luar.
- Route (Admin Yayasan): `POST akademik/kalender/{calendar}/libur-mingguan`, `POST akademik/tahun-ajaran/{year}/hari-libur`, `PATCH|DELETE akademik/hari-libur/{holiday}`.
- Halaman Kalender akademik: baris **Libur mingguan** di bawah nama kalender dan daftar **Hari libur** di tiap kartu tahun ajaran (`components/academic/holiday-actions.tsx`).
- Audit `academic.weekly_off_days_changed`, `academic.holiday_added|updated|removed`.
- Bukti: 534 test lulus (3 test baru, `HolidaysTest`); uji manual owner lancar.

**P4b — Hari efektif di presensi: SELESAI (2026-10-22, commit `e00cad0`)**

- `Application/Calendar/SchoolDays` (`holidayOn`, `effectiveDates`): hari efektif = di dalam tahun ajaran, bukan libur mingguan kalender, bukan hari libur.
- `AttendanceRecap::effective` (hari efektif periode, s.d. hari ini, belum diisi + 31 tanggal terbaru); rekap harian menampilkannya, tanggal belum diisi bisa diklik ke lembar presensi bila pengguna boleh mengisinya (prop `fill`).
- Lembar presensi (harian & per pertemuan): prop `holiday` → peringatan "Tanggal ini hari libur: …"; tetap bisa disimpan.
- Bukti: 537 test lulus (3 test baru, `EffectiveDaysTest`, memakai `travelTo` ke 22 Okt 2026); fix1 menghapus `array_values()` yang tidak perlu (Larastan `arrayValues.list`); uji manual owner lancar.

**AC-5 Penilaian & rapor (PRD-002 §19, rincian disetujui 2026-10-22)**

**AC-5a — Aturan penilaian: SELESAI (2026-10-22, commit `eb9a8a8`)**

- `Application/Grading/GradingRules` (isi aturan: KKM, batas A/B, 1–6 komponen berbobot = 100; `problem`, `fromValue`, `predicate`, `summary`), `GradingSetting` (aturan berjenjang `academic.grading`, bawaan KKM 75 · Harian 40 / PTS 30 / PAS 30 · A ≥ 90, B ≥ 80; `rulesFor`), `GradingOverview` (aturan tersimpan & yang berlaku per lembaga).
- Route `POST akademik/kalender/aturan-penilaian` (`academic.grading.assign`, Admin Yayasan); bagian **Aturan penilaian** di Kalender akademik (`components/academic/grading-section.tsx`).
- Audit: perubahan aturan berjenjang oleh Core.
- Bukti: 540 test lulus (3 test baru, `GradingRulesTest`); uji manual owner lancar.

**AC-5b — Lembar nilai: SELESAI (2026-10-22, commit `1842b9a`)**

- Migrasi `2026_10_22_200011`: `ac_grade_sheets` (mapel kelas × semester, status DRAFT/FINAL, salinan aturan `rules`, finalkan oleh/kapan) dan `ac_grades` (lembar × pendaftaran: `scores` jsonb per kode komponen, `final_score`, `description`).
- `Domain/Grading/{GradeSheet, Exceptions/GradingException}`; `Application/Grading/ManageGrades` (`terms`, `pickTerm`, `sheet`, `save`, `finalize`, `reopen`, `applyLatestRules`, `finalScore`).
- `GradeController`: pintu operator/pemantau `kelas/mapel/{id}/nilai` dan pintu pengampu `kelas-saya/mapel/{id}/nilai`; halaman `academic/grades/sheet`. Tombol **Nilai** di baris mapel detail kelas; bagian **Nilai mapel yang saya ampu** di Kelas saya (`MyClasses` menambah `taught`).
- Pengaman: mapel kelas & semester yang punya lembar nilai tidak bisa dilepas/dihapus.
- Audit `academic.grades_saved`, `grade_sheet_finalized`, `grade_sheet_reopened`, `grade_sheet_rules_applied`.
- Bukti: 544 test lulus (4 test baru, `GradeSheetTest`); uji manual owner lancar.

**AC-5c — Leger nilai kelas: SELESAI (2026-10-22, commit `8f7c63e`)**

- `Application/Grading/GradeLedger` (`build`: mapel + status lembar + KKM, siswa × nilai akhir, rata-rata siswa/mapel/kelas, jumlah di bawah KKM; `write`: .xlsx openspout; `fileName`).
- `GradeLedgerController`: `kelas/{class}/leger` (+ `/unduh`, izin lihat siswa) dan `kelas-saya/{class}/leger` (+ `/unduh`, wali kelas); halaman `academic/grades/ledger` (tabel di layar besar, kartu di HP). Tombol **Leger nilai** di detail kelas & Kelas saya (wali kelas).
- Bukti: 547 test lulus (3 test baru, `GradeLedgerTest`); uji manual owner lancar.

**AC-5d — Rapor: SELESAI (2026-10-22, commit `d233839`)**

- Migrasi `2026_10_22_200012`: `ac_report_notes` (kelas × semester × pendaftaran, catatan wali kelas).
- `Application/Grading/TermRoster` (daftar siswa kelas per semester; dipakai leger & rapor), `ReportCards` (`build`: mapel final, nilai/predikat/deskripsi, jumlah & rata-rata, presensi S/I/A semester, catatan, nama wali kelas; `saveNotes`).
- `ReportCardController`: `kelas/{class}/rapor` (+ `/catatan`, `/cetak`) dan `kelas-saya/{class}/rapor` (wali kelas). Halaman `academic/grades/report-index` dan halaman cetak `print/report-cards` dengan `layouts/print-layout.tsx` (awalan halaman `print/` di `app.tsx`). Tombol **Rapor** di detail kelas & Kelas saya.
- Audit `academic.report_notes_saved`. Semester bercatatan rapor tidak bisa dihapus.
- Bukti: 550 test lulus (3 test baru, `ReportCardTest`; fix1 memperbaiki nama wali kelas yang diharapkan test); uji manual owner lancar.

**AC-5 Penilaian & rapor SELESAI (2026-10-22).**

**AC-6 Presensi mengajar & guru pengganti (PRD-002 §20, OD-AC-13–15, disetujui 2026-10-23)** — presensi per pertemuan di tiap lembaga tempat guru mengajar, jadwal piket, guru pengganti saat cuti. Kehadiran kerja harian tetap di HR-4.

**AC-6a — Jadwal piket & presensi mengajar: SELESAI (2026-10-23, commit `a69c50a`)**

- Dibangun di atas HR-4a + fix1 (belum di origin saat dibuat).
- Permission baru `academic.teaching.manage` (Operator Akademik, Admin Yayasan, Kepala Lembaga) → jalankan `educore:sync-access`; `AccessCatalogTest` & `TreeAuthorizationTest` disesuaikan.
- Migrasi `2026_10_23_200013`: `ac_duty_rosters` (lembaga × tahun ajaran × hari × pegawai) dan `ac_teaching_records` (satu per baris jadwal × tanggal; status MENGAJAR/DIGANTIKAN/KOSONG, pengganti, alasan, keterangan, sumber GURU/PETUGAS/OTOMATIS; mapel, jam & pengampu tersalin).
- `Domain/Teaching/{TeachingStatus, AbsenceReason, Exceptions/TeachingException}`; `Application/Teaching/{DutyRoster, Lessons (pertemuan per lembaga/guru + catatan), ManageTeachingRecords (markTaught, record), TeachingAccess (izin vs guru piket hari ini)}`.
- `TeachingController`: `/akademik/presensi-mengajar` (+ `POST /{entry}`), `POST /kelas-saya/jadwal/{entry}/mengajar`; `DutyRosterController`: `/akademik/jadwal-piket`. Menu **Presensi mengajar** (izin atau prop bersama `academic.teachingDuty`). Kelas saya mendapat bagian **Presensi mengajar 7 hari terakhir**.
- Mapel kelas yang punya presensi mengajar tidak bisa dilepas. Audit `academic.duty_roster_updated`, `academic.teaching_recorded` (tanpa keterangan).
- Target bukti: 575 test (571 + 4 baru, `TeachingTest`).

**AC-6b-1 — Cuti & tunjuk guru pengganti: SELESAI (2026-10-23, commit `443a98d`)**

- AC-6b dipecah: **6b-1** (paket ini) cuti → Perlu pengganti, tunjuk/batalkan pengganti dengan saran; **6b-2** tugas pengganti di Kelas saya, akses presensi siswa untuk pengganti, catatan otomatis dari presensi per pertemuan.
- Kontrak HR baru `ApprovedLeaves` (`EloquentApprovedLeaves`). `Lessons` menambah `leave` per pertemuan. `SubstituteFinder` (calon + alasan tidak tersedia: cuti, jadwal sendiri beririsan, sudah menggantikan). `ManageTeachingRecords`: penunjukan sebelum hari-H (maks. 7 hari, hanya Digantikan), cek ketersediaan, `clearSubstitute`. `TeachingAccess::canAssign`.
- `TeachingController`: `/akademik/perlu-pengganti` (halaman `academic/teaching/substitutes`), `POST /akademik/presensi-mengajar/{entry}/batalkan-pengganti`; Presensi mengajar kini mengirim `candidates` per pertemuan dan `duty` berupa daftar nama. Komponen `components/academic/substitute-fields.tsx`.
- Target bukti: +3 test (`SubstituteTest`); `TeachingTest` disesuaikan (`duty` = daftar nama).

**HR-3 Cuti & Izin (PRD-001 §13, rincian disetujui 2026-10-22)**

**HR-3a — Jenis cuti & kalender kerja: SELESAI (2026-10-22, commit `4cf3e44`)**

- Permission baru `hr.leave.approve` (Kepala Lembaga, Pimpinan, Admin SDM, Admin Yayasan) dan `hr.leave.manage` (Admin SDM, Admin Yayasan) → jalankan `educore:sync-access`.
- Migrasi `2026_10_22_100005`: `hr_leave_types` (kode tetap, berjatah + jatah per tahun, batas hari per pengajuan, aktif) dan `hr_holidays` (tanggal merah yayasan).
- `Domain/Leave/{LeaveType, Holiday, Exceptions/LeaveException}`; `Application/Leave/{DefaultLeaveTypes, ManageLeaveTypes, ManageWorkHolidays, WeeklyOffSetting (aturan berjenjang hr.weekly_off_days, bawaan Ahad), WorkCalendar (hari kerja), LeaveSettingsOverview}`; seeder `HrLeaveTypeSeeder` (dipanggil DatabaseSeeder).
- `LeaveSettingsController` + halaman `hr/leave-settings` (`/pegawai/cuti/pengaturan`, menu **Pengaturan cuti** di tingkat Yayasan).
- Audit `hr.leave_type_created|updated|deactivated|reactivated`, `hr.holiday_added|updated|removed`.
- Bukti: 553 test lulus (3 test baru, `LeaveSettingsTest`; `AccessCatalogTest` & `TreeAuthorizationTest` disesuaikan untuk permission baru); uji manual owner lancar.

**HR-3b — Cuti saya: SELESAI (2026-10-22, commit `8de802d`)**

- Migrasi `2026_10_22_100006`: `hr_leave_requests` (pegawai, jenis, lembaga penempatan utama, tanggal, hari kerja + rincian per tahun, alasan, status, jumlah/tahap persetujuan, dicatat oleh).
- `Domain/Leave/{LeaveRequest, LeaveStatus}`; `Application/Leave/{LeaveApprovalSetting (aturan berjenjang hr.leave_approval, bawaan 2), LeaveBalances (saldo per tahun), ManageLeaveRequests (plan, submit, withdraw)}`.
- `MyLeaveController`: `/cuti-saya` (+ `/hitung` JSON pratinjau hari, `POST`, `/{leave}/tarik`); halaman `hr/my-leave`; menu **Cuti saya** lewat prop bersama `hr.isEmployee` (HRServiceProvider).
- Bukti: 556 test lulus (3 test baru, `MyLeaveTest`); uji manual owner lancar.

**HR-3c — Persetujuan berjenjang: SELESAI (2026-10-22, commit `7da5e98`)**

- Migrasi `2026_10_22_100007`: `hr_leave_decisions` (pengajuan, tahap, `SETUJU`/`TOLAK`/`DILEWATI`, oleh, catatan maks. 300, waktu).
- `Application/Leave/LeaveApprovals` (kotak masuk, `canDecide`, `decide` + cek ulang tumpang tindih & jatah pada persetujuan terakhir). `ManageLeaveRequests::submit(selfService)`: bila pengaju sendiri berhak `hr.leave.approve` di lembaga pengajuan, tahap lembaga dilewati (baris `DILEWATI`) dan pengajuan langsung ke tahap SDM.
- `LeaveApprovalController`: `/persetujuan-cuti` (menu **Persetujuan cuti**) + `POST /persetujuan-cuti/{leave}`; halaman `hr/leave-approvals`. Pengaturan cuti mendapat bagian **Alur persetujuan** (`POST /pegawai/cuti/alur-persetujuan`). Cuti saya menampilkan alasan penolakan.
- Audit `hr.leave_approved` / `hr.leave_rejected` (tanggal, hari, tahap, final, catatan diisi ya/tidak; tanpa isi catatan).
- Bukti: 560 test lulus (4 test baru, `LeaveApprovalTest`); uji manual owner lancar.

**HR-3d-a — Kelola cuti Admin SDM: SELESAI (2026-10-22, commit `4544d79`)**

- HR-3d dipecah: **3d-a** daftar, detail, catat atas nama, batalkan; **3d-b** penyesuaian jatah, tabel saldo, rekap .xlsx, ringkasan di detail pegawai.
- Migrasi `2026_10_22_100008`: keputusan cuti bertambah `DICATAT` (dicatat atas nama) dan `BATAL` (dibatalkan).
- `ManageLeaveRequests`: `record()` (langsung disetujui, 1 tahap, boleh mundur 90 hari) dan `cancel()` (hanya yang disetujui, alasan wajib); admin tidak bisa mencatat/membatalkan cutinya sendiri. `submit()` tidak lagi menerima `recordedBy`.
- `Application/Leave/{LeaveRegister (daftar + saringan lembaga termasuk bawahannya, jenis, status, bulan; detail + riwayat keputusan), LeaveEmployeePicker (cari pegawai aktif berpenempatan utama)}`.
- `LeaveAdminController`: `/pegawai/cuti` (menu **Kelola cuti**, tingkat Yayasan), `/pegawai/cuti/catat`, `/pegawai/cuti/hitung` (JSON), `POST /pegawai/cuti`, `/pegawai/cuti/{leave}`, `POST /pegawai/cuti/{leave}/batalkan`. Halaman `hr/leaves/{index,show,record}`; bantuan tampilan `components/hr/leave-format.ts`.
- Cuti saya menampilkan alasan pembatalan. Audit `hr.leave_recorded`, `hr.leave_cancelled` (tanpa isi alasan).
- Bukti: 564 test lulus (4 test baru, `LeaveAdminTest`); perbaikan lint `no-base-to-string` (fix1); uji manual owner lancar.
- Pelajaran: nilai `FormData.get()` bisa berupa `File`; cek `typeof value === 'string'` sebelum dipakai sebagai teks.

**HR-3d-b — Saldo, penyesuaian jatah & rekap: SELESAI (2026-10-22, commit `451f94d`)**

- Migrasi `2026_10_22_100009`: `hr_leave_adjustments` (pegawai, jenis berjatah, tahun, ±hari, alasan, oleh, waktu). `LeaveBalances` kini menjumlah penyesuaian + `table()` (saldo banyak pegawai sekaligus).
- `Application/Leave/{ManageLeaveAdjustments (tahun berjalan/sebelumnya, sisa tidak boleh negatif, bukan diri sendiri), LeaveBalanceSheet (saldo per pegawai aktif + saringan), LeaveRecapExport (xlsx: "Daftar cuti" disetujui per periode + "Saldo {tahun}"), EmployeeLeaveSummary}`.
- `LeaveBalanceController`: `/pegawai/cuti/saldo` (halaman `hr/leaves/balances`), `POST /pegawai/cuti/saldo/{employee}`, `/pegawai/cuti/rekap?tahun=&bulan=&lembaga=&jenis=`. Kelola cuti mendapat tombol **Saldo & rekap**.
- Detail pegawai: bagian **Cuti {tahun}** (saldo + 5 cuti terbaru) bagi yang boleh melihat data pribadi; Admin SDM mendapat tombol Catat cuti & Saldo.
- Audit `hr.leave_adjusted` (jenis, tahun, hari; tanpa alasan).
- Bukti: 568 test lulus (4 test baru, `LeaveBalanceTest`; fix1 perbandingan audit jsonb); uji manual owner lancar.
- **HR-3 Cuti & Izin SELESAI (2026-10-22).**

**HR-4 Presensi pegawai (PRD-001 §14, OD-HR-09, rincian disetujui 2026-10-22)** — absen mandiri + petugas; status + jam masuk/pulang; jam wajib guru dari jadwal mengajar, staf dari jam kerja per jenjang/lembaga; mesin absen menyusul.

**HR-4a — Jam kerja & Presensi saya: SELESAI (2026-10-23, ikut commit `a69c50a`)**

- Migrasi `2026_10_22_100010`: `hr_attendances` (satu baris per pegawai per tanggal; lembaga, status, jam masuk/pulang + sumber MANDIRI/PETUGAS, jam wajib tersalin, terlambat & pulang cepat dalam menit, keterangan).
- Kontrak baru `Modules\HR\Contracts\TeachingSchedule` (bawaan `NoTeachingSchedule`); Academic mengisinya lewat `Application/Classroom/AcademicTeachingSchedule` (jadwal kelas aktif + libur kalender akademik), didaftarkan di `AcademicServiceProvider::register()`.
- `Application/Attendance/{WorkHoursSetting (hr.work_hours, bawaan 07:00–14:00 toleransi 15), ExpectedHours, ManageAttendance (checkIn/checkOut), MyAttendance}`; `Domain/Attendance/{AttendanceStatus, Exceptions/AttendanceException}`.
- `MyAttendanceController`: `/presensi-saya` (+ `POST /masuk`, `/pulang`); menu **Presensi saya**. Pengaturan cuti → **Pengaturan cuti & presensi** dengan bagian **Jam kerja** (`POST /pegawai/cuti/jam-kerja`).
- Audit `hr.attendance_checked_in|checked_out` (tanggal, jam, menit terlambat/pulang cepat).
- Target bukti: 571 test (3 test baru, `MyAttendanceTest`).
- Pelajaran (fix1): Larastan `nullsafe.neverNull` — `$x?->prop ?? …` tidak perlu `?->` (operator `??` sudah aman untuk properti); untuk beberapa cabang pakai `match (true)` agar jelas.
- Pelajaran (fix1): kolom `jsonb` PostgreSQL menyusun ulang key (yang pendek dulu); di test bandingkan isi audit dengan `toEqual`, bukan `toBe`.

**Perbaikan login → 403 (2026-10-23, paket `educore-fix-login-403`)**

- Penyebab: Fortify mengarahkan ke _intended URL_ (halaman terakhir sebelum sesi habis) yang bisa milik akun lain atau butuh lembaga kerja lain.
- Perbaikan: `app/Http/Responses/LoginResponse.php` (untuk `LoginResponse` & `TwoFactorLoginResponse`) selalu ke Dashboard; +1 test di `AuthenticationTest`.
- Pelajaran: di aplikasi multi-akun & multi-lembaga kerja, jangan memakai `redirect()->intended()` sesudah login.

**Perbaikan libur mingguan HR vs kalender akademik (2026-10-23, paket `educore-weekly-off-compare`)**

- Keputusan owner: opsi A — tetap terpisah (OD-HR-07), perbedaan ditampilkan.
- Kontrak HR baru `SchoolWeeklyOff` (bawaan `NoSchoolWeeklyOff`); Academic mengisinya lewat `Application/Calendar/AcademicSchoolWeeklyOff`.
- `LeaveSettingsOverview`: `weeklyOff.usage` + `academic` & `differs`, `weeklyOff.differs` (jumlah lembaga berbeda). Halaman Pengaturan cuti & presensi menampilkan peringatan ⚠ dan pembanding per lembaga; halaman Kalender akademik memberi keterangan pemakaian libur mingguannya.
- Target bukti: +1 test (`WeeklyOffComparisonTest`).

**HR-1g — NIK + lembaga tambahan di impor: SELESAI (2026-10-23, commit `9849873`)**

- Nama tahap: HR-1f sudah dipakai (peringatan nama sama, 2026-10-17), jadi tahap ini HR-1g.
- Migrasi Core `2026_10_23_000013`: `person_identifiers` (person_id, type `NIK`, encrypted_value, value_fingerprint; unik per orang+jenis dan per jenis+fingerprint; CHECK jenis & format fingerprint).
- Core `Application/Person/PersonNik` (normalize, isValid, mask, mismatch, fingerprint HMAC dari APP_KEY, ownerOf/ownersOf, valueOf, personsWithNik, assign) + `Domain/Person/Exceptions/NikTakenException`.
- HR: `EmployeeNik` (pesan "NIK sudah terdaftar" yang aman lintas yayasan). `NewEmployeeData` + `nik`, `extraOrganizationIds`, `requireNik`; `CreateEmployee` menyimpan NIK, menolak NIK terpakai, membuat penempatan tambahan, audit `lembaga_tambahan`. `EmployeeProfileData` + `nik`, `nikSubmitted`; `UpdateEmployeeProfile` (lengkapi/perbaiki, tidak bisa dikosongkan). `EmployeeProfile`: NIK tersamar + `nik_warning` di detail, NIK lengkap di `manage.values`.
- Impor: kolom `nik`* (setelah `nama`) dan `lembaga_tambahan` (setelah `kode_lembaga`); validator memeriksa NIK (wajib, bukan angka Excel, dobel di file, sudah terdaftar, tidak cocok = peringatan) dan lembaga tambahan; pratinjau menampilkan "Juga di: …".
- Peringatan orang ganda: bila kedua orang ber-NIK, hanya muncul bila tanggal lahir juga sama.
- Frontend: `components/hr/nik-field.tsx` (kolom NIK + peringatan langsung); form Tambah pegawai, Ubah profil, detail pegawai, halaman impor.
- Test: `EmployeeNikTest` (12 test, awalan helper `nik`); `tests/Support/TestNik` membuat NIK uji unik yang cocok dengan jenis kelamin & tanggal lahir; test impor/tambah pegawai lama disesuaikan.
- Bukti: 592 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.
- Pelajaran: string angka 16 digit (NIK) menjadi kunci **int** di array PHP; jangan jadikan NIK kunci array lalu dipakai ulang sebagai string (test sempat gagal karenanya).

**AC-1g-a — NIK siswa & orang tua/wali (form): SELESAI (2026-10-24, ikut commit `6360f6a`)**

- AC-1g dipecah: **1g-a** form (tambah siswa, orang tua/wali, ubah data diri, detail); **1g-b** kolom NIK di impor siswa.
- Academic `Application/Student/StudentNik` (pemilik NIK di yayasan ini + pesan aman lintas yayasan). `DuplicateCandidates::find(…, nik, birthDate)`: pemilik NIK sama ikut sebagai calon "(NIK sama)"; orang ber-NIK lain dengan tanggal lahir beda bukan calon.
- `CreateStudent`: NIK siswa (orang baru → disimpan; orang yang sama → melengkapi, beda → ditolak); `NewStudentData::nik`, `GuardianData::nik`. `ManageGuardians::membershipFor` (NIK → nama+HP → orang baru) dan `update(…, nik)`. `UpdateStudentProfile` + `StudentProfileData::nik/nikSubmitted`. `StudentProfile`: NIK tersamar + `nik_warning` siswa, NIK tersamar orang tua/wali, NIK lengkap di `manage.profile`.
- Frontend: `NikField` dipindah ke `components/nik-field.tsx` (dipakai HR & Academic; prop `name`, `label`); form Tambah siswa (siswa + tiap orang tua/wali), Ubah data diri, Tambah/Ubah orang tua/wali, detail siswa.
- Test: `StudentNikTest` (9 test, awalan `snk`).
- Bukti (salinan kerja Claude): 601 test lulus, Pint PASS, Larastan `[OK]`, `tsc` tanpa error baru.

**AC-1g-b — NIK di impor siswa: SELESAI (2026-10-24, commit `6360f6a`)**

- Dibangun di atas AC-1g-a (uji owner lancar, belum di origin saat paket dibuat).
- `StudentImportColumns`: `nik`, `nik_ayah`, `nik_ibu`, `nik_wali`; `guardians()` membawa kolom NIK. Petunjuk template + teks halaman impor diperbarui.
- `StudentImportValidator`: `nik()` (wajib teks, 16 angka), cek NIK orang tua di baris yang sama, `checkNik()` + `checkStudentNik()` + `members()` (NIK + nama cocok = siswa lama), peringatan NIK tidak cocok, peringatan nama sama memakai aturan NIK. `NewStudentData::nik` & `GuardianData::nik` terisi dari file.
- Test: `StudentImportNikTest` (4 test, awalan `sni`).
- Bukti: 605 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar (termasuk masuk kelas lewat impor).

**AC-6b-2 — Tugas pengganti & presensi siswa oleh pengganti: SELESAI (2026-10-24, commit `bc3d261`)**

- `Application/Teaching/SubstituteTasks` (daftar tugas menggantikan, `hasAny` untuk menu, `find`, `fillable`). Kelas saya mengirim `substitutions`; menu Kelas saya juga muncul bagi pengganti tanpa kelas (`AcademicServiceProvider::hasMyClasses`).
- `AttendanceController::showSubstitute/storeSubstitute` + route `kelas-saya/menggantikan/{entry}/{date}/presensi` (`academic.substitute-attendance.show|store`); lembar presensi menerima rentang tetap satu tanggal dan prop `asSubstitute`.
- `ManageTeachingRecords::autoTaught` dipanggil sesudah pengampu menyimpan presensi per pertemuan (`storeMyLesson`): catatan Mengajar sumber OTOMATIS bila belum ada catatan; audit `oleh = otomatis dari presensi siswa`.
- Frontend: bagian **Tugas menggantikan** di Kelas saya; `teaching.ts` (`SubstituteTask`, `recordedByLabel`); Presensi mengajar menampilkan "Dicatat otomatis dari presensi siswa".
- Test: `SubstituteTaskTest` (5 test, awalan `sbt`).
- Bukti (salinan kerja Claude): 610 test lulus, Pint PASS, Larastan `[OK]`, `tsc` tanpa error baru.

**AC-6c — Rekap presensi mengajar bulanan: SELESAI (2026-10-24, commit `c13d0d7`)**

- Dibangun di atas AC-6b-2 (belum di origin saat paket dibuat).
- `Application/Teaching/TeachingRecap` (bulan sah, label, `build`: baris per guru + jumlah + daftar pertemuan, memakai `Lessons::forOrganization` per hari) dan `TeachingRecapExport` (xlsx: Rekap & Rincian).
- `TeachingController::recap/recapExport` + `managedOrganization` (hanya lembaga berizin); route `akademik/rekap-mengajar` (`academic.teaching.recap`) dan `/unduh` (`academic.teaching.recap.export`).
- Halaman `academic/teaching/recap` (tabel di layar lebar, kartu di HP); tombol **Rekap** di Presensi mengajar (pemilik izin).
- Test: `TeachingRecapTest` (3 test, awalan `rmg`).
- Bukti (salinan kerja Claude): 613 test lulus, Pint PASS, Larastan `[OK]`, `tsc` tanpa error baru.
- **AC-6 Presensi mengajar & guru pengganti SELESAI (2026-10-24).**

**HR-4b — Lembar presensi pegawai (petugas): SELESAI (2026-10-24, commit `58b83d0`)**

- Permission baru `hr.attendance.manage` (Admin SDM, Admin Yayasan, Kepala Lembaga) dan role penugasan baru **Petugas Presensi** (`petugas-presensi`) → jalankan `educore:sync-access`. `AccessCatalogTest`, `MemberAccessTest`, `TreeAuthorizationTest` disesuaikan.
- `Application/Attendance/AttendanceSheet` (`rows`, `save`, `earliest`; aturan di docblock). Audit `hr.attendance_recorded`.
- `AttendanceSheetController` + route `GET|POST pegawai/presensi` (`hr.attendance.index|store`); halaman `hr/attendance/sheet` (kartu per pegawai, tombol simpan menempel di bawah); menu **Presensi pegawai**.
- Test: `AttendanceSheetTest` (5 test, awalan `lpp`).
- Bukti: 618 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.

**HR-4c — Rekap presensi pegawai bulanan: SELESAI (2026-10-24, commit `e3df916`)**

- `Application/Attendance/AttendanceRecap` (`month`, `range`, `forOrganization`, `forEmployee`; aturan hitung di docblock) dan `AttendanceRecapExport` (xlsx Rekap & Rincian).
- `AttendanceSheetController::recap/recapExport` + route `pegawai/presensi/rekap` (`hr.attendance.recap`) & `/unduh` (`hr.attendance.recap.export`); halaman `hr/attendance/recap` (tabel di layar lebar, kartu di HP); tombol **Rekap bulanan** di Presensi pegawai.
- Ringkasan bulan berjalan: prop `summary` di Presensi saya, prop `attendance` di detail pegawai (komponen `components/hr/attendance-summary.tsx`).
- Test: `AttendanceRecapTest` (4 test, awalan `rpg`).
- Bukti: 622 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.
- Pelajaran: alias tipe phpstan berbentuk irisan (`array{...}&Counts`) tidak bisa di-resolve; tulis bentuk array lengkap.
- **HR-4 Presensi pegawai SELESAI (2026-10-24)** (mesin absen menunggu contoh file, §14.5).

**Cakupan "HR selesai" [OWNER, 2026-10-24]:** HR-2a → HR-2b → HR-5 (komponen tetap, honor JP, potongan presensi, tunjangan lain → rekap input gaji .xlsx) → HR-6a (laporan & ekspor daftar pegawai). Sisanya menyusul (PRD-001 §3).

**HR-2a — Dokumen & SK pegawai: SELESAI (2026-10-24, commit `287f261`)** — PRD-001 §15.1, OD-HR-10

- Migrasi `2026_10_24_100011_create_hr_documents_tables` (`hr_document_types`, `hr_documents`).
- Jenis dokumen = `CatalogKind::DocumentType` (`jenis-dokumen`, model `Domain/Catalog/DocumentType`, kolom `has_expiry`) — tab baru di Katalog SDM; `CreateCatalogItem` (+`hasExpiry`), `UpdateCatalogItem::setHasExpiry`, audit `hr.catalog_updated`; isi awal 7 jenis via `HrCatalogSeeder` (aman diulang).
- `Domain/Document/{EmployeeDocument, Exceptions/DocumentException}`; `Application/Document/{DocumentData, DocumentFileStore (disk privat, finfo, SHA-256, nama aman), ManageEmployeeDocuments (add/update/void/download + audit), EmployeeDocuments (daftar + status HABIS/SEGERA ≤ 60 hari)}`.
- `EmployeeDocumentController` + route `hr.employees.documents.{store,update,void,download}`; `EmployeeController::show` prop `documents` (bila view_personal) + `manage.options.documentTypes/documentMaxMb`.
- Frontend: `components/hr/employee-documents.tsx` (bagian **Dokumen & SK**: daftar, tambah dengan unggah, ubah isian, batalkan, unduh, tampilkan yang dibatalkan); `pages/hr/catalog.tsx` (pilihan masa berlaku).
- Test: `EmployeeDocumentTest` (5 test, awalan `dok`); `HrCatalogTest` disesuaikan (23 item awal, 4 katalog).
- Bukti: 627 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.
- Pelajaran: `UploadedFile::fake()` melaporkan MIME dari ekstensi; untuk menguji pemeriksaan isi file pakai `new UploadedFile($path, $name, null, null, true)` dari file sungguhan.

**HR-2b — Dokumen saya, Dokumen akan habis, kartu Beranda: SELESAI (2026-10-24, commit `93b8e0c`)** — PRD-001 §15.2

- Core: `Application/Dashboard/DashboardCards` (registri kartu Beranda, singleton) + `Http/Controllers/DashboardController` (route `dashboard` kini controller, prop `cards`); halaman `dashboard.tsx` diganti sambutan + kartu (placeholder starter kit dibuang).
- HR: `Application/Document/ExpiringDocuments` (`list`, `counts`); `MyDocumentController` (`hr.my-documents.index|download`), `ExpiringDocumentController` (`hr.documents.expiring`); kartu `hr.expiring_documents` didaftarkan di `HRServiceProvider`.
- Frontend: `pages/hr/my-documents.tsx`, `pages/hr/documents/expiring.tsx`; `DocumentSection` menerima `downloadUrl`; menu **Dokumen saya** & **Dokumen akan habis**.
- `EmployeeDocuments` kini diurutkan juga dengan `id` (UUIDv7) agar urutan stabil saat waktu dibuat sama.
- Test: `DocumentSelfServiceTest` (4 test, awalan `dks`).
- Bukti: 631 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.
- **HR-2 Dokumen & SK SELESAI (2026-10-24).**

**HR-5a — Komponen gaji & nominal per pegawai: SELESAI (2026-10-25, commit `0fc5661`)** — PRD-001 §16.1, OD-HR-11

- Permission baru `hr.payroll.manage` (Admin SDM, Admin Yayasan) → wajib `educore:sync-access`; `AccessCatalogTest` 20 permission.
- Migrasi `2026_10_25_100012_create_hr_pay_tables` (`hr_pay_components`, `hr_employee_pay_components`).
- `Domain/Payroll/{PayComponent, PayComponentKind, PayComponentBasis, EmployeePayComponent, Exceptions/PayrollException}`; `Application/Payroll/{DefaultPayComponents, ManagePayComponents, EmployeePay (ringkasan + simpan + parseAmount)}`; seeder `HrPayComponentSeeder` (dipanggil DatabaseSeeder, aman diulang).
- `PayrollSettingsController` (`/pegawai/gaji/pengaturan`, `hr.pay-components.*`), `EmployeePayController` (`PATCH pegawai/{id}/gaji`); prop `pay` di detail pegawai.
- Frontend: `pages/hr/payroll-settings.tsx`, `components/hr/employee-pay.tsx`, menu **Pengaturan gaji**.
- Test: `PayComponentTest` (3 test, awalan `gji`).
- Bukti: 634 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.

**HR-5a-2 — Gaji menurut jabatan & menu Gaji pegawai: SELESAI (2026-10-27, commit `b8a7184`)** — PRD-001 §16.1, OD-HR-12

- Owner 2026-10-27: tunjangan/gaji boleh mengikuti jabatan (selain mengajar); setup gaji di menu sendiri; slip gaji (jabatan + nominal) masuk HR-5c; jam mati **dan** jam hidup untuk honor JP.
- Migrasi `2026_10_27_100013_create_hr_pay_position_rates_table` (cara hitung `MENURUT_JABATAN`, tabel `hr_pay_position_rates` dengan unique parsial semua-lembaga/per-lembaga).
- `Domain/Payroll/PayPositionRate`; `Application/Payroll/ManagePositionRates` (set/remove, audit `hr.pay_rate_set|removed`); `EmployeePay::summaries()` (bulk: komponen per pegawai, penempatan, baris jabatan — tarif lembaga didahulukan, rangkap dijumlahkan).
- `PayrollEmployeeController` (`/pegawai/gaji` daftar, `/pegawai/gaji/{id}` setup, PATCH simpan); `PayrollSettingsController::setRate/removeRate`; `EmployeePayController` & bagian gaji di detail pegawai **dihapus**.
- Frontend: `pages/hr/payroll/{settings, employees, employee}.tsx` (settings dipindah dari `hr/payroll-settings`), `components/hr/pay-format.ts` (mengganti `employee-pay.tsx`), menu **Gaji pegawai**.
- Test: `PositionPayTest` (3 test, awalan `jbt`); `PayComponentTest` disesuaikan.
- Bukti: 637 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.

**HR-5b — Honor mengajar & potongan presensi: SELESAI (2026-10-27, commit `12956f2`)** — PRD-001 §16.2, OD-HR-11

- Owner 2026-10-27: cara hitung honor (jam mati/jam hidup) dipilih per jenjang/lembaga (opsi A).
- Migrasi `2026_10_27_100014_add_teaching_pay_flag_to_hr_catalogs` (`receives_teaching_pay` di jenis pegawai & status kepegawaian; HONORER & GTT dicentang).
- Katalog: pilihan ya/tidak dibuat umum — `CatalogKind::flag()` (jenis dokumen: masa berlaku; jenis pegawai & status kepegawaian: honor per JP), `UpdateCatalogItem::setFlag`, field form/prop `flag` (menggantikan `has_expiry`/`hasExpiryFlag`).
- Aturan berjenjang `hr.teaching_pay` (`TeachingPaySetting`) & `hr.attendance_deduction` (`AttendanceDeductionSetting`); kontrak `Contracts/TeachingLoad` (`NoTeachingLoad`; Academic `AcademicTeachingLoad`); `Application/Payroll/VariablePay` (honor JP per lembaga, presensi & potongan).
- `PayrollSettingsController::setTeachingPay/setDeduction` (`hr.payroll.teaching-pay|deduction`); `PayrollEmployeeController::show` prop `preview` (?bulan=); `LeaveSettingsOverview::rules/usage/targetOptions` kini publik untuk dipakai ulang.
- Frontend: `components/hr/scoped-rules.tsx`, bagian Honor mengajar & Potongan presensi di Pengaturan gaji, **Perkiraan bulan** di setup gaji pegawai, `pages/hr/catalog.tsx` (pilihan umum).
- Test: `TeachingPayTest` (3 test, awalan `hjp`); `EmployeeDocumentTest` disesuaikan.
- Bukti: 640 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.

**HR-5c-1 — Rekap gaji bulanan, penyesuaian, kunci, .xlsx: SELESAI (2026-10-27, commit `090afbd`)** — PRD-001 §16.3

- Owner 2026-10-27: tarif gaji diubah Admin Yayasan & Admin SDM (keuangan bagian dari Biro SDM dan Keuangan) → Core `SettingDefinition` dapat `permission` (bawaan `core.settings.manage`), `SetScopedSetting` memakainya; `hr.teaching_pay` & `hr.attendance_deduction` memakai `hr.payroll.manage`. Tidak ada role Staf Keuangan / `hr.payroll.view`.
- Migrasi `2026_10_27_100015_create_hr_payroll_tables` (`hr_payroll_adjustments`, `hr_payroll_months`, `hr_payslips` jsonb lines/basis).
- `Application/Payroll/PayrollCalculator` (baris per kelompok JABATAN/TETAP/HARI_HADIR/HONOR_JP/POTONGAN_PRESENSI/PENYESUAIAN, `columns()`), `ManagePayroll` (rows/payslips/lock/unlock/addAdjustment/removeAdjustment, decode snapshot bertipe), `PayrollRecapExport`.
- `PayrollRecapController` + route `hr.payroll-recap.{index,export,lock,unlock,adjustments.store,adjustments.destroy}`; halaman `pages/hr/payroll/recap.tsx`; menu **Rekap gaji**.
- Test: `PayrollRecapTest` (5 test, awalan `rkg`); `TeachingPayTest` + 1 (Admin SDM mengubah tarif).
- Bukti: 646 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.

**HR-5c-2 — Slip gaji & Gaji saya: SELESAI (2026-10-27, commit `564a061`)** — PRD-001 §16.3, OD-HR-12

- `ManagePayroll::lockedSlips()` (null bila belum dikunci; saring lembaga/pegawai) & `monthsFor()`; `PayslipController` (`print` → `hr.payroll-recap.payslips`; `myIndex`/`mySlip` → `hr.my-pay.index|payslip`).
- Frontend: `pages/print/payslips.tsx` (PrintLayout, A4, satu slip per halaman), `pages/hr/my-pay.tsx`, tombol **Cetak slip** & **Slip gaji** di Rekap gaji (bulan terkunci), menu **Gaji saya**.
- Test: `PayslipTest` (3 test, awalan `slp`).
- Bukti: 649 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.
- **HR-5 Komponen gaji SELESAI (2026-10-27).**

**HR-6a — Laporan pegawai & ekspor daftar pegawai: SELESAI (2026-10-28, commit `3c575a4`)** — PRD-001 §17

- `EmployeeDirectory`: `query()` dipakai bersama daftar, `ids()`, `export()` (data pribadi per baris lewat cakupan `view_personal`, tanpa NIK, batas 5.000); `EmployeeReport` (jumlah per lembaga/jenis/status/jabatan/jenis kelamin/pendidikan + berhenti tahun ini); `EmployeeExport` (xlsx daftar & laporan).
- `EmployeeReportController` + route `hr.employees.export` (`pegawai/unduh`), `hr.employees.report` (`pegawai/laporan`), `hr.employees.report.export`; audit `hr.employees_exported`.
- Frontend: `pages/hr/employees/report.tsx` (kartu + batang sederhana), tombol **Laporan** & **Unduh .xlsx** di halaman Pegawai.
- Test: `EmployeeReportTest` (4 test, awalan `lpg`).
- Bukti: 653 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.
- **Cakupan inti HR SELESAI (2026-10-28)** (PRD-001 §3): HR-1, HR-1e, HR-1g, HR-2, HR-3, HR-4, HR-5, HR-6a.
- Pelajaran: jangan `git reset --hard origin/main` sebelum memastikan origin sudah memuat commit owner — HR-5b sempat belum di-push (dipulihkan lewat reflog).

**AC-7a — Akun orang tua/wali & Anak saya: SELESAI (2026-10-28, commit `db7f755`)** — PRD-002 §21.1–21.2, OD-AC-16 s.d. OD-AC-18

- `Academic/Application/Guardian/GuardianAccounts` (status akun per tautan, buat/atur ulang/nonaktif/aktif lewat `ManageLoginAccount` Core; username = HP tanpa `+`; hanya orang yang murni orang tua/wali), `MyChildren` (daftar & detail anak: profil ringkas tanpa NIK/HP/alamat, lembaga + NIS, kelas berjalan + wali kelas, jadwal + pengampu, kalender lembaga).
- `GuardianAccountController` (`siswa/{student}/orang-tua/{guardian}/akun`, `/atur-ulang`, `/nonaktifkan`, `/aktifkan`), `MyChildrenController` (`anak-saya`, `anak-saya/{student}`); prop `manage.guardianAccounts` di detail siswa; shared prop `academic.isGuardian` → menu **Anak saya**.
- Frontend: `components/core/issued-password.tsx` (dialog sandi sementara dipindah dari `login-access.tsx`, + **Cetak kartu akun** berbasis DOM), `components/academic/guardian-account.tsx`, `pages/academic/my-children/{index,show}.tsx`.
- Test: `GuardianPortalTest` (5 test, awalan `aot`).
- Bukti: 658 test lulus, Pint PASS, Larastan `[OK]`, tsc tanpa error baru.

**AC-7a-fix1 — HP orang tua/wali unik & pencarian nama/HP: SELESAI (2026-10-28, commit `1e17de5`)** — PRD-002 §21.5, OD-AC-19, OD-AC-20

- Dari uji manual owner: HP yang sama bisa dipakai dua orang tua/wali berbeda. Sekarang `ManageGuardians::assertPhoneFree` (kunci advisory per HP) menolaknya di tambah baru/impor/form Tambah siswa/ubah HP; `GuardianAccounts` menolak membuat akun bila HP data lama masih dobel.
- `GuardianDirectory` (cari sebagian nama/HP, HP tersamar, nama anak + lembaga), `GuardianLookupController` (`orang-tua/cari`), pencarian detail siswa memakai `q`; `addExisting` tanpa HP; `GuardianData::membershipId` untuk baris form Tambah siswa yang dipilih dari pencarian.
- Frontend: `components/academic/guardian-search.tsx`; detail siswa "Orang yang sudah ada"; form Tambah siswa tombol "Cari yang sudah ada" per baris.
- Test: `StudentGuardiansTest` (pencarian, lookup, HP unik), `StudentNikTest` (NIK lain + HP sama ditolak), `GuardianPortalTest` (HP dobel data lama). 661 test lulus, Pint PASS, Larastan `[OK]`.
- Uji manual owner lancar (peringatan HP sama muncul, pencarian berhasil).

**AC-7b — Presensi, nilai final & rapor anak: SELESAI (2026-10-28, commit `b51efe5`)** — PRD-002 §21.3

- `Academic/Application/Guardian/ChildRecords` (kelas yang pernah diikuti anak, pilihan bulan, presensi per bulan mengikuti mode lembaga, tanpa keterangan guru); `MyChildren::name` (cek tautan).
- `MyChildrenController::attendance|grades|reportCard` + route `anak-saya/{student}/presensi`, `/nilai`, `/rapor` (rapor memakai `ReportCards::build` untuk satu pendaftaran; 404 bila masih ada mapel belum final).
- Frontend: `pages/academic/my-children/{attendance,grades}.tsx`, tombol Presensi & Nilai di detail anak; cetak memakai `print/report-cards`.
- Test: `ChildRecordsTest` (3 test, awalan `anr`). 664 test lulus, Pint PASS, Larastan `[OK]`; uji manual owner lancar.
- **AC-7 Akun & portal orang tua/wali SELESAI (2026-10-28).**

## Modul Keuangan (PRD-003)

**FN-1a — Kerangka modul, hak akses, jenis biaya & tarif: SELESAI (2026-10-28, commit `a51468a`)** — PRD-003 §12

- Modul baru `modules/Finance` (`FinanceServiceProvider` sesudah Academic); arch test diperluas (Finance → Core + `Academic\Contracts`).
- Kontrak Academic `AcademicYears` (+ `CalendarAcademicYears`).
- `FinanceAccess` (4 permission, role Bendahara & Admin Keuangan), `FinanceAuditAction`, migration `2026_10_28_300001_create_fn_fee_tables` (`fn_fee_types`, `fn_fee_rates`), `FeeType`/`FeeKind`/`FinanceException`, `ManageFeeTypes`, `ManageFeeRates`, `FeeSettingsOverview`, `Rupiah`, `FeeSettingsController` (`/keuangan/pengaturan`, `/keuangan/jenis-biaya…`, `/keuangan/tarif/{id}`).
- Frontend: `pages/finance/settings.tsx`; menu **Pengaturan keuangan** (tingkat Yayasan, `finance.settings.manage`).
- Test: `tests/Feature/Finance/FeeSettingsTest.php` (4 test, awalan `fns`); `AccessCatalogTest`, `MemberAccessTest`, `TreeAuthorizationTest` disesuaikan. 670 test lulus, Pint PASS, Larastan `[OK]`.
- Perbaikan sesudah uji owner: `composer test` memisah test arsitektur ke proses sendiri (kehabisan memori di Windows). Uji manual owner lancar.

**FN-1b — Potongan/beasiswa siswa: SELESAI (2026-10-29, commit `518d374`)** — PRD-003 §13

- Kontrak Academic `EnrolledStudents` (+ `AcademicEnrolledStudents`): cari siswa aktif per lembaga & data dasar pendaftaran.
- Migration `2026_10_29_300002_create_fn_discounts_table`; `ManageDiscounts`, `DiscountOverview`, `DiscountController` (`/keuangan/potongan`, `/keuangan/siswa/cari`); audit `finance.discount_set|removed` (tanpa alasan).
- Frontend: `pages/finance/discounts.tsx`; menu **Potongan siswa** (pemilik `finance.billing.view`).
- Test: `tests/Feature/Finance/DiscountsTest.php` (3 test, awalan `fnd`). 673 test lulus (7 arsitektur + 666), Pint PASS, Larastan `[OK]`.
- Uji manual owner lancar. **FN-1 SELESAI (2026-10-29).**

**FN-2a — Jatuh tempo, tagihan bulanan, daftar & batal tagihan: SELESAI (2026-10-29, commit `e2b07b6`)** — PRD-003 §14

- Core `ScopedSettingOverview` (rules/usage/targetOptions, dipindah dari HR `LeaveSettingsOverview` yang kini mendelegasikan); komponen `components/hr/scoped-rules` → `components/core/scoped-rules`.
- Kontrak Academic `EnrolledStudents::activeBetween()` (pendaftaran aktif dalam rentang + kelas & tingkat di tahun ajaran).
- Finance: `DueDaySetting` (`finance.due_day`), migration `2026_10_29_300003_create_fn_invoices_table`, `InvoiceStatus`, `GenerateMonthlyInvoices` (pratinjau/buat), `InvoiceList`, `ManageInvoices` (batal), `InvoiceController` (`/keuangan/tagihan`, `/pratinjau`, `/bulanan`, `/{id}/batalkan`), `FeeSettingsController::setDueDay` (`/keuangan/jatuh-tempo`), trait `Http/Controllers/Concerns/WorksPerOrganization` (dipakai juga `DiscountController`).
- Frontend: `pages/finance/invoices.tsx`, bagian Jatuh tempo di `pages/finance/settings.tsx`; menu **Tagihan**.
- Test: `tests/Feature/Finance/InvoicesTest.php` (4 test, awalan `fnv`). 677 test lulus (7 arsitektur + 670), Pint PASS, Larastan `[OK]`.
- Uji manual owner lancar.

**FN-2b — Tagihan sekali bayar & batal otomatis saat siswa keluar: SELESAI (`d4a46cb`)** — PRD-003 §15

- Academic: kejadian publik `Contracts\Events\EnrollmentClosed` (dikirim `ManageEnrollments::close`, juga dipakai lulus massal); kontrak `EnrolledStudents::classes()` + `class_id` di `activeBetween()`.
- Finance: `InvoicePricing` (tarif & potongan bersama, dipakai juga `GenerateMonthlyInvoices`), `GenerateOnceInvoices`, `VoidInvoicesAfterExit` (pendengar `EnrollmentClosed`), migration `2026_10_30_300004_add_once_unique_to_fn_invoices`; `InvoiceController::previewOnce|generateOnce` (`/keuangan/tagihan/pratinjau-sekali`, `/keuangan/tagihan/sekali`).
- Frontend: form **Buat tagihan sekali bayar** di `pages/finance/invoices.tsx`; komponen bersama `components/finance/student-search.tsx` (dipakai juga Potongan siswa).
- Test: `tests/Feature/Finance/OnceInvoicesTest.php` (4 test, awalan `fno`). 681 test lulus (7 arsitektur + 674), Pint PASS, Larastan `[OK]`.
- Uji manual owner lancar.

**FN-3 — Kasir, pembayaran, kuitansi: SELESAI (`d610d32`)** — PRD-003 §16

- Academic: kontrak `EnrolledStudents::search()` dapat parameter `includeClosed` (kasir juga mencari siswa yang sudah keluar; yang aktif diurutkan dulu, hasil memuat `active`).
- Finance: migration `2026_10_30_300005_create_fn_payments_tables` (`fn_payments`, `fn_payment_allocations`), `PaymentMethod`, `PaymentStatus`, `ManagePayments` (catat dengan alokasi terlama dulu + nomor kuitansi di bawah advisory lock; batal), `CashierOverview`, `Receipts`, `Terbilang`; `CashierController` (`/keuangan/kasir`, `/keuangan/kasir/cari`, `POST /keuangan/pembayaran`, `POST /keuangan/pembayaran/{id}/batalkan`, `/keuangan/kuitansi/{id}`).
- Frontend: `pages/finance/cashier.tsx`, `pages/print/receipt.tsx`; menu Keuangan → **Kasir**; `components/finance/student-search.tsx` dapat prop `url` dan tanda "Sudah keluar".
- Test: `tests/Feature/Finance/PaymentsTest.php` (5 test, awalan `fnp`). 686 test lulus (7 arsitektur + 679), Pint PASS, Larastan `[OK]`, `tsc` bersih.
- Uji manual owner lancar.

**FN-4a — Tunggakan & kartu Beranda: SELESAI (`2fdf51f`)** — PRD-003 §17

- Finance: `Application/Report/Arrears` (per lembaga, per kelompok kelas, per siswa, rincian), `ArrearsExport` (.xlsx 3 lembar), `ArrearsController` (`/keuangan/tunggakan`, `/keuangan/tunggakan/unduh`), kartu Beranda `finance.arrears` di `FinanceServiceProvider`, migration `2026_10_30_300006_add_open_due_index_to_fn_invoices` (indeks parsial).
- Frontend: `pages/finance/arrears.tsx`; menu Keuangan → **Tunggakan**.
- Test: `tests/Feature/Finance/ArrearsTest.php` (3 test, awalan `fna`); `DocumentSelfServiceTest` disesuaikan (Beranda kini bisa memuat kartu Keuangan). 689 test lulus (7 arsitektur + 682), Pint PASS, Larastan `[OK]`, `tsc` bersih.
- Uji manual owner lancar.

**FN-4b — Rekap penerimaan: SELESAI (`fc62e5b`)** — PRD-003 §18

- Finance: `Application/Report/ReceiptRecap` (ringkasan bulan: total, per hari, per jenis biaya, per lembaga; daftar transaksi), `ReceiptRecapExport` (.xlsx), `ReceiptRecapController` (`/keuangan/rekap-penerimaan`, `/keuangan/rekap-penerimaan/unduh`). Tanpa migrasi (indeks `fn_payments (tenant_id, organization_id, paid_on)` dari FN-3).
- Frontend: `pages/finance/receipt-recap.tsx`; menu Keuangan → **Rekap penerimaan**.
- Test: `tests/Feature/Finance/ReceiptRecapTest.php` (3 test, awalan `fnr`). 692 test lulus (7 arsitektur + 685), Pint PASS, Larastan `[OK]`, `tsc` bersih.
- Uji manual owner lancar.

**FN-5 — Tagihan di portal orang tua/wali: SELESAI (`a167383`)** — PRD-003 §19, PRD-002 §21.3

- Core: registri `Application/Portal/GuardianPortalLinks` (tombol modul lain di detail anak).
- Academic: kontrak `Contracts\GuardianChildren` + `Application/Guardian/AcademicGuardianChildren`; `MyChildrenController::show` mengirim `links`; `pages/academic/my-children/show.tsx` menampilkan tombolnya.
- Finance: `ChildBillingController` (`/anak-saya/{id}/tagihan`, `/anak-saya/{id}/kuitansi/{pembayaran}`), memakai `CashierOverview` & `Receipts`; tombol **Tagihan** didaftarkan di `FinanceServiceProvider`.
- Frontend: `pages/finance/child-billing.tsx`; `pages/print/receipt.tsx` dapat prop `backLabel`.
- Test: `tests/Feature/Finance/ChildBillingTest.php` (3 test, awalan `fnc`). 695 test lulus (7 arsitektur + 688), Pint PASS, Larastan `[OK]`, `tsc` bersih.
- Uji manual owner lancar. **Tahap awal Keuangan (PRD-003) selesai.**

## Kas umum & pengeluaran (PRD-004)

**KS-1 — Kas, rekening, kategori, pemasukan, buku kas: SELESAI (`fbcfef6`)** — PRD-004 §12

- Akses: `FinanceAccess::CASH_VIEW|CASH_MANAGE` (Bendahara, Admin Keuangan, Admin Yayasan: keduanya; Pimpinan & Kepala Lembaga: lihat) → **wajib `php artisan educore:sync-access`**.
- Finance: migration `2026_10_30_300007_create_fn_cash_tables`; `Domain/Cash` (`CashDirection`, `CashAccountKind`, `CashEntrySource`); `Application/Cash` (`ManageCashCategories`, `ManageCashAccounts`, `ManageCashEntries`, `CashBalances`, `CashBook`); `CashController` (`/keuangan/kas`, `/kas/rekening`, `/kas/pemasukan`, `/kas/transaksi/{id}/batalkan`), `CashCategoryController` (`/keuangan/kategori-kas`).
- Frontend: `pages/finance/cash.tsx`, `pages/finance/cash-categories.tsx`; menu Keuangan → **Kas**, **Kategori kas**.
- Test: `tests/Feature/Finance/CashTest.php` (5 test, awalan `fnk`); `AccessCatalogTest` & `TreeAuthorizationTest` disesuaikan. 700 test lulus (7 arsitektur + 693), Pint PASS, Larastan `[OK]`, `tsc` bersih.
- Uji manual owner lancar.

**KS-2 — Pembayaran siswa masuk buku kas: SELESAI (`68a18cb`)** — PRD-004 §13

- Finance: migration `2026_10_30_300008_add_payment_mirror_to_fn_cash` (`receives_transfers` + indeks unik); `Application/Cash/PaymentCashSync` (cerminan kuitansi per lembaga / per kuitansi, kunci advisory); `ManagePayments::record|void` memanggilnya di dalam transaksi kasir (batal ditolak bila saldo minus); `ManageCashAccounts::setTransferReceiver`; saldo awal hanya dikunci transaksi non-cerminan; `CashController::setTransferReceiver` (`POST /keuangan/kas/rekening/{id}/penerima-transfer`), `index` menyinkronkan kas lembaga.
- Frontend: `pages/finance/cash.tsx` — tanda **Otomatis** + tautan kuitansi, tombol **Jadikan penerima transfer siswa**, peringatan bila belum ada penerima.
- Test: `tests/Feature/Finance/CashPaymentsTest.php` (3 test, awalan `fnm`). 703 test lulus (7 arsitektur + 696), Pint PASS, Larastan `[OK]`, `tsc` bersih.
- Uji manual owner lancar.

**KS-3 — Pengajuan → persetujuan → realisasi pengeluaran: SELESAI (`1428db8`)** — PRD-004 §14

- Akses: `FinanceAccess::EXPENSES_APPROVE` (Kepala Lembaga, Pimpinan, Admin Keuangan, Admin Yayasan) → **wajib `php artisan educore:sync-access`**.
- Finance: migration `2026_10_30_300009_create_fn_expense_requests_table`; `Domain/Cash/ExpenseStatus`; `Application/Cash` (`ManageExpenses`, `ExpenseList`, `ExpenseFileStore`); `ExpenseController` (`/keuangan/pengeluaran`, `/{id}/batalkan|setujui|tolak|realisasi|batalkan-realisasi`, `/{id}/lampiran|bukti`); trait `Http/Controllers/Concerns/WorksPerCashBook` (pilihan kas & cek izin kas, dipakai juga `CashController`); kartu Beranda `finance.expenses_pending` & `finance.expenses_approved`.
- Frontend: `pages/finance/expenses.tsx`; menu Keuangan → **Pengeluaran**; tombol **Ajukan pengeluaran** di `pages/finance/cash.tsx`.
- Test: `tests/Feature/Finance/ExpensesTest.php` (4 test, awalan `fne`); `AccessCatalogTest` & `TreeAuthorizationTest` disesuaikan. 707 test lulus (7 arsitektur + 700), Pint PASS, Larastan `[OK]`, `tsc` bersih.
- Uji manual owner lancar.

**KS-4 — Mutasi & laporan arus kas: SELESAI (`d672621`)** — PRD-004 §15

- Finance: migration `2026_10_30_300010_create_fn_cash_transfers_table`; `Application/Cash/ManageCashTransfers`; kategori sistem `MUTASI-MASUK|KELUAR` (`ManageCashCategories::ensureDefaults` melengkapi kategori sistem, `systemId`); `CashBook::ledger` memisahkan mutasi (`transfer_in|out`); `CashController::storeTransfer|voidTransfer` (`POST /keuangan/kas/mutasi`, `/mutasi/{id}/batalkan`) + `transferTargets`; `Application/Report/CashFlowReport` & `CashFlowExport`; `CashReportController` (`/keuangan/laporan-kas`, `/unduh`). Kategori sistem tidak bisa dipilih untuk pengajuan pengeluaran.
- Frontend: `pages/finance/cash.tsx` (form **Mutasi / setoran**, tanda Mutasi, batal mutasi, ringkasan mutasi), `pages/finance/cash-report.tsx`; menu Keuangan → **Laporan kas**.
- Test: `tests/Feature/Finance/CashTransfersTest.php` (4 test, awalan `fnt`); `CashTest` disesuaikan (12 kategori bawaan). 711 test lulus (7 arsitektur + 704), Pint PASS, Larastan `[OK]`, `tsc` bersih.
- Uji manual owner lancar.

**KS-5 — Persetujuan pengeluaran berjenjang: SELESAI (`1693032`)** — PRD-004 §16

- Finance: `Application/Cash/ExpenseApprovalLimitSetting` (aturan berjenjang `finance.expense_approval_limit`, didaftarkan di `FinanceServiceProvider`); migration `2026_10_30_300011_add_second_approval_to_fn_expense_requests` (status `MENUNGGU_YAYASAN`, kolom tahap 1, `status` varchar 20); `ManageExpenses::approve|reject` (parameter `$foundationLevel`), `cancel` mengizinkan menunggu Yayasan; `ExpenseList` (`first_decision`, `countAwaitingFoundation`); `ExpenseController` (`can_decide` tahap 2); `FeeSettingsController::setApprovalLimit` (`POST /keuangan/batas-persetujuan`).
- Frontend: `pages/finance/settings.tsx` (bagian **Batas persetujuan pengeluaran**), `pages/finance/expenses.tsx` (status & jejak tahap 1).
- Test: `tests/Feature/Finance/ExpenseApprovalLimitTest.php` (2 test, awalan `fnb`). 713 test lulus (7 arsitektur + 706), Pint PASS, Larastan `[OK]`, `tsc` bersih.
- Uji manual owner lancar.

## Perapian tampilan

**UI-1 — Menu berkelompok & halaman Keuangan: DIBANGUN (2026-10-31, menunggu uji owner)** — permintaan owner 2026-10-31

- Menu samping (`components/app-sidebar.tsx`, `components/nav-main.tsx`, tipe `NavGroup`): kelompok **Untuk saya**, **Akademik**, **Kepegawaian**, **Keuangan siswa**, **Kas & pengeluaran**, **Pengaturan** (pengaturan cuti/gaji/keuangan, kategori kas, kalender akademik, katalog SDM, lembaga, log audit dipindah ke sini). Kelompok bisa dibuka/tutup (diingat di browser), kelompok yang memuat halaman aktif selalu terbuka; hanya satu menu aktif (alamat paling cocok, mis. `/pegawai/123` → Pegawai). Hak akses tiap menu tidak berubah.
- Komponen bersama: `components/page-tabs.tsx` (`PageTabs`, `usePageTab` — tab tercatat di `?tab=`), `components/form-dialog.tsx` (`FormDialog`).
- Halaman Keuangan: **Pengaturan keuangan** (tab Jenis biaya · Tarif · Jatuh tempo · Batas persetujuan; tambah jenis biaya di pop-up), **Kas** (tab Buku kas · Rekening; catat pemasukan, mutasi, tambah rekening di pop-up), **Tagihan** (buat tagihan bulanan/sekali bayar di pop-up), **Pengeluaran** (ajukan, setujui, tolak, realisasi, batal realisasi di pop-up), **Rekap penerimaan** (tab Ringkasan · Transaksi; klik tanggal → tab Transaksi), **Kasir** (tab Tagihan & bayar · Riwayat pembayaran), **Potongan siswa** (tambah di pop-up), **Kategori kas** (tab Pemasukan · Pengeluaran; tambah di pop-up).
- Tanpa perubahan backend/test; `tsc` bersih. (`vite build` tidak bisa dijalankan di lingkungan uji Claude — dijalankan owner.)

### Pedoman tampilan (berlaku untuk halaman baru) [owner 2026-10-31]

1. Menu baru masuk ke salah satu kelompok di atas; pengaturan selalu di **Pengaturan**.
2. Satu tampilan = satu fungsi utama. Bagian yang berbeda (mis. daftar vs pengaturan vs riwayat) dipisah dengan `PageTabs`; tab tercatat di alamat dan ikut dikirim saat mengganti saringan.
3. Form tambahan (tambah, catat, ajukan, setujui, batalkan) dibuka dengan `FormDialog`, bukan disisipkan di atas daftar. Form sangat panjang (mis. tambah siswa/pegawai) boleh tetap halaman tersendiri.
4. Saringan di atas daftar; ringkasan angka (kotak statistik) paling banyak satu baris.

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
- Larastan menolak `array_values()` pada data yang sudah list (mis. hasil `array_reverse`/`array_slice` dari list); pakai `array_values()` hanya sesudah `array_filter()`/`array_unique()` (P4b-fix1).
- Test yang bergantung pada tanggal: kunci "hari ini" dengan `$this->travelTo(...)` di awal `beforeEach`, sebelum seeder (P4b).
- Sebelum mulai tahap baru di salinan kerja: `git fetch` lalu pastikan berbasis commit terakhir di origin (AC-5b sempat dimulai di atas commit lama; diselamatkan dengan stash → reset → pop).
- Pegawai yang ditautkan ke akun yang sudah ada memakai nama orang akun itu (bukan nama di `NewEmployeeData`); ekspektasi test harus memakai nama tersebut (AC-5d-fix1).
- Permission baru untuk role yang sudah ada: perbarui juga test yang mencocokkan daftar permission role (mis. `TreeAuthorizationTest`, `AccessCatalogTest`). Seeder yang mencatat audit: saring audit di test per subjek (HR-3a-fix1).
- `composer test` menjalankan test arsitektur (`--testsuite=Architecture`) dan test lain (`--testsuite=Unit,Feature`) di **dua proses terpisah**: Pest arch mem-parse seluruh kode dan menahan ±400 MB sampai proses selesai, sehingga di Windows/Laragon (`memory_limit` 512M) test berikutnya kehabisan memori sesudah FN-1a menambah aturan batas modul (2026-10-28). Saat menjalankan satu file test, cukup `php artisan test tests/Feature/...`.
- `assertSessionHasNoErrors()` **tetap lulus untuk respons 500** (tanpa error validasi). Untuk aksi POST yang harus berhasil, pakai `->assertRedirect()->assertSessionHasNoErrors()` (KS-5: pelanggaran CHECK constraint sempat lolos test).
- Jangan panggil `set_time_limit()` langsung; pakai `Modules\Core\Support\ExecutionTime::atLeast()` (hanya menaikkan batas). `set_time_limit(300)` di controller impor sempat memasang batas 300 detik ke seluruh proses `composer test` sehingga test sesudahnya gagal "Maximum execution time" (AC-4a, 2026-10-21).

## Tugas berikutnya

1. Uji UI-1 oleh owner, lalu **UI-2**: pola yang sama untuk halaman Akademik & SDM yang padat (Kalender akademik, Pengaturan cuti & presensi, Pengaturan gaji, detail siswa, detail pegawai, kelas, lembaga, dll.).
2. Keuangan lanjutan berikutnya (urutan owner, PRD-004 §11): tabungan siswa → payment gateway Midtrans → pembukuan; masing-masing dirinci & disetujui dulu.
3. Sesudah Keuangan: AC-8 (ekspor Dapodik/EMIS, PPDB, asrama) atau HR "menyusul" (kinerja/PKG, rekrutmen, disiplin, offboarding, Dapodik/Simpatika, mesin absen).
