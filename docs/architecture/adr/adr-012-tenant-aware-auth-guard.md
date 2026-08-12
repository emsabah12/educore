# ADR-012: Tenant-Aware Authentication Guard

- **Status**: Superseded
- **Context Date**: 2026-07-08
- **Author**: Senior Fullstack Engineer & Technical Mentor

> **Superseded Notice**
>
> ADR ini mengasumsikan `users.tenant_id` dan tenant-aware user lookup sebagai security boundary. Model tersebut telah digantikan oleh canonical global User identity dan Person-owned Membership.
>
> Current authentication/tenant flow menggunakan verified `user_id`, `tenant_id`, dan `membership_id` token context lalu memvalidasi `User → Person → Membership → Tenant`. Role/permission tidak dipercaya dari token.
>
> Current baseline: [`../current-architecture.md`](../current-architecture.md). Canonical replacement: **ADR-015 — Authentication Token & Request Context**.

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
