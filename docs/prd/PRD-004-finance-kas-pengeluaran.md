# PRD-004 — Modul Finance (Keuangan): Kas Umum & Pengeluaran

- **Versi:** 0.4 (2026-10-30: rincian & pembangunan KS-3 pengajuan → persetujuan → realisasi pengeluaran, §14)
- **Versi sebelumnya:** 0.3 (KS-2, §13), 0.2 (disetujui owner; KS-1, §12), 0.1 (draf pertama)
- **Status:** **DISETUJUI (2026-10-30)**; KS-1 SELESAI (`fbcfef6`); KS-2 SELESAI (`68a18cb`); KS-3 dibangun 2026-10-30, menunggu uji owner
- **Bergantung pada:** PRD-000 (pondasi, pohon lembaga, aturan berjenjang), PRD-003 (tagihan & pembayaran siswa — penerimaan dari kasir), PRD-001 (HR: gaji tetap di HR), ADR-001
- **Lanjutan dari:** PRD-003 §11 "Di luar tahap awal" — kas umum & pengeluaran

Label: **[OWNER]** = keputusan owner · **[ASUMSI]** = usulan baru, perlu konfirmasi.

---

## 1. Tujuan

Setiap lembaga dan yayasan punya **buku kas** yang rapi: uang masuk (pembayaran siswa, BOS, donasi, dll.), uang keluar (listrik, ATK, honor kegiatan, dll.), dan **saldo** tunai/bank yang selalu bisa dicek. Setiap pengeluaran **diajukan lalu disetujui** sebelum uang keluar, dengan bukti nota, sehingga pimpinan tahu ke mana uang pergi.

Prinsip (sama dengan PRD-003):

1. Data per yayasan (tenant), disaring per pohon lembaga; katalog akses di kode; audit.
2. **Uang tidak pernah dihapus.** Transaksi yang salah **dibatalkan dengan alasan**.
3. **Rupiah bulat**, bilangan bulat.
4. Saldo dihitung dari transaksi (tidak diketik manual), kecuali **saldo awal** saat mulai memakai aplikasi.

## 2. Keputusan owner (2026-10-30)

| ID       | Topik                  | Keputusan                                                                               |
| -------- | ---------------------- | --------------------------------------------------------------------------------------- |
| OD-KS-01 | Fitur lanjutan pertama | **Kas umum & pengeluaran** (sebelum tabungan siswa, payment gateway, pembukuan) [OWNER] |
| OD-KS-02 | Tingkat kas            | **Per lembaga + kas yayasan (pusat)** [OWNER]                                           |
| OD-KS-03 | Pengeluaran            | **Perlu disetujui** sebelum sah [OWNER]                                                 |

## 3. Konsep & model data [ASUMSI]

```text
Kas (fn_cash_books)                 satu per LEMBAGA + satu kas Yayasan (organization_id kosong)
  └─► Rekening (fn_cash_accounts)   "Kas tunai" (otomatis, wajib) + rekening bank (nama bank, no. rekening)
                                    saldo awal + tanggal saldo awal
Kategori (fn_cash_categories)       per yayasan: kode, nama, jenis MASUK | KELUAR, aktif
Transaksi kas (fn_cash_entries)     rekening, tanggal, MASUK | KELUAR, kategori, jumlah, uraian, bukti (opsional),
                                    status SAH | BATAL (+ alasan), dicatat oleh
Pengajuan pengeluaran (fn_expense_requests)
                                    kas, kategori, uraian, jumlah diajukan, diajukan oleh →
                                    DIAJUKAN → DISETUJUI | DITOLAK (+ catatan, oleh) → DIREALISASI (transaksi KELUAR)
                                    | DIBATALKAN (oleh pengaju, sebelum disetujui)
Mutasi (fn_cash_transfers)          rekening asal → rekening tujuan (boleh beda kas: setoran lembaga ke yayasan,
                                    subsidi yayasan ke lembaga) = satu KELUAR + satu MASUK berpasangan
```

- **Kas**: hanya node **LEMBAGA** dan **Yayasan**. Unit dan Biro tidak punya kas sendiri (Biro memakai kas Yayasan).
- **Rekening**: setiap kas otomatis punya **Kas tunai**; bendahara menambah rekening bank. Saldo per rekening = saldo awal + masuk − keluar (yang SAH). Saldo tidak boleh minus saat mencatat keluar/mutasi [ASUMSI].
- **Kategori** bawaan (bisa diubah): MASUK — Pembayaran siswa (sistem), BOS, Donasi/Infak, Lain-lain; KELUAR — Listrik/Air/Internet, ATK & Perlengkapan, Kegiatan, Pemeliharaan, Honor non-gaji, Lain-lain.
- **Pembayaran siswa (PRD-003)** masuk ke buku kas **otomatis**: setiap pembayaran SAH dari kasir menjadi transaksi MASUK kategori "Pembayaran siswa" di kas lembaganya — Tunai → rekening Kas tunai; Transfer → rekening bank **penerima transfer** yang dipilih bendahara (bila belum dipilih: Kas tunai, dengan peringatan). Pembayaran dibatalkan → transaksi kasnya ikut batal.
- **Gaji pegawai** tetap di HR (PRD-001 §16) dan **tidak** otomatis masuk buku kas pada tahap ini; bila gaji dibayar dari kas, dicatat sebagai pengeluaran biasa (kategori "Honor non-gaji"/"Gaji") [ASUMSI].

## 4. Alur pengeluaran (OD-KS-03)

1. **Ajukan** (Bendahara kas itu): kategori, uraian, jumlah, rencana tanggal, lampiran opsional (nota/penawaran). Status **Diajukan**. Pengaju boleh membatalkan selama belum diputus.
2. **Setujui / Tolak** (penyetuju kas itu, **bukan pengajunya sendiri**): boleh menurunkan jumlah yang disetujui; tolak wajib alasan.
    - Kas **lembaga** → **Kepala Lembaga** (atau Admin Keuangan / Admin Yayasan).
    - Kas **Yayasan** → **Pimpinan tingkat Yayasan** (atau Admin Yayasan) [ASUMSI].
3. **Realisasi** (Bendahara): saat uang benar-benar keluar — tanggal, rekening, jumlah (≤ yang disetujui), **bukti/nota** (foto/PDF, disarankan). Baru di sini saldo berkurang.
4. Realisasi yang salah → **batalkan** (alasan) → pengajuan kembali **Disetujui** dan bisa direalisasi ulang.

Pemasukan lain (BOS, donasi) dan mutasi antar-rekening **langsung sah** tanpa persetujuan [ASUMSI]; salah → batalkan dengan alasan.

## 5. Siapa melihat & mengelola [ASUMSI]

| Permission                 | Isi                                                                      |
| -------------------------- | ------------------------------------------------------------------------ |
| `finance.cash.view`        | Lihat buku kas, saldo, pengajuan, laporan (sesuai cakupan)               |
| `finance.cash.manage`      | Catat pemasukan, mutasi, ajukan & realisasi pengeluaran, kelola rekening |
| `finance.expenses.approve` | Setujui / tolak pengajuan pengeluaran                                    |
| `finance.settings.manage`  | (sudah ada) kategori kas tingkat Yayasan                                 |

| Role                     | Tambahan                                 | Cakupan                                    |
| ------------------------ | ---------------------------------------- | ------------------------------------------ |
| Bendahara (lembaga)      | cash.view, cash.manage                   | Kas lembaganya                             |
| Admin Keuangan (Yayasan) | cash.view, cash.manage, expenses.approve | Semua kas (bendahara kas Yayasan)          |
| Admin Yayasan            | semua                                    | Semua kas                                  |
| Kepala Lembaga           | cash.view, expenses.approve              | Kas lembaganya                             |
| Pimpinan                 | cash.view, expenses.approve              | Cakupannya (tingkat Yayasan → kas Yayasan) |

Aturan tambahan: tidak boleh menyetujui pengajuan sendiri (walau punya izin).

## 6. Halaman (ringkas) [ASUMSI]

- **Keuangan → Kas**: pilih kas (lembaga / Yayasan) → saldo per rekening, buku kas (tanggal, uraian, kategori, masuk, keluar, saldo berjalan), saring bulan & rekening; tombol **Catat pemasukan**, **Mutasi**, **Ajukan pengeluaran**; unduh .xlsx.
- **Keuangan → Pengeluaran**: daftar pengajuan (Diajukan, Disetujui menunggu realisasi, Ditolak, Selesai) + aksi sesuai peran; lampiran bisa dibuka.
- **Keuangan → Pengaturan**: kategori kas (tingkat Yayasan); rekening & saldo awal di halaman Kas (bendahara).
- **Laporan**: arus kas per bulan per kategori (masuk/keluar), per kas & semua kas, unduh .xlsx.
- **Beranda**: kartu "Pengajuan menunggu persetujuan" (penyetuju) dan "Siap direalisasi" (bendahara).

## 7. Tahapan [ASUMSI]

| Tahap    | Isi                                                                                                                  |
| -------- | -------------------------------------------------------------------------------------------------------------------- |
| **KS-1** | Hak akses, kategori, kas & rekening + saldo awal, pemasukan lain, buku kas & saldo; batal transaksi                  |
| **KS-2** | Penerimaan pembayaran siswa otomatis masuk buku kas (termasuk riwayat sejak saldo awal) + rekening penerima transfer |
| **KS-3** | Pengajuan → persetujuan → realisasi pengeluaran, lampiran bukti, kartu Beranda                                       |
| **KS-4** | Mutasi antar-rekening & antar-kas (setoran lembaga ke Yayasan), laporan arus kas & unduh .xlsx                       |

## 8. Audit

`finance.cash_account_*` (tambah, ubah, saldo awal), `finance.cash_entry_recorded|voided`, `finance.expense_requested|approved|rejected|cancelled|realized|realization_voided`, `finance.cash_transfer_recorded|voided`, `finance.cash_category_*`. Nominal boleh dicatat (bukti transaksi keuangan, sama dengan PRD-003 §8).

## 9. Kebutuhan non-fungsional

- Semua hitung di server; transaksi database + kunci rekening saat mencatat keluar/mutasi (saldo tidak minus walau dua orang bersamaan).
- Lampiran: JPG/PNG/PDF ≤ 2 MB, disimpan privat (bukan folder publik), hanya bisa diunduh pemilik izin lihat kas itu.
- Buku kas besar memakai halaman & saringan bulan.

## 10. Open decisions

| ID       | Topik                                      | Usulan                                                                                                   |
| -------- | ------------------------------------------ | -------------------------------------------------------------------------------------------------------- |
| OD-KS-04 | Penyetuju kas Yayasan                      | **DIPUTUSKAN** — Pimpinan tingkat Yayasan (+ Admin Yayasan) [OWNER, 2026-10-30]                          |
| OD-KS-05 | Pembayaran siswa otomatis masuk buku kas   | **DIPUTUSKAN** — ya (KS-2), per tanggal bayar; batal ikut batal [OWNER, 2026-10-30]                      |
| OD-KS-06 | Batas nominal persetujuan berjenjang       | **DIPUTUSKAN** — satu tingkat penyetuju dulu; berjenjang menyusul [OWNER, 2026-10-30]                    |
| OD-KS-07 | Pemasukan lain & mutasi perlu persetujuan? | **DIPUTUSKAN** — tidak, langsung sah (dibatalkan bila salah) [OWNER, 2026-10-30]                         |
| OD-KS-08 | Saldo boleh minus?                         | **DIPUTUSKAN** — tidak; keluar/mutasi/pembatalan pemasukan ditolak bila saldo kurang [OWNER, 2026-10-30] |

## 11. Di luar tahap ini

Anggaran (RAPBS) & realisasi anggaran, persetujuan berjenjang per nominal, pembukuan (COA, jurnal, neraca), rekonsiliasi mutasi bank otomatis, tabungan siswa, payment gateway, integrasi gaji HR ke kas.

## 12. KS-1 Kas, rekening, kategori, pemasukan, buku kas [ASUMSI — rincian teknis 2026-10-30]

- **Hak akses**: permission baru `finance.cash.view` & `finance.cash.manage` (§5). Bendahara & Admin Keuangan & Admin Yayasan: keduanya; Pimpinan & Kepala Lembaga: lihat. `finance.expenses.approve` ditambahkan di KS-3 (saat dipakai). Kas Yayasan = izin yang sama di tingkat Yayasan.
- **Kategori kas** (`/keuangan/kategori-kas`, `finance.settings.manage` tingkat Yayasan): 10 kategori bawaan dibuat otomatis saat kas/kategori pertama kali dibuka — MASUK: Pembayaran siswa (_sistem_), Dana BOS, Donasi / infak, Pemasukan lain-lain; KELUAR: Listrik, air & internet; ATK & perlengkapan; Kegiatan; Pemeliharaan; Honor non-gaji; Pengeluaran lain-lain. Tambah (kode 2–30 huruf besar/angka/"-", unik, tetap; nama ≤ 100; jenis tetap), ubah nama, nonaktif/aktif. Kategori sistem tidak bisa diubah/dinonaktifkan/dipilih manual.
- **Halaman Kas** (`/keuangan/kas`): pilih **kas** (Kas Yayasan bila berhak, lalu lembaga), **bulan** (bulan ini + 24 ke belakang), **rekening** (semua / satu).
    - **Kas tunai** dibuat otomatis saat kas pertama kali dibuka (saldo awal Rp 0 per hari itu).
    - **Rekening bank**: nama ≤ 100, bank ≤ 60, nomor opsional (angka/spasi/"."/"-" ≤ 40), saldo awal Rp 0–999.999.999.999 per tanggal (tidak di masa depan, ≤ 10 tahun lalu).
    - **Ubah rekening**: nama/bank/nomor kapan saja; **saldo awal & tanggalnya hanya selama rekening belum punya transaksi**. Kas tunai: hanya saldo awal.
    - **Saldo rekening** = saldo awal + masuk − keluar yang sah (dihitung, tidak disimpan).
    - **Buku kas bulanan**: saldo awal bulan, baris "Saldo awal <rekening>" bila tanggal saldo awal jatuh di bulan itu, transaksi urut tanggal & waktu catat, **saldo berjalan**, total pemasukan/pengeluaran, saldo akhir. Transaksi batal tampil dicoret + alasan, tidak dihitung.
- **Catat pemasukan** (`finance.cash.manage`): rekening aktif kas itu, tanggal (tidak di masa depan, tidak sebelum tanggal saldo awal rekening), kategori MASUK aktif non-sistem, jumlah Rp 1–999.999.999.999, uraian 3–200. Langsung sah (OD-KS-07). **Pengeluaran tidak bisa dicatat langsung** — lewat pengajuan (KS-3).
- **Batalkan transaksi**: hanya transaksi manual; alasan 1–200; ditolak bila saldo rekening menjadi minus (OD-KS-08). Transaksi dari kasir/pengajuan/mutasi dibatalkan dari asalnya.
- **Data**: `fn_cash_categories`, `fn_cash_accounts` (satu Kas tunai per kas dijaga indeks unik parsial), `fn_cash_entries` (`source` MANUAL/PEMBAYARAN/PENGELUARAN/MUTASI, status SAH/BATAL).
- **Audit**: `finance.cash_category_*`, `finance.cash_account_created|updated`, `finance.cash_entry_recorded|voided`.

## 13. KS-2 Pembayaran siswa masuk buku kas [ASUMSI — rincian teknis 2026-10-30]

- **Cerminan kuitansi**: setiap kuitansi **Sah** dari Kasir (PRD-003 §16) menjadi satu baris MASUK di buku kas **lembaganya**: kategori sistem "Pembayaran siswa", tanggal = tanggal bayar, uraian "Pembayaran siswa — <nama> (<no. kuitansi>)", dicatat oleh petugas kasir, bertanda **Otomatis** + tautan ke kuitansi. Sumber aslinya tetap kuitansi; baris ini tidak bisa dibatalkan dari buku kas.
- **Rekening tujuan**: Tunai → **Kas tunai**; Transfer → rekening bank yang ditandai **"Penerima transfer siswa"** (paling banyak satu per lembaga, hanya rekening bank aktif milik lembaga). Belum ada penerima → transfer masuk Kas tunai dan halaman Kas menampilkan peringatan.
- **Saldo awal mencakup penerimaan sebelumnya**: kuitansi bertanggal **sebelum** tanggal saldo awal rekening tujuan tidak dicerminkan (agar tidak dobel).
- **Penataan ulang**: cerminan kuitansi **tidak mengunci** saldo awal (§12). Bila saldo awal/tanggalnya atau penerima transfer berubah, cerminan disusun ulang (dipindah/ditambah/dihapus) mengikuti aturan di atas. Riwayat kuitansi sebelum KS-2 ikut masuk saat halaman Kas lembaga dibuka.
- **Kuitansi dibatalkan** di Kasir → barisnya ikut **Batal** ("Pembayaran dibatalkan: <alasan>"). Pembatalan kuitansi **ditolak** bila saldo rekening tujuan menjadi minus (OD-KS-08), dengan pesan agar menghubungi bendahara.
- **Data**: `fn_cash_accounts.receives_transfers` (+ indeks unik per lembaga); satu cerminan per kuitansi (`fn_cash_entries.source = PEMBAYARAN`, indeks unik `source_id`).
- **Audit**: penandaan penerima transfer dicatat sebagai `finance.cash_account_updated` (`penerima_transfer_siswa`). Cerminan tidak diaudit tersendiri (kuitansinya sudah diaudit, PRD-003 §8).

## 14. KS-3 Pengajuan → persetujuan → realisasi pengeluaran [ASUMSI — rincian teknis 2026-10-30]

- **Hak akses**: permission baru `finance.expenses.approve` — Kepala Lembaga, Pimpinan, Admin Keuangan, Admin Yayasan (§5). Kas lembaga: izin di lembaga itu; kas Yayasan: izin tingkat Yayasan (OD-KS-04). **Tidak boleh menyetujui/menolak pengajuan sendiri**, walau punya izin.
- **Halaman Pengeluaran** (`/keuangan/pengeluaran`, menu Keuangan → _Pengeluaran_; tombol "Ajukan pengeluaran" juga di halaman Kas): saring kas (semua / satu) & status; urut menunggu persetujuan, siap direalisasi, lalu lainnya (terbaru dulu); 30 per halaman.
- **Ajukan** (`finance.cash.manage` di kas itu): kas, kategori **pengeluaran** aktif, uraian 3–200, jumlah Rp 1–999.999.999.999, rencana tanggal opsional (±1 tahun), lampiran opsional (PDF/JPG/PNG ≤ 2 MB). Status **Menunggu persetujuan**. **Batalkan** hanya selama menunggu.
- **Setujui**: jumlah disetujui Rp 1 s.d. jumlah diajukan (boleh dikurangi), catatan opsional → **Disetujui, belum direalisasi**. **Tolak**: alasan wajib 1–200 → **Ditolak**. Satu tingkat penyetuju (OD-KS-06).
- **Realisasi** (`finance.cash.manage`): rekening aktif kas itu, tanggal keluar (tidak di masa depan, tidak sebelum tanggal pengajuan & tanggal saldo awal rekening), jumlah Rp 1 s.d. jumlah disetujui, bukti/nota opsional (disarankan). Ditolak bila saldo rekening kurang (OD-KS-08). Membuat transaksi kas **KELUAR** (kategori pengajuan, uraian "Pengeluaran — …", `source = PENGELUARAN`) → **Selesai (direalisasi)**; saldo berkurang dan saldo awal rekening terkunci.
- **Batalkan realisasi** (`finance.cash.manage`): alasan wajib → transaksi kas **Batal**, pengajuan kembali **Disetujui** (bisa direalisasi ulang), bukti lama dihapus. Transaksi realisasi tidak bisa dibatalkan dari buku kas.
- **Lampiran & bukti**: disimpan privat (`storage/app/private/tenants/{yayasan}/finance/expenses/…`), jenis dicek dari isi file, diunduh lewat `/keuangan/pengeluaran/{id}/lampiran|bukti` hanya oleh pemilik izin lihat kas itu.
- **Beranda**: "Pengajuan menunggu persetujuan" (pemilik `expenses.approve`; tidak menghitung pengajuan sendiri) dan "Pengeluaran siap direalisasi" (pemilik `cash.manage`), sesuai cakupan kas.
- **Data**: `fn_expense_requests` (status DIAJUKAN/DISETUJUI/DITOLAK/DIBATALKAN/DIREALISASI, jumlah diajukan & disetujui, pengaju, pemutus, catatan, lampiran, bukti, transaksi realisasi); paling banyak satu realisasi sah per pengajuan.
- **Audit**: `finance.expense_requested|cancelled|approved|rejected|realized|realization_voided`.
