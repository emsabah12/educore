# PRD-002 — Modul Academic (Akademik)

- **Versi:** 2.0 (2026-10-24: rincian & pembangunan AC-6b-2 — tugas pengganti, presensi siswa oleh pengganti, catatan otomatis, §20.3)
- **Versi sebelumnya:** 1.9 (AC-1g-b — kolom NIK di impor siswa), 1.8 (AC-1g-a — NIK siswa & orang tua/wali di form), 1.7 (OD-AC-02 direvisi — NIK siswa & orang tua/wali disimpan, opsional), 1.6 (rincian AC-6 Presensi mengajar & guru pengganti — §20; rencana lama AC-6 jadi AC-7), 1.5 (rincian AC-5 Penilaian & rapor, §19), 1.4 (penyempurnaan P1–P4, §18), 1.3 (rincian AC-4, §17), 1.2 (rincian AC-3, §16; §7 akses wali kelas/guru), 1.1 (rincian AC-2, §15), 1.0 (rincian AC-1e impor siswa), 0.9 (rincian AC-1d-b anggota kelas), 0.8 (AC-1d dipecah d-a/d-b; aturan tahun ajaran lewat & tingkat kelas), 0.7 (rincian AC-1c-b2; kontrak HR `EmployeeLookup`), 0.6 (AC-1c-b dipecah b1/b2; ubah pendaftaran; pencarian orang tua lewat HP), 0.5 (AC-1c dipecah AC-1c-a/b; siswa alumni per lembaga; OD-AC-09 ubah data diri), 0.4 (rincian AC-1b), 0.3 (rincian teknis AC-1a), 0.2 (owner menyetujui semua usulan v0.1; OD-AC-04 s.d. OD-AC-07 diputuskan), 0.1 (draf pertama)
- **Status:** **AC-1 SELESAI (2026-10-20)** — AC-1a `ccc183b`, AC-1b & AC-1c-a `759ac3d`, AC-1c-b1 `1b572e8`, AC-1c-b2 `dd5744d`, AC-1d-a `0fb2643`, AC-1d-b `c7f7dd8`, AC-1e `e09a3f3` + `1938694`; **AC-2 SELESAI (2026-10-21, commit `ece7aa6`)**; **AC-3 SELESAI (2026-10-21)** — AC-3a `9a0b128`, AC-3b `26d3594`, AC-3c `2e58909`, AC-3d `6878d84` (§16); **AC-4 SELESAI (2026-10-21)** — AC-4a `5fceb18`, AC-4b `887a384`, AC-4c `98a3aac` (§17); **Penyempurnaan P1–P4 SELESAI (2026-10-22, §18)** — P1 `3027bb4`, P2 `1d076ba`, P3 `84a5053`, P4a `b7683df`, P4b `e00cad0`; **AC-5 SELESAI (2026-10-22, §19)** — AC-5a `eb9a8a8`, AC-5b `1842b9a`, AC-5c `8f7c63e`, AC-5d `d233839`; **AC-6 dirinci & disetujui (§20, 2026-10-23)**: AC-6a SELESAI (`a69c50a`), AC-6b-1 SELESAI (`443a98d`), AC-6b-2 dibangun 2026-10-24; **AC-1g SELESAI (2026-10-24, commit `6360f6a`)**; AC-7 dst. hanya digariskan
- **Bergantung pada:** PRD-000 (pondasi, F5 Kelola akses SELESAI), PRD-001 (HR-1 SELESAI — sumber data wali kelas), ADR-001
- **Referensi domain lama:** `architecture/current-architecture.md` §9–§11 (Student, Guardian, Teacher), ADR-013 (identitas manusia) di `docs.zip`

Label: **[LAMA]** = dari dokumen lama · **[OWNER]** = keputusan owner · **[ASUMSI]** = usulan baru, perlu konfirmasi.

---

## 1. Tujuan

Setiap lembaga (SMP, MTs, MA, SMK, MDA, Bahasa, Al-Qur'an) punya **data siswa/santri yang rapi dan satu sumber**: siapa siswanya, terdaftar di lembaga mana, di kelas apa pada tahun ajaran berjalan, dan siapa orang tua/walinya. Pimpinan dan koordinator melihat siswa di cakupannya tanpa melihat lembaga lain.

Prinsip:

1. Mengikuti pondasi: data disaring per pohon lembaga (PRD-000 §7), tercatat di audit log, katalog akses di kode.
2. **Satu orang, banyak pendaftaran.** Siswa SMP Unit 3 yang sore harinya santri MDA Unit 3 adalah **satu orang** dengan **dua pendaftaran** (PRD-000 §12).
3. Dibangun bertahap. PRD ini merinci **AC-1 Data Siswa & Kelas** [OWNER, OD-AC-01]. Tahap berikutnya hanya digariskan (§3).
4. Polanya sama dengan HR-1 supaya mudah dipelajari dan dirawat: Person → Membership → profil modul → riwayat bertanggal.

## 2. Pengguna

| Peran                              | Kebutuhan di AC-1                                                                              | Sumber            |
| ---------------------------------- | ---------------------------------------------------------------------------------------------- | ----------------- |
| **Operator Akademik** (role baru)  | Menambah siswa, mendaftarkan ke lembaga, mengatur kelas & anggota kelas, mencatat lulus/pindah | [OWNER, OD-AC-04] |
| Admin Yayasan                      | Semua hak Academic di seluruh yayasan; mengatur kalender akademik yayasan                      | [ASUMSI]          |
| Pimpinan Yayasan / Pimpinan Ponpes | Melihat siswa & kelas di cakupannya                                                            | [ASUMSI]          |
| Kepala Lembaga / Kepala Unit       | Melihat siswa & kelas di lembaganya beserta turunan                                            | [ASUMSI]          |
| Koordinator (mis. MDA)             | Melihat siswa & kelas di lembaga binaannya; boleh menetapkan kalender untuk jenjangnya (§4.4)  | [ASUMSI]          |
| Wali kelas / guru                  | Belum ada fitur khusus di AC-1; melihat siswa kelasnya sendiri menyusul di AC-3                | [OWNER, OD-AC-06] |
| Orang tua / wali                   | Belum login. Datanya dicatat sebagai kontak (§4.5)                                             | [OWNER]           |

## 3. Tahapan modul Academic [OWNER, 2026-10-17]

| Tahap                                       | Isi                                                                                                                                                  | Catatan                                     |
| ------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| **AC-1 Data Siswa & Kelas**                 | Kalender akademik (tahun ajaran, semester), siswa, pendaftaran per lembaga, orang tua/wali, kelas/rombel, anggota kelas, wali kelas, daftar & detail | **Dirinci di PRD ini** [OWNER, OD-AC-01]    |
| AC-1e Impor siswa                           | Impor siswa baru dari Excel/CSV, pola sama dengan HR-1e                                                                                              | Sesudah AC-1 [OWNER, OD-AC-07]              |
| AC-2 Kenaikan kelas & kelulusan             | Proses akhir tahun ajaran: naik kelas massal, tinggal kelas, lulus massal                                                                            | Dirinci di §15 [OWNER, 2026-10-20]          |
| AC-3 Mata pelajaran & jadwal                | Mapel per lembaga/tingkat, guru pengampu (pegawai HR), jadwal; wali kelas/guru melihat siswa kelasnya                                                | [OWNER, OD-AC-06]; dirinci di §16           |
| AC-4 Presensi siswa                         | Presensi harian/per pertemuan                                                                                                                        | Dirinci di §17 [OWNER, 2026-10-21]          |
| AC-5 Penilaian & rapor                      | Nilai, KKM (aturan berjenjang PRD-000 §8), rapor                                                                                                     | [LAMA §11: guru = Employee]; dirinci di §19 |
| **AC-6 Presensi mengajar & guru pengganti** | Presensi mengajar per pertemuan di tiap lembaga, jadwal piket, guru pengganti saat guru cuti/tidak hadir, rekap                                      | Dirinci di §20 [OWNER, 2026-10-23]          |
| AC-7 dst.                                   | Akun orang tua, ekspor Dapodik/EMIS (butuh NIK), PPDB, asrama                                                                                        |                                             |

## 4. Model data AC-1

### 4.1 Konsep

```text
Person ──► Membership (orang × yayasan)
              └──► Siswa (ac_students)                     satu per membership; menyimpan NISN
                     └──► Pendaftaran (ac_enrollments)      "terdaftar di Lembaga X"
                            │                               boleh lebih dari satu lembaga sekaligus
                            └──► Anggota kelas (ac_class_members) ──► Kelas (ac_classes)
                                                                     └──► Tahun ajaran (ac_academic_years)

Person (orang tua/wali) ──► Membership ──► ac_student_guardians ◄── Siswa
```

- Nama, jenis kelamin, dan data pribadi tetap di **Person** (sama seperti pegawai). Academic tidak menyimpan nama sendiri. [LAMA ADR-013]
- Siswa **tidak otomatis punya akun login**. [LAMA §9]
- **Status siswa ada di pendaftaran, bukan di profil.** Orang yang lulus SMP tetapi masih santri MDA tetap "aktif" di MDA. Ini menjawab catatan PRD-000 §12: profil siswa hanya satu per orang dan isinya NISN saja; semua hal per lembaga ada di pendaftaran. [ASUMSI]
- Satu orang boleh sekaligus pegawai dan siswa (mis. ustadz yang juga belajar di program Bahasa) karena keduanya profil terpisah di atas Membership yang sama. [ASUMSI]

### 4.2 Tabel

Semua tabel memakai UUIDv7, `tenant_id` dengan FK gabungan per yayasan, dan prefiks `ac_`. [ASUMSI]

**`ac_students`** — profil siswa

| Field         | Aturan                                                                                     |
| ------------- | ------------------------------------------------------------------------------------------ |
| membership_id | Wajib, unik (satu profil siswa per orang per yayasan)                                      |
| nisn          | Opsional; tepat **10 digit**; unik per yayasan bila diisi [OWNER, OD-AC-02; format ASUMSI] |

**`ac_enrollments`** — pendaftaran di lembaga

| Field           | Aturan                                                                                                                                                                                                                                                                           |
| --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| student_id      | Wajib                                                                                                                                                                                                                                                                            |
| organization_id | Wajib; node bertipe **LEMBAGA**, aktif, di yayasan yang sama (bukan Unit/Biro)                                                                                                                                                                                                   |
| student_number  | Nomor induk siswa di lembaga itu (NIS/nomor induk santri); opsional; unik **per lembaga** bila diisi (database menjaga di antara pendaftaran terbuka; service menolak nomor yang pernah dipakai siswa lain di lembaga itu) [ASUMSI]; huruf besar, angka, `.`, `/`, `-`, maks. 30 |
| start_date      | Tanggal masuk; wajib; tidak di masa depan                                                                                                                                                                                                                                        |
| entry_type      | `BARU` (siswa baru) \| `PINDAHAN` (dari sekolah lain)                                                                                                                                                                                                                            |
| end_date        | Kosong = masih terdaftar; ≥ start_date                                                                                                                                                                                                                                           |
| end_reason      | Wajib saat ditutup: `LULUS`, `PINDAH`, `MENGUNDURKAN_DIRI`, `DIKELUARKAN`, `MENINGGAL`, `LAINNYA` (+ catatan)                                                                                                                                                                    |

- Maksimal **satu pendaftaran terbuka per siswa per lembaga** (partial unique index). Lembaga berbeda boleh bersamaan (SMP + MDA).
- Daftar ulang setelah keluar = pendaftaran baru; riwayat lama tetap.

**`ac_academic_calendars`**, **`ac_academic_years`**, **`ac_terms`** — kalender akademik (§4.4)

| Tabel                   | Field utama                                                                     | Aturan                                                                                            |
| ----------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `ac_academic_calendars` | name, is_default                                                                | Satu kalender **default** per yayasan (dibuat otomatis); kalender lain opsional                   |
| `ac_academic_years`     | calendar_id, name (mis. `2026/2027`), start_date, end_date, is_current          | Tidak tumpang tindih dalam satu kalender; maksimal satu `is_current` per kalender                 |
| `ac_terms`              | academic_year_id, name (mis. `Semester Ganjil`), sequence, start_date, end_date | 1–4 per tahun ajaran (semester, catur wulan); di dalam rentang tahun ajaran, tidak tumpang tindih |

**`ac_classes`** — kelas / rombongan belajar

| Field                | Aturan                                                                                                                 |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| organization_id      | Wajib; lembaga aktif                                                                                                   |
| academic_year_id     | Wajib; tahun ajaran dari kalender yang **berlaku** untuk lembaga itu (§4.4)                                            |
| grade                | Tingkat, angka 1–12 (SMP/MTs 7–9, MA/SMK 10–12, MDA Ula 1–4, dst.)                                                     |
| name                 | Nama rombel, mis. `VII-A`, `Ula 2 Putri`; wajib, maks. 50; unik per lembaga per tahun ajaran                           |
| homeroom_employee_id | Wali kelas; opsional; **pegawai aktif yang punya penempatan berjalan di lembaga itu** (dibaca lewat kontrak publik HR) |
| is_active            | Tidak dihapus bila sudah punya anggota; nonaktif = tidak bisa diisi siswa baru                                         |

**`ac_class_members`** — anggota kelas

| Field                 | Aturan                                                                                  |
| --------------------- | --------------------------------------------------------------------------------------- |
| class_id              | Wajib                                                                                   |
| enrollment_id         | Wajib; pendaftaran **terbuka** di lembaga yang sama dengan kelas                        |
| start_date / end_date | Kosong = masih di kelas ini; pindah kelas = tutup lama + buka baru dalam satu transaksi |

- Satu pendaftaran hanya boleh berada di **satu kelas terbuka per tahun ajaran**.
- Pendaftaran ditutup (lulus/pindah/…) → keanggotaan kelas yang terbuka ikut ditutup di tanggal yang sama.

### 4.3 Invarian

1. Tambah siswa baru = Person + Membership + Siswa + pendaftaran pertama (+ kelas bila dipilih), **dalam satu transaksi**. Bila satu langkah gagal, semuanya batal. (Pola HR-1 §4.4.)
2. Menutup pendaftaran **tidak** menghapus Person, Membership, akun, profil pegawai, maupun pendaftaran di lembaga lain.
3. Tidak ada hapus permanen untuk siswa, pendaftaran, dan anggota kelas. Salah input diperbaiki lewat ubah; tercatat di audit.
4. Kelas dan siswanya selalu di lembaga yang sama; database menjaga lewat FK gabungan bila memungkinkan, sisanya diperiksa di service.

### 4.4 Tahun ajaran: default yayasan, lembaga boleh beda [OWNER, OD-AC-03]

Mekanisme [ASUMSI]:

- Tiap yayasan punya **Kalender Yayasan** (default). Admin Yayasan boleh membuat kalender lain, mis. **Kalender MDA** bila MDA memakai periode berbeda.
- Kalender yang dipakai sebuah lembaga ditentukan oleh **aturan berjenjang** `academic.calendar` (PRD-000 §8, sudah ada): kosong = Kalender Yayasan. Admin Yayasan atau Koordinator MDA bisa menetapkan "Kalender MDA" untuk **jenjang MDA**, dan Yayasan bisa **mengunci**-nya.
- Keuntungan: tidak ada mekanisme baru; urutan "terdekat menang, kecuali dikunci" sudah teruji.
- **Tahun ajaran berjalan** sebuah lembaga = tahun `is_current` di kalender yang berlaku untuknya. Halaman kelas dan siswa menampilkan tahun ini secara default.
- Kalender, tahun ajaran, dan semester hanya dikelola di lembaga kerja **Seluruh Yayasan** (seperti Katalog SDM).

Rincian AC-1a [disetujui owner 2026-10-17]:

- **Kalender Yayasan** dibuat otomatis (seeder, dan saat halaman Kalender akademik pertama kali dibuka). Kalender tidak dihapus dan tidak dinonaktifkan di AC-1; namanya boleh diubah.
- **Tahun ajaran** dibuat bersama semesternya dalam satu form: nama `YYYY/YYYY` (tahun kedua = tahun pertama + 1), tanggal mulai & selesai (maks. 18 bulan), 1–4 semester berurutan yang tidak tumpang tindih dan berada di dalam rentang tahun ajaran. Nama semester bebas (mis. `Semester Ganjil`, `Caturwulan 1`). Tahun ajaran & semester boleh diubah; tidak dihapus.
- **Jadikan tahun berjalan**: memindahkan tanda berjalan di kalender itu (tetap maksimal satu). Tidak otomatis berganti menurut tanggal.
- **Pemakaian kalender**: di halaman Kalender akademik, Admin Yayasan menetapkan kalender untuk **jenjang** tertentu (mis. semua MDA → Kalender MDA) atau untuk **lembaga** tertentu, opsional dikunci. Disimpan sebagai aturan `academic.calendar` lewat mekanisme aturan berjenjang (PRD-000 §8, termasuk audit nilai lama & baru). Halaman yang sama menampilkan tabel ringkas: tiap lembaga aktif → kalender yang berlaku & tahun ajaran berjalannya.
- Halaman umum untuk Kepala Lembaga/Koordinator mengubah aturan berjenjang belum ada; sampai ada, pemakaian kalender diatur Admin Yayasan. [ASUMSI]

Keputusan teknis AC-1a [ASUMSI, 2026-10-17]:

- Nama tahun ajaran juga boleh tahun Hijriah (mis. `1447/1448`); tanggal mulai tidak harus di tahun yang tertulis di nama.
- Tahun ajaran **pertama** di sebuah kalender otomatis menjadi tahun berjalan.
- Mengubah jumlah semester: semester yang ada diperbarui sesuai urutan, kelebihannya dihapus. Kelak (nilai/rapor) foreign key akan menolak penghapusan semester yang sudah dipakai.
- Pemakaian kalender tidak bisa "dihapus"; untuk kembali, tetapkan target itu ke Kalender Yayasan.
- Aturan pemakaian di tingkat Yayasan tanpa jenjang tidak dipakai (sama dengan Kalender Yayasan).
- **Contoh tahun ajaran** (Juli–Juni, dua semester) hanya dibuat seeder data uji (local/testing). Di production, Admin Yayasan mengisi tahun ajaran sendiri sesuai keputusan yayasan.
- Anggota kelas menyimpan `organization_id` & `academic_year_id` kelasnya sehingga **database** menjamin kelas & pendaftaran di lembaga yang sama dan satu kelas terbuka per tahun ajaran (diuji langsung di PostgreSQL 16).

### 4.5 Data pribadi siswa & orang tua/wali [OWNER, OD-AC-02]

Prinsip yang sama dengan pegawai (PRD-001 §4.5): **minimal**, dengan tambahan NISN dan kontak orang tua/wali. **Revisi 2026-10-23 [OWNER]:** NIK disimpan sebagai penanda unik orang (lihat "NIK" di bawah).

| Data                                    | Tempat                | Aturan                                       |
| --------------------------------------- | --------------------- | -------------------------------------------- |
| name, gender                            | `persons`             | **Nama dan jenis kelamin wajib** untuk siswa |
| birth_place, birth_date, phone, address | `persons` (sudah ada) | Opsional, aturan sama dengan pegawai         |
| nisn                                    | `ac_students`         | §4.2                                         |
| student_number                          | `ac_enrollments`      | §4.2                                         |

**Orang tua/wali** dicatat sebagai **Person + Membership** (bukan teks bebas di siswa) [OWNER, OD-AC-05]:

- Alasannya: kakak-adik berbagi orang tua yang sama tanpa input ulang, dan akun orang tua (AC-7) kelak tinggal ditambahkan pada Person yang sama. [LAMA §9–§10]
- Isian: nama (wajib), jenis kelamin (opsional), nomor HP (opsional), hubungan.
- **`ac_student_guardians`**: student_id, guardian_membership_id, relation `AYAH` \| `IBU` \| `WALI` \| `LAINNYA`, is_primary_contact. Satu siswa maksimal 4 orang tua/wali; maksimal satu kontak utama; orang yang sama tidak dobel untuk satu siswa.
- Membuat orang tua/wali **tidak** membuat akun login dan **tidak** memberi akses apa pun.
- Pekerjaan, penghasilan, pendidikan orang tua, dan No. KK **tidak** disimpan di AC-1 (dibutuhkan ekspor Dapodik/EMIS kelak).
- **NIK [OWNER, 2026-10-23]** — AC-1g-a (form) dan AC-1g-b (impor siswa) dibangun 2026-10-24: siswa dan orang tua/wali boleh punya NIK, disimpan lewat Core `person_identifiers` (terenkripsi, unik di seluruh EduCore; aturan sama dengan pegawai, PRD-001 §4.5). **Opsional** untuk siswa & orang tua [ASUMSI, disetujui 2026-10-23] karena data lama belum lengkap. Isian: form tambah/ubah siswa, form orang tua/wali, dan impor siswa. Orang tua dengan NIK yang sudah tercatat = **orang yang sama** (dipakai ulang, mis. kakak-adik), lebih pasti daripada pencocokan HP. NIK anak tampil tersamar dan hanya bagi yang boleh melihat data pribadi siswa; yayasan disarankan mencantumkannya di persetujuan pendaftaran (UU PDP).
    - Rincian AC-1g-a [ASUMSI, mengikuti aturan pegawai]:
        - **Tambah siswa**: NIK siswa opsional. Bila sudah milik anggota yayasan ini, pemiliknya ikut di kotak "mungkin orang yang sama" (bertanda "NIK sama", walau namanya beda) dan simpan ditolak sampai operator memilih orang itu atau memperbaiki NIK — pilihan "Orang berbeda" tidak berlaku untuk NIK yang sama. Milik orang di yayasan lain → pesan umum tanpa nama. Orang lama dengan nama sama yang sudah ber-NIK (pasti beda) hanya menjadi calon bila tanggal lahirnya juga sama.
        - **Memilih orang yang sama**: NIK yang diisi melengkapi NIK kosong; bila berbeda dari yang tercatat, ditolak (sama dengan aturan NISN).
        - **Orang tua/wali (form tambah siswa & tambah di detail)**: NIK opsional. Urutan pencocokan: NIK tercatat di yayasan ini → orang itu dipakai ulang (nama/HP lama tidak ditimpa); nama + HP sama dan orang lama belum ber-NIK → dipakai ulang dan NIK-nya dilengkapi; orang lama ber-NIK lain → orang baru. NIK milik orang di yayasan lain → ditolak di isiannya.
        - **Ubah data diri siswa**: NIK lengkap tampil di form; boleh dilengkapi/diperbaiki, tidak bisa dikosongkan. Detail siswa: NIK tersamar + peringatan bila digit tanggal lahir/jenis kelamin di NIK tidak cocok.
        - **Ubah orang tua/wali**: NIK lengkap tidak dikirim ke browser; kolom kosong = tetap, diisi = melengkapi/memperbaiki. Orang yang juga pegawai/siswa: NIK-nya diubah dari menu masing-masing. Daftar orang tua/wali di detail menampilkan NIK tersamar.
        - Audit hanya mencatat nama field `nik`.
    - Rincian AC-1g-b (impor siswa) [ASUMSI, mengikuti aturan NISN & form]:
        - Kolom baru (semua opsional, format Teks): `nik` (setelah `nisn`), `nik_ayah`, `nik_ibu`, `nik_wali` (setelah HP masing-masing).
        - **NIK siswa**: milik anggota yayasan dengan **nama sama** → orang itu, hanya didaftarkan ke lembaga ini (mode "LAMA", sama dengan kecocokan NISN); nama lain → Salah (nama pemilik tidak disebut); milik orang di luar yayasan → Salah (pesan umum); sudah terdaftar aktif di lembaga ini → Salah; NISN baris berbeda dari NISN orang itu → Salah. Siswa lama lewat NISN: NIK harus milik orang itu atau orang itu belum ber-NIK.
        - **NIK ayah/ibu/wali**: milik anggota yayasan → orang itu dipakai ulang saat simpan; milik orang di luar yayasan → Salah; sama dengan NIK siswa atau orang tua lain di baris yang sama → Salah; NIK tanpa nama → Salah.
        - Salah juga: NIK bukan 16 angka, tersimpan sebagai angka Excel, NIK siswa dobel di file. Peringatan: NIK siswa tidak cocok dengan tanggal lahir/jenis kelamin.
        - Peringatan nama sama dilewati bila kedua orang ber-NIK dan tanggal lahirnya beda.

Siswa umumnya **anak di bawah umur**, maka:

- Tempat/tanggal lahir, HP, alamat siswa, serta seluruh data orang tua/wali hanya tampil bagi pemilik `academic.students.view_personal`.
- Audit log hanya mencatat **nama field** yang berubah, bukan nilainya.
- Data pribadi tidak masuk log aplikasi maupun pesan error.

**Mencegah orang ganda**: sama dengan HR [OWNER, 2026-10-17] — peringatan bila **nama sama** dengan anggota yayasan ini (tanggal lahir orang lama ditampilkan), admin boleh lanjut dengan konfirmasi. Untuk siswa, NISN yang sama **ditolak** (bukan peringatan). Saat memilih orang tua/wali, form menawarkan orang tua yang sudah ada (pencarian nama + nomor HP) sebelum membuat yang baru. [ASUMSI]

Rincian AC-1b [OWNER, 2026-10-17 — usulan A, B, C disetujui]:

- **A. Orang yang sama** (mis. santri MDA yang mendaftar SMP): form **tidak** menampilkan daftar seluruh anggota yayasan. Bila nama yang diketik sama (tanpa beda huruf besar/kecil & spasi ganda) dengan anggota aktif yayasan ini, muncul maks. 5 calon dengan keterangan peran Academic dan tanggal lahir, mis. _"Ahmad Fauzi — siswa MDA Unit 3, lahir 2013-04-12"_. Operator memilih **"Ini orang yang sama"** (hanya pendaftaran baru; profil siswa dibuat bila belum ada) atau **"Orang berbeda"** (orang baru). ID calon yang dikirim browser diperiksa ulang: wajib termasuk orang dengan nama itu.
- Untuk orang yang sama: nama **tidak** diubah; data pribadi yang diisi hanya **melengkapi yang masih kosong** (data yang dicatat lembaga lain tidak tertimpa); NISN yang diisi harus sama dengan yang tercatat, atau melengkapi bila masih kosong.
- **B. Orang tua yang sudah ada**: bila **nama dan nomor HP** sama persis dengan anggota yayasan, orang itu dipakai ulang (kakak-adik berbagi orang tua); pesan berhasil menyebutnya. Nama sama tetapi HP berbeda/kosong = orang baru. Pencarian & pemilihan orang tua secara manual di detail siswa (AC-1c).
- Kontak utama hanya dipasang bila siswa **belum** punya kontak utama, agar kontak yang dicatat lembaga lain tidak berubah diam-diam; orang tua yang sudah tertaut ke siswa itu tidak dibuat ulang. [ASUMSI]
- Gagal di langkah mana pun (mis. orang tua ditulis dua kali) → tidak ada yang tersimpan, termasuk orang & keanggotaan baru.
- **Pesan kesalahan jelas** [OWNER, 2026-10-18]: sebelum membuat orang baru, semua masalah dikumpulkan **sekaligus** dan ditandai di isiannya masing-masing (jenis kelamin kosong, NISN terpakai, nomor induk terpakai) bersama kotak peringatan nama sama. Bila NISN yang diisi milik salah satu calon nama sama, pesan NISN menunjuk ke kotak peringatan (kemungkinan orang yang sama) tanpa membuka data orang lain. Di atas tombol Simpan tampil ringkasan "Ada N isian yang perlu diperbaiki" beserta nama isiannya.
- **Pencarian orang tua lewat nomor HP** (AC-1c-b2) [OWNER, 2026-10-18]: nomor HP harus **cocok persis** (setelah dirapikan); hasilnya hanya nama dan keterangan "sudah tercatat sebagai orang tua/wali", **tanpa** nama anak-anaknya; hanya bagi yang berhak melihat data pribadi siswa.
- **C. Menu sementara**: menu **Siswa** (pemilik `academic.students.manage`) mengarah ke `/siswa/tambah`; setelah simpan, form kosong kembali dengan notifikasi. Daftar siswa menyusul di AC-1c. Pilihan kelas di form menyusul setelah kelas ada (AC-1d).

## 5. Siapa melihat apa

### 5.1 Permission baru (di modul Academic, disinkronkan lewat `educore:sync-access`) [ASUMSI]

| Permission                        | Isi                                                                                    |
| --------------------------------- | -------------------------------------------------------------------------------------- |
| `academic.students.view`          | Daftar & detail siswa: nama, NISN, nomor induk, lembaga, kelas, status, riwayat        |
| `academic.students.view_personal` | Data pribadi siswa + data orang tua/wali (§4.5)                                        |
| `academic.students.manage`        | Tambah siswa, ubah profil, pendaftaran (tambah/tutup), orang tua/wali                  |
| `academic.classes.manage`         | Buat/ubah/nonaktifkan kelas, wali kelas, masukkan/pindahkan/keluarkan siswa dari kelas |
| `academic.calendar.manage`        | Kalender, tahun ajaran, semester (hanya di tingkat Yayasan)                            |

Melihat kelas cukup dengan `academic.students.view`.

### 5.2 Role [OWNER, OD-AC-04]

| Role                                              | Permission Academic                                  | Lingkup                                       |
| ------------------------------------------------- | ---------------------------------------------------- | --------------------------------------------- |
| **Operator Akademik** (baru, `operator-akademik`) | students.view, view_personal, manage, classes.manage | Penugasan di lembaga (atau unit) + turunannya |
| Admin Yayasan                                     | kelimanya                                            | Seluruh yayasan                               |
| Pimpinan                                          | students.view + view_personal                        | Cakupan penugasannya                          |
| Kepala Lembaga                                    | students.view + view_personal                        | Lembaganya + turunan                          |
| Koordinator                                       | students.view                                        | Lembaga binaannya                             |
| Staf                                              | —                                                    | —                                             |

**Beda dengan HR**: data siswa dikelola **di tiap lembaga** oleh operator/TU lembaga itu, bukan satu pintu di pusat, karena siswa masuk dan keluar di lembaga dan jumlahnya jauh lebih banyak dari pegawai. Diputuskan owner (OD-AC-04).

Role **Operator Akademik** ditawarkan sebagai role penugasan di halaman Kelola akses (F5b) lewat `AccessCatalog::offerAsAssignmentRole`. Mengatur kalender (`academic.calendar.manage`) tetap hanya Admin Yayasan, sedangkan aturan `academic.calendar` per jenjang mengikuti hak aturan berjenjang yang sudah ada (PRD-000 §8.3).

### 5.3 Aturan penyaringan

Siswa terlihat bila **salah satu pendaftarannya** ada di `visibleNodeIds('academic.students.view')` dan di dalam lembaga kerja aktif (PRD-000 §7.2):

- Siswa **aktif**: pendaftaran yang masih terbuka.
- Siswa yang sudah **keluar/lulus**: pendaftaran yang sudah ditutup **tetap dihitung**, sehingga setiap lembaga melihat alumni/riwayat siswanya — termasuk siswa yang lulus MDA tetapi masih aktif di SMP (MDA tetap melihatnya sebagai alumni). [tafsiran 2026-10-18: aturan "pendaftaran terakhir" ala HR tidak cocok karena status siswa per lembaga, bukan per orang]
- **Status di daftar dihitung terhadap lembaga yang terlihat** (dan saringan lembaga): _Aktif_ = punya pendaftaran terbuka di sana; _Keluar/lulus_ = pernah terdaftar di sana tetapi tidak ada lagi yang terbuka. Admin Yayasan melihat status seluruh yayasan.
- Di detail, **semua** pendaftaran siswa yang terlihat ikut tampil (mis. Kepala SMP Unit 3 melihat bahwa siswanya juga santri MDA Unit 3). [ASUMSI]
- **Kelas** terlihat bila lembaganya di dalam cakupan.
- **Mengelola** siswa/kelas hanya pada lembaga di dalam cakupan izin kelola. Operator SMP tidak bisa menutup pendaftaran MDA siswa yang sama.

Saringan dijalankan di query database sebelum paginasi. Di luar cakupan → **404**; di dalam cakupan tanpa izin aksi → **403** (OD-13).

## 6. Halaman AC-1

Menu baru **Siswa** (`/siswa`) dan **Kelas** (`/kelas`) bagi pemilik `academic.students.view`; menu **Kalender akademik** (`/akademik/kalender`) bagi pemilik `academic.calendar.manage`, hanya di lembaga kerja Seluruh Yayasan. [ASUMSI]

| Halaman           | Isi                                                                                                                                                                                                                                       |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Kalender akademik | Daftar kalender; per kalender: tahun ajaran (tambah, ubah, jadikan berjalan) dan semesternya                                                                                                                                              |
| Daftar siswa      | Cari nama/NISN/nomor induk; filter lembaga (ikut turunan), kelas, tingkat, status (default: aktif), tahun ajaran; 25 per halaman; tabel di desktop, kartu di HP                                                                           |
| Tambah siswa      | **Siswa baru** atau **anggota yayasan yang sudah ada**; nama, L/P, data pribadi, NISN; pendaftaran pertama (lembaga, nomor induk, tanggal masuk, baru/pindahan); kelas (opsional); orang tua/wali (opsional, bisa memilih yang sudah ada) |
| Detail siswa      | Profil; pendaftaran per lembaga (aktif & riwayat); kelas per tahun ajaran; orang tua/wali                                                                                                                                                 |
| Aksi di detail    | Ubah profil, Daftarkan ke lembaga lain, Tutup pendaftaran (lulus/pindah/…), Pindah kelas, Tambah/ubah/lepas orang tua/wali                                                                                                                |
| Daftar kelas      | Per lembaga & tahun ajaran (default: tahun berjalan): nama rombel, tingkat, wali kelas, jumlah siswa (L/P)                                                                                                                                |
| Detail kelas      | Anggota kelas; **Masukkan siswa** (pilih banyak dari siswa lembaga itu yang belum punya kelas di tahun tersebut); keluarkan/pindahkan; ganti wali kelas; nonaktifkan                                                                      |

Semua halaman punya kondisi kosong, loading, dan error. Pesan validasi berbahasa Indonesia. Deretan tombol/tab memakai grid yang turun baris di HP (aturan UI proyek).

## 7. Batas modul

- Arah dependency: `Academic → Core, HR` (ADR-001 §2.2). Academic hanya memakai **kontrak publik HR** (arch test sudah ada).
- Kontrak HR `Modules\HR\Contracts\EmployeeLookup` (AC-1c-b2): membership mana yang punya profil pegawai; dipakai untuk mengunci data diri orang tua/wali yang juga pegawai. Tidak membuka data kepegawaian; AC-3d menambah `employeeIdForMembership` untuk menu Kelas saya.
- Kontrak HR baru [ASUMSI]: `Modules\HR\Contracts\ActiveEmployees` — daftar pegawai aktif yang punya penempatan berjalan di sebuah lembaga (id, nama) dan pemeriksaan "pegawai X aktif di lembaga Y". Dipakai untuk memilih dan memvalidasi wali kelas.
- **Jabatan "Wali Kelas" di HR tidak otomatis menjadi wali kelas** di Academic (jabatan bukan hak akses, PRD-001 §4.1). [LAMA]
- **Wali kelas & guru pengampu** hanya melihat kelas yang ia pegang di menu **Kelas saya** (AC-3d, §16). Hak ini tidak berlaku di menu Siswa/Kelas biasa dan berakhir saat penugasannya selesai atau ia tidak aktif lagi di lembaga itu. [OWNER, 2026-10-21 — memperbarui aturan lama "menjadi wali kelas tidak memberi hak akses apa pun"]
- Wali kelas yang berhenti/dimutasi tetap tercatat di kelas lama; kelas aktif menampilkan tanda "wali kelas tidak aktif lagi di lembaga ini" agar operator menggantinya. [ASUMSI]

## 8. Audit

Dicatat: kalender/tahun ajaran/semester dibuat/diubah/dijadikan berjalan; siswa dibuat; profil diubah (nama field saja); pendaftaran dibuka/ditutup; orang tua/wali ditambah/dilepas; kelas dibuat/diubah/nonaktif; wali kelas diganti; siswa masuk/pindah/keluar kelas. Label Bahasa Indonesia didaftarkan lewat `AuditAction::registerLabels()`.

## 9. Kebutuhan non-fungsional

| Area               | Target                                                                                                                             |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| Volume             | Ribuan s.d. puluhan ribu siswa per yayasan; daftar ≤ 500 ms p95 dengan index `(organization_id, end_date)` di pendaftaran [ASUMSI] |
| Paginasi           | 25 default, maks. 100 (PRD-000 §9)                                                                                                 |
| Data pribadi       | Tidak masuk log aplikasi, audit log, maupun pesan error; data anak & orang tua diperlakukan CONFIDENTIAL                           |
| Masuk kelas massal | Memasukkan 40 siswa ke kelas dalam satu simpan, satu transaksi                                                                     |

## 10. Uji wajib AC-1

| Skenario                                                            | Hasil                                                                             |
| ------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| Operator SMP Unit 3 menambah siswa baru + pendaftaran + kelas VII-A | Person, Membership, Siswa, pendaftaran, anggota kelas tercipta; tercatat di audit |
| Siswa SMP Unit 3 didaftarkan juga ke MDA Unit 3                     | Satu orang, dua pendaftaran; tidak ada Person baru                                |
| Kepala SMP Unit 3 membuka detail siswa tersebut                     | Melihat kedua pendaftaran; tidak bisa menutup pendaftaran MDA                     |
| Kepala MTs Unit 1 membuka siswa SMP Unit 3                          | 404                                                                               |
| Koordinator MDA membuka siswa MDA Unit 2 / siswa SMP                | Boleh / 404 (kecuali siswa itu juga terdaftar di MDA)                             |
| Pendaftaran SMP ditutup "LULUS"                                     | Kelas SMP ikut tertutup; pendaftaran MDA tetap aktif                              |
| Dua pendaftaran terbuka di lembaga yang sama / NISN dobel           | Ditolak dengan pesan ramah; database menolak                                      |
| Siswa dimasukkan ke dua kelas pada tahun ajaran yang sama           | Ditolak                                                                           |
| Wali kelas bukan pegawai aktif di lembaga itu                       | Ditolak                                                                           |
| Kakak-adik memakai orang tua yang sama                              | Satu Person orang tua, dua relasi                                                 |
| MDA memakai Kalender MDA lewat aturan berjenjang                    | Kelas MDA memakai tahun ajaran Kalender MDA; SMP tetap Kalender Yayasan           |
| Data pribadi siswa & orang tua                                      | Tidak tampil tanpa `view_personal`; tidak ada di audit log                        |
| Yayasan lain                                                        | 404                                                                               |

## 11. Rencana pengerjaan AC-1 [OWNER, 2026-10-17]

| Sub-tahap | Isi                                                                                                                                                                                     |
| --------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| AC-1a     | Skema semua tabel, permission & role Operator Akademik, kalender akademik + aturan `academic.calendar`, halaman Kalender akademik                                                       |
| AC-1b     | Tambah siswa (orang baru / anggota), pendaftaran pertama, orang tua/wali, peringatan orang ganda                                                                                        |
| AC-1c-a   | Daftar & detail siswa (penyaringan §5.3); menu **Siswa** → `/siswa`; setelah tambah siswa langsung ke detail                                                                            |
| AC-1c-b1  | Aksi di detail: ubah data diri (OD-AC-09), daftarkan ke lembaga lain, **ubah pendaftaran** (nomor induk, tanggal masuk, jenis masuk), tutup pendaftaran [OWNER, 2026-10-18]             |
| AC-1c-b2  | Orang tua/wali di detail: tambah (baru atau cari lewat nomor HP), ubah, lepas, ganti kontak utama                                                                                       |
| AC-1d-a   | Kontrak HR `ActiveEmployees`, menu **Kelas** (`/kelas`): daftar per lembaga & tahun ajaran, tambah/ubah kelas, wali kelas, nonaktifkan/aktifkan, hapus kelas kosong [OWNER, 2026-10-20] |
| AC-1d-b   | Detail kelas, masukkan siswa massal, pindah/keluarkan, kelas di detail siswa, pilihan kelas di form tambah siswa [OWNER, 2026-10-20]                                                    |

Rincian AC-1c-b1 [OWNER, 2026-10-18 — usulan disetujui]:

- **Ubah data diri** (nama, L/P, tempat/tanggal lahir, HP, alamat, NISN): Operator Akademik di lembaga mana pun tempat siswa **aktif** + Admin Yayasan (OD-AC-09), dan wajib berhak melihat data pribadi. Kolom yang dikosongkan = datanya dihapus. Data diri milik Person, jadi perubahan nama ikut berlaku di data pegawai/akun login bila orang itu juga punya. Audit hanya nama field.
- **Daftarkan ke lembaga lain**: lembaga aktif di cakupan izin kelola yang belum mencatat siswa sebagai siswa aktif; daftar ulang setelah keluar = pendaftaran baru, riwayat lama tetap. Nomor induk yang sama boleh dipakai lagi oleh siswa yang sama.
- **Ubah pendaftaran**: memperbaiki nomor induk, tanggal masuk, jenis masuk, baik pendaftaran berjalan maupun riwayat; tanggal masuk tidak boleh sesudah tanggal keluar atau sesudah siswa masuk kelas pertamanya. Hanya di lembaga yang dikelola.
- **Tutup pendaftaran**: tanggal keluar (tidak sebelum tanggal masuk, tidak di masa depan, tidak sebelum siswa masuk kelasnya), alasan, keterangan (wajib bila "Lainnya", maks. 500). Kelas terbuka di lembaga itu ikut ditutup di tanggal yang sama. Audit tidak menyimpan isi keterangan. Membatalkan penutupan belum tersedia (lihat §14).
- Audit baru: `academic.student_updated`, `academic.enrollment_updated`, `academic.enrollment_closed`.

Rincian AC-1c-b2 [OWNER, 2026-10-19 — usulan disetujui]:

- **Siapa boleh**: sama dengan ubah data diri (OD-AC-09): Operator Akademik di lembaga mana pun tempat siswa aktif + Admin Yayasan; wajib berhak melihat data pribadi.
- **Cari lewat HP**: hanya di antara orang yang **sudah tercatat sebagai orang tua/wali** (bukan seluruh anggota yayasan, agar HP pegawai tidak bisa dicari dari halaman siswa); nomor harus cocok persis setelah dirapikan; maks. 5 hasil berisi nama saja; dibatasi 30 pencarian/menit per pengguna. ID orang yang dipilih diperiksa ulang bersama nomor HP-nya.
- **Tambah orang baru**: aturan B AC-1b (nama + HP sama → dipakai ulang). Orang tua/wali pertama otomatis menjadi kontak utama; pilihan "Jadikan kontak utama" memindah tanda. Maks. 4, tidak dobel, bukan siswa itu sendiri.
- **Ubah**: hubungan + nama, L/P, HP. Bila orangnya juga **pegawai atau siswa**, hanya hubungan yang bisa diubah (data diri dari menu Pegawai/Siswa). Form memberi tahu jumlah siswa lain yang memakai orang tua/wali yang sama (tanpa nama).
- **Lepas**: hanya tautan yang dihapus; orangnya tetap. Kontak utama yang dilepas wajib diganti bila masih ada orang tua/wali lain; bila tidak ada, siswa boleh tanpa kontak utama.
- **Ganti kontak utama**: dari orang tua/wali yang sudah tertaut.
- Audit baru: `academic.guardian_updated`, `academic.guardian_unlinked`, `academic.primary_contact_changed` (tanpa nilai data pribadi).

Rincian AC-1d-a [OWNER, 2026-10-20 — usulan disetujui]:

- **Tahun ajaran**: kelas dibuat untuk tahun berjalan atau yang akan datang, dari kalender yang berlaku untuk lembaga itu. Kelas tahun ajaran yang **sudah lewat** (bukan tahun berjalan dan tanggal selesainya lewat) hanya bisa dilihat: tidak bisa diubah, dinonaktifkan, maupun dihapus.
- **Tingkat** 1–12 untuk semua lembaga; belum dibatasi per jenjang (penomoran MDA/Bahasa/Al-Qur'an bisa berbeda).
- **Wali kelas**: pegawai aktif dengan penempatan berjalan di lembaga itu sendiri (kontrak HR `ActiveEmployees`); opsional; boleh memegang lebih dari satu kelas [ASUMSI]. Wali kelas yang berhenti/dimutasi tetap tercatat dan ditandai "tidak aktif lagi di lembaga ini" sampai diganti.
- **Hapus** hanya untuk kelas yang belum pernah punya anggota; selain itu nonaktifkan.
- Daftar kelas: saringan lembaga (yang terlihat) & tahun ajaran (bawaan: tahun berjalan); nama, tingkat, wali kelas, jumlah siswa L/P yang masih di kelas.
- Audit baru: `academic.class_created`, `class_updated` (termasuk tanda wali kelas diganti), `class_deactivated`, `class_reactivated`, `class_deleted`.

Rincian AC-1d-b [OWNER, 2026-10-20 — usulan disetujui]:

- **Detail kelas** (`/kelas/{id}`): anggota yang masih di kelas, yang pernah di kelas ini, wali kelas, jumlah L/P. Dibuka pemilik izin lihat siswa di lembaga kelas.
- **Masukkan siswa**: pilih (centang, cari, pilih semua) dari siswa dengan pendaftaran terbuka di lembaga kelas yang belum punya kelas terbuka di tahun ajaran itu; maks. 100 per simpan; **semua atau tidak sama sekali**, masalah tiap siswa disebut sekaligus. Kelas nonaktif tidak bisa diisi.
- **Tanggal masuk kelas**: tahun berjalan → dipilih (bawaan hari ini), tidak sebelum awal tahun ajaran maupun tanggal masuk siswa ke lembaga, tidak di masa depan. Tahun ajaran yang belum mulai → otomatis tanggal mulai tahun ajaran itu (menyiapkan rombel tahun depan).
- **Pindah kelas**: hanya ke kelas aktif di lembaga & tahun ajaran yang sama; keanggotaan lama ditutup dan yang baru dibuka pada tanggal yang sama, satu transaksi.
- **Keluarkan dari kelas**: hanya menutup keanggotaan kelas; pendaftaran di lembaga tetap aktif (untuk keluar sekolah pakai Tutup pendaftaran).
- **Detail siswa**: bagian Kelas per tahun ajaran; Pindah/Keluarkan bagi pengelola kelas di lembaga itu (tahun belum lewat).
- **Tambah siswa**: pilihan kelas opsional (kelas aktif tahun berjalan di lembaga terpilih, bila boleh mengelola kelas di sana); tanggal masuk kelas = tanggal masuk lembaga, atau awal tahun ajaran bila lebih awal [ASUMSI disetujui]. Gagal masuk kelas → tidak ada yang tersimpan.
- Audit baru: `academic.class_members_added` (jumlah per simpan), `class_member_moved`, `class_member_removed`.

### AC-1e Impor siswa [OWNER, 2026-10-20 — usulan disetujui]

Pola sama dengan impor pegawai (PRD-001 §6.2): template `.xlsx` → unggah `.xlsx`/`.csv` (maks. 1.000 baris, 2 MB) → pratinjau Siap/Peringatan/Salah per kolom (tidak ada yang disimpan) → Simpan semua dalam satu transaksi, hanya bila tidak ada baris Salah; Peringatan wajib dikonfirmasi. Dipecah **AC-1e-a** (template, unggah, pratinjau) dan **AC-1e-b** (simpan).

- **Siapa**: pemegang `academic.students.manage` (Operator Akademik di lembaganya, Admin Yayasan) — data siswa dikelola per lembaga (OD-AC-04).
- **Satu file = satu lembaga**, dipilih dari lembaga aktif yang boleh dikelola saat unggah; tidak ada kolom kode lembaga. Template diunduh per lembaga dan memuat daftar kelas aktif tahun ajaran berjalan lembaga itu.
- **Isi per baris** = form Tambah siswa: data diri, NISN, pendaftaran (nomor induk, tanggal masuk, jenis masuk — kosong = BARU), kelas (opsional, nama kelas aktif tahun berjalan; butuh izin kelola kelas), ayah/ibu/wali (nama + HP; nama + HP sama dengan orang tua tercatat = orang yang sama, aturan B), kontak utama (AYAH/IBU/WALI; kosong = orang tua pertama yang diisi).
- **Siswa yang sudah tercatat**: NISN sama dengan siswa yang **namanya juga sama** → hanya didaftarkan ke lembaga ini (bukan orang baru); NISN milik nama lain → Salah (nama pemiliknya tidak disebut); sudah aktif di lembaga ini → Salah. Tanpa kecocokan NISN, nama sama dengan anggota yayasan atau baris lain → Peringatan; setelah dikonfirmasi menjadi orang baru.
- **Salah** juga untuk: NISN/nomor induk dobel di dalam file, nomor induk milik siswa lain di lembaga ini, NISN/HP yang tersimpan sebagai angka di Excel (0 di depan hilang), HP orang tua tanpa nama.
- File sementara di penyimpanan privat (`storage/app/private/academic-imports`), dihapus saat batal/simpan dan disapu `academic:prune-imports` bila tertinggal lebih dari 24 jam. Pratinjau tidak mengirim data pribadi selain tanggal lahir; orang tua/wali hanya jumlahnya.
- **Simpan (AC-1e-b)**: file dibaca & diperiksa ulang; semua baris lewat CreateStudent (aturan & audit sama dengan form, termasuk orang tua/wali dan kelas) dalam satu transaksi. Gagal → pesan "Baris N: alasan", tidak ada yang tersimpan, file tetap ada. Berhasil → file dihapus, diarahkan ke daftar siswa lembaga itu dengan jumlah siswa (dan berapa siswa lama). Audit `academic.students_imported` (lembaga, jumlah, siswa lama; tanpa nama file & data pribadi). Batas waktu proses 5 menit.
- **Teknis**: cara membaca file (openspout) dipindah ke Core (`Modules\Core\Support\Import`) dan dipakai bersama impor pegawai; perilaku impor pegawai tidak berubah.

## 12. Di luar AC-1

Kenaikan kelas massal (kini AC-2, §15), mata pelajaran, jadwal, presensi, nilai, rapor, akun orang tua, PPDB, asrama, ekspor pemerintah, NIK/No. KK, dan data sosial-ekonomi orang tua.

## 13. Open decisions

| ID       | Topik                                                                         | Status                                                                                                                                                                                        |
| -------- | ----------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OD-AC-01 | Cakupan tahap pertama                                                         | **DIPUTUSKAN** — data siswa + pendaftaran + tahun ajaran + kelas/rombel + wali kelas; jadwal, presensi, nilai menyusul [OWNER, 2026-10-17]                                                    |
| OD-AC-02 | Data pribadi siswa                                                            | **DIPUTUSKAN** — minimal seperti pegawai + NISN + kontak orang tua/wali [OWNER, 2026-10-17]. **Direvisi 2026-10-23:** NIK siswa & orang tua/wali disimpan terenkripsi, opsional (AC-1g, §4.5) |
| OD-AC-03 | Tahun ajaran & semester                                                       | **DIPUTUSKAN** — default yayasan, lembaga boleh beda [OWNER, 2026-10-17]; mekanismenya kalender + aturan berjenjang (§4.4) **[ASUMSI]**                                                       |
| OD-AC-04 | Siapa mengelola data siswa & kelas                                            | **DIPUTUSKAN** — role baru Operator Akademik per lembaga (+ Admin Yayasan); Kepala Lembaga hanya melihat [OWNER, 2026-10-17]                                                                  |
| OD-AC-05 | Orang tua/wali sebagai Person                                                 | **DIPUTUSKAN** — Person + Membership tanpa akun; tanpa pekerjaan/penghasilan/NIK [OWNER, 2026-10-17]                                                                                          |
| OD-AC-06 | Wali kelas/guru melihat siswa kelasnya sendiri                                | **DIPUTUSKAN** — ditunda ke AC-3 (butuh hak akses berbasis kelas, bukan pohon lembaga) [OWNER, 2026-10-17]                                                                                    |
| OD-AC-07 | Impor siswa dari Excel/CSV                                                    | **DIPUTUSKAN** — AC-1e sesudah AC-1, memakai pola HR-1e [OWNER, 2026-10-17]                                                                                                                   |
| OD-AC-09 | Siapa boleh mengubah data diri siswa yang terdaftar di beberapa lembaga       | **DIPUTUSKAN** — Operator Akademik dari lembaga mana pun tempat siswa masih aktif, dan Admin Yayasan; setiap perubahan tercatat di audit [OWNER, 2026-10-18]                                  |
| OD-AC-08 | Model data, permission, penyaringan, kalender lewat aturan berjenjang (§4–§5) | **DISETUJUI** — owner menyetujui semua usulan v0.1 [OWNER, 2026-10-17]                                                                                                                        |

## 14. Saran tambahan (di luar ruang lingkup)

- ~~Ekspor daftar siswa per kelas~~ dan ~~Buka kembali pendaftaran~~ — diminta owner, kini dirinci di §18 (P1, P2).

## 15. AC-2 Kenaikan kelas & kelulusan [OWNER, 2026-10-20 — usulan disetujui]

Proses akhir tahun ajaran **per kelas**, dari detail kelas (`/kelas/{id}` → tombol **Proses akhir tahun** → `/kelas/{id}/akhir-tahun`).

- **Siapa**: `academic.classes.manage` di lembaga kelas (Operator Akademik, Admin Yayasan); di luar cakupan 404, tanpa izin 403. Pilihan **Lulus** juga butuh `academic.students.manage` di lembaga itu (lulus menutup pendaftaran); tanpa izin itu pilihan Lulus tidak muncul dan dikirim paksa → 403.
- **Tahun ajaran tujuan** = tahun ajaran berikutnya di kalender yang sama dengan tahun ajaran kelas (tanggal mulai paling awal sesudah tahun kelas) [ASUMSI]. Belum ada → halaman memberi tahu agar ditambahkan di Kalender akademik; menyimpan ditolak. Kelas tahun tujuan disiapkan lebih dulu di daftar kelas (AC-1d-a).
- **Siswa** = yang masih tercatat di kelas ini. Tiap siswa dipilihkan satu: **Naik ke** kelas aktif tahun tujuan bertingkat lebih tinggi, **Tinggal di** kelas aktif tahun tujuan bertingkat sama, **Lulus**, atau **Tidak diproses**.
- **Pilihan bawaan** (operator boleh mengubah tiap siswa): kelas pertama bertingkat +1 (urut nama); bila tahun tujuan sudah punya kelas aktif tetapi tidak ada yang bertingkat lebih tinggi → Lulus (hanya bagi yang boleh mencatat lulus); selain itu Tidak diproses. Siswa yang **sudah punya kelas di tahun tujuan** ditampilkan dengan nama kelasnya dan tidak diproses.
- **Naik/tinggal**: masuk kelas tujuan mulai **tanggal mulai tahun tujuan** (sama dengan aturan tahun yang belum mulai di AC-1d-b; bila siswa masuk lembaga sesudahnya, tanggal masuk lembaga). Keanggotaan kelas lama **tidak ditutup**, tetap sebagai riwayat [OWNER].
- **Lulus**: pendaftaran di lembaga ditutup dengan alasan `LULUS` pada **tanggal lulus** yang sama untuk semua siswa lulus di proses itu (bawaan: akhir tahun ajaran kelas, atau hari ini bila belum lewat; tidak di masa depan). Kelas yang masih terbuka ikut ditutup (aturan §4.2). Tidak ada pembatalan dari aplikasi (lihat saran "Batalkan penutupan pendaftaran" §14).
- **Semua atau tidak sama sekali**, satu transaksi; masalah tiap siswa disebut sekaligus dengan namanya (bukan siswa aktif, sudah punya kelas di tahun tujuan, kelas tujuan tidak tersedia). Maks. 200 siswa sekali proses.
- **Tahun ajaran kelas yang sudah lewat** tetap bisa diproses: kelas lama hanya dibaca.
- **Audit**: `academic.class_year_end_processed` satu catatan per proses (kelas, tahun ajaran, tahun tujuan, jumlah naik/tinggal/lulus, tanggal lulus) + `academic.enrollment_closed` per siswa lulus.

## 16. AC-3 Mata pelajaran & jadwal [OWNER, 2026-10-21 — usulan disetujui]

Dipecah empat bagian, dikerjakan berurutan. Mapel, pengampu, dan jadwal dikelola dengan izin yang sudah ada, `academic.classes.manage` (Operator Akademik, Admin Yayasan); melihatnya cukup `academic.students.view` [ASUMSI disetujui]. Di luar cakupan 404, tanpa izin 403.

### AC-3a Mata pelajaran

- **Tabel `ac_subjects`**: lembaga (wajib, lembaga aktif), nama (wajib, maks. 100, unik per lembaga tanpa beda huruf besar/kecil), kode singkat (opsional, maks. 20, huruf besar, unik per lembaga), rentang tingkat `grade_min`–`grade_max` (keduanya kosong = semua tingkat; bila diisi 1–12 dan min ≤ maks), aktif/nonaktif.
- **Halaman** `/akademik/mapel`, menu **Mata pelajaran** bagi pemilik `academic.students.view`: saringan lembaga (yang terlihat), daftar urut nama berisi kode, tingkat, status. Tambah, ubah, nonaktifkan/aktifkan kembali, hapus.
- **Nonaktif** = tidak bisa dipilih untuk kelas (AC-3b); yang sudah dipakai tetap tercatat. **Hapus** hanya untuk mapel yang belum pernah dipakai di kelas.
- Audit baru: `academic.subject_created`, `subject_updated`, `subject_deactivated`, `subject_reactivated`, `subject_deleted`.

### AC-3b Mapel kelas & guru pengampu

- Di detail kelas, bagian **Mata pelajaran**: pilih mapel aktif lembaga itu yang rentang tingkatnya memuat tingkat kelas; tiap mapel paling banyak sekali per kelas.
- **Satu guru pengampu per mapel per kelas** [ASUMSI disetujui; belum ada team teaching]; opsional; pegawai aktif yang ditempatkan di lembaga itu (kontrak HR `ActiveEmployees`, sama dengan wali kelas). Pengampu yang berhenti/dimutasi tetap tercatat dan ditandai sampai diganti.
- Karena kelas berlaku per tahun ajaran, mapel & pengampu otomatis per tahun ajaran. Kelas tahun yang sudah lewat hanya bisa dilihat.
- Tambah mapel: centang banyak sekaligus, semua atau tidak sama sekali (masalah tiap mapel disebut). **Lepas** mapel dari kelas hanya menghapus kaitannya; mapelnya tetap. Mapel yang sudah dipakai di kelas tidak bisa dihapus dari menu Mata pelajaran (nonaktifkan saja). Kelas kosong yang dihapus ikut menghapus mapel kelasnya.
- Audit baru: `academic.class_subjects_added` (jumlah & nama mapel), `class_subject_teacher_changed` (tanda pengampu diisi), `class_subject_removed`.

### AC-3c Jadwal pelajaran

- **Jadwal mingguan per kelas** dengan **jam bebas per baris** [OWNER, 2026-10-21]: hari (Senin–Ahad), jam mulai–selesai (jam selesai sesudah jam mulai), mapel kelas itu.
- **Bentrok ditolak**: (1) kelas yang sama punya dua baris yang jamnya beririsan di hari yang sama; (2) guru pengampu yang sama mengajar di dua tempat yang jamnya beririsan di hari yang sama, **di seluruh yayasan** (mis. guru MA pagi dan MDA sore) pada tahun ajaran yang tanggalnya beririsan. Pesan bentrok menyebut kelas & mapel lawannya.
- Mengganti pengampu di AC-3b juga diperiksa bentroknya terhadap jadwal guru baru.
- Jam yang hanya bersambung (07.00–08.00 lalu 08.00–09.00) tidak dianggap bentrok. Jam ditulis HH:MM.
- Di detail kelas: bagian **Jadwal pelajaran**, dikelompokkan per hari, urut jam mulai, menampilkan mapel & pengampu. Tambah/ubah/hapus per baris bagi pengelola kelas; jadwal hanya bisa diisi dari mapel yang sudah ada di kelas.
- **Lepas mapel** dari kelas ikut menghapus jadwal mapel itu (jumlahnya tercatat di audit). Kelas kosong yang dihapus ikut menghapus jadwalnya.
- Audit baru: `academic.schedule_entry_added`, `schedule_entry_updated`, `schedule_entry_removed` (kelas, mapel, hari, jam).

### AC-3d Kelas saya (OD-AC-06)

- Menu **Kelas saya** untuk pengguna yang akunnya tertaut ke profil pegawai (kontrak HR baru: pegawai milik keanggotaan yang sedang login) dan saat ini menjadi **wali kelas** atau **guru pengampu** di kelas tahun ajaran berjalan, selama ia masih pegawai aktif yang ditempatkan di lembaga kelas itu.
- Isi: daftar kelas yang ia pegang (sebagai wali kelas dan/atau mapel yang diampu), siswa di kelas itu, dan **jadwal mengajar** mingguan. Hanya bisa dilihat.
- **Data yang terlihat** [OWNER, 2026-10-21]: semua guru melihat nama, L/P, NISN, nomor induk. **Wali kelas** juga melihat **kontak utama orang tua/wali** (nama + HP) siswa kelasnya. Data pribadi lain (alamat, tanggal lahir, orang tua lain) tidak terlihat.
- Hak ini tidak membuka menu Siswa/Kelas biasa (tetap mengikuti §5).
- **Mencakup semua kelas yang ia pegang di yayasan ini, tidak dibatasi lembaga kerja aktif** [ASUMSI]: guru SMP pagi & MDA sore melihat satu jadwal gabungan. Kelas di lembaga tempat ia tidak lagi ditempatkan aktif tidak tampil.
- Tanpa permission baru: akses ditentukan data penugasan (wali kelas/pengampu); kelas yang tidak ia pegang → 404. Menu muncul hanya bila ia memegang kelas. Akun yang tidak tertaut ke pegawai diberi tahu di halaman.
- Kontrak HR: `EmployeeLookup::employeeIdForMembership` (hanya ID pegawai; status & penempatan lewat `ActiveEmployees::describe`).

## 17. AC-4 Presensi siswa [OWNER, 2026-10-21 — usulan disetujui]

Dikerjakan bertahap: **AC-4a** presensi harian, **AC-4b** rekap, **AC-4c** presensi per pertemuan.

### Keputusan

- **Mode** [OWNER]: harian dan per pertemuan, **dipilih per lembaga** lewat aturan berjenjang (PRD-000 §8). Aturan `academic.attendance_mode` (HARIAN / PER_PERTEMUAN, bawaan HARIAN) dibuat di AC-4c; sampai itu semua lembaga memakai presensi harian.
- **Kode** [OWNER]: **H** Hadir, **S** Sakit, **I** Izin, **A** Alpa. Keterangan opsional, maks. 200 karakter; isi keterangan tidak dicatat di audit (hanya jumlah per kode).
- **Siapa mengisi & mengoreksi** [OWNER]: wali kelas (harian) / guru pengampu (per pertemuan) sampai **7 hari ke belakang** (hari ini termasuk); Operator Akademik (`academic.classes.manage`) kapan saja di dalam tahun ajaran yang belum lewat. Semua pengisian & koreksi tercatat di audit.
- **Siapa melihat rekap** [OWNER]: pemilik `academic.students.view` di lembaga kelas (Kepala Lembaga, Pimpinan, Koordinator, operator); wali kelas untuk kelasnya di Kelas saya; pengampu untuk pertemuan mapelnya. Orang tua belum (AC-7).

### Aturan bersama [ASUMSI disetujui]

- Satu **sesi presensi** = kelas + tanggal (+ baris jadwal untuk per pertemuan) berisi status tiap siswa. Tanggal di dalam tahun ajaran kelas dan tidak di masa depan.
- **Daftar siswa pada tanggal itu** = yang tercatat di kelas pada tanggal tersebut (masuk kelas ≤ tanggal, dan belum keluar/pindah pada tanggal itu). Siswa yang masuk/keluar di tengah tahun hanya muncul di tanggal ia memang ada di kelas.
- Form dibuka dengan semua siswa **Hadir** (atau status tersimpan bila sudah pernah diisi); simpan = semua siswa sekaligus.
- Belum ada kalender libur: hari tanpa sesi = "belum diisi", bukan alpa.

### AC-4a Presensi harian

- Tabel `ac_attendance_sessions` (kelas, tanggal, pengisi terakhir; satu sesi harian per kelas per tanggal) dan `ac_attendance_records` (sesi, pendaftaran, status H/S/I/A, keterangan).
- **Wali kelas**: dari Kelas saya → kelas yang ia walikan → **Presensi** (`/kelas-saya/{kelas}/presensi?tanggal=`), tanggal hari ini s.d. 7 hari ke belakang.
- **Operator**: dari detail kelas → **Presensi** (`/kelas/{kelas}/presensi?tanggal=`), tanggal mana pun di tahun ajaran (tidak di masa depan, tahun belum lewat).
- Halaman: pilih tanggal, daftar siswa dengan pilihan H/S/I/A dan keterangan, jumlah per kode, tanda "sudah diisi oleh … pada …" bila sesi sudah ada.
- Simpan semua siswa sekaligus; daftar siswa diperiksa ulang saat simpan (siswa yang tidak ada di kelas pada tanggal itu ditolak, yang belum diberi status ditolak).
- Audit baru: `academic.attendance_recorded` (kelas, tanggal, jumlah H/S/I/A, tanda koreksi).

### AC-4b Rekap presensi

- **Periode**: seluruh tahun ajaran, tiap semester (dari kalender), atau tiap bulan yang sudah mulai; bawaan bulan berjalan.
- **Per siswa**: jumlah H/S/I/A, hari tercatat, dan **% hadir = Hadir ÷ hari tercatat** untuk siswa itu (kosong bila belum ada yang tercatat; bukan 0). Baris jumlah di bawah. Juga jumlah hari yang sudah diisi untuk kelas pada periode itu.
- **Siswa** = yang pernah tercatat di kelas dalam periode itu; siswa yang pindah kelas tetap tampil dengan presensi selama di kelas tersebut.
- Hanya presensi harian (per pertemuan direkap di AC-4c). Hari tanpa sesi tidak dihitung alpa.
- **Siapa**: dari detail kelas (`/kelas/{kelas}/rekap-presensi`) bagi pemilik `academic.students.view` di lembaga kelas; dari Kelas saya (`/kelas-saya/{kelas}/rekap-presensi`) bagi wali kelasnya. Tahun ajaran yang sudah lewat tetap bisa direkap.

### AC-4c Per pertemuan [OWNER, 2026-10-21 — usulan disetujui]

- **Aturan mode** `academic.attendance_mode` (HARIAN / PER_PERTEMUAN, bawaan HARIAN), diatur **Admin Yayasan saja** di halaman Kalender akademik (bagian **Mode presensi**), pola sama dengan pemakaian kalender: per jenjang (seluruh yayasan) atau per lembaga/unit, bisa dikunci. Ringkasan mode yang berlaku di tiap lembaga aktif ikut tampil.
- **Lembaga PER_PERTEMUAN**: presensi harian **ditutup** (tombol Isi presensi/Presensi diganti keterangan); data harian lama tetap terlihat di rekap. **Lembaga HARIAN**: presensi per pertemuan tidak dibuka.
- **Yang mengisi**: guru pengampu mapel itu (7 hari terakhir, hari ini termasuk) dari Kelas saya → Jadwal mengajar; Operator (`academic.classes.manage`) sepanjang tahun ajaran dari baris jadwal di detail kelas. Wali kelas tidak mengisi pertemuan mapel guru lain kecuali ia pengampunya; mapel tanpa pengampu hanya bisa diisi operator.
- **Tanggal** wajib jatuh pada hari jadwal itu (jadwal Senin → tanggal Senin), di dalam tahun ajaran, tidak di masa depan. Satu sesi per baris jadwal per tanggal; daftar siswa = yang tercatat di kelas pada tanggal itu (sama dengan harian).
- Sesi pertemuan menyimpan **mapel kelas & jam** saat diisi [ASUMSI disetujui], sehingga riwayat tidak berubah bila jadwal diubah/dihapus. Mapel yang **sudah punya presensi tidak bisa dilepas** dari kelas (hapus jadwalnya saja).
- **Rekap**: pilihan **Harian / Per pertemuan**; per pertemuan bisa disaring per mapel; % hadir = Hadir ÷ pertemuan tercatat. Bawaan mengikuti mode lembaga. Pengampu (bukan wali kelas) melihat rekap pertemuan mapelnya sendiri dari Kelas saya.
- Audit: `academic.attendance_recorded` ditambah mapel & jam untuk pertemuan; perubahan mode lewat audit aturan berjenjang Core.

## 18. Penyempurnaan [OWNER, 2026-10-21 — diminta owner, usulan disetujui]

Empat fitur kecil yang sebelumnya saran (§14 dan saran sesudah AC-2/AC-4), dikerjakan berurutan P1 → P4.

### P1 Ekspor daftar siswa per kelas

- Tombol **Unduh daftar (.xlsx)** di detail kelas (pemilik `academic.students.view` di lembaga kelas) dan di Kelas saya (wali kelas).
- Kolom: No, Nama, L/P, NISN, Nomor induk, Tanggal masuk kelas — siswa yang masih di kelas, urut nama. Tempat & tanggal lahir serta kontak utama orang tua/wali (nama, hubungan, HP) hanya bagi pemilik `academic.students.view_personal`; wali kelas mendapat kontak utama (sama dengan layar Kelas saya, §16 AC-3d) tanpa tempat/tanggal lahir.
- Nama file `daftar-siswa-{kelas}-{tahun ajaran}.xlsx`; memakai pustaka xlsx yang sudah ada (openspout). Pengunduhan tidak dicatat di audit [ASUMSI].

### P2 Buka kembali pendaftaran

- **Admin Yayasan saja** [OWNER], dari riwayat pendaftaran yang sudah ditutup di detail siswa; **alasan wajib** (maks. 500; audit hanya mencatat ada/tidaknya).
- Syarat: belum ada pendaftaran terbuka lain milik siswa itu di lembaga yang sama; lembaga masih aktif. (Nomor induk juga diperiksa ulang sebagai pengaman, walau aturan AC-1c-b1 sudah melarang nomor induk dipakai siswa lain.)
- Tanggal keluar, alasan keluar, dan keterangannya dikosongkan. Keanggotaan kelas yang ditutup **pada tanggal keluar yang sama** dibuka lagi bila kelasnya masih aktif, tahun ajarannya belum lewat, dan siswa belum punya kelas terbuka lain di tahun ajaran itu; selain itu dibiarkan (operator memasukkan ke kelas secara manual).
- Audit baru: `academic.enrollment_reopened` (lembaga, tanggal keluar lama, alasan keluar lama, jumlah kelas yang dibuka lagi).

### P3 Salin mapel & pengampu dari kelas lain

- Tombol **Salin dari kelas lain** di bagian Mata pelajaran detail kelas (pengelola kelas, tahun belum lewat). Kelas sumber: kelas mana pun di lembaga yang sama (tahun mana pun); saran bawaan = kelas setingkat dari tahun ajaran sebelumnya.
- Yang disalin: mapel yang **aktif dan cocok dengan tingkat kelas tujuan** dan belum ada di kelas tujuan, beserta pengampunya bila masih pegawai aktif yang ditempatkan di lembaga itu (selain itu pengampu dikosongkan). **Jadwal tidak disalin.** Hasil menyebut jumlah yang disalin dan yang dilewati.
- Halaman proses akhir tahun (§15) menampilkan tautan ke kelas tujuan untuk menyalin mapel.
- Bila tidak ada satu pun mapel yang bisa disalin, permintaan ditolak dengan pesan alasannya (tidak ada perubahan) [ASUMSI]. Pilihan kelas sumber hanya kelas yang punya mapel [ASUMSI].
- Audit memakai `academic.class_subjects_added` dengan tanda sumber salinan.

### P4 Kalender hari libur

- Per **kalender akademik** (Admin Yayasan, halaman Kalender akademik): **hari libur mingguan** (pilih hari; bawaan Ahad) dan **daftar hari libur** per tahun ajaran (nama, tanggal mulai–selesai, di dalam tahun ajaran).
- **Hari efektif** = hari di dalam periode yang bukan libur mingguan dan bukan hari libur.
- Rekap presensi harian menampilkan jumlah hari efektif dan **hari efektif yang belum diisi** (sampai hari ini).
- Mengisi presensi di hari libur **boleh, dengan peringatan** "Tanggal ini hari libur: …" [OWNER]; hari itu tetap tidak dihitung hari efektif.
- Audit baru: perubahan hari libur mingguan dan tambah/ubah/hapus hari libur.
- Dikerjakan dua tahap: **P4a** data hari libur & pengelolaannya di halaman Kalender akademik; **P4b** hari efektif di rekap presensi harian dan peringatan saat mengisi presensi di hari libur.
- Rincian P4a [ASUMSI]: libur mingguan 0–6 hari (minimal satu hari sekolah sepekan; tanpa centang = tanpa libur mingguan) dan berlaku untuk semua tahun ajaran kalender itu; nama hari libur maks. 100 karakter; paling banyak 200 hari libur per tahun ajaran; hari libur boleh tumpang tindih; tanggal tahun ajaran tidak bisa diubah bila ada hari libur yang jadi berada di luarnya.
- Audit: `academic.weekly_off_days_changed` (sebelum/sesudah), `academic.holiday_added|updated|removed` (nama, mulai, selesai; ubah hanya mencatat yang berubah).
- Rincian P4b [ASUMSI]: hari efektif hanya untuk rekap **harian** (rekap per pertemuan tidak berubah); persentase hadir tetap Hadir ÷ hari yang diisi. Rekap menampilkan hari efektif periode, hari efektif sampai hari ini, jumlah yang belum diisi, dan paling banyak 31 tanggal belum diisi yang terbaru; tanggal itu bisa diklik ke lembar presensi hanya bagi yang boleh mengisinya pada tanggal tersebut (operator: seluruh tahun ajaran; wali kelas: 7 hari terakhir). Peringatan hari libur muncul di lembar presensi harian dan per pertemuan, menyebut nama hari libur atau "Ahad (libur mingguan)".

## 19. AC-5 Penilaian & rapor [OWNER, 2026-10-22 — 3 keputusan + usulan rincian disetujui; SELESAI]

### Keputusan owner

| ID       | Topik              | Keputusan                                                                                                                                             |
| -------- | ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| OD-AC-10 | Cara mengisi nilai | **Komponen + bobot** (mis. Harian 40%, PTS 30%, PAS 30%); nilai akhir dihitung otomatis; bobot diatur per jenjang/lembaga [OWNER]                     |
| OD-AC-11 | Format rapor       | **Format umum** untuk semua jenjang: nilai angka + predikat + deskripsi + KKM [OWNER]                                                                 |
| OD-AC-12 | Kunci nilai        | Pengampu **Finalkan**; sesudahnya hanya Operator Akademik/Admin Yayasan yang bisa membuka lagi (tercatat di audit). Rapor memakai nilai final [OWNER] |

### Dasar

- Nilai diisi per **mapel kelas** (AC-3b) per **semester** tahun ajaran kelas. Guru = pengampu mapel itu (pegawai HR), lewat **Kelas saya** seperti presensi (§17).
- Hak akses [ASUMSI]: melihat = `academic.students.view` di lembaga kelas (sama dengan rekap presensi); mengisi = `academic.classes.manage` di lembaga kelas atau pengampu mapel itu; catatan rapor = wali kelas atau `academic.classes.manage`. Belum ada permission baru. Di luar cakupan 404, tanpa izin 403; pintu Kelas saya: bukan kelas/mapel yang ia pegang → 404.
- Kelas tahun ajaran yang sudah lewat hanya bisa dilihat (aturan sama dengan AC-3/AC-4).

### AC-5a Aturan penilaian

- Aturan berjenjang **`academic.grading`** (PRD-000 §8) per jenjang atau lembaga/unit, opsional dikunci; diatur **Admin Yayasan** di halaman Kalender akademik, bagian **Aturan penilaian** (pola sama dengan Mode presensi, AC-4c) [ASUMSI].
- Isi aturan [ASUMSI]:
    - **KKM** 0–100, bawaan **75**;
    - **Komponen** 1–6, masing-masing kode singkat (huruf besar, maks. 10, unik), nama (maks. 50), bobot bilangan bulat 1–100; **jumlah bobot = 100**. Bawaan: `HARIAN` Nilai harian 40, `PTS` Penilaian tengah semester 30, `PAS` Penilaian akhir semester 30;
    - **Predikat**: A ≥ batas A (bawaan 90), B ≥ batas B (bawaan 80), C ≥ KKM, D < KKM; syarat batas A > batas B > KKM.
- Halaman menampilkan aturan yang berlaku di tiap lembaga aktif (seperti Kalender per lembaga).
- Audit: perubahan aturan berjenjang dicatat Core (nilai lama & baru).
- Rincian teknis AC-5a [ASUMSI]: kode komponen diubah ke huruf besar dan nama dirapikan spasinya sebelum disimpan; aturan tersimpan yang rusak dibaca sebagai bawaan; form bisa diisi dari aturan yang sudah ada ("Pakai sebagai dasar"). Route `POST akademik/kalender/aturan-penilaian`.

### AC-5b Lembar nilai

- **Tabel `ac_grade_sheets`** [ASUMSI]: mapel kelas × semester (unik), status `DRAFT` | `FINAL`, **salinan aturan** (komponen & bobot, KKM, batas predikat) yang diambil saat lembar pertama kali disimpan, difinalkan oleh/kapan.
    - Aturan yang berubah sesudah lembar dibuat **tidak** mengubah lembar itu; operator bisa **Terapkan aturan terbaru** selama lembar masih DRAFT (nilai komponen yang kodenya sama dipertahankan) [ASUMSI].
- **Tabel `ac_grades`**: lembar × pendaftaran siswa (unik): nilai per komponen (0–100, maks. 2 desimal, boleh kosong), **nilai akhir** (otomatis = Σ nilai × bobot ÷ 100, dibulatkan ke bilangan bulat terdekat; kosong bila ada komponen kosong), deskripsi (opsional, maks. 300).
- **Siswa** = yang tercatat di kelas pada semester itu (pernah aktif di kelas antara tanggal mulai–selesai semester); siswa yang pindah keluar tetap tampil dengan tanda.
- **Halaman** dari baris mapel di detail kelas (operator) dan dari Kelas saya (pengampu): pilih semester (bawaan semester berjalan), tabel siswa × komponen, nilai akhir & predikat & tanda "di bawah KKM" dihitung langsung saat diketik; **Simpan** (semua siswa sekaligus) dan **Finalkan**.
- **Finalkan**: semua siswa wajib punya nilai akhir. Lembar FINAL hanya dibaca; **Buka kembali** hanya bagi `academic.classes.manage` (alasan wajib, audit tanpa isi alasan).
- Semester yang sudah punya lembar nilai tidak bisa dihapus dari Kalender akademik (janji §4.4); mapel kelas yang punya lembar nilai tidak bisa dilepas.
- Audit [ASUMSI]: `academic.grades_saved` (kelas, mapel, semester, jumlah siswa; tanpa angka nilai), `academic.grade_sheet_finalized`, `academic.grade_sheet_reopened`.
- Rincian teknis AC-5b [ASUMSI]: koma atau titik sama-sama diterima sebagai tanda desimal; nilai akhir dihitung dalam perseratus lalu dibulatkan setengah ke atas (sama di layar dan server). Simpan hanya mengubah siswa yang dikirim. Siswa yang keluar dari kelas sebelum semester berakhir tetap tampil ("sudah keluar dari kelas") dan tidak wajib dinilai untuk Finalkan. Finalkan dinonaktifkan selama ada perubahan yang belum disimpan. Pengampu (Kelas saya) mengisi & memfinalkan; membuka kembali & menerapkan aturan terbaru hanya pintu operator. Audit tambahan `academic.grade_sheet_rules_applied` (ringkasan aturan sebelum/sesudah). Route: `kelas/mapel/{id}/nilai` (+ `/final`, `/buka-kembali`, `/aturan-terbaru`) dan `kelas-saya/mapel/{id}/nilai` (+ `/final`), semester lewat `?semester=`.

### AC-5c Leger nilai kelas

- Per kelas per semester: siswa × mapel berisi **nilai akhir** (lembar DRAFT ditandai), rata-rata per siswa & per mapel, jumlah nilai di bawah KKM per siswa, dan daftar mapel yang lembarnya belum ada/belum final.
- Dibuka wali kelas (Kelas saya) dan pemilik `academic.students.view`; unduh **.xlsx** (openspout yang sudah ada).
- Rincian AC-5c [ASUMSI]: rata-rata siswa, mapel, dan kelas dihitung dari nilai akhir yang sudah ada (lembar draf ikut, ditandai *), dibulatkan 1 desimal; "di bawah KKM" memakai KKM salinan aturan lembar mapel itu (mapel tanpa lembar: aturan yang berlaku). Mapel urut nama, judul kolom memakai kode mapel bila ada. Di layar besar tabel bisa digeser di dalam kotaknya bila mapel banyak; di HP satu kartu per siswa. Pemantau/pengelola mendapat tautan ke lembar nilai tiap mapel; wali kelas tidak. File `leger-{kelas}-{semester}-{tahun ajaran}.xlsx` berisi baris KKM, satu baris per siswa, dan baris rata-rata mapel; judul mapel bertanda "(draf)"/"(belum ada)". Route `kelas/{id}/leger` (+ `/unduh`) dan `kelas-saya/{id}/leger` (+ `/unduh`).

### AC-5d Rapor

- Per siswa per semester, dibuka wali kelas & operator dari leger: identitas (nama, NISN, nomor induk, kelas, semester, tahun ajaran, lembaga), tabel mapel (KKM, nilai akhir, predikat, deskripsi), **rekap presensi harian semester itu** (S/I/A dari AC-4), **catatan wali kelas** (maks. 1000), tempat untuk tanda tangan wali kelas & kepala lembaga (nama kepala diketik di halaman cetak) [ASUMSI].
- Hanya mapel dengan lembar **FINAL** yang masuk rapor; yang belum final disebut di atas halaman sebagai peringatan.
- **Cetak lewat browser** (halaman khusus cetak, A4), satu siswa atau semua siswa kelas sekaligus — **tanpa pustaka PDF baru** [ASUMSI].
- **Tabel `ac_report_notes`**: kelas × semester × pendaftaran siswa (unik), catatan wali kelas. Audit `academic.report_notes_saved` (kelas, semester, jumlah catatan yang berubah; tanpa isi catatan).
- Rincian AC-5d [ASUMSI]: halaman **Rapor** per kelas & semester (detail kelas: pemilik izin lihat siswa, catatan oleh pengelola kelas; Kelas saya: wali kelas) berisi ringkasan tiap siswa dan catatan wali kelas yang disimpan sekaligus (kosong = dihapus). Halaman cetak memakai tata letak khusus tanpa menu, satu halaman A4 per siswa: identitas, tabel mapel final (KKM, nilai, predikat, deskripsi) + jumlah & rata-rata, ketidakhadiran (harian; lembaga mode per pertemuan memakai jumlah pertemuan), catatan wali kelas, tempat/tanggal, tanda tangan orang tua/wali, wali kelas (nama pegawai), dan kepala lembaga (nama diketik di halaman cetak, tidak disimpan). Semester yang punya catatan rapor juga tidak bisa dihapus. Route `kelas/{id}/rapor` (+ `/catatan`, `/cetak?semester=&siswa=`) dan `kelas-saya/{id}/rapor` (sama).

### Di luar AC-5

Nilai sikap/spiritual-sosial, ekstrakurikuler, prestasi, rapor P5 (Kurikulum Merdeka), format resmi K13/Merdeka, impor nilai dari Excel, remedial/nilai perbaikan, nilai per pertemuan, akses orang tua.

## 20. AC-6 Presensi mengajar & guru pengganti [OWNER, 2026-10-23 — usulan disetujui]

Latar: guru bisa mengajar di beberapa lembaga (mis. penempatan utama Al-Qur'an Unit 1, juga mengajar di SMP Unit 3). Kehadiran kerja harian tetap di HR-4 (PRD-001 §14); **presensi mengajar per jadwal** dicatat di Akademik karena jadwal, kelas, dan presensi siswa milik Akademik (ADR-001: HR tidak bergantung pada Academic).

| ID       | Keputusan        | Isi                                                                                                                                                 |
| -------- | ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| OD-AC-13 | Guru piket       | **Jadwal piket per hari** per lembaga & tahun ajaran; guru piket hari itu disarankan pertama sebagai pengganti [OWNER]                              |
| OD-AC-14 | Pencatat         | **Guru** menandai "Sudah mengajar"; **guru piket / Operator Akademik / Kepala Lembaga** mencatat yang kosong/digantikan & mengoreksi [OWNER]        |
| OD-AC-15 | Cuti & pengganti | Cuti/izin tetap **per hari** (HR-3); pertemuan terdampak otomatis "Perlu pengganti"; pengganti **ditunjuk** dengan saran yang tidak bentrok [OWNER] |

### 20.1 Aturan dasar

- **Pertemuan** = satu baris jadwal pada satu tanggal: kelas aktif, tanggal di dalam tahun ajaran kelas, hari efektif sekolah (bukan libur kalender akademik), hari jadwal = hari tanggal itu.
- **Presensi mengajar** = satu catatan per pertemuan, di **lembaga kelasnya** (guru yang mengajar di dua lembaga tercatat di keduanya). Status: **Mengajar**; **Digantikan** (guru pengganti + alasan guru tidak hadir); **Kosong** (alasan). Alasan: Cuti, Sakit, Izin, Dinas luar, Alpa, Lainnya; keterangan opsional maks. 300.
- Pengampu & jam pertemuan disalin ke catatan agar riwayat tetap bila jadwal diubah/dihapus.
- Pertemuan yang lewat tanpa catatan = **"Belum dicatat"**, tidak otomatis Alpa [ASUMSI].

### 20.2 AC-6a Jadwal piket, Presensi mengajar, "Sudah mengajar"

- Permission baru **`academic.teaching.manage`** (Kelola presensi mengajar & jadwal piket): Operator Akademik, Admin Yayasan, **Kepala Lembaga** [ASUMSI].
- **Jadwal piket** (`/akademik/jadwal-piket`): per lembaga × tahun ajaran (berjalan atau yang belum lewat) × hari (Senin–Ahad); guru piket = pegawai aktif lembaga itu (kontrak HR), maks. 20 per hari [ASUMSI]. Pemilik `academic.teaching.manage`.
- **Presensi mengajar** (`/akademik/presensi-mengajar?lembaga=&tanggal=`, menu bagi pemilik izin dan guru yang piket hari ini): daftar pertemuan lembaga pada tanggal itu, pengampu, status; catat Mengajar/Digantikan/Kosong. Pemilik izin: tanggal mana pun di tahun ajaran yang belum lewat, tidak di masa depan; **guru piket: hanya lembaga & hari piketnya, tanggal hari ini** [ASUMSI]. Pilihan pengganti di AC-6a = pegawai aktif lembaga itu (guru piket hari itu di atas); saran tidak bentrok di AC-6b.
- **Kelas saya**: bagian "Mengajar 7 hari terakhir" — pertemuan miliknya beserta status; tombol **Sudah mengajar** untuk yang belum dicatat, paling cepat saat jam pelajaran mulai, paling lama 7 hari ke belakang [ASUMSI]. Pertemuan yang sudah dicatat petugas tidak bisa diubah guru.
- Mapel kelas yang sudah punya presensi mengajar tidak bisa dilepas dari kelas.
- Audit `academic.duty_roster_updated` (hari, jumlah guru) dan `academic.teaching_recorded` (tanggal, jam, mapel, status, pengganti diisi ya/tidak; tanpa keterangan).

### 20.3 AC-6b Cuti & guru pengganti

- Kontrak HR baru (cuti disetujui per pegawai & tanggal): pertemuan guru yang cuti ditandai **"Perlu pengganti"** di semua lembaga.
- Halaman **Perlu pengganti** per lembaga: hari ini s.d. 7 hari ke depan (cuti) + pertemuan bertanda Kosong.
- **Tunjuk pengganti** (boleh sebelum hari-H): saran = guru piket hari itu, lalu pegawai aktif lembaga yang tidak bentrok jadwal mengajar di semua lembaga dan tidak cuti.
- Pengganti melihat tugasnya di Kelas saya dan boleh mengisi presensi siswa pertemuan itu saja; lembaga mode per pertemuan: mengisi presensi siswa otomatis mencatat Mengajar (pengampu) / Digantikan (pengganti).
- Dikerjakan dua bagian [ASUMSI]: **AC-6b-1** kontrak cuti, Perlu pengganti, tunjuk/batalkan pengganti dengan saran; **AC-6b-2** tugas pengganti di Kelas saya, akses presensi siswa untuk pengganti, pencatatan otomatis dari presensi per pertemuan.
- Rincian teknis AC-6b-1 [ASUMSI]:
    - Kontrak HR `ApprovedLeaves` (pegawai → tanggal → nama jenis cuti; setiap tanggal di rentang cuti disetujui). Pertemuan menampilkan "Pengampu cuti (jenis)"; di Kelas saya tampil "Cuti".
    - Halaman `/akademik/perlu-pengganti?lembaga=` (tombol dari Presensi mengajar): pemilik izin melihat hari ini s.d. 7 hari ke depan; guru piket hari ini saja. Isinya pertemuan yang pengampunya cuti (termasuk yang sudah punya pengganti, untuk ditinjau) atau tercatat Kosong.
    - Calon pengganti = pegawai aktif lembaga kelas selain pengampu. **Tidak tersedia** bila cuti disetujui hari itu, punya jadwal mengajar sendiri yang beririsan (semua lembaga; libur sekolah tidak diperhitungkan), atau sudah menggantikan pertemuan lain yang beririsan. Urutan: piket & tersedia, tersedia, tidak tersedia (tetap tampil dengan alasannya, tidak bisa dipilih). Pemeriksaan yang sama dilakukan server saat menyimpan, termasuk dari halaman Presensi mengajar.
    - Penunjukan sebelum hari-H hanya oleh pemilik izin (maks. 7 hari ke depan) dan hanya status Digantikan; alasan bawaan "Cuti" bila pengampu cuti. Penunjukan hari ini/yang akan datang bisa dibatalkan (catatan dihapus, tercatat di audit).
- Rincian teknis AC-6b-2 [ASUMSI]:
    - **Kelas saya → "Tugas menggantikan"**: pertemuan berstatus Digantikan dengan pengguna sebagai pengganti, 6 hari terakhir s.d. 7 hari ke depan; tampil kelas, mapel, jam, lembaga, guru yang digantikan, dan alasannya. Menu Kelas saya juga muncul bagi pegawai yang punya tugas menggantikan walau tidak memegang kelas.
    - **Presensi siswa oleh pengganti** (`/kelas-saya/menggantikan/{jadwal}/{tanggal}/presensi`): hanya pertemuan yang ia gantikan, hanya tanggal itu, pada hari-H s.d. 6 hari sesudahnya (sama dengan batas pengampu), hanya di lembaga yang memakai presensi per pertemuan. Selain itu 404 / terkunci dengan pesan. Lembaga mode harian: presensi tetap oleh wali kelas.
    - **Catatan otomatis**: pengampu yang menyimpan presensi siswa per pertemuan miliknya (lewat Kelas saya) tercatat **Mengajar** dengan sumber **OTOMATIS** ("otomatis dari presensi siswa"), bila pertemuan itu belum punya catatan dan jam pelajarannya sudah mulai. Catatan yang sudah ada (mis. petugas mencatat Kosong) tidak diubah. Pengganti sudah tercatat Digantikan sejak ditunjuk. Presensi yang diisi operator tidak membuat catatan otomatis.

### 20.4 AC-6c Rekap

- Rekap bulanan per lembaga per guru: terjadwal, mengajar, digantikan, kosong, belum dicatat, dan jumlah **menggantikan**; unduh .xlsx.

### 20.5 Di luar AC-6

Izin per jam pelajaran (bukan sehari penuh), honor/insentif piket, notifikasi ke guru pengganti, tukar jadwal permanen.
