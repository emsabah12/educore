# EduCore

Sistem informasi yayasan pendidikan (lembaga formal, pesantren, dan nonformal) berbasis **Laravel 13 + Inertia + React + PostgreSQL**, dengan arsitektur modular monolith sederhana.

- Arsitektur: [`docs/adr/ADR-001-rebuild-simplified-modular-monolith.md`](docs/adr/ADR-001-rebuild-simplified-modular-monolith.md)
- Kebutuhan pondasi: [`docs/prd/PRD-000-platform-foundation.md`](docs/prd/PRD-000-platform-foundation.md)

---

## 1. Prasyarat (Windows + Laragon)

| Kebutuhan | Versi | Cara cek |
|---|---|---|
| PHP | ≥ 8.3 dengan ekstensi `pdo_pgsql` dan `pgsql` aktif | `php -v` dan `php -m \| findstr pgsql` |
| PostgreSQL | Sama dengan versi produksi (CI memakai 16) | `psql --version` |
| Composer | 2.x | `composer -V` |
| Node.js | 22 | `node -v` |
| Git | terbaru | `git --version` |

Mengaktifkan ekstensi PostgreSQL di Laragon: **Menu Laragon → PHP → Extensions → centang `pdo_pgsql` dan `pgsql`**, lalu restart Laragon. Tanpa ini Laravel akan gagal dengan error *"could not find driver"*.

## 2. Instalasi pertama kali (dari nol)

Semua perintah dijalankan di **Terminal Laragon** (Menu → Terminal).

### 2.1 Amankan proyek lama

Folder dan nama database lama dipakai ulang, jadi yang lama diganti nama dulu supaya tidak tertimpa:

```bash
cd C:\laragon\www
ren educore educore-legacy

psql -U postgres -c "ALTER DATABASE educore RENAME TO educore_legacy;"
psql -U postgres -c "ALTER DATABASE educore_testing RENAME TO educore_legacy_testing;"
```

Jika muncul error *"database is being accessed by other users"*, tutup aplikasi lama/DBeaver/pgAdmin yang masih terhubung, lalu ulangi.

### 2.2 Buat database baru

```bash
psql -U postgres -c "CREATE DATABASE educore;"
psql -U postgres -c "CREATE DATABASE educore_testing;"
```

### 2.3 Buat proyek dengan installer resmi Laravel

```bash
composer global require laravel/installer
cd C:\laragon\www
laravel new educore --react --pest --database=pgsql --npm --git
```

Jika `laravel` tidak dikenali, tambahkan `%APPDATA%\Composer\vendor\bin` ke PATH lalu buka ulang terminal.

Jika di akhir instalasi muncul error migrasi (biasanya karena username/password database belum benar), abaikan dulu — kredensial dibetulkan di langkah 2.5 dan migrasi dijalankan ulang di langkah 2.6.

Saat installer bertanya **"Which authentication features would you like to enable?"**, pilih sesuai PRD-000 OD-04:

| Fitur | Pilihan | Alasan |
|---|---|---|
| Registration | **tidak** | Akun dibuat admin, bukan daftar sendiri (PRD-000 §2) |
| Email verification | **tidak** | Akun dibuat admin dengan email yang sudah dikenal |
| Two-factor authentication | **ya** | Lapisan keamanan untuk akun admin |
| Password confirmation | **ya** | Konfirmasi ulang sebelum aksi sensitif |
| Passkeys | **tidak** | Ditunda agar pondasi tetap sederhana |

### 2.4 Salin file pondasi EduCore

Salin seluruh isi paket `educore-f0` ke `C:\laragon\www\educore` dan **timpa** file yang sama namanya:

```text
.github/workflows/tests.yml
bootstrap/providers.php
docs/
modules/
phpstan.neon
phpunit.xml
README.md
tests/Architecture/ModuleBoundaryTest.php
tests/Feature/Core/FoundationSetupTest.php
```

Lalu ekstrak `docs.zip` (dokumentasi lama) ke folder `docs-legacy/`.

### 2.5 Ubah 4 file secara manual

**a) `composer.json`** — daftarkan namespace modul dan hapus pembuatan file SQLite:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Modules\\": "modules/",
        "Database\\Factories\\": "database/factories/",
        "Database\\Seeders\\": "database/seeders/"
    }
},
```

Di bagian `"post-create-project-cmd"`, hapus baris ini:

```json
"@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\"",
```

**b) `config/database.php`** baris `'default'`:

```php
'default' => env('DB_CONNECTION', 'pgsql'),
```

**c) `app/Providers/AppServiceProvider.php`** — tambahkan import di atas dan satu baris di `configureDefaults()`:

```php
use Illuminate\Database\Eloquent\Model;
```

```php
protected function configureDefaults(): void
{
    Date::use(CarbonImmutable::class);

    // Tangkap query N+1 sejak dini: error di local/testing, diam di production (PRD-000 §9).
    Model::preventLazyLoading(! app()->isProduction());

    // ... baris DB::prohibitDestructiveCommands dan Password::defaults tetap seperti semula
}
```

**d) `.env` dan `.env.example`**:

```dotenv
APP_NAME=EduCore
APP_URL=http://educore.test
APP_LOCALE=id
APP_FAKER_LOCALE=id_ID

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=educore
DB_USERNAME=postgres
DB_PASSWORD=
```

Isi `DB_USERNAME`/`DB_PASSWORD` di `.env` sesuai PostgreSQL Anda. Di `.env.example` biarkan password kosong — **jangan pernah commit password asli**.

### 2.6 Jalankan & verifikasi

```bash
composer dump-autoload
php artisan migrate
php artisan test
composer test
```

Hasil yang diharapkan:

- `php artisan test` → semua hijau, termasuk 5 test di `Architecture` dan 5 test di `Feature/Core/FoundationSetupTest`.
- `composer test` → Pint (format), Larastan (analisis statis) dan seluruh test lulus.

## 3. Menjalankan saat development

```bash
composer run dev
```

Buka `http://educore.test` (virtual host otomatis Laragon) atau `http://localhost:8000`.

Queue worker di Windows: gunakan `php artisan queue:work` (Horizon tidak berjalan di Windows karena butuh ekstensi `pcntl`).

## 4. Struktur proyek

```text
app/                    # shell Laravel (auth Fortify, middleware global)
modules/
├── Core/               # tenant, pohon lembaga, identitas, RBAC, aturan berjenjang, audit
├── HR/                 # kepegawaian
└── Academic/           # akademik
    ├── Domain/         # entitas & aturan bisnis
    ├── Application/    # use case / service
    ├── Contracts/      # interface publik untuk modul lain
    ├── Http/           # controller, request, routes.php
    └── Database/Migrations/
resources/js/           # halaman & komponen React (Inertia)
tests/Architecture/     # penjaga batas modul
docs/                   # ADR & PRD (sumber kebenaran)
docs-legacy/            # dokumentasi repo lama (referensi saja)
```

Aturan dependency: `Core → (tidak ada)`, `HR → Core`, `Academic → Core + kontrak publik HR`. Pelanggaran otomatis membuat test gagal.

## 5. Deploy

Belum didefinisikan — dijadwalkan di milestone F4 (PRD-000 §10).
