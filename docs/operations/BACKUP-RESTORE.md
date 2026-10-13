# Backup & Uji Restore EduCore

Acuan: PRD-000 §9 (`pg_dump` harian + uji restore bulanan di DB terpisah), §10 F4.

## 1. Jadwal

| Kegiatan               | Kapan                  | Script                                                            | Masa simpan                |
| ---------------------- | ---------------------- | ----------------------------------------------------------------- | -------------------------- |
| Backup database        | Setiap hari 01:30      | `scripts/backup/backup-db.sh` (Linux) / `backup-db.ps1` (Windows) | 14 hari di server [ASUMSI] |
| Uji restore            | Tanggal 1 setiap bulan | `scripts/backup/restore-test.sh` / `restore-test.ps1`             | –                          |
| Salinan di luar server | Setiap minggu          | Manual / rsync / penyimpanan cloud                                | Minimal 3 bulan [ASUMSI]   |

Backup yang hanya ada di server yang sama **bukan** perlindungan bila server rusak atau hilang. Salin minimal satu file per minggu ke tempat lain (komputer kantor, NAS, atau penyimpanan cloud terenkripsi).

## 2. Cara kerja script

**Backup** (`backup-db`):

1. Membaca `DB_*` dari `.env` (tanpa menjalankan isinya; password tidak ditampilkan).
2. `pg_dump --format=custom` ke `educore_YYYYMMDD_HHMMSS.dump.partial`.
3. Memeriksa file dengan `pg_restore --list`; bila rusak, script gagal dan file dihapus.
4. Mengganti nama menjadi `.dump`, lalu menghapus backup yang lebih tua dari `RETENTION_DAYS`.

**Uji restore** (`restore-test`):

1. Membuat database sementara `educore_restore_test` (database asli tidak disentuh).
2. Me-restore backup terbaru (atau file yang disebut).
3. Menghitung isi `tenants`, `organizations`, `users`, `audit_logs`; gagal bila tidak ada yayasan.
4. Menghapus database sementara, apa pun hasilnya.

### Linux

```bash
bash scripts/backup/backup-db.sh
bash scripts/backup/restore-test.sh
```

Variabel opsional: `BACKUP_DIR` (default `/var/backups/educore`), `RETENTION_DAYS` (default 14), `APP_DIR`.

### Windows / Laragon

```powershell
powershell -ExecutionPolicy Bypass -File scripts\backup\backup-db.ps1
powershell -ExecutionPolicy Bypass -File scripts\backup\restore-test.ps1
```

Bila `pg_dump` belum ada di PATH, tambahkan `-PgBin "C:\laragon\bin\postgresql\<versi>\bin"`. Default folder backup: `%USERPROFILE%\educore-backup`.

Menjadwalkan di Windows: Task Scheduler → Create Basic Task → Daily 01:30 → Program `powershell.exe`, argumen `-ExecutionPolicy Bypass -File C:\laragon\www\educore\scripts\backup\backup-db.ps1`.

## 3. Restore darurat (database asli rusak)

1. Hentikan akses: `php artisan down`.
2. Buat backup kondisi sekarang dulu (bila masih bisa): `bash scripts/backup/backup-db.sh`.
3. Pilih file backup yang akan dipakai, lalu restore ke database **baru**:
    ```bash
    createdb educore_pulih
    pg_restore --no-owner --no-privileges --exit-on-error --dbname=educore_pulih /var/backups/educore/educore_YYYYMMDD_HHMMSS.dump
    ```
4. Periksa isinya, lalu ubah `DB_DATABASE=educore_pulih` di `.env`, jalankan `php artisan optimize` dan `php artisan up`.
5. Database lama jangan dihapus sampai yakin semuanya benar.
6. Catat kejadian ini di bawah.

## 4. Keamanan audit log

Tabel `audit_logs` dijaga trigger database: UPDATE, DELETE, dan TRUNCATE ditolak. Pengecualian hanya untuk `php artisan educore:prune-audit` (berjalan otomatis harian), dan hanya untuk baris yang lebih tua dari masa simpan (`AUDIT_RETENTION_DAYS`, minimal 30 hari).

Perlindungan ini menahan bug aplikasi, **bukan** orang yang memegang password database pemilik tabel (ia bisa mematikan trigger). Karena itu:

- simpan password database hanya di `.env` server, jangan dibagikan;
- bila nanti dibutuhkan perlindungan penuh, jadikan role database terpisah sebagai pemilik tabel dan beri aplikasi hak INSERT/SELECT saja (keputusan lanjutan, belum diterapkan).

Backup ikut berisi audit log (termasuk IP), jadi perlakukan file backup sebagai data pribadi: simpan di folder dengan akses terbatas.

## 5. Catatan uji restore

Isi setiap kali uji restore bulanan selesai (salin baris terakhir dari log `restore-test`).

| Tanggal | Oleh | File backup | Hasil | Durasi | Isi (tenants / organizations / users / audit_logs) | Catatan |
| ------- | ---- | ----------- | ----- | ------ | -------------------------------------------------- | ------- |
|         |      |             |       |        |                                                    |         |
