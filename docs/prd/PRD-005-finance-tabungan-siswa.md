# PRD-005 — Modul Finance (Keuangan): Tabungan Siswa

- **Versi:** 0.5 (2026-11-03: rincian & pembangunan TB-4, §15)
- **Versi sebelumnya:** 0.4 (TB-3, §14), 0.3 (TB-2, §13), 0.2 (disetujui owner; TB-1, §12), 0.1 (draf pertama)
- **Status:** **DISETUJUI (2026-11-01)**; TB-1 SELESAI (`7bb4a3d`); TB-2 SELESAI (`a6e9a65`); TB-3 SELESAI (`1bd00e2`); TB-4 dibangun 2026-11-03, menunggu uji owner
- **Bergantung pada:** PRD-000 (pondasi, pohon lembaga, akses), PRD-002 (siswa & pendaftaran, orang tua/wali), PRD-003 (kasir, kuitansi, portal Anak saya), PRD-004 (kas, rekening, kategori sistem, mutasi), ADR-001
- **Lanjutan dari:** PRD-004 §11, urutan owner 2026-10-30: (1) persetujuan berjenjang (selesai) → **(2) tabungan siswa** → (3) payment gateway Midtrans → (4) pembukuan

Label: **[OWNER]** = keputusan owner · **[ASUMSI]** = usulan, perlu konfirmasi.

---

## 1. Tujuan

Siswa bisa **menabung di sekolah**. Petugas mencatat setoran dan penarikan dengan slip bernomor. Saldo setiap siswa selalu bisa dicek oleh petugas dan orang tua. Uang tabungan adalah **titipan milik siswa**, jadi dicatat terpisah dari uang operasional lembaga dan tidak ikut terpakai untuk pengeluaran. Saldo tabungan juga boleh dipakai untuk **membayar tagihan** di kasir.

Prinsip (sama dengan PRD-003/004):

1. Data per yayasan (tenant), disaring per pohon lembaga. Katalog akses ada di kode. Setiap aksi tercatat di audit.
2. **Uang tidak pernah dihapus.** Transaksi yang salah **dibatalkan dengan alasan**.
3. **Rupiah bulat** (bilangan bulat).
4. Saldo **dihitung dari transaksi**, tidak diketik manual. Saldo tidak boleh minus.

## 2. Keputusan owner (2026-11-01)

| ID       | Topik                | Keputusan                                                                                                                                                      |
| -------- | -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OD-TB-01 | Pencatat             | **Kasir keuangan**, lewat halaman Kasir yang sudah ada, dengan slip setoran/penarikan [OWNER]                                                                  |
| OD-TB-02 | Aturan penarikan     | **Bebas kapan saja** selama saldo cukup, dicatat kasir [OWNER]                                                                                                 |
| OD-TB-03 | Hubungan kas/tagihan | Uang tabungan masuk **rekening kas "Titipan tabungan"** tiap lembaga, terpisah dari kas operasional; **saldo boleh dipakai membayar tagihan** di kasir [OWNER] |

## 3. Konsep & model data [ASUMSI]

```text
Rekening tabungan (fn_savings_accounts)   satu per SISWA per LEMBAGA (dibuat otomatis saat setoran pertama)
                                          saldo = Σ transaksi SAH (disimpan juga sebagai kolom, dikunci saat transaksi)
Transaksi tabungan (fn_savings_entries)   rekening tabungan, tanggal, jenis SETOR | TARIK | BAYAR_TAGIHAN,
                                          jumlah, cara (TUNAI | TRANSFER, untuk setor/tarik), nomor slip,
                                          diambil oleh (nama penerima, khusus TARIK), catatan,
                                          status SAH | BATAL (+ alasan, waktu, petugas), dicatat oleh
Rekening kas "Titipan tabungan"           jenis rekening baru TITIPAN di fn_cash_accounts, satu per kas LEMBAGA,
                                          dibuat otomatis, tidak bisa dipakai untuk pemasukan/pengeluaran manual
```

- **Per siswa per lembaga, bukan per pendaftaran.** Kalau siswa keluar lalu mendaftar lagi di lembaga yang sama, saldonya tetap satu. Siswa yang terdaftar di dua lembaga (mis. SMP + MDA) punya dua rekening tabungan terpisah, karena uangnya dipegang kasir yang berbeda.
- **Hanya node LEMBAGA** yang punya tabungan (sama dengan kas, PRD-004 §3).
- **Siswa yang sudah keluar** tetap bisa menarik saldonya. Siswa ini tidak bisa menabung lagi.

### 3.1 Hubungan dengan buku kas (OD-TB-03)

| Kejadian                      | Buku kas lembaga (otomatis)                                                                                                                                                                                                                                                                                         |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Setoran                       | MASUK ke rekening **Titipan tabungan**, kategori sistem **Setoran tabungan**                                                                                                                                                                                                                                        |
| Penarikan                     | KELUAR dari **Titipan tabungan**, kategori sistem **Penarikan tabungan**                                                                                                                                                                                                                                            |
| Bayar tagihan dari tabungan   | KELUAR dari **Titipan tabungan** (kategori sistem Penarikan tabungan, uraian "Bayar tagihan dari tabungan"), lalu kuitansi tercermin sebagai MASUK **Pembayaran siswa** di **Kas tunai** (PRD-004 KS-2) — efeknya sama dengan mutasi Titipan → Kas tunai, tetapi tetap terhitung sebagai pemasukan pembayaran siswa |
| Transaksi tabungan dibatalkan | Transaksi kas pasangannya ikut batal                                                                                                                                                                                                                                                                                |

- Saldo rekening Titipan tabungan = jumlah semua saldo tabungan siswa di lembaga itu. Rekening ini **tidak dihitung dalam saldo operasional** di ringkasan kas dan laporan arus kas. Rekening ini ditampilkan terpisah sebagai "Titipan".
- Uang fisik tabungan boleh disimpan di laci/brankas tersendiri. Aplikasi hanya memastikan pencatatannya terpisah. Setoran yang ditransfer ke rekening bank tetap dicatat di Titipan tabungan dengan cara "Transfer" (lihat OD-TB-04).

## 4. Alur di kasir

Halaman **Kasir** (PRD-003 §16), setelah memilih siswa, mendapat tab baru **Tabungan**. Tab ini berisi saldo, tombol **Setor** dan **Tarik**, serta riwayat 30 transaksi terakhir.

1. **Setor**: isi jumlah (Rp 1–100.000.000), tanggal (bawaan hari ini; tidak di masa depan, paling jauh 1 tahun ke belakang, sama dengan pembayaran), cara Tunai/Transfer, dan catatan opsional. Setelah disimpan, **slip setoran** bisa dicetak.
2. **Tarik**: isi jumlah (≤ saldo), tanggal, cara, **diambil oleh** (nama, wajib, mis. "Ibu Siti (ibu)"), dan catatan. Setelah disimpan, **slip penarikan** bisa dicetak.
3. **Bayar tagihan dari tabungan**: di tab Tagihan & bayar ada cara bayar ketiga, **Tabungan**. Pilihan ini hanya aktif bila saldo > 0, dan jumlah yang dibayar ≤ saldo. Hasilnya satu kuitansi pembayaran (PRD-003) ditambah satu transaksi BAYAR_TAGIHAN di tabungan. Membatalkan pembayaran itu akan mengembalikan saldo.
4. **Batalkan** transaksi tabungan (wajib alasan). Pembatalan setoran ditolak bila saldo sekarang tidak cukup. Transaksi BAYAR_TAGIHAN hanya batal lewat pembatalan kuitansinya.

**Slip** bernomor **`TAB-KODE-LEMBAGA/TAHUN/000001`**. Nomornya urut per lembaga per tahun, tidak dipakai ulang, dan tidak dobel walau dua kasir menyimpan bersamaan. Isi slip: nama siswa + kelas + NIS, jenis, jumlah, **terbilang**, cara, saldo sesudah transaksi, diambil oleh (khusus penarikan), petugas, dan tanggal. Slip dicetak lewat dialog cetak browser. Slip transaksi yang dibatalkan tetap bisa dibuka dengan tanda **BATAL**.

## 5. Siapa melihat & mengelola [ASUMSI]

| Permission               | Isi                                                                        |
| ------------------------ | -------------------------------------------------------------------------- |
| `finance.savings.view`   | Lihat saldo & riwayat tabungan, daftar saldo, rekap (sesuai cakupan)       |
| `finance.savings.manage` | Catat setoran/penarikan, batalkan, cetak slip, bayar tagihan dari tabungan |

| Role                     | Tambahan                     | Cakupan           |
| ------------------------ | ---------------------------- | ----------------- |
| Bendahara (lembaga)      | savings.view, savings.manage | Lembaganya        |
| Admin Keuangan (Yayasan) | savings.view, savings.manage | Semua lembaga     |
| Admin Yayasan            | semua                        | Semua lembaga     |
| Kepala Lembaga           | savings.view                 | Lembaganya        |
| Pimpinan                 | savings.view                 | Cakupannya        |
| Orang tua/wali           | lewat portal Anak saya       | Anak yang tertaut |

Bayar tagihan dari tabungan butuh `finance.payments.manage` **dan** `finance.savings.manage` di lembaga siswa itu. Wali kelas tidak melihat saldo (lihat OD-TB-06).

## 6. Halaman (ringkas) [ASUMSI]

- **Kasir → tab Tabungan** (§4).
- **Keuangan siswa → Tabungan** (menu baru): menampilkan total titipan per lembaga dan daftar saldo per siswa (saring lembaga, kelas, cari nama/NIS, centang "saldo > 0", siswa yang sudah keluar ditandai). Klik siswa membuka Kasir di tab Tabungan. Ada unduhan .xlsx.
- **Rekap tabungan** (tab di halaman Tabungan): per bulan, berisi total setoran, penarikan, bayar tagihan, dan saldo akhir, plus daftar transaksi. Unduh .xlsx.
- **Anak saya → Tabungan** (portal orang tua): saldo dan 30 transaksi terakhir yang **Sah**. Slip bisa dibuka/cetak.
- **Kas** (PRD-004): rekening Titipan tabungan tampil di tab Rekening dengan label "Titipan". Rekening ini tidak muncul sebagai pilihan untuk pemasukan, pengajuan, atau mutasi manual.

Tampilan mengikuti Pedoman tampilan (STATUS): tab per fungsi, form di pop-up.

## 7. Tahapan [ASUMSI]

| Tahap    | Isi                                                                                                                                           |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| **TB-1** | Hak akses, rekening tabungan, rekening kas Titipan + kategori sistem, setor/tarik/batal di Kasir, slip bernomor, sinkron ke buku kas          |
| **TB-2** | Halaman Tabungan: daftar saldo + rekap bulanan + unduh .xlsx; menu & kartu Beranda "Total titipan tabungan"                                   |
| **TB-3** | Bayar tagihan dari tabungan (cara bayar Tabungan di kasir, mutasi otomatis Titipan → Kas tunai, rekap penerimaan menampilkan "Dari tabungan") |
| **TB-4** | Portal orang tua: tab Tabungan di Anak saya                                                                                                   |

## 8. Audit

`finance.savings_deposited`, `finance.savings_withdrawn` (nomor slip, jumlah, cara, diambil oleh), `finance.savings_entry_voided` (nomor slip, alasan), dan `finance.savings_used_for_payment` (nomor slip, nomor kuitansi, jumlah). Nominal boleh dicatat, sama dengan PRD-003 §8.

## 9. Kebutuhan non-fungsional

- Semua hitungan dilakukan di server. Setiap transaksi memakai transaksi database dan mengunci rekening tabungan + rekening Titipan, sehingga saldo tidak minus walau dua kasir menyimpan bersamaan.
- Saldo kolom dan Σ transaksi SAH harus selalu sama (dicek di test).
- Daftar saldo memakai halaman & saringan (lembaga besar ±1.000 siswa).
- Data tabungan termasuk data keuangan pribadi siswa, jadi tidak tampil di halaman yang bisa dibuka wali kelas atau guru.

## 10. Open decisions

| ID       | Topik                                          | Keputusan                                                                                                                      |
| -------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| OD-TB-04 | Titipan satu rekening atau dipisah tunai/bank? | **DIPUTUSKAN** — satu rekening Titipan per lembaga; cara Tunai/Transfer dicatat di transaksinya [OWNER, 2026-11-01]            |
| OD-TB-05 | Setoran minimal / saldo minimal                | **DIPUTUSKAN** — tidak ada (≥ Rp 1); penarikan boleh sampai saldo 0 [OWNER, 2026-11-01]                                        |
| OD-TB-06 | Wali kelas melihat saldo anak kelasnya?        | **DIPUTUSKAN** — tidak (data keuangan siswa) [OWNER, 2026-11-01]                                                               |
| OD-TB-07 | Saldo siswa yang sudah keluar/lulus            | **DIPUTUSKAN** — tetap tersimpan sampai diambil; daftar saldo menandai "Sudah keluar, saldo belum diambil" [OWNER, 2026-11-01] |
| OD-TB-08 | Bunga / bagi hasil / biaya administrasi        | **DIPUTUSKAN** — tidak ada (tabungan titipan murni) [OWNER, 2026-11-01]                                                        |

## 11. Di luar tahap ini

Setoran lewat payment gateway (Midtrans menyusul, PRD berikutnya), tabungan berjangka/target (mis. tabungan haji, study tour), setoran kolektif per kelas oleh wali kelas, notifikasi WA setiap transaksi, dan pembukuan (COA/jurnal) untuk titipan.

## 12. TB-1 Setor, tarik, slip & rekening Titipan [ASUMSI — rincian teknis 2026-11-01]

- **Data**: `fn_savings_accounts` (lembaga × siswa, `balance` ≥ 0, unik per lembaga+siswa) dan `fn_savings_entries` (rekening tabungan, pendaftaran saat transaksi, tanggal, jenis SETOR/TARIK/BAYAR_TAGIHAN, cara, jumlah, saldo sesudahnya, nomor slip unik, diambil oleh, catatan, status Sah/Batal + alasan/waktu/petugas, petugas pencatat). `fn_cash_accounts.kind` ditambah **TITIPAN** (satu per lembaga, otomatis saat transaksi tabungan pertama, nama "Titipan tabungan", saldo awal Rp 0 per 1 tahun lalu). `fn_cash_entries.source` ditambah **TABUNGAN** (satu baris kas per transaksi tabungan). Kategori sistem baru: **Setoran tabungan** (MASUK) & **Penarikan tabungan** (KELUAR).
- **Kasir → tab Tabungan** (pemilik `finance.savings.view` di lembaga; tab tidak muncul bila tidak punya): saldo, tombol **Setor** & **Tarik** (pop-up; hanya `finance.savings.manage`), 30 transaksi terakhir (termasuk yang batal) dengan tombol **Slip** dan **Batalkan**. Setor nonaktif untuk siswa yang sudah keluar; Tarik nonaktif bila saldo 0.
- **Isian**: jumlah Rp 1–100.000.000 (titik ribuan boleh), tanggal ≤ hari ini dan ≥ 1 tahun lalu, cara Tunai/Transfer bank, catatan ≤ 200 karakter; penarikan wajib **Diambil oleh** ≤ 100 karakter. Penarikan pertama sebelum pernah menabung → "Siswa ini belum punya tabungan di lembaga ini."
- **Sesudah simpan** → halaman **slip** (`/keuangan/slip-tabungan/{id}`): judul SLIP SETORAN/PENARIKAN TABUNGAN, nomor `TAB-KODE/TAHUN/000001`, tanggal, siswa + kelas + NIS, jenis, cara, jumlah, saldo sesudahnya, diambil oleh, catatan, terbilang, tanda tangan penyetor/penerima & petugas; tombol cetak/simpan PDF dan kembali ke Kasir (tab Tabungan). Slip batal bertanda BATAL.
- **Batal** (`finance.savings.manage`): alasan wajib ≤ 200; batal setoran ditolak bila saldo sekarang kurang ("Batalkan dulu penarikan sesudahnya"); baris kas Titipan ikut BATAL; nomor slip tidak dipakai ulang.
- **Kas (PRD-004)**: rekening Titipan tampil di tab Rekening dengan label **Titipan** (tanpa tombol Ubah); "Total saldo" hanya rekening operasional, titipan disebut terpisah. Buku kas "Semua rekening" dan **Laporan arus kas** tidak memuat rekening Titipan; buku kas Titipan bisa dilihat dengan memilih rekeningnya. Pemasukan manual, mutasi, realisasi pengeluaran, dan ubah saldo awal ke/dari rekening Titipan **ditolak**.
- **Hak akses**: `finance.savings.view|manage` (§5). Lembaga di luar cakupan → 404; tanpa izin kelola → 403.
- **Audit**: `finance.savings_deposited`, `finance.savings_withdrawn` (slip, jumlah, cara, tanggal, diambil oleh), `finance.savings_entry_voided` (slip, jenis, jumlah, alasan).

## 13. TB-2 Halaman Tabungan, rekap bulanan, unduh, kartu Beranda [ASUMSI — rincian teknis 2026-11-02]

- **Menu** Keuangan siswa → **Tabungan** (`/keuangan/tabungan`) untuk pemilik `finance.savings.view`; pilihan lembaga = node LEMBAGA aktif dalam cakupan izin itu (lembaga lain di URL diabaikan; unduhan lembaga lain → 404; tanpa izin → 403). Bila cakupan lebih dari satu lembaga: **Titipan per lembaga** (jumlah siswa bersaldo & total) di atas.
- **Tab Saldo per siswa**: saring **kelas** (kelas berjalan, "Tanpa kelas", "Sudah keluar"), **cari** (sebagian nama, awal NIS/NISN, ≤ 100 karakter), bawaan hanya **saldo > 0**; centang "Tampilkan juga saldo Rp 0". Ringkasan jumlah siswa & total saldo; kotak per kelas (siswa bersaldo & total) bila tanpa saringan; daftar urut kelas lalu nama, 50 per halaman, berisi kelas, NIS, tanggal transaksi sah terakhir, saldo, tanda **"Sudah keluar, saldo belum diambil"** (OD-TB-07), tombol **Buka di kasir** (tab Tabungan). Data siswa diambil dari pendaftaran pada transaksi tabungan terakhirnya di lembaga itu.
- **Tab Rekap bulanan** (12 bulan terakhir, bawaan bulan berjalan): saldo awal bulan, setoran, penarikan, bayar tagihan (bila ada), saldo akhir — hanya transaksi **Sah**; jumlah transaksi batal disebut terpisah. Daftar semua transaksi bulan itu (termasuk batal, bertanda) urut tanggal lalu nomor slip, dengan tautan slip.
- **Unduh .xlsx**: saldo (`saldo-tabungan-<kode>-<YYYY-MM-DD>.xlsx`, lembar **Saldo**, saringan ikut, baris Total) dan rekap (`rekap-tabungan-<kode>-<YYYY-MM>.xlsx`, lembar **Ringkasan** & **Transaksi** termasuk yang batal + alasan). Bulan di luar pilihan → 404.
- **Kartu Beranda** "Titipan tabungan siswa": jumlah siswa bersaldo, deskripsi total rupiah (dan jumlah lembaga bila lebih dari satu), tautan ke halaman Tabungan; untuk pemilik `finance.savings.view`, sesuai cakupan.

## 14. TB-3 Bayar tagihan dari tabungan [ASUMSI — rincian teknis 2026-11-03]

- **Kasir → tab Tagihan & bayar**: cara bayar ketiga **Tabungan siswa** (`fn_payments.method = TABUNGAN`) hanya ditawarkan bila pengguna punya `finance.payments.manage` **dan** `finance.savings.manage` di lembaga itu dan saldo tabungan siswa > 0; saat dipilih tampil saldo dan tombol "Pakai seluruh saldo" bila saldo kurang dari tagihan terpilih. Server tetap memeriksa izin & saldo.
- **Simpan**: satu transaksi database — kuitansi biasa (nomor, alokasi tagihan, cicil boleh, kelebihan bayar ditolak) + transaksi tabungan **BAYAR_TAGIHAN** (slip `TAB-…` berurut, tanpa cara, catatan "Kuitansi …", `payment_id`) + baris KELUAR di **Titipan tabungan**; kuitansi tercermin MASUK di **Kas tunai**. Saldo kurang → "Saldo tabungan tidak cukup. Saldo sekarang Rp …" dan tidak ada yang tersimpan. Siswa yang belum pernah menabung → "Siswa ini belum punya tabungan di lembaga ini." Siswa yang sudah keluar tetap boleh membayar tunggakan dari saldonya.
- **Kuitansi** menampilkan cara bayar **Tabungan siswa**. **Batal kuitansi** (butuh juga `finance.savings.manage`): tagihan terbuka lagi, transaksi BAYAR_TAGIHAN ikut BATAL ("Kuitansi dibatalkan: …"), saldo kembali, baris Titipan & Kas tunai ikut batal. Transaksi BAYAR_TAGIHAN tidak bisa dibatalkan dari tab Tabungan.
- **Rekap penerimaan** (PRD-003 FN-4b): kolom/kotak **Dari tabungan** (tampil bila ada), juga di unduhan lembar Harian. **Rekap tabungan** (TB-2) menghitungnya sebagai "Bayar tagihan".
- Setor/tarik tabungan tetap hanya Tunai / Transfer bank.
- **Audit**: `finance.savings_used_for_payment` (slip, jumlah, kuitansi, tanggal); batal tercatat `finance.savings_entry_voided` + `finance.payment_voided`.

## 15. TB-4 Portal orang tua: Tabungan di Anak saya [ASUMSI — rincian teknis 2026-11-03]

- Halaman anak di **Anak saya** mendapat tombol **Tabungan** (registri Core `GuardianPortalLinks`, sesudah Tagihan) → `/anak-saya/{siswa}/tabungan`. Akses tanpa izin/role, cukup tautan orang tua/wali (sama dengan Tagihan, PRD-003 FN-5); bukan anaknya → 404.
- Isi: per lembaga tempat anak pernah/sedang terdaftar **yang punya rekening tabungan** (lembaga yang sudah ditinggalkan tetap tampil, bertanda "Sudah keluar"), saldo, dan **30 transaksi Sah terakhir** (tanggal, jenis, cara, diambil oleh, catatan mis. "Kuitansi …", jumlah, saldo sesudahnya, tautan slip). Transaksi yang dibatalkan petugas **tidak ditampilkan** (sama dengan riwayat pembayaran FN-5). Total saldo tampil bila lebih dari satu lembaga. Belum punya tabungan → "… belum punya tabungan di sekolah."
- **Slip** (`/anak-saya/{siswa}/slip-tabungan/{id}`): halaman cetak yang sama dengan petugas, tombol "Kembali ke tabungan"; hanya slip Sah milik pendaftaran anak itu, lainnya → 404.
- Hanya baca; setor/tarik tetap di kasir sekolah.
