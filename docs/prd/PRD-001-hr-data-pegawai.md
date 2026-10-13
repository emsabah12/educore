# PRD-001 — Modul HR (Kepegawaian)

- **Versi:** 0.3 (2026-10-14: rincian HR-1a — alasan `PERUBAHAN_STATUS`, format nomor induk, halaman Katalog SDM)
- **Status:** SIAP DIBANGUN — HR-1 Data Pegawai
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

| Tahap                              | Isi                                                                                                                                                                         | Catatan                                                 |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| **HR-1 Data Pegawai**              | Katalog (jenis pegawai, status kepegawaian, jabatan), profil pegawai, periode kepegawaian, penempatan + jabatan, berhenti / aktif kembali, daftar dan detail pegawai, audit | **Dirinci di PRD ini**                                  |
| HR-2 Dokumen & SK                  | Arsip SK/kontrak (file privat), masa berlaku dan pengingat                                                                                                                  | Butuh keputusan penyimpanan file                        |
| Core: Kelola akses                 | Halaman buat akun login, penugasan, pasang/cabut role                                                                                                                       | **Dibuat sesudah HR-1, sebelum HR-3** [OWNER, OD-HR-03] |
| HR-3 Cuti & Izin + layanan mandiri | Jenis cuti, jatah, pengajuan, persetujuan berjenjang (memakai aturan berjenjang PRD-000 §8)                                                                                 | Pegawai perlu akun login                                |
| HR-4 Presensi                      | Input manual/impor + rekap; mesin absen menyusul                                                                                                                            | [LAMA OD-HR-005]                                        |
| HR-5 Komponen gaji                 | Data tunjangan + rekap input gaji (ekspor); modul Keuangan belum ada                                                                                                        | [LAMA OD-HR-003]                                        |
| HR-6 dst.                          | Kinerja/PKG & PKB, rekrutmen, disiplin, ekspor Dapodik/EMIS/Simpatika                                                                                                       | [LAMA HR-001 §7]                                        |

Urutan HR-2 s.d. HR-6 bisa diubah owner sebelum tiap tahap dimulai.

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

### 4.5 Data pribadi (OD-HR-01) [OWNER, 2026-10-13]

Disimpan minimal. Pemiliknya mengikuti PRD-000 §5: data tentang **orang** di Person (dipakai juga oleh Academic kelak), data khusus **pegawai** di `hr_employees`.

| Field                                   | Tabel                 | Aturan                                                                                            |
| --------------------------------------- | --------------------- | ------------------------------------------------------------------------------------------------- |
| birth_place                             | `persons`             | Opsional, maks. 100 karakter                                                                      |
| birth_date                              | `persons`             | Opsional; tidak di masa depan                                                                     |
| phone                                   | `persons`             | Opsional; 8–19 angka, boleh diawali `+`; disimpan tanpa spasi/tanda hubung (form membersihkannya) |
| address                                 | `persons`             | Opsional, maks. 500 karakter                                                                      |
| gender                                  | `persons` (sudah ada) | **Wajib** untuk pegawai baru (`L`/`P`) [ASUMSI]                                                   |
| nuptk, education_level, education_major | `hr_employees`        | Lihat §4.3                                                                                        |

- **NIK tidak disimpan** di HR-1. `person_identifiers` terenkripsi (PRD-000 §5) dibuat saat ekspor pemerintah dibutuhkan.
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

Jawaban akses mengikuti OD-13: pegawai di luar cakupan → **404**; di dalam cakupan tanpa izin aksi → **403**.

## 6. Halaman HR-1

Menu **Pegawai** (`/pegawai`) muncul bagi pemilik `hr.employees.view`. Menu **Katalog SDM** (`/pegawai/katalog?jenis=jenis-pegawai|status-kepegawaian|jabatan`) muncul bagi pemilik `hr.catalog.manage` dan hanya bisa dibuka di lembaga kerja **Seluruh Yayasan** (selain itu 403). [ASUMSI]

| Halaman        | Isi                                                                                                                                                                                                                                          |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Daftar pegawai | Cari nama/nomor induk; filter lembaga, jenis, status kepegawaian, jabatan, aktif/berhenti (default: aktif); 25 baris per halaman; jumlah total tampil. Responsif: tabel di desktop, kartu di HP                                              |
| Tambah pegawai | Pilih **orang baru** atau **anggota yayasan yang sudah ada** (mis. pemilik akun yang belum punya profil pegawai). Lalu isi nama, L/P, data pribadi (§4.5), nomor induk, jenis, status, tanggal mulai, penempatan pertama (lembaga + jabatan) |
| Detail pegawai | Profil; periode kepegawaian (riwayat); penempatan aktif & riwayat; masa kerja (jumlah hari dari semua periode, ditampilkan sebagai tahun-bulan)                                                                                              |
| Aksi di detail | Ubah profil, Ganti status kepegawaian, Tambah penempatan, Mutasi, Akhiri penempatan, Jadikan utama, Berhentikan, Aktifkan kembali                                                                                                            |
| Katalog SDM    | Tiga tab (Jenis pegawai, Status kepegawaian, Jabatan): tambah, ubah nama, nonaktifkan / aktifkan kembali                                                                                                                                     |

Semua halaman punya kondisi kosong, loading, dan error. Pesan validasi berbahasa Indonesia.

**Mencegah orang ganda** [LAMA RISK-HR-004]: tanpa NIK, sistem tidak bisa yakin dua nama yang sama adalah satu orang. Form tambah memperingatkan bila sudah ada pegawai/anggota dengan nama + tanggal lahir yang sama, tetapi admin tetap boleh melanjutkan.

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

Akun login pegawai, dokumen/SK, cuti, presensi, gaji, kinerja, rekrutmen, ekspor, impor massal, dan layanan mandiri. Semuanya di tahap berikutnya (§3).

## 11. Open decisions

| ID       | Topik                                                                                                                   | Status                                                                                                                                                                        |
| -------- | ----------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OD-HR-01 | Data pribadi di HR-1                                                                                                    | **DIPUTUSKAN** — minimal: tempat & tanggal lahir, no. HP, alamat, pendidikan terakhir, NUPTK. **Tanpa NIK**; NIK menyusul saat ekspor Dapodik/EMIS (§4.5) [OWNER, 2026-10-13] |
| OD-HR-02 | Hak menambah/mengubah pegawai                                                                                           | **DIPUTUSKAN** — hanya Admin SDM pusat (dan Admin Yayasan); Kepala Lembaga hanya melihat [OWNER, 2026-10-13]                                                                  |
| OD-HR-03 | Akun login & role pegawai                                                                                               | **DIPUTUSKAN** — halaman kelola akses (Core) dibuat sesudah HR-1, sebelum HR-3 Cuti; HR-1 tidak membuat akun [OWNER, 2026-10-13]                                              |
| OD-HR-04 | Penyederhanaan model data (§4.2), role Admin SDM & hak Admin Yayasan (§5.2), aturan penyaringan pegawai berhenti (§5.3) | **ASUMSI** — dianggap disetujui bila owner tidak keberatan                                                                                                                    |

## 12. Saran tambahan (di luar ruang lingkup)

- **Impor pegawai dari Excel/CSV** (HR-1b): memasukkan ratusan pegawai lama satu per satu sangat lambat. Impor dengan pratinjau dan laporan baris yang gagal bisa jadi tahap kecil sesudah HR-1.
- **Ekspor daftar pegawai** (CSV) dengan saringan dan izin yang sama seperti halaman daftar [LAMA NFR-009].
