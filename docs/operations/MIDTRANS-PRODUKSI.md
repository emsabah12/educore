# Checklist Midtrans Produksi

Acuan: PRD-006 §6, §12, §15–§17 (OD-MG-01 s.d. OD-MG-09); `.env.example`; STATUS "Tugas berikutnya" no. 1.

Dokumen ini dipakai **sekali** saat pembayaran online dipindah dari **sandbox** (uji, uang bohongan) ke **produksi** (uang sungguhan), lalu disimpan sebagai catatan. Kerjakan berurutan; centang tiap butir. Butir bertanda **[CEK MIDTRANS]** bergantung pada akun/kontrak yayasan di Midtrans, bukan pada EduCore.

## 0. Sebelum mulai

- [ ] **Akun Midtrans yayasan sudah aktif di produksi.** Masuk ke `dashboard.midtrans.com`, pilih lingkungan **Production** di pojok kiri atas. Bila belum bisa, aktivasi akun (verifikasi usaha & dokumen) diurus dengan Midtrans lebih dulu. [CEK MIDTRANS]
- [ ] **Metode yang dipakai sudah aktif di produksi**: Settings → Snap Preferences → tab **Payment Channels**. EduCore mendukung VA BCA, VA BNI, VA BRI, Mandiri Bill, VA Permata, dan QRIS (OD-MG-02). Metode yang belum aktif di Midtrans **jangan** dibuka di EduCore. [CEK MIDTRANS]
- [ ] **Tarif biaya layanan** per metode dari kontrak Midtrans sudah di tangan (nominal per transaksi untuk VA, persen untuk QRIS). EduCore tidak punya tarif bawaan (PRD-006 §6). [CEK MIDTRANS]
- [ ] Situs produksi sudah bisa dibuka lewat **HTTPS** dengan sertifikat sah (gembok di browser), di port standar 443. Midtrans tidak bisa mengirim notifikasi ke `localhost`, alamat ber-password, VPN, atau port tak lazim.
- [ ] **Backup basis data** produksi dibuat dan diuji (lihat `docs/operations/BACKUP-RESTORE.md`).
- [ ] Pilih **waktu sepi** (mis. malam/akhir pekan) dan beri tahu bendahara: selama pergantian, tombol Bayar online dimatikan sebentar.

## 1. Bersihkan sisa uji sandbox

Kunci sandbox dan produksi berbeda. Pesanan yang dibuat dengan kunci sandbox tidak bisa dicek atau dibatalkan lagi setelah kunci diganti.

- [ ] Keuangan → Pengaturan → tab **Pembayaran online** → **hapus centang semua metode** lalu simpan. Status berubah menjadi **Belum aktif** dan orang tua tidak bisa membuat pesanan baru. (Pembayaran online aktif bila minimal satu metode dibuka.)
- [ ] Keuangan siswa → **Pembayaran online** → saring status **Menunggu**. Pastikan **kosong**: batalkan, atau tunggu kedaluwarsa (QRIS 15 menit, VA 24 jam — OD-MG-05).
- [ ] **Hanya bila server produksi ini pernah dipakai uji sandbox**: kuitansi "Pembayaran online" hasil uji sandbox adalah **uang bohongan**. Cari di saringan status **Lunas**, lalu batalkan kuitansinya lewat Kasir (pembatalan kuitansi biasa) supaya tagihan & buku kas kembali benar. Catat nomor order yang dibatalkan.

    Untuk menghitung sisa pesanan uji, bisa juga lewat basis data (hanya baca):

    ```sql
    SELECT status, count(*) FROM fn_online_payments GROUP BY status;
    ```

## 2. Kunci produksi di server

Kunci **hanya** disimpan di file `.env` server — tidak pernah di Git, chat, email, atau tangkapan layar (PRD-006 §6).

- [ ] Ambil kunci dari Midtrans: lingkungan **Production** → Settings → **Access Keys** → _Server Key_ dan _Client Key_. Pastikan bukan kunci sandbox (kunci sandbox biasanya berawalan `SB-`).
- [ ] Ubah `.env` di server produksi:

    ```dotenv
    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://<domain-yayasan>

    MIDTRANS_SERVER_KEY=<server key produksi>
    MIDTRANS_CLIENT_KEY=<client key produksi>
    MIDTRANS_IS_PRODUCTION=true
    ```

    `APP_DEBUG=false` wajib: bila `true`, halaman error bisa membocorkan isi konfigurasi termasuk kunci.

- [ ] Batasi hak baca file: `chmod 600 .env` (pemiliknya user yang menjalankan PHP).
- [ ] Muat ulang konfigurasi:

    ```bash
    docker exec educore-app php artisan optimize:clear
    docker exec educore-app php artisan config:cache
    ```

## 3. Proxy tepercaya (`TRUSTED_PROXIES`)

Menentukan apakah Laravel percaya header `X-Forwarded-*` (asal `https`, IP asli pengunjung). Salah atur → alamat kembali dari Midtrans bisa menjadi `http://`, atau pembatasan laju salah menghitung IP.

- [ ] **HTTPS dilayani langsung oleh container `educore-web`** (sertifikat dipasang di situ, tidak ada server lain di depannya): biarkan `TRUSTED_PROXIES=` **kosong**.
- [ ] **Ada reverse proxy / load balancer / Cloudflare di depan** (mis. Nginx/Caddy di host, Nginx Proxy Manager): isi IP proxy itu, dipisah koma, mis. `TRUSTED_PROXIES=172.18.0.1`. Untuk Cloudflare: daftar IP resmi Cloudflare.
- [ ] **Jangan pernah `*` di produksi** (`*` hanya untuk ngrok / Laragon Share saat pengembangan).
- [ ] Setelah mengubah: `optimize:clear` + `config:cache` lagi.

## 4. Dashboard Midtrans produksi

- [ ] Lingkungan **Production** → **Settings → Configuration** → **Payment Notification URL**:

    ```
    https://<domain-yayasan>/webhook/midtrans
    ```

    Simpan (Update). Alamat ini dipanggil server Midtrans tiap ada perubahan status pembayaran; tanpanya pembayaran tidak otomatis lunas di EduCore.

- [ ] Kolom _Finish / Unfinish / Error Redirect URL_ boleh dikosongkan: EduCore sudah mengirim alamat kembali sendiri per pesanan (`/anak-saya/{siswa}/bayar-online/selesai`).
- [ ] Bila server memakai firewall yang membatasi IP masuk, pastikan IP pengirim notifikasi Midtrans **tidak diblokir** (daftar IP ada di dokumentasi Midtrans). [CEK MIDTRANS]

## 5. Pengaturan di EduCore

- [ ] Keuangan → Pengaturan → tab **Pembayaran online**: tampil tulisan **Akun produksi** (bukan "Sandbox (uji coba)").
- [ ] Isian metode **belum dicentang dulu** (dibuka di butir 6–7). Metode yang nanti dibuka hanya yang aktif di Midtrans produksi (butir 0).
- [ ] Isi **biaya layanan** per metode sesuai kontrak (VA nominal Rp; QRIS persen, maks. 2 desimal). Biaya ini ditanggung orang tua dan tampil sebelum bayar (OD-MG-03).
- [ ] Kas → **Rekening**: tiap lembaga yang menerima pembayaran online punya rekening bank bertanda **Penerima pembayaran online** (OD-MG-06). Tanpa itu, pembayaran online masuk ke rekening penerima transfer, lalu ke Kas tunai.
- [ ] **Scheduler** berjalan tiap menit (dipakai antara lain penyusunan jurnal pembukuan dan tugas terjadwal lain):

    ```bash
    # cron di host, atau container scheduler — sesuaikan dengan susunan Docker server
    * * * * * docker exec educore-app php artisan schedule:run >> /dev/null 2>&1
    ```

    Cek: `docker exec educore-app php artisan schedule:list` menampilkan tugas, dan Pembukuan → jurnal pembayaran baru muncul dalam ±1 menit.

## 6. Uji dengan uang sungguhan (nominal kecil)

- [ ] Buat **tagihan uji** bernominal kecil untuk satu siswa uji (mis. anak pegawai yang bersedia), lalu centang **satu VA** saja di Pengaturan → Pembayaran online. Selama uji, umumkan ke petugas bahwa tombol Bayar online sudah terlihat oleh semua orang tua.
- [ ] Masuk sebagai orang tua → Anak saya → Tagihan → **Bayar online** → pilih **satu VA** → bayar sungguhan dari m-banking.
    - [ ] Halaman Midtrans menampilkan nama siswa & total = tagihan + biaya layanan.
    - [ ] Setelah bayar, kembali ke EduCore lewat alamat **`https://`** (bukan `http://`).
    - [ ] Dalam ±1 menit status pesanan **Lunas**, kuitansi "Pembayaran online (otomatis)" terbit sebesar **tagihan** (bukan termasuk biaya layanan), dan buku kas lembaga mendapat pemasukan di rekening penerima online.
    - [ ] Keuangan siswa → Pembayaran online → rincian pesanan: jejak notifikasi Midtrans tercatat dengan **tanda tangan valid**.
- [ ] Centang QRIS (bila dipakai) lalu ulangi uji dengan QRIS.
- [ ] Uji **batal**: buat pesanan lalu Batalkan → status Dibatalkan, tidak ada kuitansi.
- [ ] Cocokkan dengan **dashboard Midtrans → Transactions**: order_id (`EDU-<KODE LEMBAGA>-…`) & jumlah sama.
- [ ] Bila sudah selesai, kuitansi uji dibatalkan lewat Kasir dan uangnya dikembalikan di luar aplikasi (refund otomatis di luar cakupan — PRD-006 §14). Atau biarkan bila tagihan uji memang tagihan sungguhan.

## 7. Buka untuk orang tua

- [ ] Centang semua metode yang dipakai → status **Aktif**.
- [ ] Umumkan ke orang tua: cara bayar, biaya layanan per metode, batas waktu (VA 24 jam, QRIS 15 menit), dan bahwa **cicilan tetap di kasir** (OD-MG-04).
- [ ] Ingatkan petugas kasir: perhatikan spanduk kuning "pesanan online menunggu" di Kasir agar tidak menagih tagihan yang sama (OD-MG-07).

## 8. Pemantauan minggu pertama

- [ ] Tiap hari cek Keuangan siswa → Pembayaran online: tidak ada pesanan yang di Midtrans sudah _settlement_ tetapi di EduCore masih **Menunggu**. Bila ada, buka rinciannya → **Cek status di Midtrans**.
- [ ] Beranda: kartu **Kelebihan bayar online** ditindaklanjuti bendahara (kembalikan uang, lalu **Tandai sudah dikembalikan**).
- [ ] Log server (`storage/logs`) tidak memuat error berulang dari webhook Midtrans.

## 9. Bila ada masalah (mundur)

- **Matikan sementara**: Pengaturan → Pembayaran online → hapus centang semua metode. Pesanan yang sudah dibuat tetap diproses lewat notifikasi.
- **Notifikasi tidak masuk**: periksa Payment Notification URL (butir 4), HTTPS/sertifikat, firewall, dan bahwa alamat `https://<domain>/webhook/midtrans` dapat dijangkau dari internet. Sementara itu, petugas bisa menekan **Cek status di Midtrans** di rincian pesanan.
- **Kembali ke sandbox** hanya untuk uji, dan **hanya bila tidak ada pesanan produksi Menunggu**: `MIDTRANS_IS_PRODUCTION=false` + kunci sandbox, lalu `optimize:clear` + `config:cache`.
- **Kunci bocor** (terlanjur dikirim/terpotret): segera matikan pembayaran online, minta Midtrans mengganti kunci, pasang kunci baru di `.env`. [CEK MIDTRANS]

## Catatan pelaksanaan

| Tanggal | Butir | Oleh | Catatan |
| ------- | ----- | ---- | ------- |
|         |       |      |         |
