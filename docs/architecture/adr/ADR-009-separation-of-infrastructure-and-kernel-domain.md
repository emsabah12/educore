# ADR-009 — Separation of Infrastructure and Kernel Domain

**Status** : Accepted

**Date** : 2026-07-01

**Sprint** : CORE-001

---

# Context

Seiring berkembangnya EduCore Platform, Kernel akan menangani semakin banyak kemampuan seperti discovery modul, lifecycle management, dependency resolution, event bus, scheduler, dan layanan platform lainnya.

Tanpa pemisahan tanggung jawab yang jelas, detail infrastruktur (filesystem, parser, penyimpanan, framework) berisiko bercampur dengan business rule Kernel. Hal tersebut akan meningkatkan coupling, menyulitkan pengujian, dan menghambat evolusi platform.

Kernel memerlukan batas yang tegas antara logika domain dan detail implementasi teknis.

---

# Decision

EduCore memisahkan arsitektur menjadi tiga lapisan utama:

```text
Presentation Layer
        │
        ▼
Application / Kernel Layer
        │
        ▼
Infrastructure Layer
```

Setiap lapisan hanya memiliki tanggung jawab yang sesuai dengan perannya.

Business rule berada di **Kernel Layer**.

Detail teknis berada di **Infrastructure Layer**.

Antarmuka pengguna berada di **Presentation Layer**.

---

# Layer Responsibilities

## Presentation Layer

Bertanggung jawab menerima input dan menampilkan output.

Contoh:

- Artisan Command
- HTTP Controller (future)
- REST API (future)
- GraphQL Resolver (future)

Presentation Layer tidak mengandung business rule.

---

## Kernel Layer

Merupakan pusat business rule platform.

Contoh:

- ModuleManager
- ModuleRegistry
- ModuleDefinition
- Health Checker (future)
- Dependency Resolver (future)

Kernel Layer mengorkestrasi proses tetapi tidak bergantung langsung pada detail penyimpanan atau framework.

---

## Infrastructure Layer

Bertanggung jawab terhadap detail implementasi teknis.

Contoh:

- ModuleManifestParser
- ModuleDiscovery
- ModuleStateRepository
- Filesystem
- YAML Parser
- JSON Storage

Infrastructure dapat berubah tanpa mengubah business rule Kernel.

---

# Rationale

Pemisahan ini dipilih karena:

- Mengurangi coupling.
- Mempermudah pengujian.
- Mempermudah refactoring.
- Memungkinkan pergantian teknologi.
- Menjaga business rule tetap stabil.

Kernel hanya mengetahui _apa_ yang harus dilakukan.

Infrastructure menentukan _bagaimana_ hal tersebut dilakukan.

---

# Consequences

## Positive

- Batas tanggung jawab menjadi jelas.
- Evolusi platform lebih mudah.
- Pergantian teknologi tidak memengaruhi business rule.
- Komponen lebih mudah diuji secara terpisah.
- Kode lebih mudah dipelihara.

## Negative

- Menambah jumlah komponen dan lapisan.
- Membutuhkan disiplin dalam menjaga dependency antar lapisan.

---

# Alternatives Considered

## Option A — Mixed Architecture

Business rule dan detail teknis berada pada class yang sama.

**Ditolak** karena meningkatkan coupling dan menyulitkan maintainability.

---

## Option B — Layered Separation (**Dipilih**)

Business rule dipisahkan dari detail implementasi.

Setiap lapisan hanya mengetahui tanggung jawabnya sendiri.

Pendekatan ini memberikan fleksibilitas jangka panjang tanpa meningkatkan kompleksitas secara berlebihan.

---

# Current Implementation

Status implementasi pada Sprint CORE-001:

## Presentation

- ✅ `kernel:test-loader`
- ✅ `module:list`
- ✅ `module:status`
- ✅ `module:enable`
- ✅ `module:disable`

## Kernel

- ✅ ModuleManager
- ✅ ModuleRegistry
- ✅ ModuleDefinition

## Infrastructure

- ✅ ModuleDiscovery
- ✅ ModuleManifestParser
- ✅ ManifestValidator
- ✅ ModuleDefinitionFactory
- ✅ ModuleStateRepository

---

# Future Evolution

Prinsip pemisahan lapisan ini akan tetap digunakan pada sprint berikutnya.

Kemampuan baru seperti:

- Event Bus
- Scheduler
- Queue
- Feature Flags
- Configuration Engine
- Plugin Marketplace

harus ditempatkan pada lapisan yang sesuai tanpa melanggar batas tanggung jawab yang telah ditetapkan.

---

# References

- ADR-001 — Kernel Architecture Overview
- ADR-002 — Modular Monolith Architecture
- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Metadata Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-007 — ModuleManager as Kernel Facade
- ADR-008 — Thin Command Pattern
- PRD CORE-001
- Sprint 001
