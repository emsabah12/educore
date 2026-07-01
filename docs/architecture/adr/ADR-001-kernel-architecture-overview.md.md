# ADR-001 — Kernel Architecture Overview

**Status** : Accepted

**Date** : 2026-07-01

**Sprint** : CORE-001

---

# Context

EduCore dirancang sebagai sebuah platform yang akan menjadi fondasi bagi berbagai aplikasi pendidikan, seperti PPDB, Academic, Finance, HR, Library, Inventory, Learning Management System (LMS), dan modul lainnya.

Pada tahap awal pengembangan terdapat dua pendekatan yang dipertimbangkan:

1. Membangun setiap aplikasi secara mandiri.
2. Membangun sebuah Platform Kernel yang menyediakan layanan inti dan dapat digunakan oleh seluruh modul.

Pendekatan pertama lebih sederhana pada awal proyek, tetapi akan menghasilkan duplikasi implementasi, inkonsistensi antar aplikasi, serta biaya pemeliharaan yang tinggi.

---

# Decision

EduCore menggunakan **Platform Kernel Architecture** sebagai fondasi sistem.

Kernel bertanggung jawab menyediakan layanan inti yang digunakan bersama oleh seluruh modul.

Kernel tidak berisi business logic dari setiap domain, tetapi hanya menyediakan kemampuan dasar platform.

Contoh layanan Kernel meliputi:

- Module Discovery
- Module Loading
- Module Registry
- Module State Management
- Health Check
- Dependency Resolution (future)
- Event Bus (future)
- Scheduler (future)
- Configuration Engine (future)

Seluruh modul dibangun di atas layanan yang disediakan oleh Kernel.

---

# Rationale

Keputusan ini dipilih karena:

- Menghindari duplikasi implementasi antar modul.
- Menyediakan fondasi yang konsisten.
- Mempermudah pengembangan modul baru.
- Mendukung evolusi platform tanpa mengubah modul yang sudah ada.
- Memungkinkan pengembangan bertahap melalui sprint.

Kernel menjadi pusat layanan teknis (_technical capabilities_), sedangkan setiap modul berfokus pada business domain masing-masing.

---

# Consequences

## Positive

- Arsitektur lebih konsisten.
- Modul menjadi lebih independen.
- Layanan platform dapat digunakan ulang.
- Pengembangan modul baru menjadi lebih cepat.
- Risiko duplikasi kode berkurang.

## Negative

- Membutuhkan desain awal yang lebih matang.
- Kompleksitas Kernel meningkat seiring bertambahnya kemampuan platform.
- Membutuhkan dokumentasi arsitektur yang baik.

---

# Alternatives Considered

## Option A — Independent Applications

Setiap aplikasi memiliki implementasi sendiri terhadap discovery, konfigurasi, dependency, dan layanan platform lainnya.

**Ditolak** karena menghasilkan banyak duplikasi.

---

## Option B — Shared Library

Layanan bersama disediakan sebagai library yang digunakan oleh setiap aplikasi.

**Ditolak** karena sinkronisasi versi menjadi lebih sulit dan integrasi antar modul menjadi terbatas.

---

## Option C — Platform Kernel (**Dipilih**)

Layanan inti berada dalam satu Kernel yang digunakan oleh seluruh modul.

Pendekatan ini memberikan keseimbangan antara modularitas, maintainability, dan kemudahan evolusi platform.

---

# Current Implementation

Status implementasi pada Sprint CORE-001:

- ✅ Module Discovery
- ✅ Manifest Parser
- ✅ Manifest Validator
- ✅ Module Definition
- ✅ Module Registry
- ✅ Module Loader
- ✅ Module State Repository
- ✅ Module Manager
- 🚧 UUID v7
- 🚧 Health Check
- ⏳ Unit Test

---

# Future Evolution

Kernel akan terus berkembang pada sprint berikutnya dengan menambahkan kemampuan seperti:

- Dependency Resolver
- Module Installer
- Module Publisher
- Event Bus
- Feature Flags
- Scheduler
- Queue
- Configuration Engine
- Plugin Marketplace

Seluruh kemampuan tersebut akan menjadi bagian dari Kernel tanpa mengubah kontrak publik yang digunakan oleh modul.

---

# References

- PRD CORE-001
- Sprint 001
- `docs/architecture/kernel.md`
- ADR-002 — Modular Monolith Architecture
