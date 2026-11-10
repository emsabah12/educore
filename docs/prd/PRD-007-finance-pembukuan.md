# PRD-007 — Modul Finance (Keuangan): Pembukuan (COA, Jurnal, Laporan ISAK 35)

- **Versi:** 0.5 (2026-11-09: rincian & pembangunan PB-3, §18)
- **Versi sebelumnya:** 0.4 (PB-2b, §17), 0.3 (PB-2 dipecah; PB-2a, §16), 0.2 (disetujui owner; PB-1, §15), 0.1 (draf pertama, 2026-11-04)
- **Status:** **DISETUJUI (2026-11-05)**; PB-1 SELESAI (`c602d11`); PB-2a SELESAI (`03bde32`); PB-2b SELESAI (`71a92ca`); PB-3 dibangun 2026-11-09, menunggu uji owner
- **Bergantung pada:** PRD-003 (tagihan, potongan, kuitansi), PRD-004 (kas, rekening, kategori, pengeluaran, mutasi), PRD-005 (tabungan, rekening Titipan), PRD-006 (pembayaran online), PRD-000 (pohon lembaga, akses, audit), ADR-001
- **Lanjutan dari:** PRD-004 §11, urutan owner 2026-10-30: (1) persetujuan berjenjang (selesai) → (2) tabungan siswa (selesai) → (3) Midtrans (selesai) → **(4) pembukuan**

Label: **[OWNER]** = keputusan owner · **[ASUMSI]** = usulan, perlu konfirmasi.

---

## 1. Tujuan

Yayasan punya **pembukuan berpasangan (double-entry)** yang tersusun otomatis dari transaksi yang sudah dicatat di aplikasi. Hasilnya adalah **laporan keuangan format ISAK 35** (entitas nirlaba) yang bisa dibuka kapan saja untuk satu lembaga atau seluruh yayasan, tanpa input ulang di Excel.

Prinsip:

1. **Satu sumber data.** Kasir, kas, pengeluaran, tabungan, dan pembayaran online tetap menjadi tempat mencatat. Pembukuan **membaca** transaksi itu dan tidak mengubahnya.
2. **Selalu seimbang.** Setiap jurnal debit = kredit, dijaga di server dan di database.
3. **Selalu cocok dengan buku pembantu.** Saldo akun kas = buku kas, piutang siswa = total tunggakan, utang titipan = total saldo tabungan. Selisih ditampilkan, bukan disembunyikan.
4. **Tidak ada yang dihapus.** Jurnal salah dibatalkan dengan alasan (sama dengan PRD-003 §1 butir 2).

## 2. Keputusan owner (2026-11-04)

| Kode     | Topik                   | Keputusan                                                                                                                            |
| -------- | ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| OD-PB-01 | Cara jurnal dibuat      | **Otomatis + jurnal manual**: semua transaksi aplikasi otomatis jadi jurnal; akuntan bisa menambah jurnal penyesuaian manual [OWNER] |
| OD-PB-02 | Format laporan          | **ISAK 35 nirlaba**: posisi keuangan, penghasilan komprehensif, perubahan aset neto, arus kas [OWNER]                                |
| OD-PB-03 | Pengakuan tagihan siswa | **Akrual**: tagihan terbit = pendapatan + piutang siswa; pembayaran melunasi piutang [OWNER]                                         |

## 3. Konsep & model data [ASUMSI]

```
Akun / COA (fn_ledger_accounts)     per yayasan: kode (mis. 1-1100), nama, golongan ASET | LIABILITAS | ASET_NETO |
                                    PENDAPATAN | BEBAN, induk (akun judul) / akun posting, aktif, akun sistem (terkunci),
                                    "dengan pembatasan" (pendapatan & aset neto), aktivitas arus kas (operasi |
                                    investasi | pendanaan) untuk akun non-kas
Pemetaan                            kolom akun di tabel yang sudah ada:
                                    - fn_cash_accounts.ledger_account_id   (rekening kas → akun Kas/Bank)
                                    - fn_cash_categories.ledger_account_id (kategori kas → akun pendapatan/beban/aset)
                                    - fn_fee_types.ledger_account_id       (jenis biaya → akun pendapatan)
Jurnal (fn_journals)                per yayasan: nomor, tanggal, uraian, sumber (TAGIHAN | KAS | MUTASI | SALDO_REKENING |
                                    SALDO_AWAL | MANUAL) + id sumber, status SAH | BATAL (+ alasan), dicatat oleh
Baris jurnal (fn_journal_lines)     jurnal, akun, lembaga (kosong = Yayasan), rekening kas (bila akun kas), debit, kredit
Pengaturan pembukuan                per yayasan: tanggal mulai pembukuan, bulan awal tahun buku
```

### 3.1 Satu buku yayasan, lembaga sebagai dimensi (OD-PB-05)

- Satu COA dan satu buku besar per yayasan. Setiap baris jurnal membawa **lembaga** (LEMBAGA atau kosong = Yayasan, sama dengan tingkat kas di PRD-004 OD-KS-02).
- Laporan bisa dibuka untuk **satu lembaga**, **Yayasan pusat saja**, atau **gabungan seluruh yayasan**.
- Uang yang pindah antar-kas (setoran lembaga ke Yayasan, subsidi Yayasan ke lembaga; PRD-004 KS-4) dicatat lewat akun sistem **Rekening antar-kantor (RAK)**. Dengan begitu buku tiap lembaga tetap seimbang. Pada laporan gabungan, saldo RAK saling hapus (jumlahnya nol).

### 3.2 COA bawaan [ASUMSI]

Dibuat otomatis saat halaman Pembukuan pertama kali dibuka. Akun boleh ditambah, diubah nama, dan dinonaktifkan. Akun **sistem** (bertanda 🔒) tidak bisa dinonaktifkan atau dipakai di jurnal manual.

| Kode   | Akun                            | Golongan   | Catatan                                                               |
| ------ | ------------------------------- | ---------- | --------------------------------------------------------------------- |
| 1-1100 | Kas tunai                       | Aset       | Bawaan untuk rekening kas jenis TUNAI                                 |
| 1-1200 | Bank                            | Aset       | Bawaan untuk rekening kas jenis BANK                                  |
| 1-1300 | Kas titipan tabungan siswa 🔒   | Aset       | Rekening kas jenis TITIPAN (PRD-005); kas yang dibatasi penggunaannya |
| 1-1400 | Piutang siswa 🔒                | Aset       | Dari tagihan (akrual)                                                 |
| 1-1900 | Aset lancar lain                | Aset       | Uang muka, persediaan, dll. (jurnal manual)                           |
| 1-2100 | Tanah & bangunan                | Aset       | Aset tetap                                                            |
| 1-2200 | Peralatan & inventaris          | Aset       | Aset tetap                                                            |
| 1-2900 | Akumulasi penyusutan            | Aset       | Kontra aset, saldo normal kredit                                      |
| 1-9100 | Rekening antar-kantor (RAK) 🔒  | Aset       | Hanya untuk mutasi antar-kas (§3.1)                                   |
| 2-1100 | Utang titipan tabungan siswa 🔒 | Liabilitas | Pasangan 1-1300                                                       |
| 2-1900 | Utang lain-lain                 | Liabilitas |                                                                       |
| 3-1000 | Aset neto tanpa pembatasan 🔒   | Aset neto  | Saldo awal + surplus/defisit tahun lalu                               |
| 3-2000 | Aset neto dengan pembatasan 🔒  | Aset neto  |                                                                       |
| 4-1100 | Pendapatan biaya pendidikan     | Pendapatan | Bawaan untuk semua jenis biaya                                        |
| 4-1900 | Potongan & beasiswa             | Pendapatan | Kontra pendapatan, saldo normal debit (OD-PB-06)                      |
| 4-2100 | Pendapatan dana BOS             | Pendapatan | Kategori kas "Dana BOS"                                               |
| 4-3100 | Donasi / infak                  | Pendapatan | Kategori kas "Donasi / infak"                                         |
| 4-9100 | Pendapatan lain-lain            | Pendapatan | Kategori kas "Pemasukan lain-lain" + kategori MASUK baru              |
| 5-1100 | Beban listrik, air & internet   | Beban      | Kategori kas yang namanya sama                                        |
| 5-1200 | Beban ATK & perlengkapan        | Beban      |                                                                       |
| 5-1300 | Beban kegiatan                  | Beban      |                                                                       |
| 5-1400 | Beban pemeliharaan              | Beban      |                                                                       |
| 5-1500 | Beban honor non-gaji            | Beban      |                                                                       |
| 5-1600 | Beban gaji pegawai              | Beban      | Jurnal manual / kategori kas buatan sendiri (§11)                     |
| 5-1700 | Beban penyusutan                | Beban      | Jurnal manual                                                         |
| 5-9100 | Beban lain-lain                 | Beban      | Kategori kas "Pengeluaran lain-lain" + kategori KELUAR baru           |

Pemetaan bawaan mengikuti kolom "Catatan". Kategori kas atau jenis biaya baru otomatis dipetakan ke akun "lain-lain" atau "biaya pendidikan" dan bisa diubah kapan saja.

## 4. Jurnal otomatis (OD-PB-01, OD-PB-03) [ASUMSI]

Setiap perubahan transaksi sumber dicatat ke **antrean pembukuan** oleh trigger database **di transaksi database yang sama**, lalu jurnal disusun dari antrean itu tiap menit (scheduler) dan selalu sebelum halaman pembukuan dibuka (§15). Akibatnya tidak ada transaksi yang terlewat dijurnal, kasir tidak melambat, dan tidak ada antrean kunci antar-kasir.

| Kejadian di aplikasi                                   | Debit                                        | Kredit                                     |
| ------------------------------------------------------ | -------------------------------------------- | ------------------------------------------ |
| Tagihan terbit (PRD-003)                               | Piutang siswa (nominal akhir)                | Pendapatan jenis biaya (tarif)             |
|                                                        | Potongan & beasiswa (potongan, bila ada)     |                                            |
| Tagihan Rp 0 karena potongan penuh                     | Potongan & beasiswa                          | Pendapatan jenis biaya                     |
| Kuitansi Tunai / Transfer / Online (cerminan buku kas) | Akun rekening kas penerima                   | Piutang siswa                              |
| Kuitansi dari tabungan (PRD-005 TB-3)                  | Utang titipan tabungan                       | Kas titipan tabungan                       |
|                                                        | Kas tunai lembaga                            | Piutang siswa                              |
| Setoran tabungan                                       | Kas titipan tabungan                         | Utang titipan tabungan                     |
| Penarikan tabungan                                     | Utang titipan tabungan                       | Kas titipan tabungan                       |
| Pemasukan manual di Kas (PRD-004 KS-1)                 | Akun rekening kas                            | Akun kategori (mis. Pendapatan dana BOS)   |
| Realisasi pengeluaran (PRD-004 KS-3)                   | Akun kategori (beban, atau aset tetap)       | Akun rekening kas                          |
| Mutasi dalam satu kas (mis. bank → tunai)              | Akun rekening tujuan                         | Akun rekening asal                         |
| Mutasi antar-kas (lembaga → Yayasan)                   | Lembaga: RAK · Yayasan: akun rekening tujuan | Lembaga: akun rekening asal · Yayasan: RAK |

- **Tanggal jurnal**: tanggal transaksi sumber (tanggal kas, tanggal bayar, tanggal slip). Untuk tagihan **bulanan**, tanggalnya tanggal 1 bulan periode. Untuk tagihan **sekali bayar**, tanggalnya tanggal tagihan dibuat.
- **Nomor jurnal**: `JU-<TAHUN>-<urut>` untuk semua jurnal; urutan selalu naik dan tidak dipakai ulang, tetapi boleh melompat (§15). Sumber tetap ditautkan, sehingga jurnal bisa diklik ke kuitansi, tagihan, transaksi kas, atau pengeluarannya.
- **Batal transaksi sumber** (OD-PB-08): jurnalnya ikut **BATAL** pada tanggal aslinya, sama dengan buku kas yang membuang transaksi batal dari saldo. Inilah yang membuat pembukuan selalu cocok dengan buku pembantu. Setelah ada kunci periode (PB-4), transaksi bertanggal di periode terkunci tidak bisa dibatalkan atau dicatat mundur.
- **Pembayaran online** mengikuti PRD-006 §7 apa adanya. Biaya layanan dan kelebihan bayar tidak dibukukan karena memang tidak masuk buku kas. Uang dianggap masuk bank pada tanggal lunas, walaupun pencairan Midtrans baru D+1/D+2 (selisih waktu diterima, rekonsiliasi bank di luar cakupan).
- **Bayar sebelum tagihan bulannya tiba** (mis. SPP Desember dibayar bulan November): piutang siswa sementara bersaldo kredit sampai bulan Desember. Ini disederhanakan; laporan tidak memindahkannya ke "pendapatan diterima di muka" [ASUMSI].
- **Mengubah pemetaan** hanya berlaku untuk transaksi **baru**. Untuk menerapkannya ke transaksi lama, petugas memakai tombol **Susun ulang jurnal otomatis** untuk periode yang belum dikunci.

## 5. Mulai pembukuan & saldo awal (OD-PB-10) [ASUMSI]

- Admin memilih **tanggal mulai pembukuan** (mis. 1 Januari 2027) dan bulan awal **tahun buku** (OD-PB-04).
- Jurnal otomatis dibuat untuk semua transaksi sejak tanggal itu, termasuk transaksi lama yang sudah ada di aplikasi (**susun awal**).
- **Jurnal saldo awal** disusun otomatis per lembaga pada tanggal mulai:
    - kas & bank = saldo tiap rekening kas sehari sebelumnya;
    - piutang siswa = sisa tagihan terbuka;
    - kas titipan dan utang titipan = total saldo tabungan;
    - selisihnya masuk **Aset neto tanpa pembatasan**.
- Aset yang tidak tercatat di aplikasi (tanah, bangunan, peralatan, utang lain) ditambahkan oleh akuntan di jurnal saldo awal itu. Sebelum pembukuan berjalan, jurnal saldo awal boleh diubah. Setelah itu, perubahan dilakukan lewat jurnal manual.

## 6. Jurnal manual (OD-PB-01, OD-PB-09) [ASUMSI]

- Isinya tanggal, uraian (≤ 200), dan minimal 2 baris. Tiap baris berisi akun posting aktif, lembaga, dan debit atau kredit (Rp 1 s.d. Rp 999.999.999.999). Total debit wajib sama dengan total kredit.
- **Tidak boleh** memakai akun yang terhubung ke rekening kas atau akun sistem (Kas titipan, Piutang siswa, Utang titipan, RAK). Uang yang benar-benar keluar/masuk dicatat di modul Kas, supaya buku kas tetap benar.
- Contoh pemakaian: penyusutan, pencatatan aset tetap hibah, penyisihan piutang tak tertagih, pelepasan aset neto dengan pembatasan, koreksi salah pemetaan, beban gaji yang dibayar lewat rekening di luar aplikasi.
- **Batal** dengan alasan (≤ 200); nomor tidak dipakai ulang. Tidak ada persetujuan berjenjang; setiap simpan atau batal tercatat di audit.

## 7. Laporan (OD-PB-02) [ASUMSI]

Semua laporan bisa disaring per **periode** (bulan, triwulan, tahun buku, atau rentang tanggal) dan **lingkup** (satu lembaga, Yayasan pusat, atau gabungan). Semua bisa diunduh .xlsx (openspout, library yang sudah dipakai). Hitungan dilakukan di server.

| Laporan                                | Isi                                                                                                                                               |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Daftar jurnal**                      | Nomor, tanggal, uraian, sumber (bisa diklik), total; saring sumber/status; rincian baris                                                          |
| **Buku besar**                         | Per akun: saldo awal periode, mutasi debit/kredit dengan saldo berjalan, saldo akhir                                                              |
| **Neraca saldo**                       | Semua akun: saldo awal, debit, kredit, saldo akhir; total debit = kredit                                                                          |
| **Laporan posisi keuangan**            | Aset lancar & tidak lancar, liabilitas, aset neto tanpa/dengan pembatasan, per tanggal; kolom pembanding akhir tahun buku lalu                    |
| **Laporan penghasilan komprehensif**   | Pendapatan & beban tanpa pembatasan → surplus/defisit; pendapatan dengan pembatasan & pelepasannya; kolom pembanding periode yang sama tahun lalu |
| **Laporan perubahan aset neto**        | Saldo awal, surplus/defisit, pelepasan pembatasan, saldo akhir — per golongan aset neto                                                           |
| **Laporan arus kas (metode langsung)** | Penerimaan & pengeluaran kas per aktivitas operasi/investasi/pendanaan dari akun lawan; kas awal + kenaikan = kas akhir (kas titipan dipisah)     |
| **Cek kecocokan**                      | Akun kas per rekening vs buku kas; Piutang siswa vs total tunggakan; Utang titipan vs total saldo tabungan; RAK gabungan = 0                      |

- **Surplus/defisit tahun lalu** dihitung saat laporan dibuat (pendapatan − beban sebelum awal tahun buku dimasukkan ke aset neto tanpa pembatasan). Tidak perlu jurnal tutup tahun.
- **Pembatasan** (OD-PB-07): pendapatan dari akun bertanda "dengan pembatasan" masuk kolom aset neto dengan pembatasan. Bawaannya semua akun **tanpa pembatasan**. Akuntan yang ingin memisahkan, mis. dana BOS atau donasi terikat, cukup menandai akunnya, lalu mencatat pelepasannya lewat jurnal manual.

## 8. Siapa melihat & mengelola [ASUMSI]

| Permission              | Isi                                                                                                        |
| ----------------------- | ---------------------------------------------------------------------------------------------------------- |
| `finance.ledger.view`   | Lihat daftar jurnal, buku besar, neraca saldo, laporan, cek kecocokan; unduh .xlsx                         |
| `finance.ledger.manage` | Kelola COA & pemetaan, pengaturan pembukuan, saldo awal, jurnal manual + batal, susun ulang, kunci periode |

| Role                     | Permission    | Cakupan                                              |
| ------------------------ | ------------- | ---------------------------------------------------- |
| Admin Keuangan (Yayasan) | view + manage | Seluruh yayasan                                      |
| Admin Yayasan            | view + manage | Seluruh yayasan                                      |
| Pimpinan                 | view          | Cakupannya (tingkat Yayasan → gabungan)              |
| Kepala Lembaga           | view          | Lembaganya (laporan lingkup lembaga itu saja)        |
| Bendahara                | —             | Tetap di Kas/Kasir; tidak melihat pembukuan [ASUMSI] |

- COA, pemetaan, pengaturan, dan jurnal manual hanya di tingkat **Yayasan** ("Seluruh Yayasan", PRD-000 §6). Hanya pemilik izin di tingkat Yayasan yang boleh membuka laporan gabungan dan lingkup Yayasan pusat.

## 9. Halaman (ringkas) [ASUMSI]

Menu samping kelompok baru **Pembukuan** (pedoman tampilan STATUS: satu fungsi per halaman, bagian berbeda jadi tab):

- **Jurnal** — tab Daftar jurnal · Jurnal manual (tombol **Catat jurnal** di jendela pop-up dengan baris dinamis + total debit/kredit langsung terlihat).
- **Buku besar** — pilih akun + periode + lingkup.
- **Laporan keuangan** — tab Posisi keuangan · Penghasilan komprehensif · Perubahan aset neto · Arus kas · Neraca saldo; tombol Unduh .xlsx.
- **Pengaturan pembukuan** — tab Akun (COA berbentuk pohon) · Pemetaan (rekening kas, kategori kas, jenis biaya) · Saldo awal · Periode.
- **Beranda**: kartu **Cek kecocokan** bila ada selisih (untuk `finance.ledger.manage`).

## 10. Tahapan [ASUMSI]

| Tahap     | Isi                                                                                                                                                                        |
| --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **PB-1**  | Hak akses, COA bawaan + kelola akun, pemetaan, pengaturan (tanggal mulai, tahun buku), jurnal otomatis dari semua sumber (sinkron + susun awal/susun ulang), daftar jurnal |
| **PB-2a** | Saldo awal otomatis + tambahan akuntan, jurnal manual + batal                                                                                                              |
| **PB-2b** | Buku besar, neraca saldo, cek kecocokan (+ kartu Beranda), unduh .xlsx                                                                                                     |
| **PB-3**  | Laporan ISAK 35: posisi keuangan, penghasilan komprehensif, perubahan aset neto, arus kas metode langsung; saring periode & lingkup; kolom pembanding; unduh .xlsx         |
| **PB-4**  | Kunci periode (tutup bulan): Kasir, Kas, Pengeluaran, Tabungan, dan Online menolak transaksi/batal bertanggal di periode terkunci dengan pesan jelas; buka kunci + audit   |

## 11. Di luar tahap ini

Anggaran (RAPBS) & realisasi anggaran, modul aset tetap & penyusutan otomatis, integrasi gaji HR (PRD-001 HR-5) langsung ke jurnal, rekonsiliasi mutasi bank otomatis, pajak (PPh 21/23), pendapatan diterima di muka otomatis, multi mata uang, laporan konsolidasi antar-yayasan. Sampai itu ada, penyusutan, aset tetap, dan beban gaji dicatat lewat jurnal manual atau pengeluaran biasa.

## 12. Audit

`finance.ledger_account_created|updated|deactivated`, `finance.ledger_mapping_set`, `finance.ledger_settings_set`, `finance.opening_balance_set`, `finance.journal_posted|voided` (hanya jurnal manual & saldo awal; jurnal otomatis sudah diaudit lewat transaksi sumbernya), `finance.journals_rebuilt` (periode, jumlah), `finance.period_locked|unlocked`.

## 13. Kebutuhan non-fungsional

- Debit = kredit dijaga di kode **dan** dicek di database. Nominal disimpan dalam rupiah bulat (`bigint`), sama dengan modul lain.
- Jurnal otomatis tidak boleh membuat transaksi sumber gagal kecuali datanya memang tidak konsisten. Kalau pemetaan kosong, dipakai akun bawaan.
- Susun awal/susun ulang aman diulang (idempoten) dan berjalan per potongan data: dari halaman (tiap permintaan ±5 detik, diulang otomatis sampai selesai) atau perintah `php artisan finance:ledger-process` (§15).
- Laporan satu tahun buku untuk seluruh yayasan terbuka < 3 detik. Agregasi memakai indeks (akun, tanggal, lembaga).
- ADR-001: pembukuan berada di `modules/Finance`. Modul lain tidak bergantung padanya.

## 14. Open decisions

| Kode     | Pertanyaan                               | Usulan                                                                                                                         |
| -------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| OD-PB-04 | Tahun buku                               | **DIPUTUSKAN** — Januari–Desember; bisa diatur ke bulan lain (mis. Juli–Juni) [OWNER, 2026-11-05]                              |
| OD-PB-05 | Satu buku atau buku terpisah per lembaga | **DIPUTUSKAN** — satu buku yayasan, lembaga sebagai dimensi + akun RAK (§3.1) [OWNER, 2026-11-05]                              |
| OD-PB-06 | Potongan/beasiswa                        | **DIPUTUSKAN** — bruto + akun kontra "Potongan & beasiswa" [OWNER, 2026-11-05]                                                 |
| OD-PB-07 | Aset neto dengan pembatasan              | **DIPUTUSKAN** — bawaan tanpa pembatasan; akun bisa ditandai; pelepasan lewat jurnal manual [OWNER, 2026-11-05]                |
| OD-PB-08 | Transaksi sumber dibatalkan              | **DIPUTUSKAN** — jurnal ikut BATAL di tanggal aslinya; tanggal mundur dicegah setelah kunci periode (PB-4) [OWNER, 2026-11-05] |
| OD-PB-09 | Jurnal manual perlu persetujuan?         | **DIPUTUSKAN** — tidak, cukup izin `finance.ledger.manage` + audit [OWNER, 2026-11-05]                                         |
| OD-PB-10 | Tanggal mulai & saldo awal               | **DIPUTUSKAN** — dipilih admin; saldo awal otomatis dari aplikasi + tambahan akuntan (§5) [OWNER, 2026-11-05]                  |
| OD-PB-11 | Bendahara melihat pembukuan?             | **DIPUTUSKAN** — tidak; Kepala Lembaga & Pimpinan lihat sesuai cakupannya [OWNER, 2026-11-05]                                  |

## 15. PB-1 COA, pemetaan, periode, jurnal otomatis, daftar jurnal [ASUMSI — rincian teknis 2026-11-05]

- **Hak akses**: permission baru `finance.ledger.view` (Admin Keuangan, Admin Yayasan, Pimpinan, Kepala Lembaga) & `finance.ledger.manage` (Admin Keuangan, Admin Yayasan). Pengaturan hanya izin tingkat Yayasan (403 selain itu). Jurnal: izin tingkat Yayasan → lingkup Gabungan / Yayasan (pusat) / tiap lembaga; izin lembaga → hanya lembaganya (lingkup lain di URL diabaikan, rincian jurnal lembaga lain 404). Bendahara 403.
- **Data**: migration `300016` — `fn_ledger_accounts`, `fn_ledger_settings`, `fn_journals`, `fn_journal_lines`, `fn_ledger_queue`, kolom `ledger_account_id` di `fn_cash_accounts` / `fn_cash_categories` / `fn_fee_types`. Satu jurnal per sumber (indeks unik sumber + id). Debit = kredit = jumlah jurnal dijaga **constraint trigger** yang dicek saat COMMIT; baris hanya debit **atau** kredit (> 0).
- **COA bawaan** (§3.2) dibuat saat Pengaturan pembukuan pertama dibuka, dengan akun judul pengelompok: 1-1000 Aset lancar, 1-2000 Aset tidak lancar, 1-9000 Antar-kantor, 2-1000 Liabilitas jangka pendek, 3-0000 Aset neto, 4-0000 Pendapatan, 5-0000 Beban (33 akun). Kategori kas bawaan langsung dipetakan (BOS → 4-2100, ATK → 5-1200, dst.).
- **Akun**: kode 2–20 (angka, ".", "-", diawali angka), unik, tetap; nama 1–100; golongan, judul/posting, saldo normal (kontra = kebalikan), dan "akun kas/bank" tetap; nama, induk (akun judul golongan sama, satu tingkat), "dengan pembatasan" (pendapatan/aset neto), aktivitas arus kas boleh diubah. Akun **Otomatis** (dipakai jurnal otomatis) tidak bisa dinonaktifkan; empat di antaranya **terkunci** dari jurnal manual (Kas titipan, Piutang siswa, RAK, Utang titipan). Nonaktif juga ditolak bila akun masih dipetakan atau akun judul masih punya akun aktif.
- **Pemetaan** (kosong = bawaan): rekening TUNAI/BANK → akun kas/bank (bawaan 1-1100 / 1-1200; Titipan selalu 1-1300); kategori MASUK → pendapatan (bawaan 4-9100), KELUAR → beban atau aset non-kas (bawaan 5-9100); jenis biaya → pendapatan (bawaan 4-1100; akun kontra tidak bisa dipilih). Kategori otomatis tidak dipetakan.
- **Periode**: tanggal mulai = tanggal 1 suatu bulan (paling lambat setahun dari hari ini), bulan awal tahun buku 1–12. Mengubah tanggal mulai atau pemetaan → "perlu disusun ulang".
- **Jurnal otomatis** (§4): sumber TAGIHAN, KAS (semua transaksi kas selain mutasi: cerminan kuitansi → lawan Piutang siswa; tabungan → lawan Utang titipan; lainnya → akun kategori), MUTASI (satu jurnal per mutasi; antar-kas lewat RAK di kedua kas), dan **SALDO_REKENING** — saldo awal rekening kas yang tanggal saldo awalnya **pada/sesudah** tanggal mulai (Dr akun rekening / Cr Aset neto tanpa pembatasan; Titipan: Cr Utang titipan). Rekening yang dibuka sebelum tanggal mulai masuk jurnal saldo awal PB-2. Sumber bertanggal sebelum tanggal mulai tidak dijurnal.
- **Antrean**: trigger di `fn_cash_entries` (semua perubahan), `fn_invoices` (dibuat, batal/tidak batal, nominal, periode, jenis biaya — bukan perubahan sisa bayar), `fn_cash_accounts` (saldo/tanggal saldo awal) mengisi `fn_ledger_queue` hanya bila pembukuan yayasan aktif. Antrean diproses (≤ 2.000 sumber) sebelum halaman Jurnal tampil dan tiap menit lewat `php artisan finance:ledger-process` (scheduler; aman bersamaan, `FOR UPDATE SKIP LOCKED`).
- **Proyeksi**: untuk tiap sumber dihitung jurnal yang seharusnya lalu disamakan — sumber hilang (cerminan kas disusun ulang) atau sebelum tanggal mulai → jurnalnya dihapus; sumber batal → BATAL dengan alasan sumber ("Pembayaran dibatalkan: …", "Tagihan dibatalkan: …"); selain itu dibuat/diperbarui dengan **nomor tetap**. Uraian: tagihan "Tagihan SPP — November 2026 — Nama (Kelas)", kas = uraian buku kas, mutasi "Mutasi <rekening (kas)> → <rekening (kas)> — <uraian>".
- **Nomor** `JU-<tahun tanggal jurnal>-<6 digit>` dari sequence database (tanpa kunci bersama antar-kasir); naik terus, boleh melompat, tidak dipakai ulang.
- **Susun ulang** (tab Periode): `fn_ledger_settings` menyimpan versi & posisi; tiap permintaan memproses ±5 detik (urutan: saldo rekening → tagihan → kas → mutasi → bersihkan jurnal yatim) lalu halaman mengulang otomatis dengan bilah kemajuan; tombol bisa memaksa susun ulang walau tidak ada perubahan. Scheduler juga menyelesaikan susun ulang yang tertunda. Audit `finance.journals_rebuilt`.
- **Halaman**: menu baru **Pembukuan** → **Jurnal** (`/keuangan/pembukuan/jurnal`: saring lingkup, bulan yang punya jurnal, sumber, status; cari nomor/uraian; 50 per halaman; pemberitahuan bila belum aktif / perlu disusun ulang / masih ada antrean) dan rincian (`/jurnal/{id}`: baris akun–lembaga–rekening, debit/kredit, total, tautan ke kuitansi / slip tabungan / kasir siswa / buku kas / pengeluaran); **Pengaturan pembukuan** (`/keuangan/pembukuan/pengaturan`, tab Akun · Pemetaan · Periode).
- **Audit**: `finance.ledger_account_created|updated|deactivated|activated`, `finance.ledger_mapping_set`, `finance.ledger_settings_set`, `finance.journals_rebuilt`.
- **Belum di PB-1**: jurnal saldo awal & jurnal manual (PB-2a); buku besar, neraca saldo, cek kecocokan (PB-2b); laporan ISAK 35 (PB-3); kunci periode (PB-4).

## 16. PB-2a Saldo awal & jurnal manual [ASUMSI — rincian teknis 2026-11-05]

- **Jurnal saldo awal** (sumber SALDO_AWAL, satu per lembaga + satu untuk Kas Yayasan, tanggal = tanggal mulai, uraian "Saldo awal SMP Unit 3 per 1 November 2026"), disusun otomatis dan diperbarui lewat antrean bila datanya berubah:
    - **kas & bank**: tiap rekening yang tanggal saldo awalnya **sebelum** tanggal mulai = saldo awal rekening + transaksi kas SAH sebelum tanggal mulai (sama dengan buku kas). Rekening Titipan → Dr Kas titipan / Cr Utang titipan sebesar saldonya;
    - **piutang siswa** (lembaga): tagihan tidak batal bertanggal sebelum tanggal mulai − kuitansi SAH sebelum tanggal mulai (boleh minus bila ada bayar di muka);
    - **saldo tambahan akuntan** (tab Saldo awal);
    - **selisih** → Aset neto tanpa pembatasan. Lembaga yang semua saldonya nol tidak mendapat jurnal.
- **Antrean saldo awal**: trigger di transaksi kas, kuitansi (dibuat/batal/tanggal/nominal), tagihan (dibuat/batal/nominal/periode; sekali bayar selalu), dan rekening kas (saldo/tanggal saldo awal) yang bertanggal sebelum tanggal mulai mengantrekan jurnal saldo awal lembaganya. Susun ulang juga menyusun semua jurnal saldo awal.
- **Saldo tambahan** (`fn_opening_items`, Pengaturan pembukuan → tab **Saldo awal**, pilih Kas Yayasan / lembaga): akun posting aktif selain akun kas/bank, akun otomatis terkunci, dan Aset neto tanpa pembatasan; posisi debit/kredit; nominal Rp 1 s.d. Rp 999.999.999.999; keterangan ≤ 100. Boleh tambah/ubah/hapus kapan saja (sampai kunci periode PB-4); audit `finance.opening_balance_set`. Halaman menampilkan rancangan jurnal saldo awal (dihitung dari data terbaru) beserta totalnya.
- **Jurnal manual** (Jurnal → **Catat jurnal manual**, `finance.ledger.manage` tingkat Yayasan): tanggal sejak tanggal mulai s.d. akhir bulan berjalan; uraian 3–200; 2–50 baris (baris kosong diabaikan), tiap baris akun (posting aktif, bukan kas/bank, bukan akun otomatis terkunci), lembaga (Yayasan / lembaga aktif), debit **atau** kredit; total debit = kredit (formulir menampilkan total & selisih, tombol simpan aktif bila seimbang; dicek ulang di server). Tidak bisa diubah; **Batalkan jurnal** di halaman rinciannya (alasan ≤ 200) → BATAL, tetap tampil. Jurnal otomatis hanya batal lewat transaksi sumbernya. Audit `finance.journal_posted|voided`. Susun ulang tidak menyentuh jurnal manual.
- **Daftar jurnal**: saringan sumber kini memuat Saldo awal & Jurnal manual; rincian jurnal saldo awal menautkan ke tab Saldo awal lembaganya.

## 17. PB-2b Buku besar, neraca saldo, cek kecocokan [ASUMSI — rincian teknis 2026-11-05]

- **Hanya jurnal SAH.** Lingkup sama dengan halaman Jurnal (gabungan / Yayasan pusat / lembaga; izin lembaga → lembaganya saja). Antrean jurnal diproses dulu sebelum laporan tampil; bila antrean/susun ulang masih berjalan tampil pemberitahuan "angka belum final".
- **Periode**: isian Dari–Sampai (bawaan awal tahun buku berjalan s.d. hari ini), pilihan cepat Bulan ini · Bulan lalu · Tahun buku ini · Tahun buku lalu. Tanggal sebelum tanggal mulai pembukuan dijepit ke tanggal mulai; rentang dibatasi **satu tahun buku** (lewat akhir tahun buku dipotong + keterangan).
- **Saldo awal periode**: akun aset, liabilitas, aset neto = semua mutasi sebelum tanggal Dari; akun pendapatan & beban mulai dari nol di awal tahun buku — hasil tahun buku sebelumnya dihitung masuk **Aset neto tanpa pembatasan** (pendapatan bertanda "dengan pembatasan" → Aset neto dengan pembatasan), tanpa jurnal tutup buku. **Jurnal saldo awal** (tanggal = tanggal mulai) selalu dihitung sebagai saldo awal, bukan mutasi.
- **Neraca saldo** (`/keuangan/pembukuan/neraca-saldo`): per akun posting — saldo awal, mutasi debit, mutasi kredit, saldo akhir; dikelompokkan per golongan; saldo ditampilkan menurut saldo normal akun (kurung = berlawanan); akun bersaldo nol disembunyikan (bisa ditampilkan); total saldo debit/kredit & mutasi selalu seimbang; nama akun menautkan ke buku besarnya. Unduh .xlsx (kolom debit/kredit terpisah, akun nol tidak ikut).
- **Buku besar** (`/keuangan/pembukuan/buku-besar?akun=`): pilih akun posting (bawaan Kas tunai), ringkasan saldo awal/debit/kredit/saldo akhir, baris per jurnal (tanggal, uraian + nomor + sumber, lembaga, debit, kredit, saldo berjalan) 100 per halaman, tautan ke rincian jurnal. Unduh .xlsx seluruh periode.
- **Cek kecocokan** (`/keuangan/pembukuan/cek-kecocokan`, semua tanggal): (1) saldo akun per rekening kas vs buku kas; (2) Piutang siswa per lembaga vs sisa tagihan (tagihan tidak batal); (3) Utang titipan per lembaga vs total saldo tabungan; (4) RAK gabungan = 0 (izin tingkat Yayasan). Baris yang kedua sisinya nol tidak ditampilkan. Tiap bagian yang selisih menampilkan petunjuk penyebab umum + tautan ke halaman sumber; pengelola mendapat tombol ke Susun ulang jurnal.
- **Kartu Beranda** "Pembukuan tidak cocok" (pemilik `finance.ledger.manage` tingkat Yayasan): jumlah baris selisih, hanya bila ada selisih dan antrean/susun ulang sudah selesai.
- **Menu Pembukuan**: Jurnal · Buku besar · Neraca saldo · Cek kecocokan (`finance.ledger.view`) · Pengaturan pembukuan (`finance.ledger.manage`, "Seluruh Yayasan").

## 18. PB-3 Laporan keuangan ISAK 35 [ASUMSI — rincian teknis 2026-11-09]

- **Halaman** `/keuangan/pembukuan/laporan` (menu Pembukuan → **Laporan keuangan**, `finance.ledger.view`): saringan lingkup & periode sama dengan neraca saldo (satu tahun buku); tab **Posisi keuangan · Penghasilan komprehensif · Perubahan aset neto · Arus kas**; judul entitas "<nama yayasan> — <lingkup>"; **Unduh .xlsx** berisi keempat laporan (satu lembar per laporan). Hanya jurnal SAH; angka negatif dalam kurung.
- **Pengelompokan**: akun dikelompokkan menurut akun judul induknya (tanpa induk → "Lainnya"); akun yang nol di semua kolom tidak ditampilkan. RAK pada lingkup gabungan saling hapus (nol, tidak tampil).
- **Posisi keuangan** per tanggal Sampai: aset (per kelompok), liabilitas, aset neto **tanpa pembatasan** (saldo akun aset neto tanpa pembatasan + akumulasi pendapatan − beban tanpa pembatasan s.d. tanggal laporan) dan **dengan pembatasan** (saldo akun aset neto bertanda "dengan pembatasan" + akumulasi pendapatan bertanda "dengan pembatasan"). Pembanding: akhir tahun buku lalu, bila tanggal itu tidak sebelum tanggal mulai pembukuan. Tampil peringatan bila jumlah aset ≠ liabilitas + aset neto.
- **Penghasilan komprehensif** periode Dari–Sampai: _tanpa pembatasan_ — pendapatan (akun kontra seperti Potongan & beasiswa tampil negatif), **aset neto yang dibebaskan dari pembatasan**, jumlah pendapatan, beban, surplus (defisit); _dengan pembatasan_ — pendapatan bertanda "dengan pembatasan", pelepasan (negatif), kenaikan (penurunan); total penghasilan komprehensif. Pembanding: periode yang sama tahun lalu (dijepit ke tanggal mulai), bila tidak seluruhnya sebelum tanggal mulai.
- **Pelepasan pembatasan** = kredit bersih ke akun aset neto tanpa pembatasan pada jurnal (manual) yang juga mendebit akun aset neto dengan pembatasan.
- **Perubahan aset neto** per golongan (tanpa / dengan pembatasan / jumlah): saldo awal (sehari sebelum Dari), surplus (defisit) / perubahan periode (dari laporan penghasilan komprehensif), **penyesuaian langsung lain** (jurnal manual langsung ke akun aset neto selain pelepasan; dihitung sebagai sisa), saldo akhir.
- **Arus kas metode langsung**: kas = akun kas/bank selain Kas titipan tabungan siswa. Tiap jurnal yang menggerakkan kas (dalam lingkup) dirinci per akun lawan — penerimaan (kredit akun lawan) & pengeluaran (debit akun lawan) — dikelompokkan menurut **aktivitas arus kas** akun lawan (kosong = operasi). Mutasi antar-rekening kas dalam lingkup yang sama tidak dihitung; pada lingkup lembaga, setoran ke/dari Kas Yayasan tampil sebagai pendanaan (RAK). Saldo awal rekening kas yang dibuka dalam periode tampil terpisah; kas awal + kenaikan + saldo rekening baru = kas akhir. Kas titipan tabungan ditampilkan sebagai catatan (awal & akhir).
