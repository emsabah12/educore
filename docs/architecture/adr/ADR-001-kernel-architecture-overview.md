# ADR-001 — Kernel Architecture Overview

Version : 1.0
Status : Accepted
Date : 2026-07-01
Updated : 2026-08-13
Sprint : CORE-001 Sprint-1


> ## Revalidation — 2026-08-13
> **Decision:** KEEP, amended by ADR-017. Platform Module Kernel remains valid, but the current runtime contract is now stricter: Core is the mandatory bootstrap root; non-Core providers are activated only from validated manifests and registered in dependency order; invalid dependency/provider configuration fails fast; persisted runtime module enable/disable state has been removed; and global reflection-based event auto-discovery has been removed. The historical `Current Implementation` / `Future Evolution` sections below remain decision history where they conflict with ADR-017 or current architecture documentation.

## Related ADR

- ADR-002 — Modular Monolith Architecture
- ADR-005 — Module Registry as Source of Truth
- ADR-006 — Runtime Module State Repository (**Superseded by ADR-017**)
- ADR-007 — Module Manager as Kernel Facade (**Superseded by ADR-017**)
- ADR-009 — Separation of Infrastructure and Kernel Domain
- ADR-017 — Module Runtime & Bootstrap Contract

---

# Context

EduCore dirancang sebagai sebuah platform yang menjadi fondasi bagi berbagai aplikasi pendidikan, seperti PPDB, Academic, Finance, HR, Library, Inventory, Learning Management System (LMS), dan modul lainnya.

Pada tahap awal pengembangan terdapat dua pendekatan yang dipertimbangkan:

1. Membangun setiap aplikasi sebagai aplikasi mandiri.
2. Membangun sebuah Platform Kernel yang menyediakan layanan inti bagi seluruh modul.

Pendekatan pertama lebih sederhana pada awal proyek, tetapi menghasilkan duplikasi implementasi, inkonsistensi antar aplikasi, serta biaya pemeliharaan yang tinggi.

---

# Decision

EduCore menggunakan **Platform Kernel Architecture** sebagai fondasi sistem.

Platform Kernel menyediakan layanan teknis yang dapat digunakan bersama oleh seluruh modul.

Kernel **tidak** berisi business logic domain, melainkan hanya menyediakan kemampuan fundamental platform.

Contoh layanan Kernel meliputi:

- Module Discovery
- Manifest Processing Pipeline
- Module Registry
- Runtime Module State Management
- Module Manager
- Health Check
- Dependency Resolution (future)
- Event Bus (future)
- Scheduler (future)
- Configuration Engine (future)

Seluruh modul dibangun di atas layanan yang disediakan oleh Platform Kernel.

---

# Rationale

Keputusan ini dipilih karena:

- Menghindari duplikasi implementasi antar modul.
- Menyediakan fondasi yang konsisten.
- Mempermudah pengembangan modul baru.
- Mendukung evolusi platform tanpa mengubah modul yang telah ada.
- Memungkinkan pengembangan bertahap melalui sprint.

Kernel menjadi pusat layanan teknis (_technical capabilities_), sedangkan setiap modul berfokus pada business domain masing-masing.

---

# Consequences

## Positive

- Arsitektur lebih konsisten.
- Modul dapat dikembangkan secara independen.
- Layanan platform dapat digunakan kembali.
- Pengembangan modul baru menjadi lebih cepat.
- Risiko duplikasi kode berkurang.
- Evolusi platform lebih terkontrol.

## Negative

- Membutuhkan desain awal yang matang.
- Kompleksitas Kernel meningkat seiring bertambahnya kemampuan platform.
- Membutuhkan dokumentasi arsitektur yang konsisten.

---

# Alternatives Considered

## Option A — Independent Applications

Setiap aplikasi memiliki implementasi sendiri terhadap discovery, konfigurasi, dependency, dan layanan platform lainnya.

**Rejected**, karena menghasilkan banyak duplikasi.

---

## Option B — Shared Library

Layanan bersama disediakan sebagai library yang digunakan oleh setiap aplikasi.

**Rejected**, karena sinkronisasi versi menjadi lebih sulit dan integrasi antar aplikasi menjadi terbatas.

---

## Option C — Platform Kernel (**Accepted**)

Layanan inti berada dalam satu Platform Kernel yang digunakan bersama oleh seluruh modul.

Pendekatan ini memberikan keseimbangan antara modularitas, maintainability, skalabilitas, dan kemudahan evolusi platform.

---

# Current Implementation

Status implementasi pada akhir Sprint CORE-001:

- ✅ Module Discovery
- ✅ Module Manifest Loader
- ✅ Module Manifest Parser
- ✅ Module Manifest Validator
- ✅ Module Definition Factory
- ✅ Module Definition
- ✅ Module Registry
- ✅ Module Loader
- ✅ Runtime Module State Repository
- ✅ Module Manager
- ✅ UUID v7 Strategy
- ⏳ Health Check System
- ⏳ Unit Testing Suite

---

# Future Evolution

Platform Kernel akan terus berkembang pada sprint berikutnya dengan menambahkan kemampuan seperti:

- Dependency Resolver
- Module Installer
- Module Publisher
- Event Bus
- Feature Flags
- Scheduler
- Queue
- Configuration Engine
- Plugin Marketplace

Kemampuan tersebut akan ditambahkan tanpa mengubah kontrak publik yang digunakan oleh modul.

---

# References

- PRD CORE-001
- Sprint CORE-001
- `docs/architecture/kernel.md`
- `docs/architecture/architecture-principles.md`
- ADR-002 — Modular Monolith Architecture
