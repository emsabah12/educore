# ADR-002 — Modular Monolith Architecture

Version : 1.0
Status : Accepted
Date : 2026-07-01
Updated : 2026-07-02
Sprint : CORE-001 Sprint-1

## Related ADR

- ADR-001 — Kernel Architecture Overview
- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Source of Truth

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

Pemilihan arsitektur harus mempertimbangkan kompleksitas pengembangan, kemudahan deployment, maintainability, skalabilitas, dan kemungkinan evolusi platform di masa depan.

---

# Decision

EduCore menggunakan pendekatan **Modular Monolith**.

Seluruh modul berjalan di dalam satu aplikasi Laravel, tetapi setiap modul memiliki batas tanggung jawab yang jelas, struktur direktori yang terpisah, serta dapat dikembangkan secara independen.

Setiap modul memiliki:

- Direktori modul sendiri.
- `module.yaml` sebagai manifest.
- Service Provider.
- Domain masing-masing.
- Metadata modul yang immutable.

Platform Kernel bertanggung jawab melakukan discovery, memuat metadata modul, dan menyediakan layanan dasar yang digunakan oleh seluruh modul.

---

# Rationale

Pendekatan Modular Monolith dipilih karena memberikan keseimbangan antara kesederhanaan operasional dan modularitas.

Keuntungan utama:

- Satu proses deployment.
- Satu aplikasi Laravel.
- Satu database pada tahap awal.
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
- Evolusi platform dapat dilakukan secara bertahap.

## Negative

- Seluruh modul berjalan dalam satu proses aplikasi.
- Kegagalan pada Platform Kernel dapat memengaruhi seluruh platform.
- Dibutuhkan disiplin untuk menjaga batas antar modul.
- Ketergantungan antar modul harus dikelola dengan baik.

---

# Alternatives Considered

## Option A — Traditional Monolith

Seluruh kode ditempatkan dalam struktur Laravel standar tanpa batas modul yang jelas.

**Rejected**, karena berisiko menghasilkan kode yang sulit dipelihara ketika jumlah domain terus bertambah.

---

## Option B — Microservices

Setiap domain dipisahkan menjadi layanan independen.

**Rejected**, karena:

- Kompleksitas deployment meningkat.
- Membutuhkan service discovery.
- Membutuhkan observability.
- Membutuhkan distributed tracing.
- Membutuhkan komunikasi antar layanan.
- Tidak sebanding dengan kebutuhan awal platform.

---

## Option C — Modular Monolith (**Accepted**)

Platform dibangun sebagai satu aplikasi dengan modul-modul yang memiliki batas tanggung jawab yang jelas.

Pendekatan ini memberikan modularitas tinggi dengan kompleksitas operasional yang tetap rendah.

---

# Current Implementation

Status implementasi pada akhir Sprint CORE-001:

- ✅ Struktur direktori modular (`Modules/`).
- ✅ Manifest modul (`module.yaml`).
- ✅ Platform Kernel.
- ✅ Automatic Module Discovery.
- ✅ Module Registry.
- ✅ Runtime Module State Repository.
- ✅ Module Manager.
- ✅ Bootstrap otomatis melalui `CoreServiceProvider`.

---

# Future Evolution

Arsitektur Modular Monolith dirancang agar dapat berkembang tanpa mengubah struktur dasar platform.

Kemungkinan evolusi di masa depan meliputi:

- Modul memiliki migration sendiri.
- Modul memiliki route sendiri.
- Modul memiliki konfigurasi sendiri.
- Modul memiliki event sendiri.
- Modul memiliki testing sendiri.
- Modul dapat dipublikasikan sebagai package internal apabila diperlukan.

Apabila kebutuhan operasional berubah secara signifikan, pendekatan ini tetap memungkinkan evolusi menuju arsitektur yang lebih terdistribusi secara bertahap.

---

# References

- PRD CORE-001
- Sprint CORE-001
- `docs/architecture/folder-structure.md`
- `docs/architecture/kernel.md`
- `docs/architecture/architecture-principles.md`
- ADR-001 — Kernel Architecture Overview
