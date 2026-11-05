# PRD-006 — Modul Finance (Keuangan): Pembayaran Online (Midtrans)

- **Versi:** 0.4 (2026-11-04: rincian & pembangunan MG-3, §17)
- **Versi sebelumnya:** 0.3 (MG-2, §16), 0.2 (disetujui owner; MG-1, §15), 0.1 (draf pertama)
- **Status:** **DISETUJUI (2026-11-03)**; MG-1 SELESAI (`93a5f30`); MG-2 SELESAI (`2c1b9c8`); MG-3 SELESAI (`28029fd`) — PRD-006 selesai
- **Bergantung pada:** PRD-003 (tagihan, kuitansi, portal Tagihan FN-5), PRD-004 (buku kas, rekening penerima transfer KS-2), PRD-005 (tabungan — tidak diubah), PRD-000 (akses, audit), ADR-001
- **Lanjutan dari:** PRD-004 §11, urutan owner 2026-10-30: (1) persetujuan berjenjang (selesai) → (2) tabungan siswa (selesai) → **(3) payment gateway Midtrans** (yayasan sudah punya akun) → (4) pembukuan

Label: **[OWNER]** = keputusan owner · **[ASUMSI]** = usulan, perlu konfirmasi · **[CEK]** = perlu dicek di akun/kontrak Midtrans yayasan.

---

## 1. Tujuan

Orang tua bisa **membayar tagihan anaknya dari HP** lewat portal **Anak saya → Tagihan**, memakai **Virtual Account bank** atau **QRIS**, tanpa datang ke kasir. Begitu Midtrans mengonfirmasi pembayaran, **kuitansi dibuat otomatis**, tagihan berkurang, dan uangnya tercatat di buku kas lembaga, sama seperti pembayaran di kasir.

Prinsip (sama dengan PRD-003/004/005):

1. Data per yayasan (tenant), disaring per pohon lembaga; audit.
2. **Uang tidak pernah dihapus**; kuitansi online dibatalkan dengan alasan seperti kuitansi biasa.
3. **Rupiah bulat.**
4. **Sumber kebenaran status bayar = Midtrans**, diterima lewat notifikasi resmi dan dicek ulang ke API status. Halaman browser orang tua tidak pernah dipercaya sebagai bukti bayar.
5. **Kunci rahasia tidak pernah di kode atau database**: Server Key & Client Key hanya di `.env` server.

## 2. Keputusan owner (2026-11-03)

| ID       | Topik       | Keputusan                                                                                                                   |
| -------- | ----------- | --------------------------------------------------------------------------------------------------------------------------- |
| OD-MG-01 | Pembayar    | **Orang tua dari portal** (Anak saya → Tagihan → Bayar online); kuitansi otomatis setelah lunas [OWNER]                     |
| OD-MG-02 | Metode      | **Virtual Account bank + QRIS** [OWNER]                                                                                     |
| OD-MG-03 | Biaya admin | **Ditanggung orang tua**: ditambahkan di atas tagihan dan ditampilkan sebelum bayar; kuitansi tetap sebesar tagihan [OWNER] |

## 3. Konsep & model data [ASUMSI]

```text
Pesanan bayar online (fn_online_payments)
    order_id unik (mis. "EDU-U3SMP-20261103-8F2K1Q"), lembaga, pendaftaran, dibuat oleh (membership orang tua),
    tagihan yang dipilih (+ nominal sisa saat pesanan dibuat), jumlah tagihan, biaya layanan, total dibayar,
    metode (BCA_VA | BNI_VA | BRI_VA | MANDIRI_BILL | PERMATA_VA | QRIS), status
    MENUNGGU → LUNAS | KEDALUWARSA | GAGAL | DIBATALKAN, batas waktu bayar,
    tautan Snap, id transaksi Midtrans, waktu lunas, kuitansi (fn_payments) bila lunas, catatan selisih
Notifikasi Midtrans (fn_online_payment_events)
    pesanan, isi notifikasi (tanpa data kartu), status, tanda tangan valid?, diterima pada — untuk jejak & audit
```

- **Satu pesanan = satu atau beberapa tagihan terbuka** satu anak di satu lembaga, dibayar **penuh** (tanpa cicil lewat online) [ASUMSI]. Cicilan tetap bisa di kasir.
- **Satu pesanan MENUNGGU per anak per lembaga**. Membuat pesanan baru membatalkan pesanan lama yang belum dibayar (dibatalkan juga di Midtrans) [ASUMSI].
- **Cara bayar kuitansi baru: ONLINE** (`fn_payments.method`), label mis. "Online — VA BCA" / "Online — QRIS".
- **Biaya layanan** (OD-MG-03) dihitung per metode dari pengaturan (§6) dan dikirim ke Midtrans sebagai baris terpisah "Biaya layanan". Biaya ini **tidak** masuk kuitansi dan **tidak** dicatat sebagai pemasukan, karena dipotong Midtrans sebagai fee.

## 4. Alur orang tua

1. **Anak saya → Tagihan**: tombol **Bayar online** (hanya bila pembayaran online aktif, §6, dan ada tagihan belum lunas).
2. **Pilih tagihan** (bawaan: semua yang belum lunas, urut jatuh tempo) → **pilih metode** (daftar VA bank + QRIS) → tampil rincian: total tagihan + biaya layanan = **total bayar**, dan batas waktu bayar.
3. **Lanjut bayar** → server membuat pesanan dan transaksi **Midtrans Snap** khusus metode itu, lalu orang tua diarahkan ke halaman pembayaran Midtrans (nomor VA / kode QR). Tanpa memuat skrip pihak ketiga di halaman EduCore [ASUMSI: pakai `redirect_url` Snap, bukan pop-up].
4. Sesudah bayar, orang tua kembali ke halaman **Status pembayaran** EduCore: Menunggu / Lunas (dengan tautan kuitansi) / Kedaluwarsa. Ada tombol **Cek status** (server bertanya ke API status Midtrans).
5. Selama pesanan **Menunggu**, halaman Tagihan menampilkannya beserta tombol **Lanjutkan pembayaran** (membuka lagi halaman Midtrans) atau **Batalkan**.

**Batas waktu bayar** [ASUMSI]: VA 24 jam, QRIS 15 menit. Lewat batas → Kedaluwarsa; tagihan tetap terbuka.

## 5. Konfirmasi lunas (server, otomatis)

1. Midtrans mengirim **notifikasi HTTP** ke `POST /webhook/midtrans` (tanpa login, dikecualikan dari CSRF, dibatasi laju).
2. Server memeriksa **tanda tangan**: `SHA512(order_id + status_code + gross_amount + ServerKey)` (dokumentasi Midtrans). Tidak cocok → ditolak dan dicatat.
3. Server **mengecek ulang ke API status Midtrans** untuk order itu (jangan percaya isi notifikasi saja; notifikasi bisa datang dobel atau tidak berurutan).
4. Status **settlement** (atau capture + fraud accept) dengan **jumlah yang sama persis** dengan total pesanan → dalam satu transaksi database:
    - kuitansi dibuat (`ManagePayments`, cara bayar ONLINE, tanggal = tanggal lunas, petugas = "Pembayaran online"), dialokasikan ke tagihan pesanan;
    - pesanan → **LUNAS**, tertaut ke kuitansi;
    - buku kas lembaga mendapat cerminan kuitansi (§7).
5. **Aman diulang** (idempotent, kunci = order_id): notifikasi kedua untuk pesanan yang sudah Lunas tidak membuat kuitansi lagi. Server membalas 2xx cepat. Kalau balasan bukan 2xx, Midtrans mengulang notifikasinya.
6. `expire` / `cancel` / `deny` → pesanan **Kedaluwarsa / Dibatalkan / Gagal**; tidak ada kuitansi.
7. **Tagihan sudah dibayar di kasir** sementara pesanan online menunggu lalu ikut lunas → kuitansi online dicatat untuk sisa yang masih terbuka; kelebihannya ditandai **"Kelebihan bayar online — perlu dikembalikan"** untuk bendahara [ASUMSI, lihat OD-MG-07]. Untuk mencegahnya, Kasir menampilkan peringatan bila siswa punya pesanan online yang menunggu.

## 6. Pengaturan [ASUMSI]

- **Kunci Midtrans** di `.env`: `MIDTRANS_SERVER_KEY`, `MIDTRANS_CLIENT_KEY`, `MIDTRANS_IS_PRODUCTION` (false = sandbox untuk uji). Tidak pernah tampil di layar atau tersimpan di database.
- **Keuangan → Pengaturan → tab Pembayaran online** (Admin Keuangan / Admin Yayasan, tingkat Yayasan):
    - aktif / nonaktif (bawaan nonaktif; hanya bisa diaktifkan bila kunci sudah terpasang);
    - metode yang dibuka (centang per bank VA + QRIS);
    - **biaya layanan per metode**: nominal tetap (VA) atau persen (QRIS), dibulatkan ke atas ke rupiah. Nilai mengikuti tarif kontrak Midtrans yayasan [CEK]; aplikasi tidak memasang angka bawaan.
- **Rekening penerima pembayaran online** per lembaga (§7).

## 7. Buku kas (PRD-004)

- Kuitansi ONLINE tercermin di buku kas lembaga sebagai MASUK **Pembayaran siswa** (sama dengan KS-2), ke rekening bank lembaga yang ditandai **"Penerima pembayaran online"**. Kalau belum ditandai, masuk ke rekening penerima transfer siswa; kalau itu pun belum ada, ke Kas tunai dengan peringatan [ASUMSI, lihat OD-MG-06].
- Biaya layanan tidak dicatat (dibayar orang tua, dipotong Midtrans).
- Pembatalan kuitansi online (oleh bendahara, alasan wajib) mengikuti PRD-003. **Uangnya tidak otomatis kembali ke orang tua**: pengembalian dana dilakukan di luar aplikasi / dashboard Midtrans [ASUMSI; refund otomatis di luar tahap ini].

## 8. Siapa melihat & mengelola [ASUMSI]

| Permission                      | Isi                                                                 |
| ------------------------------- | ------------------------------------------------------------------- |
| (tanpa izin) orang tua/wali     | Bayar online & lihat pesanan anaknya sendiri (tautan orang tua)     |
| `finance.billing.view` (ada)    | Lihat daftar pesanan online & statusnya (sesuai cakupan lembaga)    |
| `finance.settings.manage` (ada) | Pengaturan pembayaran online (tingkat Yayasan)                      |
| `finance.payments.manage` (ada) | Batalkan kuitansi online; tandai kelebihan bayar sudah dikembalikan |

Tidak ada permission baru.

## 9. Halaman [ASUMSI]

- **Anak saya → Tagihan**: tombol Bayar online; pesanan yang menunggu; riwayat pembayaran menampilkan cara bayar "Online — …".
- **Anak saya → Tagihan → Bayar online** (pilih tagihan & metode) dan **Status pembayaran**.
- **Keuangan siswa → Pembayaran online** (petugas): daftar pesanan (saring lembaga, status, tanggal), rincian + jejak notifikasi, tombol Cek status; kartu Beranda "Kelebihan bayar online" bila ada.
- **Kasir**: peringatan pesanan online menunggu; riwayat pembayaran menandai kuitansi online.
- **Rekap penerimaan**: kolom/kotak **Online**.

## 10. Tahapan [ASUMSI]

| Tahap    | Isi                                                                                                                                             |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| **MG-1** | Konfigurasi `.env` + klien Midtrans (Snap, API status), pengaturan (aktif, metode, biaya), rekening penerima online, tabel pesanan & notifikasi |
| **MG-2** | Portal: pilih tagihan & metode → pesanan + Snap → status; webhook + cek ulang status → kuitansi otomatis + buku kas; kedaluwarsa                |
| **MG-3** | Halaman petugas Pembayaran online, peringatan di Kasir, kelebihan bayar, kolom Online di rekap penerimaan, kartu Beranda                        |

Setiap tahap diuji di **sandbox Midtrans** sebelum kunci produksi dipasang.

## 11. Audit

`finance.online_payment_created` (order, total, metode), `finance.online_payment_paid` (order, kuitansi), `finance.online_payment_expired|cancelled|failed`, `finance.online_payment_overpaid`, `finance.online_settings_updated`. Notifikasi dengan tanda tangan tidak valid dicatat di `fn_online_payment_events`.

## 12. Kebutuhan non-fungsional & keamanan

- Webhook: verifikasi tanda tangan + cek ulang API status; idempotent; balas < 5 detik; dibatasi laju; tidak membuka data apa pun di responsnya.
- Jumlah yang dikirim ke Midtrans dihitung server dari tagihan di database, tidak dari isian browser.
- HTTPS wajib di produksi; URL notifikasi didaftarkan di dashboard Midtrans [CEK].
- Kegagalan jaringan ke Midtrans → pesan ramah ("Layanan pembayaran sedang tidak bisa dihubungi, coba lagi"), tidak ada pesanan setengah jadi.
- Tes otomatis memakai tiruan (fake) klien Midtrans; tidak ada panggilan ke internet dari test.

## 13. Open decisions

| ID       | Topik                                  | Usulan                                                                                                                          |
| -------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| OD-MG-04 | Cicilan lewat online                   | **DIPUTUSKAN** — **Tidak** — satu pesanan melunasi tagihan yang dipilih penuh; cicilan di kasir [OWNER, 2026-11-03]             |
| OD-MG-05 | Batas waktu bayar                      | **DIPUTUSKAN** — VA 24 jam, QRIS 15 menit [OWNER, 2026-11-03]                                                                   |
| OD-MG-06 | Rekening penerima di buku kas          | **DIPUTUSKAN** — Rekening bank lembaga bertanda "Penerima pembayaran online" (bawaan: penerima transfer) [OWNER, 2026-11-03]    |
| OD-MG-07 | Tagihan keburu dibayar di kasir        | **DIPUTUSKAN** — Kuitansi untuk sisa terbuka + tanda "Kelebihan bayar — perlu dikembalikan" untuk bendahara [OWNER, 2026-11-03] |
| OD-MG-08 | Satu akun Midtrans untuk semua lembaga | **DIPUTUSKAN** — Ya, akun yayasan; order_id memuat kode lembaga [CEK] [OWNER, 2026-11-03]                                       |
| OD-MG-09 | Bayar online untuk tabungan (setoran)  | **DIPUTUSKAN** — **Belum** — di luar tahap ini [OWNER, 2026-11-03]                                                              |

## 14. Di luar tahap ini

Refund otomatis lewat API Midtrans, kartu kredit / e-wallet / minimarket, setoran tabungan online, tautan bayar dari kasir (dikirim lewat WA), pengingat tagihan WA/SMS, rekonsiliasi otomatis dengan laporan settlement Midtrans, dan pembukuan (COA/jurnal).

## 15. MG-1 Konfigurasi, pengaturan, klien Midtrans, rekening penerima [ASUMSI — rincian teknis 2026-11-03]

- **Kunci**: `config/services.php` → `midtrans.server_key|client_key|is_production` dari `.env` (`MIDTRANS_SERVER_KEY`, `MIDTRANS_CLIENT_KEY`, `MIDTRANS_IS_PRODUCTION`, contoh di `.env.example`). Browser hanya menerima "terpasang?" dan "produksi/sandbox", tidak pernah kuncinya.
- **Klien Midtrans** (`MidtransGateway`, implementasi `HttpMidtransGateway` dengan HTTP client Laravel — tanpa library baru): buat transaksi Snap (`POST app[.sandbox].midtrans.com/snap/v1/transactions` → token & `redirect_url`), status (`GET api[.sandbox].midtrans.com/v2/{order}/status`), batal (`POST …/cancel`), otentikasi Basic (Server Key, sandi kosong), batas waktu 15 detik. Gangguan / HTTP 5xx → "Layanan pembayaran sedang tidak bisa dihubungi. Coba lagi beberapa saat lagi."; HTTP 4xx → "Layanan pembayaran menolak permintaan ini. Hubungi bendahara sekolah."; rincian teknis hanya di log. Cek tanda tangan notifikasi: `hash_equals(SHA512(order_id + status_code + gross_amount + ServerKey))`.
- **Metode** (`OnlinePaymentMethod`): VA BCA (`bca_va`), VA BNI (`bni_va`), VA BRI (`bri_va`), Mandiri Bill (`echannel`), VA Permata (`permata_va`), QRIS (`other_qris`) — kode Snap sesuai dokumentasi Midtrans. Batas waktu bayar: VA 1.440 menit, QRIS 15 menit (OD-MG-05).
- **Pengaturan → tab Pembayaran online** (`finance.settings.manage` tingkat Yayasan): status (Aktif / Belum aktif, Sandbox / Produksi), per metode centang **dibuka** + **biaya layanan** — VA nominal Rp 0–50.000; QRIS 0–10% dengan ≤ 2 angka desimal (koma atau titik), disimpan sebagai basis poin, biaya = `ceil(tagihan × persen)`. Kunci belum dipasang → metode tidak bisa dibuka ("Kunci Midtrans belum dipasang di server (.env)…"). Tidak ada tarif bawaan [CEK]. Audit `finance.online_settings_updated` (hanya bila berubah).
- **Kas → Rekening**: tombol **Jadikan penerima pembayaran online** / lepas pada rekening bank aktif lembaga (≤ 1 per lembaga, `fn_cash_accounts.receives_online`), lencana "Penerima pembayaran online". Dipakai buku kas di MG-2 (OD-MG-06).
- **Data** (migration `2026_11_03_300014`): `fn_online_payment_methods`, `fn_online_payments` (satu MENUNGGU per pendaftaran, `gross = tagihan + biaya`), `fn_online_payment_events`. Pesanan & notifikasi dipakai mulai MG-2.

## 16. MG-2 Bayar online di portal, webhook, kuitansi otomatis [ASUMSI — rincian teknis 2026-11-03]

- **Anak saya → Tagihan**: tombol **Bayar online** (bila aktif & ada sisa tagihan); kartu kuning untuk pesanan **Menunggu** (total, metode, batas waktu, tombol **Lanjutkan pembayaran** & **Lihat status**); daftar "Pembayaran online sebelumnya" (10 terbaru, status).
- **Bayar online** (`/anak-saya/{siswa}/bayar-online`): pilih lembaga (bila > 1), centang tagihan (bawaan semua yang belum lunas), pilih metode (dengan keterangan biaya), rincian Tagihan + Biaya layanan = **Total bayar** (perkiraan di browser; dihitung ulang server), batas waktu. **Lanjut bayar** → pesanan dibuat, lalu diarahkan penuh ke halaman Midtrans (`redirect_url`). Dibatasi 10 kali/menit.
- **Pesanan**: `order_id` = `EDU-<KODE LEMBAGA tanpa tanda>-<YYYYMMDD>-<8 huruf/angka acak>`; Snap: `transaction_details`, `item_details` (tiap tagihan + "Biaya layanan <metode>"), `customer_details.first_name` = **nama siswa saja** (tanpa HP/email), `enabled_payments` = satu metode, `expiry` (start_time WIB, `minutes`, 1.440 / 15), `callbacks.finish` = `/anak-saya/{siswa}/bayar-online/selesai`. Pesanan MENUNGGU lama dibatalkan (juga di Midtrans; bila Midtrans menolak karena transaksinya belum ada → batal lokal; bila Midtrans tak bisa dihubungi → ditolak dengan pesan ramah). Snap gagal → pesanan **GAGAL**, pesan ramah, bisa diulang.
- **Halaman status** (`/anak-saya/{siswa}/bayar-online/{id}`): status, rincian, tombol **Lanjutkan pembayaran / Cek status / Batalkan** (menunggu) atau **Lihat kuitansi** (lunas); pesan kelebihan bayar bila ada. Kembali dari Midtrans (`…/selesai?order_id=`) → status dicek sekali ke API lalu diarahkan ke halaman status.
- **Webhook** `POST /webhook/midtrans` (tanpa login & CSRF, 120/menit): setiap notifikasi dicatat di `fn_online_payment_events` (tanpa `signature_key`); tanda tangan salah → **403**; order tak dikenal → 200 tanpa tindakan; selain itu status **dicek ulang ke API** — Midtrans tak bisa dihubungi → **503** (Midtrans mengirim ulang). `settlement` (atau `capture` + `fraud_status=accept`) dengan `gross_amount` **sama persis** → LUNAS; `expire` → KEDALUWARSA; `cancel` → DIBATALKAN; `deny`/`failure` → GAGAL; `pending` → tidak berubah. Jumlah beda → diabaikan & dicatat di log.
- **Lunas** (satu transaksi, kunci baris pesanan, aman diulang): kuitansi cara bayar **ONLINE** ("Pembayaran online") untuk tagihan pesanan yang **masih terbuka**, jumlah = min(total tagihan pesanan, sisa sekarang), tanggal = tanggal `settlement_time` (WIB), catatan "Midtrans <metode> · <order_id>", petugas di kuitansi "Pembayaran online (otomatis)"; sisanya = **kelebihan bayar** (`overpaid_amount`, OD-MG-07; ditindaklanjuti bendahara di MG-3). Buku kas: MASUK "Pembayaran siswa" ke rekening penerima online → penerima transfer → Kas tunai (OD-MG-06; menandai/melepas penerima menyusun ulang cerminan).
- **Kasir**: cara bayar ONLINE tidak bisa dipilih petugas. (Kolom **Online** di Rekap penerimaan: MG-3.)
- **Audit**: `finance.online_payment_created|paid|expired|cancelled|failed` (+ `finance.payment_recorded` untuk kuitansinya).

## 17. MG-3 Halaman petugas, kelebihan bayar, Kasir, rekap, Beranda [ASUMSI — rincian teknis 2026-11-04]

- **Keuangan siswa → Pembayaran online** (`/keuangan/pembayaran-online`, `finance.billing.view`): saring lembaga (Semua lembaga bila > 1), status (Menunggu / Lunas / Kedaluwarsa / Gagal / Dibatalkan / **Kelebihan bayar (belum dikembalikan)**), bulan dibuat; 50 per halaman, terbaru dulu; baris: siswa + kelas, lembaga, waktu, metode, order_id, total, status, lencana kelebihan. Spanduk kuning bila ada kelebihan bayar yang belum dikembalikan.
- **Rincian** (`/keuangan/pembayaran-online/{id}`): status, waktu dibuat/batas/lunas, tagihan, biaya layanan (dibayar orang tua), total, tautan kuitansi, daftar tagihan, **jejak notifikasi Midtrans** (waktu, status, tanda tangan valid/tidak; tanpa isi lengkap), tombol **Buka di kasir**. Pemilik `finance.payments.manage` di lembaga itu: **Cek status di Midtrans** (bila belum lunas) dan **Tandai sudah dikembalikan** (pop-up, catatan opsional ≤ 200; sekali saja; audit `finance.online_overpaid_settled` dengan jumlah & catatan). Pengembalian uangnya di luar aplikasi.
- **Kasir**: spanduk kuning bila siswa punya pesanan online **Menunggu** (total, metode, batas, tagihan, tautan) agar petugas tidak menagih tagihan yang sama (mencegah OD-MG-07).
- **Rekap penerimaan**: kotak & kolom **Online** (tampil bila ada), lembar Harian unduhan mendapat kolom "Online (Rp)".
- **Beranda**: kartu **Kelebihan bayar online** (jumlah pesanan & total rupiah, nada peringatan, tautan ke saringan kelebihan) hanya bila ada yang belum dikembalikan, sesuai cakupan `finance.billing.view`.
- **Proxy tepercaya**: `TRUSTED_PROXIES` di `.env` (`config('educore.trusted_proxies')`, dipasang di `AppServiceProvider`); kosong = tidak ada (produksi tanpa proxy); `*` hanya untuk pengembangan lewat ngrok / Laragon Share; daftar IP dipisah koma untuk proxy/load balancer produksi.
