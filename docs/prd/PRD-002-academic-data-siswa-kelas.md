# PRD-002 — Modul Academic (Akademik)

- **Versi:** 0.6 (2026-10-18: AC-1c-b dipecah b1/b2; ubah pendaftaran; pencarian orang tua lewat HP)
- **Versi sebelumnya:** 0.5 (AC-1c dipecah AC-1c-a/b; siswa alumni per lembaga; OD-AC-09 ubah data diri), 0.4 (rincian AC-1b), 0.3 (rincian teknis AC-1a), 0.2 (owner menyetujui semua usulan v0.1; OD-AC-04 s.d. OD-AC-07 diputuskan), 0.1 (draf pertama)
- **Status:** AC-1a SELESAI (commit `ccc183b`); AC-1b & AC-1c-a SELESAI (commit `759ac3d`); AC-1c-b1 dikerjakan (menunggu verifikasi owner); AC-2 dst. hanya digariskan
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

| Tahap                           | Isi                                                                                                                                                  | Catatan                                  |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------- |
| **AC-1 Data Siswa & Kelas**     | Kalender akademik (tahun ajaran, semester), siswa, pendaftaran per lembaga, orang tua/wali, kelas/rombel, anggota kelas, wali kelas, daftar & detail | **Dirinci di PRD ini** [OWNER, OD-AC-01] |
| AC-1e Impor siswa               | Impor siswa baru dari Excel/CSV, pola sama dengan HR-1e                                                                                              | Sesudah AC-1 [OWNER, OD-AC-07]           |
| AC-2 Kenaikan kelas & kelulusan | Proses akhir tahun ajaran: naik kelas massal, tinggal kelas, lulus massal                                                                            |                                          |
| AC-3 Mata pelajaran & jadwal    | Mapel per lembaga/tingkat, guru pengampu (pegawai HR), jadwal; wali kelas/guru melihat siswa kelasnya                                                | [OWNER, OD-AC-06]                        |
| AC-4 Presensi siswa             | Presensi harian/per pertemuan                                                                                                                        |                                          |
| AC-5 Penilaian & rapor          | Nilai, KKM (aturan berjenjang PRD-000 §8), rapor                                                                                                     | [LAMA §11: guru = Employee]              |
| AC-6 dst.                       | Akun orang tua, ekspor Dapodik/EMIS (butuh NIK), PPDB, asrama                                                                                        |                                          |

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

Prinsip yang sama dengan pegawai (PRD-001 §4.5): **minimal, tanpa NIK**, dengan tambahan NISN dan kontak orang tua/wali.

| Data                                    | Tempat                | Aturan                                       |
| --------------------------------------- | --------------------- | -------------------------------------------- |
| name, gender                            | `persons`             | **Nama dan jenis kelamin wajib** untuk siswa |
| birth_place, birth_date, phone, address | `persons` (sudah ada) | Opsional, aturan sama dengan pegawai         |
| nisn                                    | `ac_students`         | §4.2                                         |
| student_number                          | `ac_enrollments`      | §4.2                                         |

**Orang tua/wali** dicatat sebagai **Person + Membership** (bukan teks bebas di siswa) [OWNER, OD-AC-05]:

- Alasannya: kakak-adik berbagi orang tua yang sama tanpa input ulang, dan akun orang tua (AC-6) kelak tinggal ditambahkan pada Person yang sama. [LAMA §9–§10]
- Isian: nama (wajib), jenis kelamin (opsional), nomor HP (opsional), hubungan.
- **`ac_student_guardians`**: student_id, guardian_membership_id, relation `AYAH` \| `IBU` \| `WALI` \| `LAINNYA`, is_primary_contact. Satu siswa maksimal 4 orang tua/wali; maksimal satu kontak utama; orang yang sama tidak dobel untuk satu siswa.
- Membuat orang tua/wali **tidak** membuat akun login dan **tidak** memberi akses apa pun.
- Pekerjaan, penghasilan, pendidikan orang tua, NIK, dan No. KK **tidak** disimpan di AC-1 (dibutuhkan ekspor Dapodik/EMIS kelak, lewat `person_identifiers` terenkripsi PRD-000 §5).

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
- Kontrak HR baru [ASUMSI]: `Modules\HR\Contracts\ActiveEmployees` — daftar pegawai aktif yang punya penempatan berjalan di sebuah lembaga (id, nama) dan pemeriksaan "pegawai X aktif di lembaga Y". Dipakai untuk memilih dan memvalidasi wali kelas.
- **Jabatan "Wali Kelas" di HR tidak otomatis menjadi wali kelas** di Academic, dan menjadi wali kelas **tidak memberi hak akses** apa pun (jabatan bukan hak akses, PRD-001 §4.1). [LAMA]
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

| Sub-tahap | Isi                                                                                                                                                                         |
| --------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| AC-1a     | Skema semua tabel, permission & role Operator Akademik, kalender akademik + aturan `academic.calendar`, halaman Kalender akademik                                           |
| AC-1b     | Tambah siswa (orang baru / anggota), pendaftaran pertama, orang tua/wali, peringatan orang ganda                                                                            |
| AC-1c-a   | Daftar & detail siswa (penyaringan §5.3); menu **Siswa** → `/siswa`; setelah tambah siswa langsung ke detail                                                                |
| AC-1c-b1  | Aksi di detail: ubah data diri (OD-AC-09), daftarkan ke lembaga lain, **ubah pendaftaran** (nomor induk, tanggal masuk, jenis masuk), tutup pendaftaran [OWNER, 2026-10-18] |
| AC-1c-b2  | Orang tua/wali di detail: tambah (baru atau cari lewat nomor HP), ubah, lepas, ganti kontak utama                                                                           |
| AC-1d     | Kontrak HR `ActiveEmployees`, kelas/rombel, wali kelas, masukkan siswa massal, pindah/keluarkan                                                                             |

Rincian AC-1c-b1 [OWNER, 2026-10-18 — usulan disetujui]:

- **Ubah data diri** (nama, L/P, tempat/tanggal lahir, HP, alamat, NISN): Operator Akademik di lembaga mana pun tempat siswa **aktif** + Admin Yayasan (OD-AC-09), dan wajib berhak melihat data pribadi. Kolom yang dikosongkan = datanya dihapus. Data diri milik Person, jadi perubahan nama ikut berlaku di data pegawai/akun login bila orang itu juga punya. Audit hanya nama field.
- **Daftarkan ke lembaga lain**: lembaga aktif di cakupan izin kelola yang belum mencatat siswa sebagai siswa aktif; daftar ulang setelah keluar = pendaftaran baru, riwayat lama tetap. Nomor induk yang sama boleh dipakai lagi oleh siswa yang sama.
- **Ubah pendaftaran**: memperbaiki nomor induk, tanggal masuk, jenis masuk, baik pendaftaran berjalan maupun riwayat; tanggal masuk tidak boleh sesudah tanggal keluar atau sesudah siswa masuk kelas pertamanya. Hanya di lembaga yang dikelola.
- **Tutup pendaftaran**: tanggal keluar (tidak sebelum tanggal masuk, tidak di masa depan, tidak sebelum siswa masuk kelasnya), alasan, keterangan (wajib bila "Lainnya", maks. 500). Kelas terbuka di lembaga itu ikut ditutup di tanggal yang sama. Audit tidak menyimpan isi keterangan. Membatalkan penutupan belum tersedia (lihat §14).
- Audit baru: `academic.student_updated`, `academic.enrollment_updated`, `academic.enrollment_closed`.

## 12. Di luar AC-1

Kenaikan kelas massal, mata pelajaran, jadwal, presensi, nilai, rapor, akun orang tua, PPDB, asrama, ekspor pemerintah, NIK/No. KK, dan data sosial-ekonomi orang tua.

## 13. Open decisions

| ID       | Topik                                                                         | Status                                                                                                                                                       |
| -------- | ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| OD-AC-01 | Cakupan tahap pertama                                                         | **DIPUTUSKAN** — data siswa + pendaftaran + tahun ajaran + kelas/rombel + wali kelas; jadwal, presensi, nilai menyusul [OWNER, 2026-10-17]                   |
| OD-AC-02 | Data pribadi siswa                                                            | **DIPUTUSKAN** — minimal seperti pegawai (tanpa NIK) + NISN + kontak orang tua/wali [OWNER, 2026-10-17]                                                      |
| OD-AC-03 | Tahun ajaran & semester                                                       | **DIPUTUSKAN** — default yayasan, lembaga boleh beda [OWNER, 2026-10-17]; mekanismenya kalender + aturan berjenjang (§4.4) **[ASUMSI]**                      |
| OD-AC-04 | Siapa mengelola data siswa & kelas                                            | **DIPUTUSKAN** — role baru Operator Akademik per lembaga (+ Admin Yayasan); Kepala Lembaga hanya melihat [OWNER, 2026-10-17]                                 |
| OD-AC-05 | Orang tua/wali sebagai Person                                                 | **DIPUTUSKAN** — Person + Membership tanpa akun; tanpa pekerjaan/penghasilan/NIK [OWNER, 2026-10-17]                                                         |
| OD-AC-06 | Wali kelas/guru melihat siswa kelasnya sendiri                                | **DIPUTUSKAN** — ditunda ke AC-3 (butuh hak akses berbasis kelas, bukan pohon lembaga) [OWNER, 2026-10-17]                                                   |
| OD-AC-07 | Impor siswa dari Excel/CSV                                                    | **DIPUTUSKAN** — AC-1e sesudah AC-1, memakai pola HR-1e [OWNER, 2026-10-17]                                                                                  |
| OD-AC-09 | Siapa boleh mengubah data diri siswa yang terdaftar di beberapa lembaga       | **DIPUTUSKAN** — Operator Akademik dari lembaga mana pun tempat siswa masih aktif, dan Admin Yayasan; setiap perubahan tercatat di audit [OWNER, 2026-10-18] |
| OD-AC-08 | Model data, permission, penyaringan, kalender lewat aturan berjenjang (§4–§5) | **DISETUJUI** — owner menyetujui semua usulan v0.1 [OWNER, 2026-10-17]                                                                                       |

## 14. Saran tambahan (di luar ruang lingkup)

- **Ekspor daftar siswa per kelas** (CSV/xlsx) untuk absen manual dan keperluan TU, dengan saringan dan izin yang sama seperti halaman daftar.
- **Batalkan penutupan pendaftaran** (salah tutup): saat ini harus didaftarkan ulang sebagai pendaftaran baru. Bisa ditambahkan aksi "Buka kembali" khusus Admin Yayasan, tercatat di audit.
