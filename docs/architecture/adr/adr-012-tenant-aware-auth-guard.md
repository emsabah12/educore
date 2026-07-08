# ADR-012: Tenant-Aware Authentication Guard

- **Status**: Accepted
- **Context Date**: 2026-07-08
- **Author**: Senior Fullstack Engineer & Technical Mentor

## 1. Konteks & Masalah

Dengan strategi _Shared Schema_, tabel `users` menampung seluruh kredensial dari semua tenant. Fitur autentikasi bawaan (_default_) Laravel Guard hanya memvalidasi keunikan `email` secara global di dalam aplikasi. Hal ini memicu celah keamanan kritis:

1. Pengguna dengan email yang sama tidak bisa mendaftar di sekolah berbeda.
2. Pengguna dari Sekolah A bisa masuk secara ilegal ke dashboard Sekolah B jika rute login diakses dari domain Sekolah B (_Cross-Tenant Authentication Attack_).

## 2. Keputusan yang Diambil

Kita memperluas sub-sistem keamanan Laravel Authentication dengan membuat **Custom User Provider** bernama `TenantAwareUserProvider` yang didaftarkan melalui driver kustom `tenant-eloquent` di dalam `CoreServiceProvider`.

Driver ini secara paksa mengubah perilaku pencarian pengguna (_user retrieval_):

```php
$query->where('email', $credentials['email'])->where('tenant_id', $currentTenantId);
```
