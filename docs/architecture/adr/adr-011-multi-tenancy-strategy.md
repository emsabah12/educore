# ADR-011: Multi-Tenancy Architecture Strategy

- **Status**: Accepted
- **Context Date**: 2026-07-08
- **Author**: Senior Software Architect & DevOps Engineer

## 1. Konteks & Masalah

EduCore dirancang untuk melayani ratusan institusi sekolah secara serentak dalam satu instalasi kode. Kita memerlukan strategi isolasi data yang efisien dari segi biaya operasional (cost-effective) namun tetap memberikan jaminan keamanan data yang tinggi, agar data antar-sekolah tidak saling bercampur atau bocor (_data leakage_).

## 2. Alternatif yang Dipertimbangkan

- **Opsi A: Multi-Database per Tenant**. Setiap sekolah memiliki database terpisah secara fisik.
    - _Kelebihan_: Isolasi tingkat tinggi di level infrastruktur.
    - _Kekurangan_: Biaya manajemen database mahal di PostgreSQL, overhead migrasi tinggi saat upgrade versi cluster.
- **Opsi B: Single Database mit Shared Schema (Terpilih)**. Semua data sekolah berada dalam satu database yang sama dan dipisahkan secara logis menggunakan kolom `tenant_id` berbasis UUID v7.
    - _Kelebihan_: Sangat hemat biaya infrastruktur, manajemen migrasi database terpusat dan instan.
    - _Kekurangan_: Risiko kesalahan developer (_human error_) yang lupa menulis klausul filter query sehingga memicu kebocoran data.

## 3. Keputusan yang Diambil

Kita memilih **Opsi B: Single Database dengan Shared Schema** dikombinasikan dengan otomatisasi penuh di level ORM (_Defense in Depth_).

Implementasi teknis mencakup:

1. Menggunakan **UUID v7** sebagai Primary Key pada tabel `tenants` dan Foreign Key `tenant_id` pada entitas terkait.
2. Identifikasi request penyewa menggunakan **Subdomain Extractor Middleware** di awal request lifecycle.
3. Mengikat data penyewa aktif ke dalam **In-Memory State Store (Singleton)** bernama `TenantContext`.
4. Otomatisasi keamanan query via **Eloquent Global Scope (`TenantScope`)** dan reusable trait `BelongsToTenant` untuk memastikan tidak ada developer yang perlu menulis klausul `WHERE tenant_id` secara manual.

## 4. Konsekuensi

- **Positif**: Skalabilitas horizontal tinggi, biaya operasional murah, developer produktif karena isolasi data terjadi secara otomatis di balik layar.
- **Negatif**: Semua model bisnis yang bersifat spesifik per sekolah wajib menyertakan trait `BelongsToTenant`. Tabel global yang tidak menggunakan tenant (seperti master konfigurasi pusat) harus dipilah secara ketat.
