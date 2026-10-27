# PRD-001 — Modul HR (Kepegawaian)

- **Versi:** 0.20 (2026-10-27: rincian & pembangunan HR-5b honor JP jam mati/jam hidup per jenjang/lembaga & potongan presensi — §16.2)
- **Versi sebelumnya:** 0.19 (komponen Menurut jabatan + menu Gaji pegawai — §16.1; jam mati & jam hidup; slip gaji masuk HR-5c; OD-HR-11 direvisi, OD-HR-12), 0.18 (rincian HR-5 — §16, OD-HR-11; HR-5a), 0.17 (HR-2b Dokumen saya, Dokumen akan habis, kartu Beranda — §15.2), 0.16 (cakupan "HR selesai" — §3; HR-2 Dokumen & SK — §15, OD-HR-10; HR-2a), 0.15 (HR-4c Rekap presensi pegawai — §14.4), 0.14 (HR-4b Lembar presensi pegawai — §14.3), 0.13 (NIK sebagai penanda unik orang — OD-HR-01 direvisi, §4.5; impor `nik` & `lembaga_tambahan` — §6.2; HR-1g), 0.12 (rincian HR-4 Presensi pegawai — §14), 0.11 (rincian HR-3 Cuti & Izin — §13), 0.10 (form Tambah pegawai memperingatkan nama sama), 0.9 (impor: peringatan nama sama), 0.8 (HR-1e-b — simpan impor; batas waktu proses simpan), 0.7 (rincian HR-1e — impor pegawai dari Excel/CSV; OD-HR-05)
- **Status:** HR-1 Data Pegawai SELESAI (2026-10-14, commit `d72eb3d`); HR-1e SELESAI (commit `b5cc66f`, `7902d3a`); **HR-3 dirinci & disetujui (§13, 2026-10-22)**: HR-3a SELESAI (`4cf3e44`), HR-3b SELESAI (`8de802d`), HR-3c SELESAI (`7da5e98`), HR-3d-a SELESAI (`4544d79`), HR-3d-b SELESAI (`451f94d`) — **HR-3 SELESAI (2026-10-22)**; **HR-4 dirinci & disetujui (§14, 2026-10-22)**: HR-4a SELESAI (ikut `a69c50a`), HR-4b SELESAI (`58b83d0`), HR-4c SELESAI (`e3df916`) — **HR-4 SELESAI (2026-10-24)**; **HR-1g SELESAI (`9849873`)**; **HR-2 dirinci & disetujui (§15, 2026-10-24)**: HR-2a SELESAI (`287f261`), HR-2b SELESAI (`93b8e0c`) — **HR-2 SELESAI (2026-10-24)**; **HR-5 dirinci & disetujui (§16, 2026-10-25)**: HR-5a SELESAI (`0fc5661`), HR-5a-2 SELESAI (`b8a7184`), **HR-5b dibangun 2026-10-27**; HR-6a belum dirinci
- **Bergantung pada:** PRD-000 (pondasi F0–F4 SELESAI), ADR-001
- **Referensi domain lama:** HR-001 (PRD HR), HR-002 (workforce foundation), HR-013 (otorisasi), HR-014 (klasifikasi data), HR-017 (daftar & tambah pegawai per workspace) di `docs.zip`

Label: **[LAMA]** = dari dokumen lama · **[OWNER]** = keputusan owner · **[ASUMSI]** = usulan baru, perlu konfirmasi.

---

## 1. Tujuan

Biro SDM dan Keuangan punya **satu sumber data pegawai** untuk seluruh yayasan: siapa pegawainya, statusnya, di lembaga mana ia bertugas, dan sebagai apa. Kepala lembaga melihat pegawai di lembaganya tanpa bisa melihat lembaga lain. [LAMA HR-001 BO-001, BO-002]

Prinsip:

1. Mengikuti pondasi: data pegawai disaring per pohon lembaga (PRD-000 §7), tercatat di audit log (OD-15), dan memakai katalog akses di kode (OD-11).
2. Dibangun bertahap. PRD ini merinci **HR-1 Data Pegawai** saja. Tahap berikutnya hanya digariskan (§3) dan dirinci ketika akan dikerjakan.
3. Desain dokumen lama disederhanakan untuk tim 1–5 orang (§4.2).

## 2. Pengguna

| Peran                              | Kebutuhan di HR-1                                                                                                      | Sumber                              |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ----------------------------------- |
| Admin SDM (Biro SDM dan Keuangan)  | **Satu-satunya** yang menambah dan mengubah pegawai, mencatat mutasi/jabatan/berhenti, mengelola katalog               | [LAMA HR-001 §6], [OWNER, OD-HR-02] |
| Admin Yayasan                      | Sama seperti Admin SDM, supaya modul langsung bisa dipakai setelah deploy (sebelum halaman kelola akses ada, OD-HR-03) | [ASUMSI]                            |
| Pimpinan Yayasan / Pimpinan Ponpes | Melihat pegawai di cakupannya                                                                                          | [LAMA]                              |
| Kepala Lembaga / Kepala Unit       | Melihat pegawai di lembaganya beserta turunannya                                                                       | [OWNER, PRD-000 §4.1]               |
| Koordinator (mis. MDA)             | Melihat pegawai di semua lembaga binaannya (penugasan fungsional)                                                      | [OWNER, PRD-000 §4.4]               |
| Guru / staf                        | Belum ada fitur di HR-1. Layanan mandiri (profil saya, cuti) menyusul di HR-3                                          | [LAMA HR-001 FR-046]                |

## 3. Tahapan modul HR [ASUMSI]

| Tahap                              | Isi                                                                                                                                                                               | Catatan                                                                                   |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| **HR-1 Data Pegawai**              | Katalog (jenis pegawai, status kepegawaian, jabatan), profil pegawai, periode kepegawaian, penempatan + jabatan, berhenti / aktif kembali, daftar dan detail pegawai, audit       | **Dirinci di PRD ini**                                                                    |
| **HR-1e Impor pegawai**            | Impor pegawai baru dari Excel/CSV dengan template, pratinjau, dan simpan sekaligus (§6.2)                                                                                         | Dipilih owner sesudah F5 [OWNER, 2026-10-14]                                              |
| HR-2 Dokumen & SK                  | Arsip SK/kontrak (file privat), masa berlaku dan pengingat                                                                                                                        | **Dirinci di §15** [OWNER, 2026-10-24; OD-HR-10]                                          |
| Core: Kelola akses                 | Halaman buat akun login, penugasan, pasang/cabut role                                                                                                                             | **Dibuat sesudah HR-1, sebelum HR-3** [OWNER, OD-HR-03]                                   |
| HR-3 Cuti & Izin + layanan mandiri | Jenis cuti, jatah, pengajuan, persetujuan berjenjang (memakai aturan berjenjang PRD-000 §8)                                                                                       | Pegawai perlu akun login; **dirinci di §13** [OWNER, 2026-10-22: dikerjakan sebelum HR-2] |
| HR-4 Presensi                      | Absen mandiri + lembar petugas + rekap; mesin absen menyusul                                                                                                                      | [LAMA OD-HR-005]; **dirinci di §14** [OWNER, 2026-10-22]                                  |
| HR-5 Komponen gaji                 | Komponen tetap per pegawai, honor per JP mengajar, potongan dari presensi, tunjangan/insentif lain → rekap input gaji bulanan (.xlsx); perhitungan & pembayaran tetap di Keuangan | [LAMA OD-HR-003]; komponen dipilih owner 2026-10-24; dirinci sesudah HR-2                 |
| HR-6a Laporan pegawai              | Jumlah pegawai per lembaga/jenis/status/jabatan + ekspor daftar pegawai                                                                                                           | [LAMA FR-041]; §12                                                                        |
| HR-6 dst. (menyusul)               | Kinerja/PKG & PKB, riwayat sertifikasi/pelatihan, rekrutmen, disiplin, offboarding checklist, ekspor Dapodik/EMIS/Simpatika, impor mesin absen                                    | [LAMA HR-001 §7]; di luar cakupan "HR selesai"                                            |

**Cakupan "HR selesai"** [OWNER, 2026-10-24]: inti dulu — HR-2 (a, b) → HR-5 → HR-6a. Sisanya (baris "HR-6 dst.") menyusul setelah modul lain. Urutan bisa diubah owner sebelum tiap tahap dimulai.

HR-5 mengambil JP terlaksana dari Akademik (AC-6c) lewat kontrak di `Modules\HR\Contracts` yang diisi Akademik, karena HR tidak boleh bergantung langsung pada Akademik [ASUMSI].

## 4. Model data HR-1

### 4.1 Konsep

```text
Person ──► Membership (orang × yayasan)
              └──► Pegawai (hr_employees)             satu per membership
                     ├──► Periode kepegawaian          riwayat jenis & status kepegawaian
                     │      (hr_employment_periods)    maks. satu yang terbuka
                     └──► Penempatan                   "Jabatan X di Lembaga Y"
                            (hr_placements)            boleh lebih dari satu sekaligus
```

- Nama dan jenis kelamin tetap di **Person**. HR tidak menyimpan nama sendiri. [LAMA BR-001]
- Pegawai **tidak otomatis punya akun login** (User). [LAMA BR-002]
- **Jabatan bukan hak akses.** Jabatan "Kepala Sekolah" tidak memberi permission apa pun; hak akses tetap lewat role di Core. [LAMA BR-006, INV-HR-003]

### 4.2 Penyederhanaan dari desain lama [ASUMSI]

| Desain lama (HR-002)                                               | HR-1                                                                       | Alasan                                                                                                |
| ------------------------------------------------------------------ | -------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| Employee → Employment → Placement → PositionAssignment (4 tingkat) | Pegawai → Periode kepegawaian + Penempatan (jabatan menyatu di penempatan) | Lebih sedikit tabel dan form. Kasus "jabatan di tingkat yayasan" cukup ditempatkan di node Biro       |
| Placement merujuk `organizational_assignment_id` (Core)            | Penempatan menyimpan `organization_id` langsung                            | Sesuai PRD-000 §5: data bisnis menyimpan node pemiliknya. Penempatan HR terpisah dari hak akses login |
| Status `PLANNED / ACTIVE / ENDED / CANCELLED`                      | Periode hanya **terbuka** / **ditutup**                                    | Tidak ada penjadwal otomatis. Tanggal di masa depan ditolak di HR-1                                   |
| Kolom legacy `jabatan`                                             | Tidak ada                                                                  | Rebuild dari nol, tanpa data lama                                                                     |

### 4.3 Tabel

Semua tabel memakai UUIDv7 dan `tenant_id` (FK gabungan `(tenant_id, x)` seperti Core) dan berprefiks `hr_` agar jelas pemiliknya. [ASUMSI]

**`hr_employment_types`, `hr_employment_classifications`, `hr_positions`** — katalog per yayasan

| Field     | Aturan                                                                                                          |
| --------- | --------------------------------------------------------------------------------------------------------------- |
| code      | Huruf besar/angka/`-`, 2–30 karakter, unik per yayasan, **tidak bisa diubah** (sama dengan kode lembaga, OD-14) |
| name      | Wajib, maks. 100 karakter                                                                                       |
| is_active | Tidak dihapus; nonaktif = tidak bisa dipilih untuk data baru, riwayat tetap tampil                              |

Isi awal (seeder, bisa diubah) [ASUMSI]:

- **Jenis pegawai** (`employment_type`): Tetap, Kontrak, Honorer.
- **Status kepegawaian** (`classification`): GTY (Guru Tetap Yayasan), GTT (Guru Tidak Tetap), PTY (Pegawai Tetap Yayasan), PTT (Pegawai Tidak Tetap). Boleh kosong di periode. [LAMA OD-HR-001, BR-004]
- **Jabatan**: Kepala Sekolah/Madrasah, Wakil Kepala, Guru, Ustadz/Ustadzah, Wali Kelas, Tata Usaha, Bendahara, Operator, Staf Biro.

**`hr_employees`** — profil pegawai

| Field           | Aturan                                                                                                                                                   |
| --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| membership_id   | Wajib, unik (satu profil pegawai per orang per yayasan) [LAMA BR-003]                                                                                    |
| employee_number | Nomor induk pegawai yayasan; opsional; unik per yayasan bila diisi; huruf besar, angka, `.`, `/`, `-`, maks. 30 karakter (disimpan huruf besar) [ASUMSI] |
| status          | `AKTIF` \| `BERHENTI` (mengikuti periode kepegawaian yang terbuka)                                                                                       |
| nuptk           | Opsional; tepat 16 digit; unik per yayasan bila diisi                                                                                                    |
| education_level | Pendidikan terakhir, opsional: `SD`, `SMP`, `SMA` (termasuk SMK/MA/pesantren setara), `D1`, `D2`, `D3`, `D4_S1`, `S2`, `S3`, `LAINNYA`                   |
| education_major | Program studi/jurusan, opsional, maks. 150 karakter                                                                                                      |

**`hr_employment_periods`** — periode kepegawaian

| Field              | Aturan                                                                                                                                                                                                         |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| employee_id        | Wajib                                                                                                                                                                                                          |
| employment_type_id | Wajib                                                                                                                                                                                                          |
| classification_id  | Opsional                                                                                                                                                                                                       |
| start_date         | Wajib, tidak boleh di masa depan                                                                                                                                                                               |
| end_date           | Kosong = periode berjalan; ≥ start_date                                                                                                                                                                        |
| end_reason         | Wajib saat periode ditutup. Pegawai berhenti: `MENGUNDURKAN_DIRI`, `KONTRAK_SELESAI`, `PENSIUN`, `DIBERHENTIKAN`, `MENINGGAL`, `LAINNYA` (+ catatan). Ganti status: `PERUBAHAN_STATUS` (diisi sistem) [ASUMSI] |

- Maksimal satu periode terbuka per pegawai (dijaga partial unique index). [LAMA OD-HR-DATA-001]
- Periode tidak boleh tumpang tindih (dicek di service dalam transaksi yang mengunci baris pegawai).
- **Ganti status** (mis. Kontrak → Tetap per 1 Juli): periode lama ditutup 30 Juni dan periode baru dibuka 1 Juli dalam satu transaksi.
- **Diangkat kembali** setelah berhenti: buka periode baru. Pegawai dan Person tetap yang sama. [LAMA INV-HR-001]

**`hr_placements`** — penempatan dan jabatan

| Field                 | Aturan                                               |
| --------------------- | ---------------------------------------------------- |
| employee_id           | Wajib                                                |
| organization_id       | Wajib; node aktif di yayasan yang sama (FK gabungan) |
| position_id           | Wajib; jabatan aktif                                 |
| is_primary            | Maksimal satu penempatan utama terbuka per pegawai   |
| start_date / end_date | Seperti periode; kosong = masih berjalan             |

- Satu orang boleh punya beberapa penempatan sekaligus, mis. Guru di MTs Unit 1 **dan** Ustadz di MDA Unit 1. [LAMA FR-008]
- Penempatan yang sama (lembaga + jabatan) tidak boleh dobel saat masih terbuka.
- **Mutasi** = tutup penempatan lama + buka penempatan baru dalam satu transaksi.
- Penempatan hanya bisa dibuat untuk pegawai `AKTIF`.

### 4.4 Invarian

1. Pegawai berhenti → periode terbuka **dan semua penempatan terbuka** ditutup di tanggal yang sama, dalam satu transaksi. [LAMA INV-HR-008]
2. Berhenti **tidak** menghapus atau menonaktifkan Person, User, Membership, maupun penugasan Core. Akses login diurus terpisah (lihat OD-HR-03). [LAMA BR-011, INV-HR-006]
3. Tidak ada hapus permanen untuk data kepegawaian. Salah input diperbaiki dengan **mengubah** baris yang masih terbuka; perubahannya tercatat di audit log. [LAMA INV-HR-010]
4. Tambah pegawai baru = Person + Membership + Pegawai + periode pertama + penempatan pertama, **dalam satu transaksi**. Bila satu langkah gagal, semuanya batal. [LAMA HR-017 INV-HR-012]

### 4.5 Data pribadi (OD-HR-01) [OWNER, 2026-10-13; NIK direvisi 2026-10-23]

Disimpan minimal. Pemiliknya mengikuti PRD-000 §5: data tentang **orang** di Person (dipakai juga oleh Academic kelak), data khusus **pegawai** di `hr_employees`.

| Field                                   | Tabel                                    | Aturan                                                                                                                             |
| --------------------------------------- | ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| birth_place                             | `persons`                                | Opsional, maks. 100 karakter                                                                                                       |
| birth_date                              | `persons`                                | Opsional; tidak di masa depan                                                                                                      |
| phone                                   | `persons`                                | Opsional; 8–19 angka, boleh diawali `+`; disimpan tanpa spasi/tanda hubung (form membersihkannya)                                  |
| address                                 | `persons`                                | Opsional, maks. 500 karakter                                                                                                       |
| gender                                  | `persons` (sudah ada)                    | **Wajib** untuk pegawai baru (`L`/`P`) [ASUMSI]                                                                                    |
| NIK                                     | `person_identifiers` (Core, terenkripsi) | **Wajib untuk pegawai baru** (form & impor), 16 angka; unik di seluruh EduCore. Pegawai lama boleh belum punya [OWNER, 2026-10-23] |
| nuptk, education_level, education_major | `hr_employees`                           | Lihat §4.3                                                                                                                         |

- **NIK** [OWNER, 2026-10-23, mengganti keputusan "tanpa NIK"]: menjadi penanda unik orang agar impor/tambah pegawai tidak membuat orang ganda hanya karena nama sama.
    - Disimpan di `person_identifiers` (PRD-000 §5): nilai **terenkripsi** + fingerprint HMAC-SHA256 (kunci turunan `APP_KEY`) untuk pencarian & cegah dobel. Satu orang satu NIK; satu NIK satu orang.
    - Isian dirapikan (spasi, titik, tanda hubung dibuang) lalu wajib 16 angka. Bila digit tanggal lahir di NIK (DDMMYY, tanggal +40 untuk perempuan) tidak cocok dengan tanggal lahir/jenis kelamin: **peringatan**, bukan penolakan [ASUMSI].
    - NIK sudah dipakai orang lain → ditolak. Pesan menyebut nama pemilik **hanya** bila ia anggota yayasan yang sama (pegawai: "sudah tercatat atas nama X"; anggota non-pegawai: arahkan ke mode Anggota yayasan). Pemilik di yayasan lain → pesan umum tanpa nama [ASUMSI, disetujui 2026-10-23].
    - Tampilan: di detail pegawai **tersamar** (`3201 •••• •••• 0001`) bagi pemilik `hr.employees.view_personal`; angka lengkap hanya di form **Ubah profil** (pengelola). Tidak pernah masuk audit/log; audit hanya mencatat nama field `nik`.
    - Ubah profil: pegawai lama boleh dilengkapi NIK; NIK yang sudah tercatat boleh diperbaiki tetapi **tidak bisa dikosongkan** [ASUMSI].
    - Mode Anggota yayasan: NIK wajib bila orangnya belum punya; bila sudah, kolom boleh kosong (NIK lama tetap).
    - Mengganti `APP_KEY` membuat fingerprint lama tidak cocok; simpan kunci lama di `APP_PREVIOUS_KEYS` dan hitung ulang fingerprint bila kunci diganti [ASUMSI].
- Tempat/tanggal lahir, HP, dan alamat hanya tampil bagi pemilik `hr.employees.view_personal`; NUPTK dan pendidikan termasuk data kepegawaian (`hr.employees.view`). [ASUMSI]
- Audit log hanya mencatat **nama field** yang berubah, bukan nilainya (§7).

## 5. Siapa melihat apa

### 5.1 Permission baru (didefinisikan di modul HR, disinkronkan lewat `educore:sync-access`) [ASUMSI]

| Permission                   | Isi                                                                                                  | Kelas data [LAMA HR-014 §4] |
| ---------------------------- | ---------------------------------------------------------------------------------------------------- | --------------------------- |
| `hr.employees.view`          | Daftar dan detail pegawai: nama, nomor induk, jenis/status kepegawaian, penempatan, jabatan, riwayat | INTERNAL                    |
| `hr.employees.view_personal` | Data pribadi §4.5                                                                                    | CONFIDENTIAL                |
| `hr.employees.manage`        | Tambah pegawai, ubah profil, ganti status, mutasi/jabatan, berhenti, aktifkan kembali                | —                           |
| `hr.catalog.manage`          | Kelola jenis pegawai, status kepegawaian, jabatan (hanya di tingkat Yayasan)                         | —                           |

### 5.2 Role [ASUMSI]

| Role                              | Permission HR        | Lingkup                            |
| --------------------------------- | -------------------- | ---------------------------------- |
| **Admin SDM** (baru, `admin-sdm`) | keempatnya           | Seluruh yayasan (role tenant-wide) |
| Admin Yayasan                     | keempatnya           | Seluruh yayasan                    |
| Pimpinan                          | view + view_personal | Cakupan penugasannya               |
| Kepala Lembaga                    | view + view_personal | Lembaganya + turunan               |
| Koordinator                       | view                 | Lembaga binaannya                  |
| Staf                              | —                    | —                                  |

Kepala Lembaga **tidak** menambah/mengubah pegawai; data satu pintu di Admin SDM pusat. [OWNER, OD-HR-02]

Sampai halaman kelola akses ada (OD-HR-03), role Admin SDM belum bisa dipasang lewat browser; Admin Yayasan yang menjalankan HR-1. [ASUMSI]

### 5.3 Aturan penyaringan

Pegawai terlihat di node N bila **salah satu penempatannya** ada di `visibleNodeIds('hr.employees.view')` dan di dalam lembaga kerja aktif (PRD-000 §7.2). Yang dipakai:

- Pegawai **aktif**: penempatan yang masih terbuka.
- Pegawai **berhenti**: penempatan yang ditutup bersamaan dengan berhentinya, supaya kepala lembaga terakhirnya tetap melihat riwayatnya. [ASUMSI]
- Pegawai **tanpa penempatan** hanya terlihat oleh pemegang role tenant-wide. [LAMA HR-002 §12.2]

Saringan dijalankan di query database sebelum paginasi. Test wajib membuktikan pegawai lembaga sebelah tidak pernah muncul. [LAMA HR-017 INV-HR-011]

Rincian daftar & detail (HR-1c) [ASUMSI]:

- Saringan **lembaga** ikut turunannya (memilih Unit 1 juga menampilkan pegawai MTs Unit 1); saringan **jenis/status kepegawaian** memakai periode terakhir pegawai; saringan **jabatan** memakai penempatan berjalan.
- Di halaman detail, **semua** penempatan pegawai yang terlihat ikut tampil, termasuk di lembaga lain (mis. Kepala Unit 1 melihat bahwa guru MTs Unit 1 juga mengajar di MDA Unit 2).
- Data pribadi di detail hanya tampil bila pegawai itu terlihat dengan `hr.employees.view_personal`; bila tidak, bagian Data pribadi menampilkan keterangan terkunci.
- Masa kerja: periode yang bersambung digabung, lalu dihitung bulan penuh sampai hari ini (tanggal yayasan); tampil "X tahun Y bulan".
- Parameter `per_page` (1–100) boleh dipakai; nilai saringan yang tidak sah diabaikan.

Jawaban akses mengikuti OD-13: pegawai di luar cakupan → **404**; di dalam cakupan tanpa izin aksi → **403**.

## 6. Halaman HR-1

Menu **Pegawai** (`/pegawai`) muncul bagi pemilik `hr.employees.view`. Menu **Katalog SDM** (`/pegawai/katalog?jenis=jenis-pegawai|status-kepegawaian|jabatan`) muncul bagi pemilik `hr.catalog.manage` dan hanya bisa dibuka di lembaga kerja **Seluruh Yayasan** (selain itu 403). [ASUMSI]

| Halaman        | Isi                                                                                                                                                                                                                                          |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Daftar pegawai | Cari nama/nomor induk; filter lembaga, jenis, status kepegawaian, jabatan, aktif/berhenti (default: aktif); 25 baris per halaman; jumlah total tampil. Responsif: tabel di desktop, kartu di HP                                              |
| Tambah pegawai | Pilih **orang baru** atau **anggota yayasan yang sudah ada** (mis. pemilik akun yang belum punya profil pegawai). Lalu isi nama, L/P, data pribadi (§4.5), nomor induk, jenis, status, tanggal mulai, penempatan pertama (lembaga + jabatan) |
| Detail pegawai | Profil; periode kepegawaian (riwayat); penempatan aktif & riwayat; masa kerja (tahun-bulan, periode bersambung digabung)                                                                                                                     |
| Aksi di detail | Ubah profil, Ganti status kepegawaian, Tambah penempatan, Mutasi, Akhiri penempatan, Jadikan utama, Berhentikan, Aktifkan kembali                                                                                                            |
| Katalog SDM    | Tiga tab (Jenis pegawai, Status kepegawaian, Jabatan): tambah, ubah nama, nonaktifkan / aktifkan kembali                                                                                                                                     |

Semua halaman punya kondisi kosong, loading, dan error. Pesan validasi berbahasa Indonesia.

**Mencegah orang ganda** [LAMA RISK-HR-004]: NIK yang sama **ditolak** (pasti orang yang sama). Untuk data lama tanpa NIK, form tambah memperingatkan bila sudah ada pegawai/anggota dengan nama yang sama, tetapi admin tetap boleh melanjutkan. Bila orang baru dan orang lama **sama-sama ber-NIK** (pasti berbeda), peringatan hanya muncul bila tanggal lahirnya juga sama — kemungkinan NIK salah ketik [OWNER 2026-10-23; rincian ASUMSI].

Rincian tambah pegawai (HR-1b) [ASUMSI]:

- Peringatan orang ganda hanya mencari di anggota **yayasan yang sama**, agar data yayasan lain tidak bocor. [OWNER, 2026-10-17] Pemicunya **nama sama** (tanpa beda huruf besar/kecil & spasi ganda), dengan atau tanpa tanggal lahir — sama dengan impor (§6.2). Pesan menyebut tanggal lahir orang lama dan apakah tanggal lahirnya juga sama. Admin melanjutkan dengan mencentang "ini orang yang berbeda".
- Mode **Anggota yayasan**: nama tidak diubah dari form ini; data pribadi yang diisi melengkapi/memperbarui Person, yang dikosongkan tidak mengubah data lama. Jenis kelamin wajib bila Person belum punya.
- "Tidak boleh di masa depan" memakai tanggal menurut zona waktu yayasan (`EDUCORE_TIMEZONE`, default Asia/Jakarta); jam server tetap UTC.
- Sampai halaman Daftar pegawai ada (HR-1c), tombol **Tambah pegawai** ditaruh di halaman Katalog SDM; setelah simpan, form kosong kembali dengan notifikasi berhasil.

### 6.2 Impor pegawai dari Excel/CSV (HR-1e) [OWNER, 2026-10-14]

Untuk memasukkan data pegawai lama sekaligus. **Hanya membuat pegawai baru** (orang baru + keanggotaan + pegawai + periode pertama + penempatan utama), persis seperti form Tambah pegawai mode orang baru (§6, §4.4 invarian 4). Tidak mengubah pegawai yang sudah ada dan tidak membuat akun login.

**Siapa:** Admin SDM / Admin Yayasan di lembaga kerja Seluruh Yayasan (`hr.employees.manage`, OD-HR-02). Selain itu → 403.

**Alur** (halaman `/pegawai/impor`, tombol **Impor** di daftar pegawai):

1. **Unduh template** `.xlsx`. Lembar _Pegawai_ berisi judul kolom; kolom kode, NUPTK, HP, dan nomor induk berformat teks agar nol di depan tidak hilang. Lembar _Petunjuk_ berisi arti kolom dan daftar kode yang sah di yayasan ini (jenis pegawai, status kepegawaian, jabatan, lembaga aktif, pendidikan).
2. **Unggah** `.xlsx` atau `.csv` (pemisah `,` atau `;`, UTF-8). Maks. **1.000 baris** dan **2 MB** per file [ASUMSI].
3. **Pratinjau**: setiap baris diberi tanda **Siap**, **Peringatan**, atau **Salah** dengan pesan per kolom, plus ringkasan jumlahnya. Tidak ada yang disimpan pada langkah ini.
4. **Simpan**: hanya bisa bila **tidak ada baris Salah** — semua baris disimpan dalam **satu transaksi**; bila satu gagal, tidak ada yang tersimpan [OWNER, OD-HR-05]. Bila ada Peringatan, admin wajib mencentang "Saya sudah memeriksa; semua orang ini berbeda" [OWNER, OD-HR-05].

**Kolom template** (`*` wajib; aturan isi sama dengan form, §4.3–§4.5):

| Kolom                                      | Isi                                                                                                                                                                                                        |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| nama*                                      | Maks. 200 karakter                                                                                                                                                                                         |
| nik*                                       | 16 angka sesuai KTP/KK, format sel Teks; unik (§4.5) [OWNER, 2026-10-23]                                                                                                                                   |
| jenis_kelamin*                             | `L` / `P`                                                                                                                                                                                                  |
| tempat_lahir, tanggal_lahir, no_hp, alamat | Opsional (data pribadi §4.5)                                                                                                                                                                               |
| nomor_induk, nuptk                         | Opsional; unik per yayasan                                                                                                                                                                                 |
| pendidikan, jurusan                        | Opsional; pendidikan memakai kode §4.3 (`SD` … `S3`, `LAINNYA`)                                                                                                                                            |
| jenis_pegawai* , status_kepegawaian        | **Kode** katalog yang aktif (mis. `TETAP`, `GTY`); status boleh kosong                                                                                                                                     |
| tanggal_mulai*                             | Tidak di masa depan                                                                                                                                                                                        |
| kode_lembaga*, jabatan*                    | **Kode** lembaga aktif dan kode jabatan aktif → penempatan utama                                                                                                                                           |
| lembaga_tambahan                           | Opsional. Kode lembaga lain dipisah koma/titik koma (mis. `U1-MDA, U1-QURAN`) → penempatan **tambahan** (bukan utama) dengan jabatan & tanggal mulai yang sama; maks. 10 [OWNER, 2026-10-23; batas ASUMSI] |

**Aturan pratinjau** [ASUMSI]:

- Tanggal diterima sebagai sel tanggal Excel, `YYYY-MM-DD`, atau `DD/MM/YYYY`. Kode tidak peka huruf besar/kecil. Spasi di tepi dibuang; baris yang seluruhnya kosong dilewati.
- **Salah**: kolom wajib kosong, format/kode tidak sah, nomor induk/NUPTK sudah dipakai pegawai lain **atau dobel di dalam file**, judul kolom wajib tidak ditemukan. Ditambah [2026-10-23]: NIK kosong/bukan 16 angka/tersimpan sebagai angka Excel/dobel di file/sudah terdaftar; `lembaga_tambahan` berisi kode yang tidak ada, nonaktif, ditulis dua kali, atau sama dengan `kode_lembaga`.
- **Peringatan**: nama + tanggal lahir sama dengan anggota yayasan ini atau dengan baris lain di file (aturan orang ganda §6). Ditambah [OWNER, 2026-10-17]: **nama saja** sama (tanpa beda huruf besar/kecil & spasi ganda), dengan tanggal lahir orang lama ditampilkan — menangkap tanggal yang terbaca beda format di Excel, data lama tanpa tanggal lahir, dan file yang sama diunggah dua kali. Bila kedua orang ber-NIK, hanya diperingatkan bila tanggal lahir juga sama (§6) [2026-10-23]. NIK yang tidak cocok dengan tanggal lahir/jenis kelamin juga menjadi Peringatan.
- Saat **Simpan**, file dibaca dan diperiksa **ulang** (data bisa berubah sejak pratinjau).
- File unggahan disimpan sementara di penyimpanan privat server hanya sampai disimpan/dibatalkan, dan dihapus otomatis bila tertinggal lebih dari 24 jam. Isinya tidak masuk log aplikasi maupun audit log.

**Audit**: tiap pegawai tercatat `hr.employee_created` seperti biasa, ditambah satu catatan `hr.employees_imported` berisi jumlah baris (tanpa nama file dan tanpa data pribadi).

**Simpan (HR-1e-b)**: setelah berhasil, file sementara dihapus dan admin diarahkan ke daftar pegawai dengan notifikasi jumlah pegawai tersimpan. Bila gagal, pesan menyebut nomor baris dan alasannya; file tetap ada sehingga admin bisa memperbaiki lalu unggah ulang. Proses simpan diberi batas waktu 5 menit (bawaan PHP 30 detik) agar 1.000 baris sempat tersimpan [ASUMSI].

**Teknis** [disetujui owner 2026-10-14]: library `openspout/openspout` ~5.3 (membaca/menulis xlsx secara streaming; butuh ekstensi PHP `zip`, `xmlreader`, `dom`). [ASUMSI] NUPTK dan nomor HP yang tersimpan sebagai **angka** di Excel ditolak, karena 0 di depan hilang atau digit ke-16 bisa berubah; nomor induk berupa angka bulat tetap diterima. File sementara di `storage/app/private/hr-imports`, disapu `hr:prune-imports` tiap jam.

### 6.1 Aksi di detail pegawai (HR-1d)

Hanya Admin SDM / Admin Yayasan di tingkat Yayasan (OD-HR-02). Tanpa izin → 403; pegawai/penempatan di luar yayasan → 404.

| Aksi                     | Aturan                                                                                                                                                                                   |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Ubah profil              | Isian lengkap (kolom kosong = data dihapus), kecuali NIK yang sudah tercatat (tidak bisa dikosongkan, §4.5). Nama boleh diubah dan ikut berubah di akun login [OWNER, 2026-10-13]        |
| Ganti status kepegawaian | Pegawai aktif; tanggal berlaku sesudah awal periode berjalan; periode lama ditutup sehari sebelumnya dengan alasan `PERUBAHAN_STATUS`; jenis & status sama ditolak                       |
| Tambah penempatan        | Pegawai aktif; tidak sebelum awal periode berjalan; jabatan sama di lembaga sama tidak dobel; jadi utama bila diminta atau belum ada utama                                               |
| Akhiri penempatan        | Tidak sebelum awal penempatan; bila penempatan utama dan masih ada penempatan lain, wajib memilih pengganti utama                                                                        |
| Mutasi                   | Penempatan lama ditutup sehari sebelum tanggal mutasi, penempatan baru dibuka pada tanggal mutasi; status utama ikut pindah                                                              |
| Jadikan utama            | Memindah tanda utama; tetap maksimal satu                                                                                                                                                |
| Berhentikan              | Alasan (bukan `PERUBAHAN_STATUS`) + catatan opsional; tanggal tidak sebelum awal periode/penempatan terakhir; periode & semua penempatan ditutup; akun login & keanggotaan tidak berubah |
| Aktifkan kembali         | Pegawai berhenti; tanggal mulai sesudah tanggal berhenti terakhir; **wajib** penempatan baru (utama) [OWNER, 2026-10-13]                                                                 |

Semua tanggal boleh di masa lalu (merapikan data lama), tidak boleh di masa depan karena belum ada penjadwal otomatis [OWNER, 2026-10-13].

## 7. Audit (memakai `AuditLogger` Core)

Dicatat: pegawai dibuat, profil diubah (field apa saja, **tanpa nilai data pribadi**), periode dibuka/ditutup, penempatan dibuat/diakhiri/jadi utama, berhenti, aktif kembali, katalog dibuat/diubah/nonaktif. Semua tampil di halaman Log audit dengan label Bahasa Indonesia. [LAMA BR-019, FR-050]

## 8. Kebutuhan non-fungsional

| Area         | Target                                                                                                                                                             |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Volume       | Ratusan s.d. beberapa ribu pegawai per yayasan; daftar ≤ 500 ms p95 dengan index `(tenant_id, status)` dan index penempatan `(organization_id, end_date)` [ASUMSI] |
| Paginasi     | 25 default, maks. 100 (PRD-000 §9)                                                                                                                                 |
| Data pribadi | Tidak masuk log aplikasi, audit log, maupun pesan error [LAMA HR-014]                                                                                              |
| Batas modul  | HR hanya memakai Core; Academic kelak membaca pegawai lewat kontrak publik HR (arch test sudah ada)                                                                |

## 9. Uji wajib HR-1

| Skenario                                                        | Hasil                                                                        |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| Admin SDM menambah pegawai baru dengan penempatan di MTs Unit 1 | Person, Membership, Pegawai, periode, penempatan tercipta; tercatat di audit |
| Penempatan pertama gagal (mis. lembaga nonaktif)                | Tidak ada yang tercipta sama sekali                                          |
| Kepala Unit 1 membuka daftar pegawai                            | Hanya pegawai yang ditempatkan di Unit 1 dan lembaga di dalamnya             |
| Kepala Unit 1 membuka detail pegawai Unit 2                     | 404                                                                          |
| Koordinator MDA membuka pegawai MDA Unit 3 / Bahasa Unit 1      | Boleh / 404                                                                  |
| Guru MTs Unit 1 + Ustadz MDA Unit 2                             | Terlihat oleh Kepala Unit 1 **dan** Kepala Unit 2                            |
| Pegawai dimutasi dari Unit 1 ke Unit 2                          | Kepala Unit 1 tidak lagi melihatnya; riwayat tetap ada                       |
| Pegawai berhenti                                                | Periode & semua penempatan tertutup; akun login dan membership tidak berubah |
| Dua periode terbuka / tumpang tindih / tanggal masa depan       | Ditolak dengan pesan ramah; database menolak periode terbuka ganda           |
| Ganti Kontrak → Tetap                                           | Periode lama tertutup sehari sebelumnya, periode baru terbuka                |
| Pegawai yang sama dibuat dua kali untuk satu membership         | Ditolak                                                                      |
| Jabatan "Kepala Sekolah" dipasang                               | Tidak menambah permission apa pun                                            |
| Data pribadi                                                    | Tidak tampil tanpa `hr.employees.view_personal`; tidak ada di audit log      |
| Yayasan lain                                                    | 404                                                                          |

## 10. Di luar HR-1

Dokumen/SK, cuti, presensi, gaji, kinerja, rekrutmen, ekspor, impor untuk **mengubah** pegawai yang sudah ada, dan layanan mandiri. (Akun login sudah tersedia lewat F5 Kelola akses; impor pegawai baru lewat HR-1e §6.2.) Semuanya di tahap berikutnya (§3).

## 11. Open decisions

| ID       | Topik                                                                                                                   | Status                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| -------- | ----------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OD-HR-01 | Data pribadi di HR-1                                                                                                    | **DIPUTUSKAN** — minimal: tempat & tanggal lahir, no. HP, alamat, pendidikan terakhir, NUPTK [OWNER, 2026-10-13]. **Direvisi 2026-10-23:** NIK disimpan terenkripsi sebagai penanda unik orang, wajib untuk pegawai baru (§4.5)                                                                                                                                                                                                               |
| OD-HR-02 | Hak menambah/mengubah pegawai                                                                                           | **DIPUTUSKAN** — hanya Admin SDM pusat (dan Admin Yayasan); Kepala Lembaga hanya melihat [OWNER, 2026-10-13]                                                                                                                                                                                                                                                                                                                                  |
| OD-HR-03 | Akun login & role pegawai                                                                                               | **DIPUTUSKAN** — halaman kelola akses (Core) dibuat sesudah HR-1, sebelum HR-3 Cuti; HR-1 tidak membuat akun [OWNER, 2026-10-13]                                                                                                                                                                                                                                                                                                              |
| OD-HR-04 | Penyederhanaan model data (§4.2), role Admin SDM & hak Admin Yayasan (§5.2), aturan penyaringan pegawai berhenti (§5.3) | **ASUMSI** — dianggap disetujui bila owner tidak keberatan                                                                                                                                                                                                                                                                                                                                                                                    |
| OD-HR-05 | Impor pegawai (HR-1e)                                                                                                   | **DIPUTUSKAN** — file Excel (.xlsx) + CSV dengan template; simpan hanya bila semua baris benar (semua atau tidak); orang ganda = peringatan + konfirmasi; impor hanya membuat pegawai baru [OWNER, 2026-10-14]                                                                                                                                                                                                                                |
| OD-HR-06 | Alur persetujuan cuti                                                                                                   | **DIPUTUSKAN** — bisa diatur per jenjang/lembaga lewat aturan berjenjang: 1 tahap (Kepala Lembaga) atau 2 tahap (Kepala Lembaga lalu Admin SDM); bawaan 2 tahap [OWNER, 2026-10-22]                                                                                                                                                                                                                                                           |
| OD-HR-07 | Cara menghitung jumlah hari cuti                                                                                        | **DIPUTUSKAN** — hari kerja: rentang tanggal dikurangi libur mingguan dan daftar hari libur (tanggal merah) di kalender kerja HR, terpisah dari kalender akademik [OWNER, 2026-10-22] Ditegaskan ulang [OWNER, 2026-10-23]: tetap terpisah; Pengaturan cuti & presensi menampilkan libur mingguan kalender akademik tiap lembaga sebagai pembanding dan menandai yang berbeda (kontrak `SchoolWeeklyOff`). Tanggal merah juga tetap terpisah. |
| OD-HR-08 | Sisa jatah cuti tahunan                                                                                                 | **DIPUTUSKAN** — hangus di akhir tahun kalender; Admin SDM bisa menambah lewat penyesuaian [OWNER, 2026-10-22]                                                                                                                                                                                                                                                                                                                                |
| OD-HR-11 | Dasar honor mengajar (HR-5)                                                                                             | **DIPUTUSKAN** — honor per JP: **jam mati** = JP per minggu dari jadwal × tarif (tidak dikali jumlah minggu) [OWNER, 2026-10-25/27], dan juga **jam hidup** = JP terlaksana selama sebulan penuh × tarif [OWNER, 2026-10-27]; JP = durasi ÷ menit per JP; tarif per JP bisa sama se-yayasan atau berbeda per lembaga; penerima = GTT/honorer; potongan presensi dihitung rupiahnya                                                            |
| OD-HR-12 | Gaji menurut jabatan, menu & slip (HR-5)                                                                                | **DIPUTUSKAN** — tunjangan/gaji boleh ditentukan **jabatan** (jabatan selain mengajar; mengajar lewat tarif JP); setup gaji pegawai di **menu tersendiri**, bukan di detail pegawai; **slip gaji** menampilkan jabatan + nominal, dilihat Admin SDM & bagian terkait, pegawai hanya slip miliknya [OWNER, 2026-10-27]                                                                                                                         |
| OD-HR-10 | Penyimpanan file dokumen HR                                                                                             | **DIPUTUSKAN** — disk privat server (`storage/app/private`), unduh hanya lewat aplikasi setelah cek izin; wajib ikut backup [OWNER, 2026-10-24]                                                                                                                                                                                                                                                                                               |
| OD-HR-09 | Presensi pegawai: pencatat, rincian, jam kerja guru                                                                     | **DIPUTUSKAN** — pegawai absen sendiri **dan** petugas lembaga mengisi/mengoreksi; status + jam masuk/pulang; jam kerja guru dari jadwal mengajar, staf dari jam kerja per jenjang/lembaga; impor mesin absen menyusul [OWNER, 2026-10-22]. Presensi mengajar per jadwal & guru pengganti → Akademik AC-6 [OWNER, 2026-10-23]                                                                                                                 |

## 12. Saran tambahan (di luar ruang lingkup)

- **Ekspor daftar pegawai** (CSV) dengan saringan dan izin yang sama seperti halaman daftar [LAMA NFR-009].

## 13. HR-3 Cuti & Izin + layanan mandiri [OWNER, 2026-10-22 — 3 keputusan + usulan rincian disetujui]

Desain lama HR-004 (Leave & Permit) **disederhanakan** untuk tim kecil (ADR-001): tanpa kebijakan berversi, ledger saldo terpisah, alokasi lintas periode, satuan jam, maupun carry-over. Satuan = **hari penuh**. Lampiran (surat dokter dll.) menunggu keputusan penyimpanan file di HR-2.

### 13.1 Dasar

- Pengaju = pegawai **AKTIF** yang akunnya tertaut ke data pegawai (Kelola akses F5), lewat menu baru **Cuti saya** (layanan mandiri, tanpa permission; dasarnya tautan akun ↔ pegawai). Admin SDM juga bisa mencatat cuti **atas nama** pegawai (mis. belum punya akun).
- **Penempatan utama** pegawai menentukan lembaga pengajuan: penyetuju tahap 1 dan kalender kerja yang dipakai. Pegawai tanpa penempatan utama terbuka tidak bisa mengajukan [ASUMSI].
- Permission baru [ASUMSI]: `hr.leave.approve` (menyetujui tahap lembaga; Kepala Lembaga, Pimpinan; di cakupannya) dan `hr.leave.manage` (jenis & jatah, kalender kerja, tahap SDM, catat atas nama, batalkan, rekap; Admin SDM & Admin Yayasan, tingkat Yayasan).
- Alasan cuti = data pribadi: dilihat pegawai itu, penyetujunya, dan Admin SDM; **isinya tidak masuk audit**.

### 13.2 HR-3a Jenis cuti, jatah, kalender kerja

- **`hr_leave_types`** (katalog per yayasan, Admin SDM): kode (aturan sama dengan katalog HR-1), nama, **berjatah** (ya/tidak), **jatah per tahun** (hari, wajib bila berjatah), **batas hari per pengajuan** (opsional), aktif/nonaktif (tidak dihapus bila sudah dipakai). Isi awal [ASUMSI]: Cuti tahunan (berjatah 12), Sakit, Izin keperluan pribadi, Cuti melahirkan (maks. 90 hari per pengajuan).
- **Saldo** per pegawai × jenis berjatah × **tahun kalender** = jatah tahun itu + penyesuaian − cuti yang **disetujui** yang jatuh di tahun itu. Sisa **hangus** di akhir tahun [OWNER, OD-HR-08]. Pengajuan yang melewati pergantian tahun dipotong ke saldo masing-masing tahun menurut hari kerjanya [ASUMSI].
- **`hr_leave_adjustments`**: penyesuaian jatah oleh Admin SDM (pegawai, jenis, tahun, ± hari, alasan wajib). Audit tanpa isi alasan.
- **Kalender kerja HR** [OWNER, OD-HR-07; rincian ASUMSI]:
    - **libur mingguan** = aturan berjenjang `hr.weekly_off_days` per jenjang/lembaga (bawaan Ahad), diatur Admin SDM;
    - **hari libur (tanggal merah)** per yayasan (`hr_holidays`: nama, tanggal mulai–selesai), diatur Admin SDM, berlaku untuk semua lembaga.
    - **Jumlah hari cuti** = hari dalam rentang yang bukan libur mingguan lembaga penempatan utama dan bukan hari libur. Rentang yang hasilnya 0 hari ditolak.
    - **Pembanding kalender akademik** [OWNER, 2026-10-23]: daftar "libur mingguan yang berlaku di tiap lembaga" juga menampilkan libur mingguan kalender akademik lembaga itu; yang berbeda diberi tanda ⚠ dan dihitung di atas daftar. Tidak ada sinkron otomatis. Halaman Kalender akademik memberi keterangan bahwa libur mingguannya hanya untuk hari sekolah.
- Halaman: **Pengaturan cuti & presensi** (`/pegawai/cuti/pengaturan`, menu tingkat Yayasan bagi pemilik `hr.leave.manage`): jenis cuti, libur mingguan, hari libur per tahun.
- Rincian teknis HR-3a [ASUMSI]: sifat berjatah tidak bisa diubah setelah jenis dibuat (jatah & batas hari bisa); satu hari libur paling lama 366 hari, boleh tumpang tindih; libur mingguan 0–6 hari. **Penyesuaian jatah** (`hr_leave_adjustments`) dikerjakan bersama tampilan saldo di HR-3d, karena saldo baru bermakna setelah ada pengajuan (HR-3b).
- Deploy: jalankan `php artisan educore:sync-access` (2 permission baru) dan seeder `HrLeaveTypeSeeder` (isi awal jenis cuti, aman diulang).

### 13.3 HR-3b Pengajuan (Cuti saya)

- Pegawai melihat **saldo tahun berjalan** per jenis berjatah dan **riwayat** pengajuannya.
- Ajukan: jenis (aktif), tanggal mulai–selesai (boleh di masa depan; boleh mundur maks. 30 hari untuk sakit/izin mendadak [ASUMSI]), alasan (wajib, maks. 500). Jumlah hari tampil otomatis sebelum dikirim.
- Ditolak bila: melebihi batas hari per pengajuan; saldo tidak cukup (dihitung dari saldo dikurangi pengajuan lain yang masih menunggu [ASUMSI]); bertumpang tanggal dengan pengajuan lain yang masih menunggu atau sudah disetujui.
- Status: **Menunggu** (tahap 1 / tahap 2) → **Disetujui** / **Ditolak**; pengaju bisa **Batalkan** selama masih menunggu.
- Rincian teknis HR-3b [ASUMSI]: halaman `/cuti-saya` (menu **Cuti saya** muncul bila akun tertaut ke data pegawai); jumlah hari kerja tampil sebelum dikirim (dihitung server); status disimpan `MENUNGGU`, `DISETUJUI`, `DITOLAK`, `DITARIK` (ditarik pengaju), `DIBATALKAN` (oleh Admin SDM, HR-3d); jumlah tahap diambil dari aturan `hr.leave_approval` (bawaan 2) saat diajukan; rentang paling lama 366 hari; riwayat menampilkan 100 pengajuan terbaru. Audit `hr.leave_requested` (jenis, tanggal, hari, lembaga, jumlah tahap; tanpa alasan) dan `hr.leave_withdrawn`.

### 13.4 HR-3c Persetujuan berjenjang

- Aturan berjenjang **`hr.leave_approval`** per jenjang/lembaga [OWNER, OD-HR-06]: `1` = Kepala Lembaga saja, `2` = Kepala Lembaga lalu Admin SDM; bawaan **2**. Jumlah tahap dicatat di pengajuan saat diajukan (perubahan aturan tidak mengubah pengajuan yang sudah ada) [ASUMSI].
- **Tahap 1 (lembaga)**: pemilik `hr.leave.approve` yang cakupannya memuat lembaga penempatan utama pengaju. **Tahap 2 (SDM)**: pemilik `hr.leave.manage`.
- Kotak masuk **Persetujuan cuti** menampilkan pengajuan yang menunggu keputusan pengguna itu; Setujui / Tolak (catatan opsional, wajib saat menolak, maks. 300).
- **Tidak boleh menyetujui cuti sendiri** (BR-017): bila tidak ada penyetuju tahap 1 selain pengaju (mis. pengaju Kepala Lembaga), tahap 1 dilewati dan pengajuan langsung ke tahap SDM; pada alur 1 tahap, ke Admin SDM [ASUMSI].
- Hak diperiksa saat memutuskan (bukan saat diajukan). Saldo & tumpang tindih **dicek ulang** pada persetujuan terakhir dalam transaksi yang mengunci pegawai.
- Tabel **`hr_leave_requests`** (pegawai, jenis, tanggal, jumlah hari, alasan, status, jumlah tahap, tahap berjalan, dicatat oleh) dan **`hr_leave_decisions`** (pengajuan, tahap, keputusan, oleh, catatan, waktu).
- Rincian teknis HR-3c [ASUMSI]:
    - Halaman `/persetujuan-cuti` (menu **Persetujuan cuti** bagi pemilik `hr.leave.approve`, atau `hr.leave.manage` di tingkat Yayasan); terlama di atas, maks. 200 pengajuan; cuti milik sendiri tidak tampil. Tiap baris menampilkan sisa jatah tahun itu dan pengajuan lain yang masih menunggu.
    - Pengajuan di luar cakupan → 404; tanpa izin → 403. Admin SDM/Admin Yayasan juga boleh memutuskan tahap lembaga karena `hr.leave.approve` mereka berlaku di semua lembaga.
    - Tahap lembaga dilewati bila pengaju sendiri berhak `hr.leave.approve` di lembaga pengajuan (dicek dari lembaga kerja aktif saat mengajukan); dicatat sebagai keputusan `DILEWATI` tanpa pemutus, dan pengajuan menjadi 2 tahap meski aturannya 1 tahap.
    - Keputusan disimpan `SETUJU`, `TOLAK`, `DILEWATI`. Alasan penolakan tampil di Cuti saya. Audit menyimpan tanggal, hari, tahap (lembaga/SDM), final atau tidak, dan apakah catatan diisi; isi catatan tidak disimpan di audit.
    - Pengaturan cuti mendapat bagian **Alur persetujuan** (per jenjang atau lembaga/unit, bisa dikunci), sama seperti libur mingguan.

### 13.5 HR-3d Kelola cuti (Admin SDM)

- Daftar semua pengajuan dengan saringan lembaga, jenis, status, bulan; detail pengajuan beserta riwayat keputusan.
- **Catat atas nama** pegawai: langsung disetujui (dicatat sebagai keputusan Admin SDM), aturan saldo & tumpang tindih sama.
- **Batalkan** cuti yang sudah disetujui (alasan wajib): saldo kembali.
- Saldo per pegawai per tahun (jatah, penyesuaian, terpakai, sisa); **unduh rekap .xlsx** per bulan/tahun.
- Detail pegawai (HR-1) menampilkan ringkasan cuti tahun berjalan bagi yang boleh melihat data pribadinya [ASUMSI].
- Rincian teknis HR-3d [ASUMSI]:
    - Dikerjakan dua bagian: **3d-a** daftar, detail, catat atas nama, batalkan; **3d-b** penyesuaian jatah, tabel saldo, rekap .xlsx, ringkasan di detail pegawai.
    - Halaman `/pegawai/cuti` (menu **Kelola cuti**) hanya di lembaga kerja **Seluruh Yayasan** bagi pemilik `hr.leave.manage`. Saringan lembaga ikut memuat lembaga di bawahnya; bulan = cuti yang jatuh di bulan itu; 50 baris per halaman, tanggal mulai terbaru di atas.
    - Catat atas nama: pilih pegawai dengan mencari nama/nomor induk (min. 2 huruf, maks. 10 hasil; hanya pegawai aktif yang punya penempatan utama). Tanggal mulai boleh mundur paling lama **90 hari** (pengajuan sendiri 30 hari). Disimpan sebagai 1 tahap, keputusan `DICATAT` oleh Admin SDM.
    - Batalkan: hanya cuti berstatus Disetujui (termasuk yang sudah lewat); alasan wajib maks. 300, terbaca pegawai di Cuti saya; keputusan `BATAL`. Cuti yang masih menunggu diputuskan lewat Persetujuan cuti.
    - Admin SDM tidak bisa mencatat atau membatalkan cutinya sendiri (BR-017).
    - Detail menampilkan riwayat keputusan lengkap (siapa, kapan, catatan).
    - **3d-b — Penyesuaian jatah** (`hr_leave_adjustments`): +/− hari per pegawai × jenis berjatah × tahun, alasan wajib (maks. 300); hanya tahun berjalan atau tahun sebelumnya, pegawai aktif, tidak boleh membuat sisa negatif, tidak untuk diri sendiri. Tidak bisa diubah/dihapus; koreksi dengan penyesuaian baru. Pegawai melihat angka penyesuaian di Cuti saya (alasannya tidak).
    - **3d-b — Saldo cuti** (`/pegawai/cuti/saldo`): pegawai aktif, 50 per halaman, saringan tahun (5 tahun ke belakang s.d. tahun depan), lembaga (penempatan utama, termasuk bawahannya), jenis, cari nama/nomor induk; riwayat penyesuaian per pegawai.
    - **3d-b — Rekap .xlsx** [OWNER, 2026-10-22: dua lembar]: "Daftar cuti" = cuti **Disetujui** yang jatuh di periode (setahun atau satu bulan), tanpa alasan; "Saldo {tahun}" = pegawai aktif × jenis berjatah. Saringan lembaga & jenis ikut dipakai.
    - **3d-b — Detail pegawai**: bagian "Cuti {tahun}" (saldo + 5 cuti terbaru) bagi pemilik `hr.employees.view_personal` atas pegawai itu; Admin SDM mendapat tombol Catat cuti dan Saldo.

### 13.6 Audit [ASUMSI]

`hr.leave_type_created|updated|deactivated|reactivated`, `hr.leave_adjusted`, `hr.holiday_added|updated|removed`, `hr.leave_requested`, `hr.leave_withdrawn`, `hr.leave_approved` (per tahap), `hr.leave_rejected`, `hr.leave_cancelled`, `hr.leave_recorded` (atas nama). Perubahan aturan berjenjang dicatat Core. Tidak ada isi alasan/catatan di audit.

### 13.7 Di luar HR-3

Satuan jam/setengah hari, lampiran, carry-over, delegasi tugas selama cuti, guru pengganti (Academic), sinkron ke presensi pegawai (HR-4), notifikasi email/WA, potongan gaji.

## 14. HR-4 Presensi pegawai [OWNER, 2026-10-22 — OD-HR-09 + usulan rincian disetujui]

### 14.1 Aturan dasar

- **Satu catatan per pegawai per hari** (`hr_attendances`): status, jam masuk, jam pulang, terlambat (menit), pulang cepat (menit), jam wajib saat dicatat, sumber jam (mandiri/petugas), keterangan (maks. 300), pengubah terakhir. Lembaga = penempatan utama saat dicatat.
- **Status**: Hadir, Sakit, Izin, Alpa, Dinas luar. **Cuti** yang disetujui tampil otomatis dari data cuti (tidak disimpan di presensi) sehingga pembatalan cuti langsung tercermin [ASUMSI].
- **Jam wajib per hari** [OWNER]:
    - **Guru** = pegawai yang punya jadwal mengajar (AC-3) di tahun ajaran yang memuat tanggal itu: jam masuk = jam pelajaran pertama, jam pulang = jam pelajaran terakhir hari itu, gabungan semua lembaga. Hari tanpa pelajaran (termasuk libur kalender akademik) = tidak wajib hadir.
    - **Staf**: aturan berjenjang **`hr.work_hours`** (jam masuk, jam pulang, toleransi terlambat dalam menit; bawaan 07.00–14.00, toleransi 15) di lembaga penempatan utama, pada hari kerja kalender kerja HR (§13.2).
    - Toleransi terlambat guru memakai toleransi `hr.work_hours` lembaga penempatan utamanya [ASUMSI].
- **Terlambat** = jam masuk lewat dari jam wajib + toleransi (menit dihitung dari jam wajib). **Pulang cepat** = jam pulang sebelum jam wajib selesai.
- Hari wajib yang belum diisi = **"Belum diisi"**, tidak otomatis Alpa [ASUMSI].
- **Batas modul**: HR mendefinisikan kontrak `Modules\HR\Contracts\TeachingSchedule`; Academic mengisinya dari jadwal & libur sekolah. Tanpa modul Academic semua pegawai dianggap staf.

### 14.2 HR-4a Jam kerja & Presensi saya

- Pengaturan **Jam kerja** per jenjang/lembaga di halaman Pengaturan cuti & presensi (Admin SDM/Admin Yayasan), sama seperti libur mingguan.
- Halaman **Presensi saya** (`/presensi-saya`, menu muncul bila akun tertaut ke data pegawai): jam wajib hari ini, tombol **Absen masuk** / **Absen pulang** (hari ini saja, jam server), riwayat bulan berjalan.
- Rincian teknis [ASUMSI]: absen masuk sekali per hari; absen pulang sekali dan tidak sebelum jam masuk; hari tidak wajib (libur/tanpa pelajaran) tetap boleh absen tanpa hitungan terlambat; ditolak bila hari itu tercatat cuti disetujui atau sudah diisi petugas dengan status selain Hadir; hanya pegawai aktif dengan penempatan utama. Tanpa GPS/QR; jam memakai menit (detik dibuang). Audit `hr.attendance_checked_in|checked_out` (jam & terlambat).

### 14.3 HR-4b Lembar presensi pegawai (petugas)

- Lembar per lembaga & tanggal: pegawai dengan penempatan utama di lembaga itu, jam wajib, status, jam masuk/pulang, keterangan; absen mandiri ditandai; cuti tampil otomatis.
- Permission baru **`hr.attendance.manage`**: Kepala Lembaga (cakupannya), Admin SDM & Admin Yayasan (semua lembaga), dan role penugasan baru **Petugas Presensi** (dipasang per lembaga lewat Kelola akses) [ASUMSI].
- Batas koreksi: petugas lembaga 7 hari ke belakang; Admin SDM/Admin Yayasan bebas; tidak di masa depan [ASUMSI]. Audit perubahan tanpa isi keterangan.
- Rincian teknis HR-4b [ASUMSI, 2026-10-24]:
    - Halaman **Presensi pegawai** (`/pegawai/presensi?lembaga=&tanggal=`, menu bagi pemilik `hr.attendance.manage`). Pilihan lembaga = node aktif di cakupan izin (termasuk Biro/Unit tempat staf ditempatkan). Pegawai di lembar = penempatan **utama** di lembaga itu pada tanggal itu, urut nama.
    - "Petugas lembaga 7 hari" = hari ini s.d. 6 hari sebelumnya; "bebas" = pemegang izin dari role tingkat Yayasan di lembaga kerja Seluruh Yayasan.
    - Setiap baris: jam wajib (§14.1), status (kosong = Belum diisi), jam masuk/pulang (hanya Hadir & Dinas luar; jam pulang tidak sebelum jam masuk, wajib ada jam masuk), keterangan maks. 300. Terlambat & pulang cepat dihitung untuk **Hadir** saja. Mengosongkan status baris yang sudah terisi **menghapus** catatannya.
    - Absen mandiri tampil bertanda; jam yang tidak diubah petugas tetap bersumber MANDIRI, yang diisi/diubah petugas bersumber PETUGAS. Pengubah terakhir tampil.
    - Pegawai yang cuti disetujui pada tanggal itu tampil "Cuti (jenis)" dan tidak bisa diisi dari lembar.
    - Simpan seluruh lembar sekaligus; hanya baris yang berubah ditulis. Audit `hr.attendance_recorded` per baris (tanggal, status, jam, terlambat, koreksi ya/tidak) tanpa isi keterangan.
    - Role penugasan **Petugas Presensi** (`petugas-presensi`) hanya berisi `hr.attendance.manage`; dipasang per lembaga di Kelola akses → `php artisan educore:sync-access`.

### 14.4 HR-4c Rekap

- Rekap bulanan per lembaga: hari wajib, hadir, terlambat (kali & menit), pulang cepat, sakit, izin, dinas luar, cuti, alpa, belum diisi; unduh .xlsx; ringkasan di Presensi saya & detail pegawai (bagi yang boleh melihat data pribadi).
- Rincian HR-4c [ASUMSI, 2026-10-24]:
    - Halaman **Rekap presensi pegawai** (`/pegawai/presensi/rekap?lembaga=&bulan=`, tombol **Rekap bulanan** di Presensi pegawai), pemilik `hr.attendance.manage` di lembaga itu. Bulan yang akan datang → bulan berjalan; bulan berjalan dihitung sampai hari ini.
    - Pegawai = penempatan **utama** di lembaga itu pada sebagian bulan; hanya hari-hari selama penempatan itu yang dihitung (mis. pegawai baru mulai tanggal 15).
    - **Hari wajib** = hari dengan jam wajib (§14.1). **Cuti** dihitung di hari wajib (catatan presensinya, bila ada, diabaikan). Hari wajib tanpa catatan & bukan cuti = **Belum diisi**. Catatan di hari tidak wajib tetap dihitung statusnya (mis. Hadir di hari libur), sehingga Hadir bisa melebihi hari wajib.
    - Terlambat & pulang cepat: jumlah kali dan total menit (tampil "2× (35 mnt)").
    - Unduh `.xlsx`: lembar **Rekap** (per pegawai + Jumlah) dan **Rincian** (per pegawai per hari wajib/tercatat: jam wajib, status, jam masuk/pulang, terlambat, pulang cepat; **tanpa keterangan**).
    - Ringkasan bulan berjalan di **Presensi saya** dan di **detail pegawai** (bagi pemilik izin lihat data pribadi), dihitung di penempatan utama pegawai saat ini.

### 14.5 Di luar HR-4

Impor/sinkron mesin absen (fingerprint) — menunggu contoh file; GPS/QR/geofencing; lembur & potongan gaji (HR-5). **Presensi mengajar per jadwal di tiap lembaga, jadwal piket, dan guru pengganti** dikerjakan di modul Akademik (PRD-002 §20, AC-6) [OWNER, 2026-10-23]; HR-4 tetap berisi kehadiran kerja harian.

## 15. HR-2 Dokumen & SK [OWNER, 2026-10-24 — OD-HR-10 + usulan rincian disetujui]

Desain lama HR-008 **disederhanakan** (ADR-001): tanpa versi file, status DRAFT/SUPERSEDED, e-signature, maupun pemindai virus. Satu dokumen = satu file.

### 15.1 HR-2a Jenis dokumen & dokumen pegawai

- **Jenis dokumen**: tab **Jenis dokumen** di Katalog SDM (`hr.catalog.manage`, aturan kode sama dengan katalog lain) + pilihan **punya masa berlaku**. Isi awal [ASUMSI]: SK Pengangkatan, SK Penempatan/Mutasi, SK Jabatan, Kontrak Kerja (punya masa berlaku), Ijazah, Sertifikat Pendidik, Dokumen lainnya. KTP tidak dimasukkan (data pribadi minimal, OD-HR-01).
- **Dokumen**: jenis, nomor (opsional, maks. 100), judul (wajib, maks. 150), tanggal terbit (tidak di masa depan), berlaku mulai, berlaku sampai (wajib bila jenisnya punya masa berlaku; tidak sebelum berlaku mulai), catatan (maks. 500), **satu file PDF/JPG/PNG maks. 5 MB**.
- **File tidak bisa diganti.** Salah file → **Batalkan** (alasan wajib, maks. 300) lalu unggah dokumen baru. Dokumen batal tidak bisa diubah, tetap tersimpan beserta file-nya dan tampil lewat "Tampilkan dokumen yang dibatalkan" (§4.4: tidak ada hapus permanen).
- **Hak akses**: tambah/ubah isian/batalkan hanya Admin SDM & Admin Yayasan (`hr.employees.manage` tingkat Yayasan, OD-HR-02); daftar & unduh bagi pemilik `hr.employees.view_personal` di cakupan pegawai itu (Pimpinan, Kepala Lembaga). Melihat pegawai tanpa data pribadi → 403; di luar cakupan → 404. Tidak ada permission baru.
- **Penyimpanan** (OD-HR-10): disk `local` → `storage/app/private/tenants/{yayasan}/hr/documents/{id}.{pdf|jpg|png}`; jenis file dibaca dari isi file (bukan ekstensi); checksum SHA-256 disimpan; unduhan lewat controller (`Content-Disposition: attachment`, `nosniff`, `no-store`).
- **Tampilan**: bagian **Dokumen & SK** di detail pegawai (bagi yang boleh melihat data pribadi), urut tanggal terbit/berlaku mulai terbaru; tanda **Masa berlaku habis** / **Segera habis** (≤ 60 hari).
- **Audit**: `hr.document_added` (jenis, ukuran, checksum), `hr.document_updated` (nama field saja), `hr.document_voided`, `hr.document_downloaded`; `hr.catalog_updated` untuk perubahan pilihan masa berlaku. Nomor, judul, catatan, dan isi file tidak dicatat.
- Rincian teknis HR-2a [ASUMSI]: route `POST pegawai/{id}/dokumen`, `PATCH …/dokumen/{doc}`, `POST …/dokumen/{doc}/batalkan`, `GET …/dokumen/{doc}/unduh`; jenis yang sudah nonaktif tetap boleh dipertahankan dokumen lama, tetapi tidak bisa dipilih untuk dokumen baru; mengubah pilihan masa berlaku tidak mengubah dokumen lama.

### 15.2 HR-2b Dokumen saya & pengingat masa berlaku

- **Dokumen saya**: pegawai (akun tertaut ke data pegawai) melihat & mengunduh dokumennya sendiri yang berlaku (tanpa permission, seperti Cuti saya).
- **Dokumen akan habis**: halaman untuk Admin SDM/Admin Yayasan berisi dokumen berlaku yang sudah habis atau habis dalam ≤ 60 hari, ditambah kartu ringkas di Beranda. Belum ada email/WA (infrastruktur notifikasi belum ada). Kontrak habis **tidak** memberhentikan pegawai otomatis.
- Rincian HR-2b [ASUMSI, 2026-10-24]:
    - **Dokumen saya** (`/dokumen-saya`, menu muncul bersama Cuti saya & Presensi saya): hanya dokumen yang **berlaku** (yang dibatalkan tidak tampil); unduhan tercatat di audit `hr.document_downloaded`. Dokumen batal atau milik pegawai lain → 404.
    - **Dokumen akan habis** (`/pegawai/dokumen/akan-habis`, menu tingkat Yayasan bagi pemilik `hr.employees.manage`): dokumen berlaku milik pegawai **aktif** (pegawai berhenti tidak diingatkan), dikelompokkan **Sudah habis** dan **Habis dalam 60 hari**, urut tanggal habis; tiap baris menautkan ke detail pegawai (lembaga = penempatan utama). Perpanjangan = unggah dokumen baru lalu batalkan yang lama bila perlu.
    - **Beranda**: kartu **Dokumen akan habis** (jumlah + "x sudah habis, y habis dalam 60 hari"; merah bila ada yang habis, kuning bila hanya akan habis). Kartu didaftarkan modul HR ke registri kartu Beranda milik Core (`DashboardCards`) sehingga Core tetap tidak bergantung pada HR; Beranda tanpa kartu hanya menampilkan sambutan.

### 15.3 Di luar HR-2

Versi file, e-signature, pemindai virus, lampiran cuti (bisa memakai penyimpanan yang sama kemudian), dokumen siswa (Akademik), batas kuota penyimpanan per yayasan.

## 16. HR-5 Komponen gaji, rekap input gaji & slip [OWNER, 2026-10-25 & 2026-10-27 — OD-HR-11, OD-HR-12]

HR menyiapkan **data input gaji** yang bisa ditelusuri dan **slip gaji**; pajak/PPh 21, transfer bank, dan akuntansi tetap di Keuangan ([LAMA] OD-HR-003, FR-023–025). Pengelolaan di tingkat Yayasan dengan permission **`hr.payroll.manage`** (Admin SDM, Admin Yayasan; **bukan** Kepala Lembaga — data gaji sensitif). Audit tidak pernah mencatat nominal.

### 16.1 HR-5a Komponen gaji, tarif jabatan, menu Gaji pegawai

- **Pengaturan gaji** (`/pegawai/gaji/pengaturan`): katalog **komponen gaji** — kode, nama, **jenis** (Pendapatan/Potongan), **cara hitung**:
    - **Tetap per bulan** — nominal diisi per pegawai (mis. gaji pokok yang berbeda tiap orang, iuran BPJS);
    - **Per hari hadir** — nominal per pegawai × hari Hadir + Dinas luar di rekap presensi bulan itu;
    - **Menurut jabatan** [OWNER, OD-HR-12; HR-5a-2] — nominal dari **tarif jabatan**: per jabatan untuk **semua lembaga** atau **lembaga tertentu** (didahulukan), mis. Wakil Kepala Rp 1.000.000, Operator di MTs Unit 1 Rp 500.000. Berlaku untuk jabatan selain mengajar; honor mengajar lewat tarif JP (§16.2).
    - Jenis & cara hitung tidak bisa diubah setelah dibuat; komponen tidak dihapus, hanya dinonaktifkan. Isi awal [ASUMSI]: Gaji pokok, Tunjangan jabatan, Tunjangan wali kelas (tetap), Uang transport (per hari hadir), Iuran BPJS (potongan, tetap).
- **Rangkap jabatan** [ASUMSI]: tarif dijumlahkan untuk **setiap penempatan berjalan** (jabatan × lembaga); di slip tiap jabatan satu baris. Jabatan tanpa tarif (mis. Guru) tidak menambah apa pun. Mutasi/ganti jabatan otomatis mengubah nominal bulan berikutnya; bulan yang sudah dikunci tidak berubah.
- **Menu Gaji pegawai** (`/pegawai/gaji`) [OWNER, OD-HR-12]: daftar pegawai aktif (cari nama/nomor induk, saring lembaga, saring "belum punya komponen per pegawai"), dengan jabatan, total dari jabatan, pendapatan & potongan tetap, per hari hadir, tanda **Belum diatur**. Halaman **setup per pegawai** (`/pegawai/gaji/{id}`): rincian dari jabatan (hanya baca, beserta sumber tarifnya) + isian komponen per pegawai (rupiah bulat 0 s.d. Rp 1.000.000.000; kosong = tidak berlaku). **Detail pegawai tidak lagi memuat gaji.**
- Audit: `hr.pay_component_*`, `hr.pay_rate_set|removed` (kode komponen, jabatan, lembaga), `hr.employee_pay_updated` (kode komponen saja).
- Menyusul bila diperlukan: impor nominal massal dari .xlsx.

### 16.2 HR-5b Honor mengajar (JP) & potongan presensi

- **JP** satu jadwal = durasi ÷ **menit per JP** (per jenjang/lembaga, bawaan 45), dibulatkan ke bawah; jadwal yang bukan kelipatan diberi peringatan [ASUMSI]. Data jadwal & presensi mengajar diambil lewat kontrak di `Modules\HR\Contracts` yang diisi Akademik.
- Dua cara hitung honor [OWNER, OD-HR-11]:
    - **Jam mati** = JP per minggu dari jadwal yang berlaku × tarif per JP (tidak dikali jumlah minggu);
    - **Jam hidup** = JP pertemuan yang **terlaksana** selama sebulan penuh (Mengajar + menggantikan; Digantikan/Kosong/Belum dicatat tidak dihitung) × tarif per JP.
    - Cara hitung **dipilih per jenjang/lembaga** (bawaan jam mati), masing-masing dengan tarifnya [OWNER, 2026-10-27: opsi A].
- **Tarif per JP**: aturan berjenjang — satu tarif se-yayasan, bisa ditimpa per jenjang/lembaga (ada lembaga yang sama, ada yang berbeda). Guru di dua lembaga dihitung per lembaga.
- **Penerima honor JP**: centang "menerima honor per JP" di katalog Status kepegawaian (mis. GTT) dan Jenis pegawai (mis. Honorer).
- **Potongan presensi** (per jenjang/lembaga penempatan utama): Rp per hari Alpa, per kali Terlambat, per kali Pulang cepat. Sakit, Izin, Dinas luar, Cuti tidak dipotong; Belum diisi tidak dipotong tetapi diperingatkan.
- Rincian teknis HR-5b [ASUMSI, 2026-10-27]:
    - Aturan berjenjang `hr.teaching_pay` = {cara hitung, menit per JP 10–120, tarif Rp 0–10.000.000; bawaan jam mati, 45, Rp 0} dan `hr.attendance_deduction` = {alpa, terlambat, pulang cepat; bawaan 0}. Diatur di **Pengaturan gaji** dengan target **Seluruh yayasan**, satu jenjang, atau lembaga/unit (bisa dikunci untuk tingkat bawah). Menyimpan aturan berjenjang memakai izin Core `core.settings.manage` (PRD-000 §8.3) — saat ini dimiliki Admin Yayasan, belum Admin SDM.
    - Penerima honor JP: pegawai yang **jenis pegawai ATAU status kepegawaian** periode terakhirnya dicentang "Menerima honor per JP" (Katalog SDM; Honorer & GTT dicentang sejak awal). Pegawai yang mengajar tetapi bukan penerima tetap ditampilkan JP-nya dengan honor Rp 0.
    - Jam mati memakai jadwal yang berlaku pada akhir rentang hitung (akhir bulan, atau hari ini untuk bulan berjalan); jam hidup memakai catatan presensi mengajar di rentang itu (pertemuan Belum dicatat tidak dihitung).
    - Uang per hari hadir = nominal komponen Per hari hadir × (Hadir + Dinas luar) dari rekap presensi HR-4c.
    - Halaman setup gaji per pegawai menampilkan **Perkiraan bulan** (pilih bulan): honor JP per lembaga beserta dasar hitungnya, hari hadir, alpa/terlambat/pulang cepat, potongan, total pendapatan, total potongan, perkiraan bersih. Angka akhir dikunci di HR-5c.
    - Data jadwal & presensi mengajar dibaca lewat kontrak `Modules\HR\Contracts\TeachingLoad` (diisi `Academic\Application\Teaching\AcademicTeachingLoad`).

### 16.3 HR-5c Rekap input gaji bulanan & slip gaji

- Rekap satu baris per pegawai aktif se-yayasan (saring per lembaga): dari jabatan, komponen tetap, per hari hadir, honor JP, potongan presensi, potongan tetap, **penyesuaian manual** (nominal + keterangan), total pendapatan, total potongan, bersih.
- **Kunci bulan**: snapshot; perubahan tarif/nominal/jabatan/jadwal sesudahnya tidak mengubah bulan terkunci; buka kunci dengan alasan (audit).
- Unduh `.xlsx`: **Rekap** dan **Rincian** (dasar hitung).
- **Slip gaji** [OWNER, OD-HR-12] dari bulan terkunci: identitas, lembaga, **setiap jabatan + nominal**, komponen lain, honor JP (JP × tarif per lembaga), potongan presensi, penyesuaian, total, bersih; bisa dicetak/disimpan PDF lewat fitur cetak browser.
    - Dilihat **Admin SDM** dan **bagian terkait** — [ASUMSI] permission baru `hr.payroll.view` (lihat rekap & slip, tanpa mengubah) lewat role tingkat Yayasan baru **Staf Keuangan** yang dipasang di Kelola akses.
    - **Pegawai** hanya slip miliknya, di menu **Gaji saya**, setelah bulan dikunci.
- Di luar HR-5: pajak/PPh 21, transfer bank, THR, lembur terjadwal.
