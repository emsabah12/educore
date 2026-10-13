# EduCore

Sistem informasi yayasan pendidikan (lembaga formal, pesantren, dan nonformal) berbasis **Laravel 13 + Inertia + React + PostgreSQL**, dengan arsitektur modular monolith sederhana.

- Arsitektur: [`docs/adr/ADR-001-rebuild-simplified-modular-monolith.md`](docs/adr/ADR-001-rebuild-simplified-modular-monolith.md)
- Kebutuhan pondasi: [`docs/prd/PRD-000-platform-foundation.md`](docs/prd/PRD-000-platform-foundation.md)
- Status & keputusan terbaru: [`docs/STATUS.md`](docs/STATUS.md)
- Backup & uji restore: [`docs/operations/BACKUP-RESTORE.md`](docs/operations/BACKUP-RESTORE.md)

---

## 1. Prasyarat

| Kebutuhan  | Versi                                                | Catatan                          |
| ---------- | ---------------------------------------------------- | -------------------------------- |
| PHP        | 8.3+ dengan `pdo_pgsql`, `pgsql`, `mbstring`, `intl` | Laragon: Menu → PHP → Extensions |
| PostgreSQL | 16                                                   | Sama dengan CI dan production    |
| Composer   | 2.x                                                  |                                  |
| Node.js    | 22                                                   |                                  |
| Git        | terbaru                                              |                                  |

## 2. Instalasi untuk developer (Windows + Laragon)

Jalankan **satu perintah per baris** di terminal Laragon, dari `C:\laragon\www`.

```bash
git clone <url-repo> educore
cd educore
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Buat dua database (aplikasi dan test):

```bash
psql -U postgres -c "CREATE DATABASE educore;"
psql -U postgres -c "CREATE DATABASE educore_testing;"
```

Isi `.env` sesuai PostgreSQL Anda. Password yang berisi spasi atau karakter khusus wajib diberi tanda kutip:

```dotenv
APP_URL=http://educore.test
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=educore
DB_USERNAME=postgres
DB_PASSWORD="isi-password-anda"
AUDIT_RETENTION_DAYS=365
EDUCORE_TIMEZONE=Asia/Jakarta
```

Siapkan database dan aset:

```bash
php artisan migrate --seed
npm run build
```

`--seed` membuat katalog akses, pohon yayasan pertama (PRD-000 §4.3), isi awal Katalog SDM (PRD-001 §4.3), dan **akun uji** (hanya di `local`/`testing`, OD-10).

## 3. Menjalankan saat development

```bash
composer run dev
```

Buka `http://educore.test`. Semua akun uji memakai password **`password`**:

| Username          | Peran                                                    |
| ----------------- | -------------------------------------------------------- |
| `superadmin`      | Superadmin platform (tanpa data yayasan)                 |
| `admin.yayasan`   | Admin Yayasan — seluruh pohon, kelola lembaga, log audit |
| `pimpinan.ponpes` | Pimpinan Pondok Pesantren (lihat saja)                   |
| `kepala.unit1`    | Kepala Unit 1                                            |
| `kepala.smk`      | Kepala SMK Unit 3                                        |
| `guru`            | Staf di MA Unit 1 dan MDA Unit 1                         |
| `koordinator.mda` | Staf Biro Pendidikan + Koordinator semua MDA             |
| `penguji`         | Akun tanpa yayasan                                       |

## 4. Sebelum commit

```bash
composer lint
composer test
npm run types:check
npm run check:fix
```

`composer test` menjalankan Pint, Larastan level 7, dan seluruh test Pest di PostgreSQL.

## 5. Struktur proyek

```text
app/                    # shell Laravel (auth Fortify, middleware global)
modules/
├── Core/               # tenant, pohon lembaga, identitas, RBAC, aturan berjenjang, audit
├── HR/                 # kepegawaian
└── Academic/           # akademik
    ├── Domain/         # entitas & aturan bisnis
    ├── Application/    # use case / service
    ├── Contracts/      # interface publik untuk modul lain
    ├── Http/           # controller, routes.php
    └── Database/       # migrasi & seeder
resources/js/           # halaman & komponen React (Inertia)
scripts/backup/         # backup & uji restore (Linux .sh, Windows .ps1)
tests/                  # Pest: Architecture + Feature
docs/                   # PRD, ADR, STATUS, operasional (sumber kebenaran)
```

Aturan dependency: `Core → (tidak ada)`, `HR → Core`, `Academic → Core + kontrak publik HR`. Pelanggaran otomatis membuat test gagal.

## 6. Operasional

| Hal           | Cara                                                                                                                                                                                               | Acuan        |
| ------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| Health check  | `GET /up` → 200 bila aplikasi & database sehat; 500 dengan pesan umum bila tidak                                                                                                                   | PRD-000 §9   |
| Katalog akses | `php artisan educore:sync-access` setiap ada permission/role baru (juga saat deploy)                                                                                                               | OD-11        |
| Akun pertama  | `php artisan educore:create-admin <KODE-YAYASAN>` dan `php artisan educore:create-superadmin`                                                                                                      | OD-19        |
| Katalog SDM   | Isi awal: `php artisan db:seed --class="Modules\\HR\\Database\\Seeders\\HrCatalogSeeder" --force` (hanya menambah kode yang belum ada; aman diulang). Selanjutnya dikelola di menu **Katalog SDM** | PRD-001 §4.3 |
| Audit log     | Halaman **Log audit** (Admin Yayasan, lembaga kerja Seluruh Yayasan). Log lama dihapus otomatis setiap hari pukul 02:00 sesuai `AUDIT_RETENTION_DAYS` (min. 30)                                    | OD-15–17     |
| Backup        | `scripts/backup/backup-db.sh` (Linux) atau `backup-db.ps1` (Windows), harian                                                                                                                       | PRD-000 §9   |
| Uji restore   | `scripts/backup/restore-test.sh` / `.ps1`, bulanan, catat hasilnya                                                                                                                                 | PRD-000 §9   |

Penghapusan log lama dan tugas terjadwal lain hanya berjalan bila `php artisan schedule:run` dipanggil setiap menit (cron di Linux, Task Scheduler di Windows).

## 7. Deploy ke VPS Linux

Asumsi (OD-18): Ubuntu 24.04 LTS, Nginx, PHP-FPM 8.3, PostgreSQL 16, satu server. Ganti `educore.example.id` dengan domain Anda.

### 7.1 Paket server (sekali)

```bash
sudo apt update
sudo apt install -y nginx postgresql-16 postgresql-client-16 git unzip \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-intl php8.3-curl php8.3-zip php8.3-bcmath
```

Pasang Composer 2 dan Node.js 22 sesuai panduan resmi masing-masing.

### 7.2 Database

```bash
sudo -u postgres createuser --pwprompt educore
sudo -u postgres createdb --owner=educore educore
sudo -u postgres psql -c "ALTER ROLE educore CREATEDB;"
```

`CREATEDB` dibutuhkan script uji restore untuk membuat database uji sementara.

### 7.3 Kode aplikasi

```bash
sudo mkdir -p /var/www/educore && sudo chown $USER:www-data /var/www/educore
git clone <url-repo> /var/www/educore
cd /var/www/educore
cp .env.example .env
```

Isi `.env` production — **jangan pernah commit file ini**:

```dotenv
APP_NAME=EduCore
APP_ENV=production
APP_DEBUG=false
APP_URL=https://educore.example.id
APP_LOCALE=id

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=educore
DB_USERNAME=educore
DB_PASSWORD="password-kuat"

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
AUDIT_RETENTION_DAYS=365
EDUCORE_TIMEZONE=Asia/Jakarta
```

Lalu:

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
npm ci
npm run build
php artisan migrate --force
php artisan educore:sync-access
php artisan db:seed --class="Modules\\Core\\Database\\Seeders\\FirstTenantSeeder" --force
php artisan db:seed --class="Modules\\HR\\Database\\Seeders\\HrCatalogSeeder" --force
php artisan optimize
sudo chown -R $USER:www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```

Seeder akun uji **tidak** berjalan di production (OD-10). Buat akun pertama dengan perintah interaktif (kata sandi ditanyakan tersembunyi, tidak pernah ditulis di perintah):

```bash
php artisan educore:create-admin YAYASAN
php artisan educore:create-superadmin
```

`create-admin` membuat akun + keanggotaan + role Admin Yayasan sekaligus. Bila emailnya sudah terdaftar, akun itu dijadikan Admin Yayasan setelah konfirmasi. Semua tercatat di audit log (OD-19).

### 7.4 Nginx

`/etc/nginx/sites-available/educore`:

```nginx
server {
    listen 80;
    server_name educore.example.id;
    root /var/www/educore/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/educore /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d educore.example.id
```

### 7.5 Tugas terjadwal, queue, dan backup

`crontab -e` sebagai user aplikasi:

```cron
* * * * * cd /var/www/educore && php artisan schedule:run >> /dev/null 2>&1
30 1 * * * bash /var/www/educore/scripts/backup/backup-db.sh >> /var/log/educore/backup.log 2>&1
0 3 1 * * bash /var/www/educore/scripts/backup/restore-test.sh >> /var/log/educore/restore-test.log 2>&1
```

Buat folder log & backup: `sudo mkdir -p /var/log/educore /var/backups/educore && sudo chown $USER /var/log/educore /var/backups/educore`.

Queue worker dengan systemd, `/etc/systemd/system/educore-queue.service`:

```ini
[Unit]
Description=EduCore queue worker
After=network.target postgresql.service

[Service]
User=www-data
WorkingDirectory=/var/www/educore
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now educore-queue
```

### 7.6 Update versi (setiap deploy)

```bash
cd /var/www/educore
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan educore:sync-access
php artisan db:seed --class="Modules\\HR\\Database\\Seeders\\HrCatalogSeeder" --force
php artisan optimize
sudo systemctl restart educore-queue
php artisan up
curl -fsS https://educore.example.id/up
```

Selalu pastikan ada backup terbaru sebelum `migrate --force`.
