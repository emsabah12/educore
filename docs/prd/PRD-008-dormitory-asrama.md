# PRD-008 — Modul Dormitory (Asrama): Fasilitas, Penghuni, Mutasi & Wali Kamar

- **Versi:** 0.10 (2026-11-15: DM-5 dipecah; DM-5a dirinci, disetujui & dibangun, §19)
- **Versi sebelumnya:** 0.9 (DM-4 dibangun, §18.5), 0.8 (rincian DM-4 disetujui owner, §18), 0.7 (DM-3 dibangun, §17.5), 0.6 (rincian DM-3 disetujui owner, §17), 0.5 (DM-2 dibangun, §16.9), 0.4 (rincian DM-2 disetujui owner, §16), 0.3 (DM-1 dibangun, §15), 0.2 (disetujui owner, OD-DM-01 s.d. 12 sesuai usulan; rincian DM-1 §15), 0.1 (draf pertama, ditulis ulang dari Fase 1 owner + analisis lama)
- **Status:** **DISETUJUI (2026-11-15)**; DM-1 SELESAI (commit `13e6156`, uji owner lancar); DM-2 SELESAI (commit `5eb5d69`, uji owner lancar); DM-3 SELESAI (commit `7c09e0c`, uji owner lancar, CI hijau); DM-4 SELESAI (commit `8e8e3ee`, uji owner lancar, CI hijau); DM-5a DIBANGUN (§19, menunggu uji owner); DM-5b impor menyusul
- **Bergantung pada:** PRD-000 (pohon lembaga, akses, aturan berjenjang, audit), PRD-001 (pegawai aktif sebagai calon wali kamar), PRD-002 (siswa, pendaftaran, tahun ajaran, portal Anak saya), ADR-001
- **Sumber yang ditulis ulang:**
    - `FASE 1 PRODUCT & BUSINESS REQUIREMENT DORMITORY.docx` (owner, Agustus 2026) → label **[DOCX]**
    - Analisis & keputusan Fase 1.1A–1.1F di sesi ChatGPT 14 Agustus 2026, disetujui owner saat itu → label **[LAMA]**
    - `docs-legacy/architecture/dormitory.md` dan ADR-019 (repo lama; kodenya **tidak** dibawa, ADR-001 §3)

Label: **[OWNER]** = keputusan owner · **[DOCX]** = dari dokumen Fase 1 owner · **[LAMA]** = keputusan sesi Agustus yang tetap berlaku · **[ASUMSI]** = usulan baru, perlu konfirmasi.

---

## 0. Penyesuaian terhadap arsitektur sekarang

Fase 1 dan analisisnya ditulis untuk **repo lama**: Module Kernel `module.yaml`, `Organization → OrganizationUnit` 2 tingkat, outbox/event envelope, dan job antrean. ADR-001 mengganti mesin itu. Tabel ini merangkum apa yang **dipertahankan** dan apa yang **diterjemahkan**.

| Konsep lama / Fase 1                                         | Di EduCore sekarang                                                                                                                                          |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `Modules/Dormitory` + `module.yaml`                          | `modules/Dormitory` + `DormitoryServiceProvider` (didaftarkan manual), arch test batas modul                                                                 |
| Tenant → Organization → OrganizationUnit?                    | Tenant (yayasan) → **satu node pohon lembaga** pemilik asrama (PRD-000 §4). Cakupan lihat/kelola mengikuti closure table (§7)                                |
| Resident = Membership, bukan Student                         | **Tetap** [LAMA]. Penghuni = Membership; siswa (`ac_students`) dan pegawai (`hr_employees`) hanya sumber kelayakan lewat kontrak publik                      |
| `tenant_id dari user` (FR-01 docx)                           | Tenant & lembaga kerja dari **session yang divalidasi ulang tiap request** (PRD-000 §6), `BelongsToTenant`, `OwnedByOrganization`                            |
| Role "Admin Asrama" sebagai syarat keamanan                  | Permission `dormitory.*` di katalog kode + role bawaan (§7). Role hanya bundel izin [LAMA]                                                                   |
| Event `DormitoryOccupancyChanged` + outbox + idempotency key | Event Laravel biasa yang dikirim **sesudah commit** (pola `EnrollmentClosed` di Academic). Pencegahan dobel lewat kunci baris + unique index database        |
| Rollover saat Academic Year `CLOSED`                         | Academic tidak punya status CLOSED (hanya "tahun berjalan"). Diganti halaman **Proses akhir tahun asrama**, dijalankan petugas, pola AC-2 (§9)               |
| Bulk 500 baris, background job, partial success              | Pola impor EduCore: template → unggah → pratinjau Siap/Peringatan/Salah → **simpan semua atau tidak**, sinkron, maks. 1.000 baris (OD-HR-05). Lihat OD-DM-07 |
| NFR "< 5 detik", "ACID isolation level"                      | Diganti hasil yang bisa diuji: kunci baris kamar + unique index; kamar tidak pernah melebihi kapasitas walau dua petugas menyimpan bersamaan (§11)           |
| Log `[EMERGENCY BYPASS]` immutable                           | `audit_logs` append-only yang sudah ada (F4) + catatan mutasi yang tidak bisa diubah/dihapus                                                                 |

## 1. Tujuan

Asrama pesantren dikelola dengan rapi dan aman [DOCX §1]:

1. Data gedung, kamar, bed, dan loker satu sumber, per asrama milik lembaga/unit tertentu.
2. Penempatan santri dan wali kamar **tidak pernah** mencampur putra–putri dan **tidak pernah** melebihi kapasitas.
3. Perpindahan kamar (mutasi) punya dua jalur: **biasa** (diajukan, lalu disetujui Kepala Asrama) dan **darurat** (langsung dieksekusi, wajib alasan, ditandai untuk ditinjau).
4. Riwayat hunian tidak pernah hilang: siapa tinggal di kamar mana, sejak kapan, sampai kapan, dan kenapa pindah/keluar.

Prinsip (sama dengan modul lain):

1. Data per yayasan, disaring per pohon lembaga; katalog akses di kode; aksi penting tercatat di audit.
2. **Riwayat tidak dihapus.** Data yang salah dibatalkan/diakhiri dengan alasan.
3. Status hunian **dihitung dari penempatan**, bukan diketik di bed/loker [LAMA].
4. Dibangun bertahap (§12); setiap tahap bisa dipakai sendiri.

## 2. Masalah & ukuran keberhasilan [DOCX §2, disesuaikan]

| Masalah                                                                   | Ukuran keberhasilan (bisa diuji)                                                                                                                            |
| ------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Salah tempat putra/putri dan kelebihan muatan kamar karena alokasi manual | Server **dan** database menolak 100% penempatan beda jenis kelamin atau melebihi kapasitas, termasuk saat dua petugas menyimpan bersamaan                   |
| Mutasi darurat terhambat birokrasi                                        | Mutasi darurat selesai dalam **satu pop-up** (pilih santri → kamar tujuan → alasan → simpan), tanpa menunggu persetujuan                                    |
| Penyalahgunaan jalur darurat                                              | 100% mutasi darurat tercatat (pelaku, waktu, kategori, alasan) dan tampil sebagai **bendera merah** di Beranda Kepala Asrama sampai ditandai Sudah ditinjau |
| Data asrama tidak tersambung dengan modul lain                            | Data penghuni bisa dibaca modul lain lewat kontrak publik; integrasi biaya asrama diputuskan terpisah (OD-DM-10)                                            |

KPI docx "sinkron keuangan < 5 detik" ditunda sampai integrasi Finance diputuskan (OD-DM-10). [ASUMSI]

## 3. Pengguna [DOCX §3 → peran EduCore]

| Persona docx                    | Di EduCore                                                                   | Kebutuhan                                                                              |
| ------------------------------- | ---------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| Super Admin / Tenant Owner      | **Admin Yayasan**, **Pimpinan** (role tingkat Yayasan)                       | Melihat laporan hunian seluruh asrama di cakupannya                                    |
| Kepala Asrama (Mudir)           | Role penugasan baru **Kepala Asrama** [ASUMSI]                               | Melihat semua data asramanya; menyetujui/menolak mutasi biasa; meninjau mutasi darurat |
| Admin Asrama (staf operasional) | Role penugasan baru **Pengurus Asrama** [ASUMSI]                             | Kelola gedung/kamar/bed/loker, menempatkan & mengeluarkan penghuni, mutasi darurat     |
| Wali Kamar / Musyrif / Pengawas | **Tanpa role**; hak dari data penugasan wali kamar (§6), pola **Kelas saya** | Melihat kamarnya, absen asrama, mengajukan mutasi santri di kamarnya                   |
| Kepala Lembaga / Kepala Unit    | Role yang sudah ada                                                          | Melihat penghuni asrama milik lembaganya/unitnya [ASUMSI]                              |

Satu orang boleh memegang lebih dari satu peran. Pemisahan tugas tetap berlaku: **pengaju mutasi tidak bisa menyetujui pengajuannya sendiri** [LAMA 11.10].

## 4. Model fasilitas

### 4.1 Hierarki & kepemilikan [LAMA 9.1–9.3, 9.13]

```text
Node pohon lembaga (pemilik)    mis. "Pondok Pesantren" (LEMBAGA) atau "Unit 1" (UNIT)
 └── Asrama (dm_dormitories)    mis. "Asrama Putra Unit 1"
      └── Gedung (dm_buildings)  mis. "Gedung A"          ← jenis kelamin Putra/Putri
           └── Kamar (dm_rooms)  mis. "A-101"             ← mengikuti gender gedung
                ├── Bed (dm_beds)        A-101-01, A-101-02, …
                └── Loker (dm_lockers)   A-101-L01, …       ← satu pintu/kompartemen = satu loker
```

- Asrama dimiliki **tepat satu node** bertipe LEMBAGA atau UNIT yang aktif, di yayasan yang sama. Node BIRO tidak boleh memiliki asrama [ASUMSI, OD-DM-01].
- Gedung, kamar, bed, dan loker **mewarisi** pemilik asrama dan tidak bisa memilih pemilik lain. Kamar tidak bisa dipindah ke asrama lain [LAMA 9.13].
- Asrama, gedung, dan kamar **bukan** node pohon lembaga dan bukan cakupan otorisasi. Hak akses tetap dari node pemilik (PRD-000 §7) [LAMA 9.14].
- Kode unik per induknya (kode asrama unik per yayasan; kode gedung per asrama; kode kamar per gedung; kode bed/loker per kamar). Huruf besar, angka, `-`, maks. 30 karakter [ASUMSI].
- Pindah asrama ke node pemilik lain **belum didukung** (OD-DM-01).

### 4.2 Jenis kelamin [DOCX BR-1, LAMA 9.7]

- **Gedung** wajib bertanda **Putra** atau **Putri**. Kamar selalu mengikuti gedungnya, sehingga hierarki "Gedung → Kamar → Penghuni sama jenis kelamin" dijaga oleh data, bukan oleh disiplin petugas [DOCX].
- Jenis kelamin penghuni diambil dari **Person.gender** (`L`/`P`). Orang tanpa jenis kelamin tidak bisa ditempatkan, dengan pesan "Lengkapi jenis kelamin di data siswa/pegawai dulu."
- Kamar campur putra–putri **tidak ada**. Istilah "Mixed" di docx diganti (§4.3) [LAMA 9.8].
- Gender gedung tidak bisa diubah selama ada penghuni aktif/direncanakan di dalamnya [ASUMSI].

### 4.3 Komposisi kamar [DOCX Story 1, LAMA 9.8–9.11]

| Komposisi             | Arti                                                                                     |
| --------------------- | ---------------------------------------------------------------------------------------- |
| **Khusus santri**     | Hanya penghuni reguler. Wali kamar boleh mengawasi tetapi tidak tinggal di kamar ini     |
| **Santri + pengawas** | Penghuni reguler dan pengawas yang **tinggal** di kamar. Wajib ada **slot pengawas ≥ 1** |

- Slot pengawas adalah **bagian dari kapasitas**, bukan tambahan. Kamar 10 slot dengan 1 slot pengawas = maks. 9 santri + 1 pengawas, bukan 10 + 1 [LAMA 9.10].
- Jumlah slot pengawas 1–5, tidak boleh ≥ kapasitas kamar [ASUMSI].

### 4.4 Dasar kapasitas [OWNER Agustus 2026, LAMA 1.1B]

Owner menegaskan: ada kamar yang dihitung dari **bed**, ada dari **pintu lemari/loker**, ada dari **bed + pintu lemari**.

| Dasar kapasitas | Kapasitas efektif                         | Penghuni wajib mendapat |
| --------------- | ----------------------------------------- | ----------------------- |
| **Bed**         | jumlah bed tersedia                       | 1 bed (loker opsional)  |
| **Loker**       | jumlah loker tersedia                     | 1 loker (bed opsional)  |
| **Bed + loker** | **minimum**(bed tersedia, loker tersedia) | 1 bed **dan** 1 loker   |

- Contoh: 20 bed + 16 pintu loker, dasar Bed + loker → kapasitas **16**, bukan 36 [LAMA].
- Satu lemari dengan 4 pintu yang dipakai 4 santri berbeda = **4 loker** [LAMA 9.4].
- Kapasitas **tidak diketik**; selalu dihitung dari bed/loker yang berstatus tersedia. Untuk mempercepat daftar, jumlahnya boleh disimpan sebagai kolom ringkasan yang diperbarui di transaksi yang sama [ASUMSI].
- Bed/loker dibuat sekaligus lewat pop-up **Tambah bed/loker** (mis. "buat 10 bed, kode A-101-01 s.d. A-101-10") [ASUMSI].
- Dasar kapasitas boleh diganti selama kamar kosong. Kalau ada penghuni, penggantian hanya boleh bila semua penghuni aktif sudah memegang sumber yang diwajibkan dasar baru [ASUMSI].

### 4.5 Status bed/loker & kelebihan muatan [LAMA 9.12, 11.22–11.23]

- Bed/loker punya status **Tersedia** atau **Tidak tersedia** (alasan: rusak, perbaikan, ditutup sementara, lainnya). Status "terisi" **tidak disimpan**; dihitung dari penempatan aktif/direncanakan [LAMA].
- Bed/loker yang sedang dipakai boleh dinyatakan tidak tersedia (mis. rusak). Penghuninya **tidak** dikeluarkan otomatis. Kamar menjadi **Kelebihan muatan** dan:
    - penempatan baru ke kamar itu ditolak;
    - kamar tampil bertanda di daftar, laporan, dan kartu Beranda;
    - diselesaikan lewat mutasi, perbaikan bed/loker, atau check-out.
- Bed/loker, kamar, gedung, dan asrama tidak pernah dihapus bila sudah punya riwayat. Yang ada hanya **Nonaktifkan** / **Aktifkan kembali**. Induk nonaktif berarti semua turunannya tidak menerima penempatan baru; riwayat tetap ada [LAMA 9.12]. Hapus permanen hanya untuk data yang belum pernah dipakai [ASUMSI].

## 5. Penghuni & penempatan

### 5.1 Siapa penghuni [LAMA 10.1–10.2, 10.9]

| Kategori     | Siapa                                             | Kelayakan                                                                                              |
| ------------ | ------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| **Santri**   | Siswa (`ac_students`) — lewat kontrak Academic    | Punya **pendaftaran terbuka** di lembaga yang berada di cakupan node pemilik asrama [ASUMSI, OD-DM-02] |
| **Pengawas** | Pegawai aktif (`hr_employees`) — lewat kontrak HR | Pegawai aktif dengan penempatan berjalan [ASUMSI, OD-DM-03]; hanya di kamar **Santri + pengawas**      |

- Penghuni tetap **Membership**. Dormitory tidak membuat salinan nama, NIS, atau data pribadi; semua dibaca dari Person/Academic/HR [LAMA 10.1].
- Kategori penghuni **bukan** hak akses [LAMA 10.9].

### 5.2 Status penempatan [LAMA 11.1, 11.21; data-design lama]

```text
DIRENCANAKAN ──check-in──► AKTIF ──check-out──► SELESAI
     │                       │
     └──batalkan──► DIBATALKAN └──mutasi──► SELESAI (alasan Mutasi) + penempatan baru AKTIF
```

| Status           | Arti                                                                                                            |
| ---------------- | --------------------------------------------------------------------------------------------------------------- |
| **Direncanakan** | Sudah ditentukan kamar (dan bed/loker), santri belum datang. Belum dihitung sebagai penghuni                    |
| **Aktif**        | Sedang tinggal; memakai bed/loker; dihitung dalam hunian                                                        |
| **Selesai**      | Sudah keluar/pindah; tinggal riwayat. Alasan: Keluar asrama, Mutasi, Akhir tahun, Lulus/keluar lembaga, Lainnya |
| **Dibatalkan**   | Rencana yang tidak jadi. Tidak dianggap pernah tinggal                                                          |

Aturan:

- Satu orang maksimal **satu penempatan Aktif** dan **satu Direncanakan** di seluruh yayasan pada satu waktu [LAMA 10.4]. Dijaga partial unique index.
- Satu bed/loker maksimal dipakai **satu** penempatan Aktif atau Direncanakan [LAMA 10.7; ASUMSI: rencana ikut memesan, OD-DM-04].
- Rencana **memesan** bed/loker, jadi kapasitas tersedia = kapasitas efektif − aktif − direncanakan [ASUMSI, OD-DM-04].
- Penempatan Aktif tidak bisa dibatalkan; hanya diakhiri lewat check-out atau mutasi [LAMA 11.21].
- Penempatan tidak pernah diubah kamarnya. Pindah kamar = penempatan lama Selesai + penempatan baru Aktif [LAMA 11.7].

### 5.3 Validasi setiap penempatan [LAMA 11.2, 11.4, 10.17]

Dijalankan ulang di setiap langkah (rencana, check-in, mutasi, impor, akhir tahun), di dalam transaksi yang mengunci kamar:

1. Asrama, gedung, kamar **aktif**; kamar tidak kelebihan muatan.
2. Jenis kelamin orang = gender gedung.
3. Komposisi: pengawas hanya di kamar Santri + pengawas; santri tidak boleh memakai slot pengawas.
4. Kapasitas tersedia, dan bed/loker yang diwajibkan dasar kapasitas tersedia dan milik kamar itu.
5. Orang layak (§5.1) dan belum punya penempatan Aktif/Direncanakan yang bertabrakan.
6. Petugas punya izin di node pemilik asrama.

Pesan penolakan ramah dan spesifik, mis. "Kamar A-101 penuh", "Kamar ini untuk putri", "Ahmad sudah tinggal di kamar B-203", "Loker A-101-L03 sedang rusak" [LAMA 13.12].

### 5.4 Bed/loker dipilih siapa [ASUMSI, OD-DM-05]

Sistem menyarankan bed/loker tersedia dengan kode terkecil. Petugas boleh menggantinya di pop-up. Server tetap memeriksa ulang.

### 5.5 Check-in, check-out, batal [LAMA 11.4–11.6, 11.21]

- **Tempatkan langsung** (check-in langsung): satu pop-up, langsung Aktif. Semua validasi §5.3 sama [LAMA 11.5].
- **Rencanakan** lalu **Check-in**: check-in mengulang semua validasi; bila bed/loker yang dipesan rusak, petugas memilih yang lain.
- **Check-out**: tanggal keluar (≤ hari ini, ≥ tanggal masuk), alasan wajib (pilihan + catatan). Bed/loker langsung lepas.
- **Batalkan rencana**: alasan wajib.
- Tanggal masuk/keluar adalah **tanggal** (bukan jam) dalam zona waktu `EDUCORE_TIMEZONE`; tidak boleh di masa depan untuk check-in/check-out [ASUMSI].

### 5.6 Santri keluar/lulus dari lembaga [ASUMSI, OD-DM-06]

Bila santri tidak punya lagi pendaftaran terbuka yang memenuhi kelayakan asrama itu, penempatannya **tidak** diakhiri otomatis. Penempatan ditandai **Perlu ditinjau** di daftar penghuni dan kartu Beranda, lalu petugas melakukan check-out. Prinsip lama: sistem tidak mengeluarkan penghuni diam-diam [LAMA 11.29].

Tanda ini **dihitung saat dibaca** dari data Academic (kontrak `ActiveStudents`), bukan disimpan lewat event `EnrollmentClosed` [OWNER, 2026-11-15, §19.1]: selalu sesuai keadaan terbaru (didaftarkan lagi → tanda hilang sendiri) dan tanpa tabel/listener tambahan.

## 6. Wali kamar (pengawas) [LAMA 10.11–10.16]

- **Wali kamar = tanggung jawab mengawasi** (`dm_room_supervisors`), terpisah dari **tinggal di kamar** (penempatan kategori Pengawas).
    - Mengawasi tanpa tinggal → tidak memakai kapasitas.
    - Mengawasi dan tinggal → dua data: penugasan wali kamar + penempatan Pengawas.
- Satu kamar boleh punya **0–3 wali kamar** aktif; satu orang boleh mengawasi beberapa kamar [ASUMSI].
- Calon wali kamar = pegawai aktif (kontrak HR `ActiveEmployees`). Jabatan HR (mis. Musyrif) **tidak** memberi hak apa pun [LAMA 12.22].
- Jenis kelamin wali kamar **harus sama** dengan gender gedung, juga bila tidak tinggal di kamar [DOCX FR-02]. Sesi lama sempat melonggarkan ini; lihat OD-DM-08.
- Riwayat penugasan disimpan (mulai, selesai, oleh siapa).

**Hak wali kamar** (pola Kelas saya, AC-3d) [ASUMSI, OD-DM-09]: menu **Kamar saya** muncul bagi pemegang penugasan wali kamar aktif. Isinya daftar penghuni kamarnya (nama, lembaga, kelas, bed/loker), **absen asrama** kamarnya (DM-7), dan **ajukan mutasi** santri kamarnya. Kamar yang tidak diawasinya → 404.

## 7. Hak akses [ASUMSI]

| Permission                      | Isi                                                                          |
| ------------------------------- | ---------------------------------------------------------------------------- |
| `dormitory.facilities.view`     | Lihat asrama, gedung, kamar, bed/loker, ketersediaan                         |
| `dormitory.facilities.manage`   | Tambah/ubah/nonaktifkan asrama, gedung, kamar, bed/loker; atur wali kamar    |
| `dormitory.residents.view`      | Lihat penghuni, riwayat, laporan hunian, unduh .xlsx                         |
| `dormitory.residents.manage`    | Rencanakan, tempatkan, check-in, check-out, batal, impor, proses akhir tahun |
| `dormitory.mutations.request`   | Ajukan mutasi biasa                                                          |
| `dormitory.mutations.approve`   | Setujui/tolak mutasi biasa; tinjau mutasi darurat                            |
| `dormitory.mutations.emergency` | Eksekusi mutasi darurat                                                      |
| `dormitory.attendance.manage`   | Isi & koreksi absen asrama (DM-7)                                            |

| Role                                  | Izin                                                                                                     | Cakupan                  |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------- | ------------------------ |
| **Pengurus Asrama** (baru, penugasan) | facilities.view/manage, residents.view/manage, mutations.request, mutations.emergency, attendance.manage | Node penugasan + turunan |
| **Kepala Asrama** (baru, penugasan)   | facilities.view, residents.view, mutations.approve                                                       | Node penugasan + turunan |
| Admin Yayasan                         | semua                                                                                                    | Seluruh yayasan          |
| Pimpinan, Kepala Lembaga, Koordinator | facilities.view, residents.view                                                                          | Cakupannya               |
| Wali kamar                            | tanpa role; dari data penugasan (§6)                                                                     | Kamar yang diawasi       |

- Matriks docx BR-3 terpenuhi lewat bundel role: Wali kamar hanya mengajukan; Kepala Asrama menyetujui, tidak punya izin darurat; Pengurus Asrama mengajukan dan mengeksekusi darurat [DOCX].
- Aturan PRD-000 berlaku: di luar cakupan 404, di dalam cakupan tanpa izin 403; daftar disaring di query sebelum paginasi.
- Kedua role baru ditawarkan di Kelola akses sebagai role penugasan (`AccessCatalog::offerAsAssignmentRole`). Wajib `php artisan educore:sync-access`.

## 8. Mutasi kamar

### 8.1 Mutasi biasa [DOCX Story 2, LAMA 11.7–11.14]

```text
Pengaju (Pengurus Asrama / wali kamar)          Kepala Asrama                 Sistem
  ajukan: santri, kamar tujuan, alasan  ──►  setujui (pilih bed/loker) ──► eksekusi atomik
                                         └─►  tolak (alasan wajib)
```

- **Status**: Diajukan → Dilaksanakan / Ditolak / Dibatalkan (ditarik pengaju sebelum diputuskan).
- Saat mengajukan, sistem langsung memeriksa kamar tujuan (§5.3) dan menampilkan sisa slot [DOCX].
- **Disetujui = langsung dieksekusi** oleh sistem dalam transaksi yang sama: penempatan lama Selesai (alasan Mutasi), penempatan baru Aktif di kamar tujuan, bed/loker lama lepas dan yang baru terpakai. Semua validasi diulang saat itu. Bila tujuan sudah penuh, persetujuan ditolak dengan pesan, dan pengajuan tetap Diajukan [LAMA 11.11–11.12]. Tidak ada status "disetujui tetapi belum dilaksanakan" [ASUMSI].
- Penyetuju **bukan** pengaju [LAMA 11.10].
- Satu santri hanya boleh punya satu pengajuan terbuka. Pengajuan otomatis gugur bila santri check-out atau dimutasi lewat jalur darurat [ASUMSI].
- Notifikasi docx ("Kepala Asrama mendapat notifikasi") = **kartu Beranda** + jumlah di menu. Notifikasi WA/email di luar tahap ini.

### 8.2 Mutasi darurat [DOCX Story 3, LAMA 11.15–11.20]

- Hanya pemilik `dormitory.mutations.emergency` [DOCX].
- Pop-up wajib berisi **kategori** (Kesehatan, Keamanan/keselamatan, Konflik, Fasilitas rusak, Lainnya) [ASUMSI, saran reviewer docx] **dan** **alasan** minimal 10 karakter. Tombol simpan baru aktif bila keduanya terisi [DOCX].
- Langsung dieksekusi dengan validasi keras yang sama: jenis kelamin, komposisi, kapasitas, bed/loker, status fasilitas. **Darurat bukan jalan pintas kapasitas** [LAMA 11.19]. Kelebihan muatan darurat di luar tahap ini.
- Tercatat di `audit_logs` dengan aksi `dormitory.mutation_emergency` (pelaku, waktu, santri, kamar asal & tujuan, kategori; isi alasan disimpan di data mutasi, tidak di audit) [DOCX].
- **Tinjauan**: kartu Beranda **bendera merah** "Mutasi darurat belum ditinjau" bagi pemilik `mutations.approve`; yang terjadi dalam 24 jam terakhir ditonjolkan [DOCX reviewer]. Kepala Asrama menandai **Sudah ditinjau** (catatan opsional). Mutasi darurat tidak bisa dibatalkan; bila keliru, lakukan mutasi baru [DOCX reviewer, ASUMSI].

### 8.3 Riwayat mutasi

Data mutasi tidak bisa diubah atau dihapus lewat aplikasi. Riwayat per santri bisa dibaca seperti "Kamar A-101 1 Juli–15 September 2026 → pindah (Mutasi) → Kamar B-203 15 September 2026–sekarang" [LAMA 11.14].

## 9. Akhir tahun ajaran [DOCX FR-04 & BR-4, LAMA 11.25–11.30]

- Setiap kamar punya **kebijakan akhir tahun**:
    - **Dikosongkan**: semua penempatan aktif diakhiri (alasan Akhir tahun), bed/loker lepas, riwayat tetap ada. **Bukan** menghapus data [LAMA 11.27].
    - **Tetap**: penghuni tetap di kamarnya. Penghuni yang sudah tidak layak (§5.6) ditandai Perlu ditinjau [LAMA 11.28–11.29].
- Bawaan kebijakan diatur per asrama (berlaku untuk kamar baru), bisa diganti per kamar [ASUMSI].
- Dijalankan lewat halaman **Proses akhir tahun asrama** (per asrama, pemilik `residents.manage`), bukan otomatis, karena Academic tidak punya status "tahun ditutup":
    1. pilih tahun ajaran yang berakhir (dari kontrak Academic `AcademicYears`);
    2. pratinjau per kamar: dikosongkan / tetap / perlu ditinjau, dengan jumlahnya;
    3. simpan semua dalam satu transaksi, semua-atau-tidak.
- Satu asrama hanya bisa diproses sekali per tahun ajaran. Ringkasan proses disimpan (`dm_year_end_runs`: tahun, pelaku, waktu, jumlah dikosongkan/tetap/ditinjau) dan tercatat di audit.

## 10. Halaman

Menu kelompok baru **Asrama** (sesudah Akademik), mengikuti Pedoman tampilan: tab per fungsi, semua tambah/ubah/hapus lewat pop-up (`FormDialog`/`ConfirmAction`).

| Halaman                           | Isi                                                                                                                                                            |
| --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Asrama** (`/asrama`)            | Daftar asrama dalam cakupan: pemilik, jumlah gedung/kamar, kapasitas, terisi, tersedia, kelebihan muatan. Tombol Tambah asrama                                 |
| **Detail asrama**                 | Tab **Gedung & kamar** (pohon gedung → kamar dengan bar hunian), **Penghuni**, **Wali kamar**, **Pengaturan** (kebijakan akhir tahun bawaan, status)           |
| **Detail kamar**                  | Ringkasan (gender, komposisi, dasar kapasitas, kapasitas efektif, slot pengawas, terisi/direncanakan/tersedia). Tab **Penghuni**, **Bed & loker**, **Riwayat** |
| **Penghuni** (`/asrama/penghuni`) | Semua penghuni dalam cakupan, saringan asrama/gedung/kamar/status/kategori/lembaga, cari nama/NIS, tanda Perlu ditinjau, unduh .xlsx                           |
| **Mutasi** (`/asrama/mutasi`)     | Tab **Menunggu persetujuan**, **Darurat** (dengan Sudah ditinjau), **Riwayat**. Tombol Ajukan mutasi dan Mutasi darurat sesuai izin                            |
| **Impor penempatan**              | Pola impor EduCore (§12 DM-5)                                                                                                                                  |
| **Proses akhir tahun**            | §9                                                                                                                                                             |
| **Laporan hunian**                | §12 DM-6                                                                                                                                                       |
| **Kamar saya**                    | Untuk wali kamar (§6), di kelompok **Untuk saya**                                                                                                              |
| Detail siswa / detail pegawai     | **Ditunda** [OWNER, 2026-11-15, §16.8]: riwayat hunian per orang ada di Asrama → Penghuni                                                                      |

Kartu Beranda [ASUMSI]: **Pengajuan mutasi menunggu** (penyetuju), **Mutasi darurat belum ditinjau** (bendera merah, penyetuju), **Kamar kelebihan muatan** dan **Penghuni perlu ditinjau** (pengurus).

## 11. Kebutuhan non-fungsional [LAMA §13, disederhanakan]

- **Keamanan & isolasi**: semua query lewat `BelongsToTenant` + saringan `visibleNodeIds`; id kamar/bed/loker dari browser tidak dipercaya tanpa cek pemilik [LAMA 13.1]. Impor, ekspor, dan laporan memakai saringan yang sama.
- **Konsistensi & kebersamaan** [DOCX NFR-03, LAMA 13.3]: setiap penempatan, check-in, mutasi, dan akhir tahun berjalan dalam satu transaksi database yang **mengunci baris kamar** (dan kamar tujuan untuk mutasi; urutan kunci menurut id agar tidak deadlock). Pengaman terakhir di database:
    - partial unique index: satu penempatan Aktif per orang per yayasan; satu Direncanakan per orang; satu bed/loker per penempatan Aktif/Direncanakan;
    - FK gabungan `tenant_id` + pemilik di seluruh rantai asrama → gedung → kamar → bed/loker → penempatan;
    - CHECK untuk nilai status, kategori, dasar kapasitas, komposisi, gender.
      Uji wajib: dua permintaan berebut loker terakhir → hanya satu berhasil.
- **Atomik**: mutasi berakhir di salah satu keadaan — lama tetap Aktif dan baru tidak dibuat, **atau** lama Selesai dan baru Aktif [LAMA 13.4].
- **Kirim ulang aman**: klik ganda/kirim ulang tidak membuat dua penempatan atau dua mutasi (kunci baris + unique index + pengecekan status di dalam transaksi) [LAMA 13.5].
- **Riwayat**: penempatan, mutasi, penugasan wali kamar, absen, dan proses akhir tahun tidak dihapus [LAMA 13.11]. Audit lewat `audit_logs` (append-only, masa simpan 1 tahun, OD-16); riwayat lengkap tetap di tabel Dormitory.
- **Kinerja**: daftar 25–50 baris per halaman (maks. 100); impor 1.000 baris diproses ≤ 60 detik di server pengembangan (`ExecutionTime::atLeast`) [ASUMSI]; event ke modul lain dikirim sesudah commit.
- **Privasi**: Dormitory hanya menampilkan nama, jenis kelamin, lembaga, NIS, dan kelas. Data pribadi lain (HP, alamat, NIK) tidak ditampilkan [LAMA 13.17].
- **UI**: responsif (kartu di HP), Bahasa Indonesia, kondisi kosong/loading/error, tema UX-2.

## 12. Tahapan [ASUMSI]

| Tahap    | Isi                                                                                                                                                                                                                                                                                                                                                                    |
| -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **DM-1** | Kerangka modul + arch test (`Dormitory → Core, Academic\Contracts, HR\Contracts`), katalog akses + 2 role, asrama/gedung/kamar/bed/loker, kapasitas efektif, status & nonaktif, halaman Asrama & Detail kamar                                                                                                                                                          |
| **DM-2** | Penempatan: tempatkan langsung, rencanakan, check-in, batal rencana, check-out; daftar Penghuni; riwayat kamar & santri; bagian Asrama di detail siswa/pegawai; penanda kelebihan muatan                                                                                                                                                                               |
| **DM-3** | Wali kamar: penugasan per kamar, penempatan Pengawas, menu **Kamar saya**                                                                                                                                                                                                                                                                                              |
| **DM-4** | Mutasi biasa & darurat, tinjauan darurat, kartu Beranda                                                                                                                                                                                                                                                                                                                |
| **DM-5** | **Dipecah** [OWNER, 2026-11-15]: **DM-5a** = tanda Perlu ditinjau, Proses akhir tahun, Tempatkan banyak (§19); **DM-5b** = impor penempatan. Rincian awal: impor penempatan (template per asrama: NIS/NISN, kode kamar, bed/loker opsional) + pilih banyak santri sekaligus ke satu kamar; **Proses akhir tahun asrama**; tanda Perlu ditinjau dari `EnrollmentClosed` |
| **DM-6** | Laporan hunian: per asrama/gedung/kamar — bed, loker, kapasitas efektif, terisi, direncanakan, tersedia, % hunian (kapasitas 0 tidak dibagi), kelebihan muatan, bed/loker rusak, jumlah santri & pengawas; riwayat per rentang tanggal; unduh .xlsx [LAMA 12.9–12.12]                                                                                                  |
| **DM-7** | Absen asrama (roll call): sesi per asrama per tanggal (mis. Subuh/Malam, diatur per asrama), status Hadir/Izin/Sakit/Tidak ada per penghuni Aktif, diisi wali kamar/pengurus, rekap bulanan. Terpisah dari presensi kelas AC-4 [LAMA 12.14–12.18]                                                                                                                      |

Setiap tahap: test Feature per aturan (awalan fungsi bantu unik, mis. `dmf`, `dmp`), test constraint di PostgreSQL, audit, `composer lint`, `composer test`, `npm run check`.

## 13. Open decisions

Semua **DIPUTUSKAN sesuai usulan** [OWNER, 2026-11-15].

| ID       | Topik                                         | Keputusan                                                                                                                                                                                                                                             |
| -------- | --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OD-DM-01 | Node pemilik asrama                           | LEMBAGA atau UNIT (bukan BIRO); pemilik tidak bisa dipindah setelah dibuat                                                                                                                                                                            |
| OD-DM-02 | Kelayakan santri                              | Santri harus punya pendaftaran terbuka di lembaga **di bawah node pemilik asrama** (asrama Unit 1 hanya untuk santri lembaga Unit 1). Alternatif: lembaga mana pun di yayasan                                                                         |
| OD-DM-03 | Kelayakan pengawas                            | Pegawai aktif mana pun di yayasan. Alternatif: hanya yang berpenempatan di bawah node pemilik                                                                                                                                                         |
| OD-DM-04 | Rencana (Direncanakan) memesan bed/loker?     | **Ya**, rencana mengurangi slot tersedia supaya tidak terjadi pemesanan ganda saat penempatan awal tahun                                                                                                                                              |
| OD-DM-05 | Pemilihan bed/loker                           | Sistem menyarankan kode terkecil yang tersedia; petugas boleh mengganti                                                                                                                                                                               |
| OD-DM-06 | Santri keluar/lulus dari lembaga              | Ditandai **Perlu ditinjau**, check-out manual oleh pengurus. Alternatif: check-out otomatis (alasan Lulus/keluar lembaga)                                                                                                                             |
| OD-DM-07 | Impor penempatan                              | Pola EduCore: pratinjau + **simpan semua atau tidak**, sinkron, maks. 1.000 baris. Ini mengganti "partial success + background job" dari sesi lama                                                                                                    |
| OD-DM-08 | Gender wali kamar yang tidak tinggal di kamar | **Wajib sama** dengan gedung (docx FR-02). Sesi lama sempat membolehkan beda selama tidak tinggal                                                                                                                                                     |
| OD-DM-09 | Hak wali kamar                                | Dari data penugasan wali kamar (pola Kelas saya, tanpa role). Sesi lama: wali kamar tetap butuh permission                                                                                                                                            |
| OD-DM-10 | Integrasi biaya asrama (Finance)              | **Belum di tahap ini.** Usulan tahap lanjut: jenis biaya bertanda "Hanya penghuni asrama" sehingga tagihan bulanan dibuat hanya untuk santri yang Aktif di asrama pada bulan itu, dibaca lewat kontrak `Dormitory\Contracts`. Perlu perubahan PRD-003 |
| OD-DM-11 | Integrasi Academic                            | Hanya baca (kelayakan, tahun ajaran, data tampilan) + mendengar `EnrollmentClosed`. Dormitory tidak mengubah data Academic. Docx "status kelas" dianggap tidak diperlukan                                                                             |
| OD-DM-12 | Kebijakan akhir tahun bawaan                  | Bawaan **Tetap**; pengurus memilih kamar mana yang Dikosongkan                                                                                                                                                                                        |

## 14. Di luar tahap ini

Perizinan keluar/pulang santri (surat izin, jam kembali), kunjungan wali santri, pelanggaran & poin asrama, inventaris barang kamar, kelebihan muatan darurat dengan izin khusus, notifikasi WA/email, portal orang tua (kamar & wali kamar anak), biaya asrama (OD-DM-10), pindah asrama ke node pemilik lain, aplikasi absen dengan pindai kartu/QR.

## 15. DM-1 Fasilitas asrama [ASUMSI — rincian teknis 2026-11-15, dibangun 2026-11-15]

- **Modul**: `modules/Dormitory` (`DormitoryServiceProvider` sesudah Finance di `bootstrap/providers.php`); arch test: Dormitory hanya memakai Core + `Academic\Contracts` + `HR\Contracts` (tanpa Finance); Core/HR/Academic/Finance tidak bergantung pada Dormitory. Label audit lewat `AuditAction::registerLabels()`.
- **Akses**: `DormitoryAccess` — 8 permission §7, role penugasan **Pengurus Asrama** (`pengurus-asrama`) & **Kepala Asrama** (`kepala-asrama`); Admin Yayasan semua; Pimpinan, Kepala Lembaga, Koordinator: lihat fasilitas & penghuni → wajib `php artisan educore:sync-access`. Di DM-1 baru `facilities.view|manage` yang dipakai halaman.
- **Data** (migrasi `2026_11_15_400001_create_dm_facility_tables`; UUIDv7, `tenant_id` + `organization_id` pemilik di setiap tabel):
    - `dm_dormitories`: organization_id (node LEMBAGA/UNIT aktif, diperiksa service), code (unik per yayasan), name (maks. 100), default_year_end_policy `TETAP|KOSONGKAN` (bawaan TETAP), is_active.
    - `dm_buildings`: dormitory_id, code (unik per asrama), name, gender `L|P`, is_active. FK gabungan `(tenant, organization, dormitory)` → asrama.
    - `dm_rooms`: building_id, dormitory_id, code (unik per gedung), name (opsional), capacity_basis `BED|LOKER|BED_LOKER`, composition `KHUSUS_SANTRI|SANTRI_PENGAWAS`, supervisor_slots, year_end_policy, is_active. FK gabungan `(tenant, organization, dormitory, building)` → gedung. CHECK: Khusus santri ⇒ slot 0; Santri + pengawas ⇒ slot 1–5.
    - `dm_beds` & `dm_lockers`: room_id, code (unik per kamar), status `TERSEDIA|TIDAK_TERSEDIA`, unavailable_reason `RUSAK|PERBAIKAN|DITUTUP|LAINNYA` + unavailable_note (maks. 200), is_active. CHECK: tidak tersedia wajib alasan (dengan `IS NOT NULL`, karena `NULL IN (...)` lolos CHECK), tersedia tanpa alasan/catatan. Status "terisi" tidak disimpan.
    - CHECK format kode di semua tabel: huruf besar, angka, `-` (`^[A-Z0-9]+(-[A-Z0-9]+)*$`), maks. 30.
- **Service** (`Application/Facility`): `FacilityCode` (rapikan: huruf besar, spasi → `-`), `ManageDormitories`, `ManageBuildings`, `ManageRooms` + `RoomData`, `ManageRoomResources` (tambah banyak: awalan + nomor mulai 0–999 + jumlah 1–100, nomor min. 2 digit, kode bentrok → tidak ada yang disimpan, maks. 200 aktif per jenis per kamar; ubah kode/status; nonaktif/aktif; hapus — di DM-1 selalu boleh karena belum ada penempatan, DM-2 menambah pengaman "belum pernah dipakai"), `RoomCapacity` (bed/loker aktif & tersedia → kapasitas efektif menurut dasar; slot santri = efektif − slot pengawas, min. 0), `FacilityDirectory` (data halaman, saringan cakupan di query). Slot pengawas ditolak bila ≥ kapasitas efektif yang sudah ada. Aktifkan kembali butuh induk aktif (asrama butuh lembaga pemilik aktif). Tambah gedung/kamar/bed-loker butuh induk aktif.
- **Hak akses**: daftar = asrama yang node pemiliknya ada di `visibleNodeIds('dormitory.facilities.view')`; aksi pakai Gate dengan datanya (di luar cakupan 404, tanpa izin kelola 403). Pilihan pemilik asrama baru = LEMBAGA/UNIT aktif dalam cakupan `facilities.manage`.
- **Halaman** (menu kelompok **Asrama** → **Asrama**, pemilik `facilities.view`):
    - `/asrama`: kartu asrama (kode, pemilik, jumlah gedung aktif & kamar aktif, kapasitas = kamar aktif di gedung aktif, slot santri + pengawas), saringan pemilik & "Tampilkan yang nonaktif"; pop-up **Tambah asrama** → langsung ke detail.
    - `/asrama/{id}`: tombol Tambah gedung · Ubah asrama · Nonaktifkan; tab **Gedung & kamar** (kartu per gedung dengan badge Putra/Putri, Tambah kamar · Ubah · Nonaktifkan, daftar kamar → halaman kamar) dan **Pengaturan** (pemilik, kode, kebijakan akhir tahun bawaan).
    - `/asrama/kamar/{id}`: Ubah kamar · Nonaktifkan; kotak Kapasitas, Slot santri, Bed tersedia x/y, Loker tersedia x/y + penjelasan dasar kapasitas; bagian **Bed** dan **Loker** berupa **petak ringkas** (kode + status; rusak merah, nonaktif garis putus) — ketuk petak → pop-up Ubah (kode, status, alasan, keterangan) yang memuat Nonaktifkan/Aktifkan dan Hapus dengan konfirmasi; Tambah bed/loker dengan pratinjau kode ("Akan dibuat: A-101-01 s.d. A-101-20").
- **Route**: `asrama` (GET/POST), `asrama/{dormitory}` (GET/PATCH, `/status`, `/gedung`), `asrama/gedung/{building}` (PATCH, `/status`, `/kamar`), `asrama/kamar/{room}` (GET/PATCH, `/status`, `/bed-loker`), `asrama/{bed|loker}/{id}` (PATCH/DELETE, `/status`).
- **Audit**: `dormitory.dormitory_created|updated|deactivated|reactivated`, `dormitory.building_*`, `dormitory.room_*`, `dormitory.resources_added` (kamar, jenis, jumlah, dari–sampai), `dormitory.resource_updated|deactivated|reactivated|deleted` (kode, status, alasan, nama isian; tanpa isi catatan).
- **Test**: `tests/Feature/Dormitory/FacilityTest.php` (6 test, awalan `dmf`): kapasitas tiga dasar (20 bed + 16 loker → 20/16/16) & slot pengawas; tambah + kode dirapikan + audit; penolakan (biro, kode dobel/format, slot pengawas, bentrok semua-atau-tidak, alasan wajib, alasan Lainnya wajib keterangan); nonaktif berjenjang; hak akses (Pengurus Unit 1 vs Unit 2 → 404, Kepala Asrama & Pimpinan hanya lihat → 403, Kepala Unit 1 hanya asrama Unit 1, guru 403); constraint database (pemilik berantai, format kode, komposisi, status bed). `AccessCatalogTest`, `TreeAuthorizationTest`, `MemberAccessTest` disesuaikan (+8 permission, +2 role).

## 16. DM-2 Penempatan penghuni [ASUMSI disetujui owner 2026-11-15]

Mewujudkan §5 (penghuni & penempatan) untuk kamar yang dibuat di DM-1. Mutasi (DM-4), wali kamar (DM-3), impor & akhir tahun (DM-5) **belum** termasuk.

### 16.1 Data

**`dm_placements`** (UUIDv7, `tenant_id` + `organization_id` pemilik asrama, `dormitory_id`, `room_id`):

| Field                               | Aturan                                                                                                                                                                                  |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| membership_id                       | Orang yang ditempatkan (Core). Nama & jenis kelamin dibaca dari Person, tidak disalin                                                                                                   |
| category                            | `PENGHUNI` (santri) \| `PENGAWAS` (pegawai yang tinggal di kamar)                                                                                                                       |
| bed_id, locker_id                   | Opsional menurut dasar kapasitas (§16.3). FK gabungan `(tenant, organization, room, bed)` → `dm_beds` sehingga bed **pasti** milik kamar itu; sama untuk loker                          |
| status                              | `DIRENCANAKAN` \| `AKTIF` \| `SELESAI` \| `DIBATALKAN`                                                                                                                                  |
| planned_on                          | Rencana tanggal masuk (opsional, khusus rencana)                                                                                                                                        |
| check_in_on                         | Tanggal masuk (wajib untuk Aktif/Selesai)                                                                                                                                               |
| check_out_on, end_reason            | Tanggal keluar (≥ masuk) & alasan, wajib untuk Selesai. Alasan: `KELUAR_ASRAMA`, `KELUAR_LEMBAGA` (lulus/pindah sekolah), `LAINNYA`; `MUTASI` & `AKHIR_TAHUN` disiapkan untuk DM-4/DM-5 |
| cancel_reason                       | Wajib untuk Dibatalkan                                                                                                                                                                  |
| note                                | Catatan ≤ 200 (wajib bila alasan Lainnya)                                                                                                                                               |
| created_by, checked_in_by, ended_by | Membership petugas                                                                                                                                                                      |

Pengaman database:

- partial unique: satu `AKTIF` per orang per yayasan; satu `DIRENCANAKAN` per orang per yayasan (§5.2);
- partial unique: satu bed dan satu loker hanya dipakai satu penempatan `DIRENCANAKAN`/`AKTIF` (rencana ikut memesan, OD-DM-04);
- CHECK konsistensi status ↔ tanggal/alasan (pola `IS NOT NULL` dari pelajaran DM-1);
- FK `restrict`: bed/loker/kamar yang pernah dipakai tidak bisa dihapus permanen.

### 16.2 Kontrak modul lain (tetap lewat kontrak publik, ADR-001)

- **Academic** — kontrak baru `Contracts\ActiveStudents` (implementasi di Academic): cari siswa dengan pendaftaran **terbuka** di daftar lembaga (`search`, maks. 10, nama/NIS/NISN), cek kelayakan (`withOpenEnrollment`), dan data tampilan (`describe`: nama, NIS, lembaga, kelas berjalan; tanpa data pribadi). Lembaga = node LEMBAGA di bawah pemilik asrama (OD-DM-02).
- **HR** — `ActiveEmployees` ditambah `searchInTenant` (pegawai aktif di yayasan, cari nama, maks. 20) dan `activeMembershipIds` (saring daftar membership yang pegawainya aktif) untuk calon pengawas (OD-DM-03).
- Jenis kelamin dibaca dari Person (Core). Person tanpa jenis kelamin → "Lengkapi jenis kelamin di data siswa/pegawai dulu."

### 16.3 Aturan penempatan (§5.3, dijalankan ulang di setiap langkah, di dalam transaksi yang mengunci baris kamar)

1. Asrama, gedung, kamar aktif; kamar tidak **kelebihan muatan** (§16.5).
2. Jenis kelamin orang = gender gedung.
3. Komposisi: Pengawas hanya di kamar Santri + pengawas, jumlah pengawas (aktif + rencana) ≤ slot pengawas. Santri (aktif + rencana) ≤ slot santri.
4. Bed/loker: Dasar Bed → bed **wajib**, loker opsional; Loker → loker wajib, bed opsional; Bed + loker → keduanya wajib. Yang dipilih harus milik kamar itu, aktif, tersedia, dan belum dipesan. Server menyarankan kode terkecil yang kosong (OD-DM-05).
5. Kelayakan: santri punya pendaftaran terbuka di lembaga di bawah pemilik asrama; pengawas pegawai aktif.
6. Orang belum punya penempatan Aktif (untuk tempatkan langsung/check-in) atau Direncanakan (untuk rencana).
7. Izin `dormitory.residents.manage` di node pemilik.

### 16.4 Aksi

| Aksi                                      | Aturan                                                                                                                                                                                                                    |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Tempatkan langsung**                    | Langsung Aktif. Tanggal masuk ≤ hari ini, ≥ 1 tahun lalu, dan tidak sebelum tanggal keluar penempatan terakhir orang itu (riwayat tidak tumpang tindih)                                                                   |
| **Rencanakan**                            | Status Direncanakan, memesan bed/loker, rencana tanggal masuk opsional. Boleh dibuat untuk orang yang masih Aktif di kamar lain (mis. kamar tahun depan)                                                                  |
| **Check-in** rencana                      | Mengulang semua aturan §16.3. Bila bed/loker pesanan kini rusak/nonaktif, petugas memilih yang lain. Ditolak bila orang itu masih Aktif di kamar lain: "Check-out dulu dari kamar B-203" (pindah langsung = mutasi, DM-4) |
| **Batalkan rencana**                      | Alasan wajib; bed/loker lepas                                                                                                                                                                                             |
| **Check-out**                             | Tanggal keluar (≥ masuk, ≤ hari ini), alasan wajib, catatan; bed/loker langsung lepas                                                                                                                                     |
| **Ganti bed/loker** dalam kamar yang sama | Untuk penempatan Aktif/Direncanakan (mis. bed rusak). Bukan mutasi, karena kamarnya tetap; dicatat di audit                                                                                                               |

Penempatan Aktif tidak pernah dibatalkan atau diubah kamarnya (§5.2). Penolakan memakai pesan spesifik (§5.3), mis. "Kamar A-101 penuh untuk santri (15 dari 15).", "Kamar ini untuk putri.", "Ahmad sudah tinggal di kamar B-203.", "Loker A-101-L03 sedang rusak."

### 16.5 Kelebihan muatan & pengaman fasilitas DM-1

- Kamar **kelebihan muatan** bila jumlah penempatan Aktif + Direncanakan > kapasitas efektif, atau ada penempatan yang memegang bed/loker tidak tersedia/nonaktif. Penempatan baru ditolak; tampil tanda di daftar kamar, detail asrama, dan Penghuni.
- Pengaman tambahan di aksi DM-1:
    - ganti gender gedung ditolak bila masih ada penempatan Aktif/Direncanakan;
    - ganti dasar kapasitas ditolak bila ada penghuni yang tidak memegang sumber yang diwajibkan dasar baru;
    - ganti komposisi ke Khusus santri, atau kurangi slot pengawas di bawah jumlah pengawas, ditolak;
    - **hapus** bed/loker ditolak bila pernah dipakai penempatan ("cukup nonaktifkan");
    - **nonaktifkan** asrama/gedung/kamar tetap boleh, tetapi pop-up konfirmasi menyebut "N penghuni masih tinggal dan tidak dikeluarkan".

### 16.6 Halaman

- **Detail kamar**: tab baru **Penghuni** (bawaan), **Bed & loker** (DM-1), **Riwayat**.
    - Penghuni: kartu penghuni Aktif & Direncanakan (nama, kategori, lembaga + kelas, NIS, bed/loker, tanggal masuk/rencana) dengan aksi Check-in · Ganti bed/loker · Batalkan rencana · Check-out (semua pop-up). Tombol **Tempatkan santri** dan **Tempatkan pengawas** (bila komposisi Santri + pengawas): pop-up cari orang (min. 3 huruf), pilih Langsung tinggal / Rencanakan, bed/loker (saran otomatis), tanggal.
    - Petak bed/loker menampilkan nama pemakainya ("Terisi · Ahmad" / "Dipesan · Budi").
    - Riwayat: penempatan Selesai & Dibatalkan di kamar ini (terbaru dulu, 50 per halaman).
- **Detail asrama**: daftar kamar menampilkan "Terisi 12/16 · 2 rencana" dan tanda Kelebihan muatan; tab baru **Penghuni** (semua penghuni asrama, saring gedung/status/kategori, cari nama/NIS).
- **Menu Asrama → Penghuni** (`/asrama/penghuni`): penghuni semua asrama di cakupan `residents.view` (saring asrama, status, kategori, cari), 25 per halaman. Klik nama → **riwayat hunian orang itu** (`/asrama/penghuni/{membership}`: urutan kamar, tanggal, alasan keluar) — hanya riwayat di asrama yang terlihat.
- Tanpa izin `residents.view`, halaman fasilitas tetap tampil tanpa nama penghuni (hanya angka terisi).

### 16.7 Hak akses, audit, test

- `dormitory.residents.view` untuk melihat, `dormitory.residents.manage` untuk aksi (Pengurus Asrama, Admin Yayasan); di luar cakupan 404, tanpa izin 403. Tanpa permission baru.
- Audit: `dormitory.placement_planned`, `placement_checked_in` (termasuk tempatkan langsung), `placement_resource_changed`, `placement_cancelled`, `placement_checked_out` (kamar, kategori, bed/loker, tanggal, alasan; tanpa isi catatan).
- Test (awalan `dmp`): semua aturan §16.3 (gender, komposisi, slot santri/pengawas, bed/loker wajib per dasar, kelayakan lintas unit, Person tanpa gender); rencana memesan bed; check-in mengulang aturan & menolak bila masih aktif; riwayat tidak tumpang tindih; check-out melepas bed; kelebihan muatan menolak penempatan; pengaman DM-1 (gender gedung, dasar kapasitas, komposisi, hapus bed terpakai); **dua permintaan berebut bed terakhir → satu berhasil** (unique index); hak akses 404/403; constraint database.

### 16.8 Perubahan dari PRD §10 [OWNER, 2026-11-15]

PRD §10 menyebut bagian **Asrama** di detail siswa/pegawai. Halaman itu milik Academic/HR, sedangkan aturan dependency melarang Academic/HR memakai Dormitory. Usulan: di DM-2 riwayat hunian per orang ada di modul Asrama (§16.6); bagian di detail siswa/pegawai menyusul lewat registri Core (pola `GuardianPortalLinks`/`DashboardCards`) bila owner tetap menginginkannya.

**Keputusan owner (2026-11-15):** riwayat hunian di modul Asrama saja; bagian di detail siswa/pegawai ditunda. Rencana penempatan **boleh** dibuat untuk orang yang masih Aktif di kamar lain (§16.4); check-in menunggu check-out.

### 16.9 Hasil pembangunan DM-2 (2026-11-15)

Dibangun sesuai §16.1–§16.8, dengan penyesuaian berikut:

- **Satu tombol Tempatkan** di tab Penghuni; kategori (Santri/Pengawas) dipilih di dalam pop-up, dan pilihan Pengawas hanya muncul di kamar Santri + pengawas. Alasannya: pedoman satu tombol utama per tampilan.
- **Cari calon** (pop-up Tempatkan): siswa dicari menurut nama, NISN, atau NIS; pegawai menurut nama. Orang yang tidak bisa dipilih tetap tampil dengan alasannya: putra/putri tidak sesuai, jenis kelamin kosong, atau sudah punya rencana. Orang yang masih tinggal di kamar lain hanya bisa dibuatkan **Rencana**.
- **Daftar penghuni** (menu Penghuni dan tab Penghuni di detail asrama) dicari menurut **nama saja**.
- **Riwayat kamar dan daftar penghuni detail asrama** dimuat saat tabnya dibuka (bukan saat halaman dibuka).
- **Kelebihan muatan** ditandai di kartu Terisi dan banner halaman kamar, baris kamar di detail asrama, banner jumlah kamar bermasalah di detail asrama, serta peringatan "bed/loker tidak bisa dipakai" pada penghuni terkait.
- **Belum dibangun:**
    - Teks "N penghuni masih tinggal" di pop-up Nonaktifkan asrama/gedung/kamar (§16.5). Pop-up masih memakai teks DM-1, dan penghuni memang tidak dikeluarkan.
    - Test "dua permintaan berebut bed terakhir" diganti test unique index satu pemegang per bed di database, ditambah kunci baris kamar di service. Hasil akhirnya sama: hanya satu permintaan yang tersimpan.
- **Kontrak:** `Academic\Contracts\ActiveStudents` (`search`, `withOpenEnrollment`, `describe`); HR `ActiveEmployees::searchInTenant` dan `activeMembershipIds`.
- **Test:** `tests/Feature/Dormitory/PlacementTest.php` (awalan `dmp`, 7 test).

## 17. DM-3 Wali kamar [ASUMSI disetujui owner 2026-11-15]

Mewujudkan §6. Mengawasi (penugasan wali kamar) dan tinggal di kamar (penempatan kategori Pengawas, DM-2) tetap **dua data terpisah**.

### 17.1 Data

**`dm_room_supervisors`** (UUIDv7, `tenant_id` + `organization_id` pemilik asrama, `dormitory_id`, `room_id`):

| Field                | Aturan                                                                 |
| -------------------- | ---------------------------------------------------------------------- |
| membership_id        | Pegawai yang mengawasi (Core). Nama & jenis kelamin dibaca dari Person |
| start_on             | Tanggal mulai (≤ hari ini, paling jauh 1 tahun ke belakang)            |
| end_on               | Tanggal selesai; kosong = masih aktif. ≥ tanggal mulai, ≤ hari ini     |
| note                 | Keterangan ≤ 200 (opsional)                                            |
| created_by, ended_by | Membership petugas                                                     |

Pengaman database: FK gabungan ke kamar (ikut pemilik & asrama kamarnya) dan ke membership; partial unique satu penugasan aktif per orang per kamar; CHECK `end_on >= start_on`. Penugasan tidak dihapus; yang ada hanya **Akhiri penugasan**.

### 17.2 Aturan

1. Kamar, gedung, asrama aktif (untuk menambah; mengakhiri tetap boleh di kamar nonaktif).
2. Calon = pegawai aktif mana pun di yayasan (OD-DM-03), lewat kontrak HR `ActiveEmployees`.
3. Jenis kelamin **wajib sama** dengan gedung, juga bila tidak tinggal di kamar (OD-DM-08). Jenis kelamin kosong → ditolak.
4. Maks. **3** wali kamar aktif per kamar (kunci baris kamar); satu pegawai boleh mengawasi beberapa kamar; tidak boleh dobel di kamar yang sama.
5. Izin: `dormitory.facilities.manage` di node pemilik (§7 "atur wali kamar"). Tanpa permission baru.
6. **Pegawai berhenti/nonaktif di HR** [OWNER, 2026-11-15]: penugasan **tidak** diakhiri otomatis; tampil tanda **Bukan pegawai aktif**, menu Kamar saya untuk orang itu hilang, pengurus mengakhirinya manual (sejalan §5.6).
7. **Tinggal di kamar** [OWNER, 2026-11-15]: tidak digabung ke pop-up wali kamar; tetap lewat Tempatkan → Pengawas (DM-2). Wali kamar yang juga tinggal di kamar itu diberi badge **Tinggal di kamar ini**.

### 17.3 Halaman

- **Detail kamar**: bagian **Wali kamar** di tab Penghuni (nama, mulai sejak, badge Tinggal di kamar ini / Bukan pegawai aktif) dengan aksi **Tambah wali kamar** (pop-up cari pegawai min. 3 huruf, tanggal mulai, keterangan) dan **Akhiri** (tanggal selesai, keterangan). Penugasan yang sudah selesai tampil di tab Riwayat.
- **Detail asrama**: tab **Wali kamar** — per gedung, setiap kamar dengan wali kamar aktifnya; kamar aktif tanpa wali kamar ditandai.
- **Untuk saya → Kamar saya** (`/kamar-saya`, pola Kelas saya, OD-DM-09): muncul bagi pegawai aktif yang punya penugasan aktif. Kartu per kamar: asrama, gedung, keterisian, daftar penghuni & rencana (nama, lembaga + kelas, bed/loker). Hanya lihat; tidak membuka halaman Asrama lain. Absen (DM-7) dan ajukan mutasi (DM-4) menyusul.

### 17.4 Audit & test

- Audit: `dormitory.supervisor_assigned`, `dormitory.supervisor_ended` (kamar, nama pegawai, tanggal; tanpa isi keterangan).
- Test (awalan `dms`): gender, batas 3, dobel, tanggal, kamar nonaktif, pegawai nonaktif (ditolak saat menambah; penugasan lama ditandai & Kamar saya hilang), hak akses (Kepala Asrama 403, unit lain 404), Kamar saya (hanya kamarnya, kamar lain 404), constraint database.

### 17.5 Hasil pembangunan DM-3 (2026-11-15)

Dibangun sesuai §17.1–§17.4, dengan catatan:

- **Bagian Wali kamar** di detail kamar juga tampil bagi pengguna yang hanya punya izin lihat fasilitas (tanpa izin lihat penghuni), di atas Bed & loker.
- **Pengguna tanpa izin asrama** (mis. wali kamar yang hanya pegawai) yang membuka halaman kamar mendapat 404, karena tidak punya izin di cakupan mana pun (aturan PRD-000). Ia memakai Kamar saya.
- Kode: migrasi `2026_11_15_400003_create_dm_room_supervisors_table`; `Domain/Supervision/{RoomSupervisor, Exceptions/SupervisionException}`; `Application/Supervision/{ManageRoomSupervisors, SupervisorDirectory}`; `SupervisorController`, `MyRoomsController`; Inertia share `dormitory.myRooms`.
- Route: `asrama/kamar/{room}/wali-kamar/calon` (GET, throttle 60/menit), `asrama/kamar/{room}/wali-kamar` (POST), `asrama/wali-kamar/{supervisor}/akhiri` (POST), `kamar-saya` (GET).
- Test: `tests/Feature/Dormitory/SupervisorTest.php` (awalan `dms`, 6 test).

## 18. DM-4 Mutasi kamar [ASUMSI disetujui owner 2026-11-15]

Mewujudkan §8 untuk penghuni yang ditempatkan di DM-2.

### 18.1 Data

**`dm_mutations`** (UUIDv7, `tenant_id`; asal: `organization_id`, `dormitory_id`, `from_room_id`, `placement_id`; tujuan: `to_organization_id`, `to_dormitory_id`, `to_room_id`):

| Field                                 | Aturan                                                                                  |
| ------------------------------------- | --------------------------------------------------------------------------------------- |
| membership_id                         | Santri yang dipindah                                                                    |
| type                                  | `BIASA` \| `DARURAT`                                                                    |
| status                                | `DIAJUKAN` → `DILAKSANAKAN` / `DITOLAK` / `DIBATALKAN`; darurat langsung `DILAKSANAKAN` |
| emergency_category                    | Wajib untuk darurat: `KESEHATAN`, `KEAMANAN`, `KONFLIK`, `FASILITAS`, `LAINNYA`         |
| reason                                | Wajib (biasa ≤ 500; darurat 10–500 karakter)                                            |
| requested_by                          | Pengaju (biasa) atau pelaku (darurat)                                                   |
| decided_by, decided_at, decision_note | Penyetuju/penolak; alasan tolak wajib; keterangan sistem bila gugur                     |
| executed_on, new_placement_id         | Tanggal dilaksanakan & penempatan baru                                                  |
| reviewed_by, reviewed_at, review_note | Tinjauan mutasi darurat                                                                 |

Pengaman database: FK gabungan asal & tujuan ke `dm_rooms`; partial unique **satu pengajuan `DIAJUKAN` per santri**; CHECK konsistensi jenis/status (darurat wajib kategori & dilaksanakan; dilaksanakan wajib tanggal & penempatan baru; ditolak wajib alasan; tujuan ≠ asal). Data mutasi tidak bisa diubah/dihapus lewat aplikasi.

### 18.2 Aturan

1. **Dilaksanakan = satu transaksi**: kedua kamar dikunci (urutan id), penempatan lama `SELESAI` (alasan Mutasi, tanggal hari ini), penempatan baru `AKTIF` di tujuan (masuk hari ini), bed/loker lama lepas. Semua aturan §5.3 diulang di tujuan (aktif, jenis kelamin, kelayakan santri di asrama tujuan, slot santri, kelebihan muatan, bed/loker). Gagal → tidak ada yang berubah; pengajuan tetap Diajukan.
2. **Hanya santri** (penempatan kategori Penghuni) yang Aktif. Pengawas pindah kamar lewat check-out + Tempatkan [ASUMSI].
3. **Lintas asrama boleh** [OWNER, 2026-11-15]. Pengaju, penyetuju, dan pelaku darurat butuh izinnya di node pemilik asrama **asal dan tujuan**; santri harus layak di asrama tujuan (OD-DM-02). Wali kamar hanya bisa memilih tujuan di asrama dengan pemilik yang sama dengan asal [ASUMSI].
4. **Biasa**: diajukan oleh pemilik `mutations.request` atau **wali kamar aktif** kamar asal (dari Kamar saya). Tujuan langsung dicek saat diajukan. Disetujui oleh pemilik `mutations.approve` yang **bukan pengaju** (memilih bed/loker tujuan; kosong = saran otomatis). Tolak wajib alasan. Pengaju bisa menarik (Dibatalkan).
5. **Darurat**: pemilik `mutations.emergency`; kategori + alasan ≥ 10 karakter; langsung dilaksanakan dengan aturan yang sama (bukan jalan pintas kapasitas); bed/loker tujuan = saran otomatis (bisa diganti sesudahnya lewat Ganti bed/loker).
6. **Tinjauan darurat**: pemilik `mutations.approve` (asal & tujuan) yang **bukan pelakunya** [OWNER, 2026-11-15] menandai Sudah ditinjau (catatan opsional).
7. **Gugur otomatis**: pengajuan terbuka menjadi Dibatalkan dengan keterangan sistem bila penempatan asal berakhir (check-out atau mutasi darurat).
8. Audit: `dormitory.mutation_requested|approved|rejected|cancelled|emergency|reviewed` (santri, kamar asal & tujuan, jenis, kategori; tanpa isi alasan).

### 18.3 Halaman

- **Detail kamar → tab Penghuni**: aksi **Mutasi** pada santri Aktif (pop-up: kamar tujuan yang cocok beserta sisa slot santri, alasan; pilihan **Darurat** + kategori bila berizin). Santri yang punya pengajuan terbuka diberi tanda "Mutasi diajukan ke …".
- **Kamar saya**: **Ajukan mutasi** per santri (biasa saja).
- **Asrama → Mutasi** (`/asrama/mutasi`): tab **Menunggu persetujuan** (Setujui dengan pilih bed/loker · Tolak · Tarik), **Darurat** (belum ditinjau di atas dan bertanda merah, ≤ 24 jam ditonjolkan; Sudah ditinjau), **Riwayat** (semua). Menu tampil bagi pemilik salah satu izin mutasi.
- **Beranda**: kartu "Pengajuan mutasi menunggu" dan "Mutasi darurat belum ditinjau" (merah) bagi penyetuju. Kartu kelebihan muatan & perlu ditinjau menyusul (DM-5/DM-6) [ASUMSI].

### 18.4 Test (awalan `dmm`)

Atomik (tujuan penuh → tidak berubah), aturan tujuan (jenis kelamin, kelayakan lintas asrama, slot, kamar sama), pengaju ≠ penyetuju, satu pengajuan terbuka, tarik, tolak, gugur otomatis saat check-out & darurat, darurat + tinjauan (bukan pelaku), wali kamar hanya kamarnya & pemilik sama, hak akses asal/tujuan (404/403), kartu Beranda, constraint database.

### 18.5 Hasil pembangunan DM-4 (2026-11-15)

Dibangun sesuai §18.1–§18.4, dengan catatan:

- **Pesan "kamar penuh"** saat disetujui/darurat tampil sebagai kesalahan umum pop-up (bukan di isian kamar tujuan), sama dengan pesan penempatan DM-2.
- **Pilihan kamar tujuan** dimuat saat pop-up dibuka: kamar aktif dengan jenis kelamin cocok di asrama yang boleh dipilih (cakupan izin pengaju/pelaku; wali kamar: pemilik sama) dan tempat santri layak. Kamar penuh/kelebihan muatan tetap tampil tetapi tidak bisa dipilih.
- **Tanggal mutasi** = hari ini (zona `EDUCORE_TIMEZONE`); tidak bisa diisi mundur.
- Pengguna yang punya izin biasa **dan** darurat memilih jenisnya di pop-up; bila santri sudah punya pengajuan terbuka, hanya Darurat yang bisa dipilih (pengajuan itu lalu gugur).
- Kode: migrasi `2026_11_15_400004_create_dm_mutations_table`; `Domain/Mutation/{Mutation, MutationType, MutationStatus, EmergencyCategory, Exceptions/MutationException}`; `Application/Mutation/{ManageMutations, MutationDirectory, OpenMutations}`; `ManagePlacements::move()` (dipakai biasa & darurat, mode periksa saja saat diajukan); `MutationController`; kartu Beranda `dormitory.mutations_pending` & `dormitory.emergency_unreviewed`.
- Route: `asrama/mutasi` (GET), `asrama/penempatan/{placement}/tujuan-mutasi` (GET, throttle 60/menit), `asrama/penempatan/{placement}/mutasi` & `/mutasi-darurat` (POST), `asrama/mutasi/{mutation}/setujui|tolak|tarik|tinjau` (POST).
- Test: `tests/Feature/Dormitory/MutationTest.php` (awalan `dmm`, 7 test).

## 19. DM-5a Perlu ditinjau, akhir tahun, tempatkan banyak [ASUMSI disetujui owner 2026-11-15]

DM-5 dipecah [OWNER, 2026-11-15]: **DM-5a** (bagian ini) dan **DM-5b** impor penempatan .xlsx (pola impor EduCore, OD-DM-07; dirinci nanti).

### 19.1 Perlu ditinjau (§5.6, OD-DM-06)

- Santri **Aktif/Direncanakan** yang tidak punya pendaftaran terbuka di lembaga di bawah pemilik asramanya → **Perlu ditinjau**. Dihitung saat dibaca (`ResidentReview`), tidak disimpan [OWNER]. Tidak ada yang dikeluarkan otomatis.
- Tampil: tanda di baris penghuni (halaman kamar, Penghuni, detail asrama, Kamar saya, riwayat orang), saringan **Hanya yang perlu ditinjau** (`?tinjau=1`) di menu Penghuni, kartu Beranda **Penghuni perlu ditinjau** (pemilik `residents.manage`). Pop-up Check-out santri bertanda memilih alasan **Keluar/lulus lembaga** sebagai bawaan.
- Kartu Beranda **Kamar kelebihan muatan** (§10) ikut dibangun untuk pemilik `residents.manage`.

### 19.2 Proses akhir tahun (§9)

- Halaman `/asrama/{id}/akhir-tahun` (tombol di tab Pengaturan detail asrama; izin `residents.manage` di pemilik): **1)** pilih tahun ajaran yang **sudah berakhir** (tanggal akhir ≤ hari ini) dari kalender lembaga di bawah pemilik (nama sama digabung, tanggal akhir paling akhir dipakai); **2)** pratinjau per kamar: kebijakan, jumlah diakhiri/tetap/perlu ditinjau; **3)** **Jalankan** (konfirmasi).
- **Kosongkan**: penempatan Aktif **santri** yang masuk paling lambat akhir tahun ajaran → Selesai, alasan Akhir tahun, **tanggal keluar = tanggal akhir tahun ajaran** [OWNER, 2026-11-15]; bed/loker lepas; pengajuan mutasi terbuka gugur. **Tetap**: tidak diubah (yang tidak layak terhitung Perlu ditinjau). Pengawas, rencana, dan santri yang masuk sesudah akhir tahun ajaran tidak tersentuh.
- Satu transaksi (kunci asrama + kamar), semua atau tidak. **Sekali per asrama per tahun ajaran**: `dm_year_end_runs` (tahun, tanggal akhir, jumlah diakhiri/tetap/ditinjau, pelaku, waktu; unique asrama + tahun). Ringkasan tampil di tab Pengaturan. Audit `dormitory.year_end_run`.

### 19.3 Tempatkan banyak (§12 DM-5)

- Pop-up Tempatkan → **Banyak santri**: cari & tambahkan (maks. 50), Langsung tinggal / Rencana, tanggal, catatan. Bed/loker dipilih otomatis berurutan. **Semua atau tidak**: satu ditolak → tidak ada yang disimpan; pesan diawali nama santrinya bila belum menyebutnya. Tiap santri tercatat di audit seperti penempatan biasa.
- Route `POST asrama/kamar/{room}/penghuni-banyak` (izin `residents.manage`).

### 19.4 Hasil pembangunan (2026-11-15)

- Kode: `Application/Placement/ResidentReview`, `ManagePlacements::placeMany()`, `Application/YearEnd/YearEndProcess`, `Domain/YearEnd/YearEndException`, `YearEndController`; migrasi `2026_11_15_400005_create_dm_year_end_runs_table`; kartu Beranda `dormitory.residents_review` & `dormitory.rooms_over_capacity`. Tanpa permission baru.
- Halaman: `pages/dormitory/year-end.tsx`; tab Pengaturan detail asrama (ringkasan & tombol); pop-up Tempatkan (Satu/Banyak santri); saringan Penghuni.
- Test: `tests/Feature/Dormitory/YearEndTest.php` (awalan `dmy`, 4 test: perlu ditinjau, akhir tahun, hak akses + constraint, tempatkan banyak).
