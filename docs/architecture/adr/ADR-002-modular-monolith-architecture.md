# ADR-002 — Modular Monolith Architecture

**Status** : Accepted

**Date** : 2026-07-01

**Sprint** : CORE-001

---

# Context

EduCore dirancang sebagai platform yang akan terus berkembang dan mencakup berbagai domain, seperti:

- PPDB
- Academic
- Finance
- Human Resource
- Library
- Inventory
- Learning Management System (LMS)
- Modul lainnya

Sejak awal proyek terdapat beberapa alternatif arsitektur yang dapat dipilih, mulai dari Monolith tradisional hingga Microservices.

Pemilihan arsitektur harus mempertimbangkan kompleksitas pengembangan, kemudahan deployment, maintainability, dan kemungkinan evolusi platform di masa depan.

---

# Decision

EduCore menggunakan pendekatan **Modular Monolith**.

Seluruh modul berjalan dalam satu aplikasi Laravel, tetapi setiap modul memiliki batas tanggung jawab yang jelas, struktur direktori yang terpisah, serta dapat berkembang secara independen.

Setiap modul memiliki:

- Folder sendiri.
- `module.yaml` sebagai manifest.
- Service Provider.
- Domain masing-masing.
- Metadata sendiri.

Kernel bertanggung jawab menemukan dan memuat modul tersebut saat aplikasi dijalankan.

---

# Rationale

Pendekatan Modular Monolith dipilih karena memberikan keseimbangan antara kesederhanaan operasional dan modularitas.

Keuntungan utama:

- Satu proses deployment.
- Satu database (pada tahap awal).
- Tidak memerlukan komunikasi jaringan antar layanan.
- Modularitas tetap terjaga.
- Refactoring menjadi lebih mudah.
- Cocok untuk pengembangan bertahap melalui sprint.

Pendekatan ini memungkinkan platform berkembang tanpa membawa kompleksitas operasional Microservices terlalu dini.

---

# Consequences

## Positive

- Struktur kode lebih terorganisasi.
- Modul memiliki batas tanggung jawab yang jelas.
- Pengembangan fitur baru menjadi lebih mudah.
- Deployment tetap sederhana.
- Debugging lebih mudah dibandingkan sistem terdistribusi.

## Negative

- Semua modul berjalan dalam satu proses aplikasi.
- Kesalahan pada Kernel dapat memengaruhi seluruh platform.
- Dibutuhkan disiplin untuk menjaga batas antar modul.

---

# Alternatives Considered

## Option A — Traditional Monolith

Seluruh kode ditempatkan dalam struktur Laravel standar tanpa batas modul yang jelas.

**Ditolak** karena berisiko menghasilkan kode yang sulit dipelihara ketika jumlah domain bertambah.

---

## Option B — Microservices

Setiap domain dipisahkan menjadi layanan independen.

**Ditolak** karena:

- Kompleksitas deployment meningkat.
- Membutuhkan service discovery.
- Membutuhkan observability.
- Membutuhkan distributed tracing.
- Membutuhkan komunikasi antar layanan.
- Tidak sebanding dengan kebutuhan Sprint 1.

---

## Option C — Modular Monolith (**Dipilih**)

Pendekatan ini memberikan modularitas tinggi dengan kompleksitas operasional yang tetap rendah.

---

# Current Implementation

Status implementasi pada Sprint CORE-001:

- ✅ Struktur folder `Modules/`
- ✅ Modul `Core`
- ✅ Modul `Academic`
- ✅ Modul `PPDB`
- ✅ Manifest per modul
- ✅ Auto Discovery
- ✅ Module Registry
- ✅ Module Loader

---

# Future Evolution

Arsitektur Modular Monolith dirancang agar dapat berkembang tanpa mengubah struktur dasar platform.

Kemungkinan evolusi di masa depan meliputi:

- Modul dapat memiliki migration sendiri.
- Modul dapat memiliki route sendiri.
- Modul dapat memiliki konfigurasi sendiri.
- Modul dapat memiliki event sendiri.
- Modul dapat memiliki testing sendiri.
- Modul dapat dipublikasikan sebagai package internal apabila diperlukan.

Selama evolusi tersebut, Kernel tetap menjadi fondasi platform.

---

# References

- ADR-001 — Kernel Architecture Overview
- PRD CORE-001
- Sprint 001
- `docs/architecture/folder-structure.md`
