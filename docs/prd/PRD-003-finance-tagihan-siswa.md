# PRD-003 — Modul Finance (Keuangan): Tagihan & Pembayaran Siswa

- **Versi:** 0.5 (2026-10-30: rincian & pembangunan FN-2b tagihan sekali bayar & batal otomatis saat siswa keluar, §15)
- **Versi sebelumnya:** 0.4 (FN-2a, §14), 0.3 (FN-1b potongan/beasiswa, §13), 0.2 (disetujui owner; FN-1a, §12), 0.1 (draf pertama)
- **Status:** **DISETUJUI (2026-10-28)**; FN-1a SELESAI (`a51468a`); FN-1b SELESAI (`518d374`); FN-2a SELESAI (`e2b07b6`); FN-2b dibangun 2026-10-30, menunggu uji owner
- **Bergantung pada:** PRD-000 (pondasi), PRD-002 (siswa, pendaftaran, kelas, orang tua/wali, portal Anak saya), ADR-001
- **Referensi domain lama:** tidak ada. Dokumen lama `prd/billing/BILL-000` membahas **langganan platform** (yayasan membayar EduCore), bukan keuangan sekolah; istilah itu tetap dipisah (lihat §7).

Label: **[OWNER]** = keputusan owner · **[ASUMSI]** = usulan baru, perlu konfirmasi.

---

## 1. Tujuan

Setiap lembaga mencatat **tagihan siswa** (SPP bulanan, daftar ulang, seragam, dll.) dan **pembayarannya** di satu tempat: siapa sudah bayar, siapa menunggak, berapa uang masuk hari/bulan ini — dengan kuitansi bernomor dan jejak audit. Orang tua melihat tagihan & riwayat bayar anaknya di portal **Anak saya**.

Prinsip:

1. Mengikuti pondasi: data per yayasan (tenant), disaring per pohon lembaga (PRD-000 §7), katalog akses di kode, audit tanpa nominal pribadi yang tidak perlu.
2. **Uang tidak pernah dihapus.** Tagihan & pembayaran yang salah **dibatalkan dengan alasan** (tetap tersimpan, tercatat di audit), bukan dihapus.
3. **Rupiah bulat** (tanpa sen), disimpan sebagai bilangan bulat.
4. Tagihan melekat pada **pendaftaran** siswa di lembaga (PRD-002 §4): siswa SMP yang juga santri MDA punya tagihan terpisah di tiap lembaga.

## 2. Keputusan owner (2026-10-28)

| ID       | Topik              | Keputusan                                                                                                                                         |
| -------- | ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| OD-FN-01 | Cakupan tahap awal | **Tagihan & pembayaran siswa**: jenis biaya, tarif, potongan/beasiswa, catat pembayaran + kuitansi, tunggakan, rekap, portal orang tua [OWNER]    |
| OD-FN-02 | Pengelola          | **Bendahara per lembaga** (role baru) mencatat pembayaran siswa lembaganya; tarif & laporan se-yayasan oleh Admin Yayasan + Biro Keuangan [OWNER] |
| OD-FN-03 | Cara bayar         | **Dicatat petugas** (tunai / transfer ke rekening yayasan) + kuitansi; payment gateway menyusul [OWNER]                                           |

## 3. Tahapan [ASUMSI — urutan usulan]

| Tahap    | Isi                                                                                                                                                                                                                                                                                                                                                               |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **FN-1** | Jenis biaya & tarif (per lembaga, tingkat, tahun ajaran) + potongan/beasiswa per siswa — dipecah **FN-1a** (kerangka modul, hak akses, jenis biaya, tarif) dan **FN-1b** (potongan/beasiswa)                                                                                                                                                                      |
| **FN-2** | Buat tagihan: SPP bulanan otomatis per tahun ajaran, biaya sekali bayar per lembaga/kelas/siswa; batalkan tagihan; **jatuh tempo SPP** (aturan berjenjang, dipindah dari FN-1 karena baru dipakai saat menagih) — dipecah **FN-2a** (jatuh tempo, tagihan bulanan, daftar & batal tagihan) dan **FN-2b** (tagihan sekali bayar, batal otomatis saat siswa keluar) |
| **FN-3** | Catat pembayaran (cicil boleh), kuitansi bernomor + cetak, batalkan pembayaran; halaman tagihan per siswa                                                                                                                                                                                                                                                         |
| **FN-4** | Tunggakan per kelas/lembaga, rekap penerimaan harian/bulanan, unduh .xlsx, kartu Beranda                                                                                                                                                                                                                                                                          |
| **FN-5** | Portal orang tua: menu **Tagihan** di Anak saya (sisa tagihan, riwayat bayar, kuitansi)                                                                                                                                                                                                                                                                           |
| Menyusul | Payment gateway (VA/QRIS), kas umum & pengeluaran, pembukuan (COA, jurnal), tabungan siswa, notifikasi WA                                                                                                                                                                                                                                                         |

Setiap tahap dirinci teknis sebelum dibangun, lalu dipecah lagi (a/b) bila besar — sama dengan HR & Akademik.

## 4. Model data [ASUMSI]

```text
Jenis biaya (fn_fee_types)                 per yayasan: kode, nama, sifat BULANAN | SEKALI, aktif
  └─► Tarif (fn_fee_rates)                 jenis × lembaga × tahun ajaran × tingkat? → nominal
Potongan (fn_discounts)                    pendaftaran × jenis biaya × tahun ajaran → persen ATAU nominal, alasan
Tagihan (fn_invoices)                      pendaftaran × jenis × periode (bulan / sekali) → nominal, potongan,
                                           jatuh tempo, status TERBUKA | LUNAS | BATAL
Pembayaran (fn_payments)                   pendaftaran, tanggal, metode TUNAI | TRANSFER, jumlah, no. kuitansi,
                                           dicatat oleh, status SAH | BATAL (+ alasan)
  └─► Alokasi (fn_payment_allocations)     pembayaran × tagihan → jumlah (satu bayar bisa melunasi beberapa tagihan)
```

- **Tarif**: per lembaga dan tahun ajaran; boleh dibedakan per **tingkat** (mis. kelas 7 vs 9). Tarif tanpa tingkat = berlaku untuk semua tingkat; tarif bertingkat didahulukan.
- **Potongan/beasiswa**: per siswa (pendaftaran) per jenis biaya per tahun ajaran, persen (1–100) **atau** nominal, alasan wajib (mis. "Yatim", "Anak guru"). Berlaku untuk tagihan yang **dibuat sesudahnya**; tagihan lama tidak berubah otomatis.
- **Tagihan SPP**: satu per siswa per bulan tahun ajaran, untuk siswa yang **terdaftar aktif** di lembaga pada bulan itu; tingkat diambil dari kelasnya. Dibuat massal ("Buat tagihan SPP Juli–Juni") dan bisa diulang dengan aman (yang sudah ada dilewati). Siswa baru masuk di tengah tahun: tagihan mulai bulan masuknya. Siswa keluar: tagihan bulan sesudahnya yang belum dibayar dibatalkan otomatis dengan alasan "Siswa keluar".
- **Tagihan sekali bayar**: dibuat untuk satu lembaga, satu kelas, atau satu siswa.
- **Jatuh tempo**: SPP tanggal 10 bulan itu (aturan berjenjang, bisa diubah per lembaga); sekali bayar diisi saat membuat.
- **Pembayaran**: jumlah dialokasikan ke tagihan **terlama dulu** atau ke tagihan yang dipilih; boleh **cicil** (tagihan tetap TERBUKA sampai lunas). **Kelebihan bayar ditolak** (belum ada saldo/deposit).
- **Kuitansi**: nomor urut per lembaga per tahun, mis. `SMP3/2026/000123`; berisi nama siswa, lembaga, kelas, rincian tagihan yang dibayar, jumlah, terbilang, metode, petugas, tanggal. Cetak lewat browser (A5/A4).
- **Batal**: pembatalan pembayaran membuka kembali tagihannya; pembatalan tagihan hanya bila belum ada pembayaran sah padanya. Keduanya wajib alasan.

## 5. Siapa melihat & mengelola [ASUMSI]

Permission baru (`finance.*`):

| Permission                | Isi                                                                  |
| ------------------------- | -------------------------------------------------------------------- |
| `finance.billing.view`    | Lihat tagihan, pembayaran, tunggakan, rekap (sesuai cakupan lembaga) |
| `finance.payments.manage` | Catat & batalkan pembayaran, cetak kuitansi                          |
| `finance.invoices.manage` | Buat & batalkan tagihan, atur potongan siswa                         |
| `finance.settings.manage` | Jenis biaya & tarif (tingkat Yayasan)                                |

| Role                                                             | Permission                                                                   | Cakupan                             |
| ---------------------------------------------------------------- | ---------------------------------------------------------------------------- | ----------------------------------- |
| **Bendahara** (baru, penugasan lembaga)                          | view, payments.manage, invoices.manage                                       | Lembaga penugasannya (+ turunannya) |
| **Admin Keuangan** (baru, tingkat Yayasan) — untuk Biro Keuangan | semua `finance.*`                                                            | Seluruh yayasan                     |
| Admin Yayasan                                                    | semua `finance.*`                                                            | Seluruh yayasan                     |
| Pimpinan, Kepala Lembaga                                         | `finance.billing.view`                                                       | Cakupannya                          |
| Operator Akademik, guru, wali kelas                              | — (tidak melihat keuangan)                                                   |                                     |
| Orang tua/wali                                                   | tanpa permission; tagihan anaknya sendiri lewat tautan orang tua/wali (FN-5) | Anaknya                             |

Admin Keuangan dibuat sebagai role terpisah dari Admin SDM agar petugas tagihan siswa tidak otomatis melihat gaji pegawai; satu orang boleh memegang keduanya.

## 6. Halaman (ringkas) [ASUMSI]

- **Keuangan → Pengaturan** (tingkat Yayasan): jenis biaya, tarif per lembaga/tahun ajaran/tingkat, jatuh tempo SPP.
- **Keuangan → Tagihan**: buat tagihan SPP/sekali bayar, daftar tagihan (saring lembaga, kelas, jenis, bulan, status).
- **Keuangan → Bayar** (layar kasir): cari siswa (nama/NISN/nomor induk) → tagihan terbuka → isi jumlah → simpan → kuitansi.
- **Keuangan → Tunggakan** & **Rekap penerimaan**: per lembaga/kelas, unduh .xlsx.
- **Detail siswa** (Akademik): tidak memuat keuangan; tautan "Lihat tagihan" hanya bagi pemilik `finance.billing.view`.
- **Anak saya → Tagihan** (FN-5).

## 7. Batas modul [ASUMSI]

- Modul baru `modules/Finance` (nama kode **Finance**, label UI **Keuangan**). Berbeda dari `Billing` (langganan platform, BILL-000) yang tidak dibangun di sini.
- Finance **membaca** siswa, pendaftaran, kelas, tingkat, tahun ajaran, dan tautan orang tua/wali lewat **kontrak** yang disediakan Akademik (`Modules\Academic\Contracts\…`), bukan query langsung ke tabel Akademik. Akademik tidak bergantung pada Finance (tombol/menu Finance dipasang lewat registri bersama, seperti kartu Beranda).
- Gaji pegawai tetap di HR (PRD-001 §16); tidak dicampur dengan tagihan siswa.

## 8. Audit

`finance.fee_type_*`, `finance.fee_rate_set`, `finance.discount_set|removed`, `finance.invoices_generated` (jenis, periode, jumlah tagihan), `finance.invoice_voided` (alasan), `finance.payment_recorded` (no. kuitansi, jumlah), `finance.payment_voided` (no. kuitansi, alasan). Nominal pembayaran **boleh** dicatat di audit (bukti transaksi keuangan, berbeda dengan gaji) [ASUMSI].

## 9. Kebutuhan non-fungsional

- Semua perhitungan uang di server; bilangan bulat; transaksi database + kunci baris saat mencatat pembayaran agar tidak dobel.
- Nomor kuitansi tidak boleh lompat/dobel walau dua bendahara menyimpan bersamaan.
- Layar kasir nyaman di HP/tablet; kuitansi bisa dicetak/simpan PDF.
- Daftar besar memakai halaman (paging) dan saringan.

## 10. Open decisions

| ID       | Topik                                       | Usulan                                                                                   |
| -------- | ------------------------------------------- | ---------------------------------------------------------------------------------------- |
| OD-FN-04 | Tarif SPP berbeda per tingkat?              | **DIPUTUSKAN** — boleh (opsional), selain per lembaga & tahun ajaran [OWNER, 2026-10-28] |
| OD-FN-05 | Role Admin Keuangan terpisah dari Admin SDM | **DIPUTUSKAN** — ya [OWNER, 2026-10-28]                                                  |
| OD-FN-06 | Kelebihan bayar / deposit                   | **DIPUTUSKAN** — ditolak di tahap awal [OWNER, 2026-10-28]                               |
| OD-FN-07 | Siswa keluar → tagihan bulan berikutnya     | **DIPUTUSKAN** — dibatalkan otomatis bila belum dibayar [OWNER, 2026-10-28]              |
| OD-FN-08 | Denda keterlambatan                         | **DIPUTUSKAN** — tidak ada di tahap awal [OWNER, 2026-10-28]                             |

## 11. Di luar tahap awal

Payment gateway (VA/QRIS) & rekonsiliasi mutasi bank, kas umum/pengeluaran, pembukuan (COA, jurnal, neraca), tabungan siswa, denda, pengingat WA/SMS, integrasi akuntansi, pajak.

## 12. FN-1a Kerangka modul, hak akses, jenis biaya & tarif [ASUMSI disetujui 2026-10-28 — rincian teknis]

- **Modul** `modules/Finance` (`FinanceServiceProvider`, didaftarkan sesudah Academic). Arch test: Finance hanya memakai Core dan `Modules\Academic\Contracts`; Core, HR, Academic tidak memakai Finance.
- **Kontrak Academic baru** `Modules\Academic\Contracts\AcademicYears::forOrganization()` — tahun ajaran kalender yang dipakai lembaga (diisi `CalendarAcademicYears`). Foreign key database ke `ac_academic_years` boleh (preseden: Academic → `hr_employees`).
- **Hak akses** sesuai §5: permission `finance.billing.view`, `finance.payments.manage`, `finance.invoices.manage`, `finance.settings.manage`; role **Bendahara** (ditawarkan di penugasan lembaga) dan **Admin Keuangan** (ditawarkan sebagai role tingkat Yayasan); Admin Yayasan semua; Pimpinan & Kepala Lembaga lihat. Wajib `php artisan educore:sync-access` saat deploy.
- **Jenis biaya** (`fn_fee_types`): kode huruf besar/angka/"-" 2–30, unik per yayasan, tidak bisa diubah; nama maks. 100; **sifat** Bulanan / Sekali bayar, tidak bisa diubah; tidak dihapus, hanya nonaktif (tarif & tagihan lama tetap; tidak bisa diberi tarif baru).
- **Tarif** (`fn_fee_rates`): jenis × **lembaga aktif (node LEMBAGA)** × **tahun ajaran kalender lembaga itu** × tingkat (kosong = semua tingkat, 1–12 = khusus, didahulukan). Nominal **Rp 1 s.d. Rp 100.000.000** (format "150.000", "Rp 150000" diterima). Simpan kombinasi yang sudah ada = ubah nominal; hapus tarif boleh (tagihan yang sudah dibuat tidak ikut berubah, FN-2).
- **Halaman** `Keuangan → Pengaturan keuangan` (`/keuangan/pengaturan?lembaga=&tahun=`, menu tingkat Yayasan bagi `finance.settings.manage`): daftar jenis biaya + tambah/ubah nama/nonaktif; bagian **Tarif** dengan pilihan lembaga & tahun ajaran (bawaan tahun berjalan), baris "Semua tingkat" dan "Tarif khusus tingkat" per jenis biaya.
- **Audit**: `finance.fee_type_created|updated|deactivated|activated` (kode, nama, sifat), `finance.fee_rate_set|removed` (kode, lembaga, tahun ajaran, tingkat).

## 13. FN-1b Potongan/beasiswa siswa [ASUMSI — rincian teknis 2026-10-29]

- **Data** (`fn_discounts`): pendaftaran × jenis biaya × tahun ajaran, **satu potongan** per kombinasi (simpan ulang = ubah). Isinya **persen** (bulat 1–100) **atau** **nominal** (Rp 1 s.d. Rp 100.000.000 per tagihan), alasan wajib maks. 100 karakter. Database menjamin pendaftaran di lembaga yang sama dengan potongan.
- **Syarat**: pendaftaran masih aktif di lembaga itu; tahun ajaran dari kalender lembaga; jenis biaya aktif. Hapus boleh. Potongan hanya berlaku untuk tagihan yang dibuat **sesudahnya** (FN-2); tagihan lama tidak berubah.
- **Halaman** `Keuangan → Potongan siswa` (`/keuangan/potongan?lembaga=&tahun=`): pilih lembaga (yang terlihat dengan `finance.billing.view`) dan tahun ajaran (bawaan: berjalan); daftar potongan (siswa, kelas, NIS/NISN, jenis biaya, besar, alasan). **Tambah/ubah** & **hapus** hanya bagi `finance.invoices.manage` di lembaga itu (Bendahara lembaganya, Admin Keuangan, Admin Yayasan); Pimpinan & Kepala Lembaga hanya melihat. Lembaga di luar cakupan → 404; tanpa izin kelola → 403.
- **Cari siswa** (`/keuangan/siswa/cari?lembaga=&q=`, JSON, 30/menit): siswa **aktif** di lembaga itu, sebagian nama atau awal NISN / nomor induk, min. 2 huruf, maks. 10 hasil; data dasar saja (nama, NISN, nomor induk, kelas).
- **Kontrak Academic baru** `EnrolledStudents` (`search`, `describe`) diisi `AcademicEnrolledStudents` — Finance tidak membaca tabel siswa langsung.
- **Audit** `finance.discount_set|removed` pada pendaftaran (jenis biaya, tahun ajaran, besar potongan, baru/ubah). **Alasan tidak dicatat** di audit karena bisa memuat keadaan pribadi siswa (mis. yatim).

## 14. FN-2a Jatuh tempo, tagihan bulanan, daftar & batal tagihan [ASUMSI — rincian teknis 2026-10-29]

- **Jatuh tempo** tagihan bulanan: aturan berjenjang `finance.due_day` (tanggal **1–28** di bulan tagihan; bawaan **10**), se-yayasan / per jenjang / per lembaga-unit, bisa dikunci; diatur di Pengaturan keuangan oleh `finance.settings.manage`. Tampilan aturan berjenjang kini dari Core (`ScopedSettingOverview`, dipindah dari HR; komponen `components/core/scoped-rules`).
- **Tagihan** (`fn_invoices`): pendaftaran × jenis biaya × bulan (`period` = tanggal 1), keterangan "SPP — Oktober 2026", tingkat, **tarif, potongan (+ label), nominal akhir disalin saat dibuat**, jatuh tempo, status **Belum lunas / Lunas / Batal**, `paid_amount` (diisi FN-3). Database menjamin: nominal = tarif − potongan, satu tagihan bulanan yang belum batal per siswa × jenis × bulan, batal wajib beralasan.
- **Buat tagihan bulanan** (Keuangan → Tagihan → _Buat tagihan bulanan_): pilih jenis biaya **bulanan aktif** dan rentang bulan di dalam tahun ajaran → **Pratinjau** (jumlah siswa, tagihan baru, yang sudah ada, total, siswa tanpa tarif — tidak menyimpan) → **Buat**.
    - Siswa = pendaftaran di lembaga yang **aktif pada sebagian bulan itu** (masuk tengah tahun → mulai bulan masuknya; sudah keluar → tidak ditagih bulan sesudahnya).
    - Tarif = tarif **tingkat kelas** siswa di tahun ajaran itu; bila tidak ada, tarif **semua tingkat**; siswa tanpa tarif dilewati dan disebut namanya.
    - Potongan FN-1b disalin: persen **dibulatkan ke bawah** ke rupiah; nominal maks. sebesar tarif. Tagihan Rp 0 (potongan penuh) langsung **Lunas**.
    - **Aman diulang**: tagihan yang sudah ada (belum batal) dilewati — dipakai juga untuk menagih siswa baru. Dua petugas bersamaan tidak membuat tagihan ganda (kunci per lembaga × tahun × jenis).
- **Daftar tagihan** (`/keuangan/tagihan?lembaga=&tahun=&jenis=&bulan=&status=&q=`): urut nama siswa lalu bulan, 50 per halaman; ringkasan jumlah, total (tanpa yang batal), sudah dibayar, sisa; tanda **Lewat jatuh tempo** untuk yang belum lunas. Cari nama/NIS hanya siswa yang masih aktif di lembaga.
- **Batalkan tagihan**: alasan wajib 1–200 karakter; hanya bila **belum ada pembayaran** (FN-3: batalkan pembayarannya dulu). Tagihan batal tetap tampil (dicoret) dan tidak menghalangi tagihan baru untuk bulan yang sama.
- **Hak akses**: lihat `finance.billing.view`; buat & batalkan `finance.invoices.manage` di lembaga itu. Menu **Tagihan** bagi pemilik `finance.billing.view`.
- **Audit**: `finance.invoices_generated` (jenis biaya, lembaga, tahun ajaran, rentang bulan, jumlah, total), `finance.invoice_voided` (keterangan, nominal, alasan).

## 15. FN-2b Tagihan sekali bayar & batal otomatis saat siswa keluar [ASUMSI — rincian teknis 2026-10-30]

- **Tagihan sekali bayar** (Tagihan → _Buat tagihan sekali bayar_): jenis biaya **sekali bayar aktif**, sasaran **seluruh lembaga / satu kelas / satu siswa**, **jatuh tempo diisi** (tanggal apa pun yang benar) → **Pratinjau** → **Buat**.
    - Siswa = pendaftaran yang aktif pada **tanggal acuan**: hari ini, atau awal/akhir tahun ajaran bila hari ini di luar tahun itu. Sasaran kelas = siswa yang kelasnya (tahun itu) kelas tersebut; sasaran siswa dicari lewat pencarian siswa aktif lembaga.
    - Tarif & potongan sama dengan tagihan bulanan (tingkat dulu, lalu semua tingkat; persen dibulatkan ke bawah; Rp 0 langsung Lunas). Keterangan: "Seragam — 2026/2027".
    - **Satu tagihan sekali bayar yang belum batal per siswa × jenis biaya × tahun ajaran** (dijaga database); yang sudah ada dilewati, sehingga bisa dijalankan per kelas lalu seluruh lembaga tanpa dobel.
- **Batal otomatis saat siswa keluar** (OD-FN-07): ketika pendaftaran ditutup (keluar, pindah, lulus — termasuk lulus massal akhir tahun), tagihan **bulanan** untuk bulan **sesudah bulan keluarnya** yang **belum ada pembayarannya** dibatalkan dengan alasan "Siswa keluar (dibatalkan otomatis)". Tagihan bulan keluarnya dan tagihan sekali bayar **tidak** disentuh; tagihan yang sudah ada pembayarannya tetap (diselesaikan bendahara).
    - Mekanisme: Academic mengirim kejadian publik `Modules\Academic\Contracts\Events\EnrollmentClosed` di dalam transaksi penutupan; Finance mendengarkannya (Academic tetap tidak bergantung pada Finance). Bila Finance gagal, penutupan ikut batal.
    - Pendaftaran yang **dibuka kembali** (PRD-002 §18 P2) tidak memulihkan tagihan yang dibatalkan otomatis; buat ulang lewat _Buat tagihan bulanan_ (yang batal tidak menghalangi).
- **Audit**: `finance.invoices_generated` (juga untuk sekali bayar, dengan sasaran), `finance.invoices_voided_on_exit` (tanggal keluar, jumlah).
