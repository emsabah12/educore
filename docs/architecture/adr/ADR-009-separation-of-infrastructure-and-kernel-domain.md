# ADR-009 — Separation of Infrastructure and Kernel Domain

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-07-01
**Sprint** : CORE-001 Sprint-1

---

> **Decision Summary**
>
> EduCore memisahkan Platform Kernel ke dalam tiga lapisan arsitektur—Presentation Layer, Application / Kernel Layer, dan Infrastructure Layer. Business rule ditempatkan pada Platform Kernel, sedangkan seluruh detail implementasi teknis ditempatkan pada Infrastructure Layer. Dependency selalu mengalir dari lapisan luar menuju Platform Kernel melalui kontrak yang jelas.

---

# Related ADR

- ADR-001 — Kernel Architecture Overview
- ADR-002 — Modular Monolith Architecture
- ADR-007 — ModuleManager as Kernel Facade
- ADR-008 — Thin Command Pattern

---

# 1. Context

Seiring berkembangnya EduCore Platform, Platform Kernel akan menangani semakin banyak kemampuan seperti Module Discovery, Lifecycle Management, Dependency Resolution, Event Bus, Scheduler, Configuration Engine, dan layanan platform lainnya.

Tanpa pemisahan tanggung jawab yang jelas, detail implementasi seperti filesystem, parser YAML, media penyimpanan runtime, maupun framework Laravel berpotensi bercampur dengan business rule Platform Kernel. Kondisi tersebut meningkatkan coupling, menyulitkan pengujian, dan menghambat evolusi platform.

Platform Kernel memerlukan batas arsitektur yang tegas antara aturan bisnis platform dan detail implementasi sehingga setiap lapisan dapat berkembang secara independen tanpa memengaruhi lapisan lainnya.

---

# 2. Decision

Platform Kernel dipisahkan menjadi tiga lapisan utama.

```text
Presentation Layer
        │
        ▼
Application / Kernel Layer
        │
        ▼
Infrastructure Layer
```

Setiap lapisan memiliki tanggung jawab yang berbeda dan tidak boleh mengambil alih tanggung jawab lapisan lainnya.

- Business rule berada pada Application / Kernel Layer.
- Detail implementasi berada pada Infrastructure Layer.
- Interaksi dengan pengguna berada pada Presentation Layer.

---

# 3. Rationale

Pemisahan lapisan dipilih untuk menjaga batas tanggung jawab yang jelas di seluruh Platform Kernel.

Pendekatan ini memberikan beberapa keuntungan utama:

- mengurangi coupling antar komponen;
- mempermudah unit testing;
- mempermudah refactoring;
- memungkinkan pergantian teknologi tanpa memengaruhi business rule;
- menjaga stabilitas Platform Kernel;
- mendukung evolusi platform secara bertahap.

Platform Kernel hanya menentukan **apa** yang harus dilakukan, sedangkan Infrastructure menentukan **bagaimana** hal tersebut diimplementasikan.

---

# 4. Responsibilities

## Presentation Layer

Bertanggung jawab untuk:

- menerima input;
- menerjemahkan request;
- memanggil Platform Kernel;
- menyajikan output.

Contoh implementasi:

- Artisan Command
- HTTP Controller (future)
- REST API (future)
- GraphQL Resolver (future)

Presentation Layer tidak mengandung business rule maupun detail implementasi teknis.

---

## Application / Kernel Layer

Merupakan pusat business rule Platform Kernel.

Bertanggung jawab untuk:

- mengorkestrasi lifecycle modul;
- menjalankan business rule;
- mengelola metadata platform;
- berinteraksi melalui abstraksi.

Contoh implementasi:

- ModuleManager
- ModuleRegistry
- ModuleDefinition
- HealthChecker (future)
- DependencyResolver (future)

---

## Infrastructure Layer

Mengimplementasikan seluruh detail teknis yang dibutuhkan Platform Kernel.

Contoh implementasi:

- ModuleDiscovery
- ModuleManifestParser
- ManifestValidator
- ModuleDefinitionFactory
- ModuleStateRepository
- Filesystem
- Symfony YAML Parser
- JSON Storage

Infrastructure dapat berubah tanpa memengaruhi business rule Platform Kernel.

---

# 5. Architectural Rules

Seluruh implementasi Platform Kernel harus mengikuti aturan berikut.

- Business rule hanya berada pada Application / Kernel Layer.
- Presentation Layer tidak boleh mengakses Infrastructure secara langsung.
- Infrastructure Layer tidak mengetahui Presentation Layer.
- Platform Kernel bergantung pada abstraksi, bukan implementasi.
- Dependency selalu mengalir dari lapisan luar menuju lapisan dalam.
- Framework merupakan bagian dari Infrastructure Layer.
- Pergantian teknologi tidak boleh memengaruhi business rule Platform Kernel.

---

# 6. Consequences

## Positive

- Batas tanggung jawab menjadi jelas.
- Business rule tidak bergantung pada framework.
- Detail implementasi dapat berubah tanpa memengaruhi Platform Kernel.
- Komponen lebih mudah diuji secara terisolasi.
- Kode lebih mudah dipelihara.
- Evolusi platform menjadi lebih aman.

## Negative

- Menambah jumlah lapisan dan komponen.
- Membutuhkan disiplin dalam menjaga dependency direction.
- Membutuhkan abstraksi tambahan pada beberapa implementasi.

---

# 7. Alternatives Considered

## Option A — Mixed Architecture

Business rule dan detail implementasi berada pada kelas yang sama.

**Rejected**, karena:

- meningkatkan coupling;
- menyulitkan pengujian;
- menghambat evolusi platform.

---

## Option B — Layered Separation (**Accepted**)

Business rule dipisahkan dari detail implementasi.

Setiap lapisan hanya mengetahui tanggung jawabnya sendiri dan berinteraksi melalui kontrak yang jelas.

Pendekatan ini memberikan fleksibilitas jangka panjang tanpa meningkatkan kompleksitas secara berlebihan.

---

# 8. Architecture / Dependency Flow

```text
                 Presentation Layer
                        │
                        ▼
             Application / Kernel Layer
                        │
          depends on abstractions
                        │
                        ▼
             Infrastructure Layer
                        │
        Filesystem • YAML • Storage
```

Dependency selalu bergerak dari lapisan luar menuju Platform Kernel.

Tidak terdapat reverse dependency.

---

# 9. Current Implementation

## Implemented Components

### Presentation Layer

- KernelTestLoaderCommand
- ModuleListCommand
- ModuleStatusCommand
- ModuleEnableCommand
- ModuleDisableCommand

### Application / Kernel Layer

- ModuleManager
- ModuleRegistry
- ModuleDefinition

### Infrastructure Layer

- ModuleDiscovery
- ModuleManifestParser
- ManifestValidator
- ModuleDefinitionFactory
- ModuleStateRepository

---

## Implemented Capabilities

- Presentation Layer bertindak sebagai adapter menuju Platform Kernel.
- Platform Kernel mengelola seluruh business rule.
- Infrastructure Layer mengimplementasikan seluruh detail teknis.
- Dependency direction telah mengikuti prinsip layered architecture.

---

# 10. Impact

Keputusan ini menjadikan Platform Kernel independen terhadap framework maupun teknologi penyimpanan.

Perubahan pada filesystem, parser YAML, storage, maupun framework tidak memengaruhi business rule Platform Kernel.

Sebaliknya, perubahan pada business rule tidak mengharuskan perubahan pada Presentation Layer maupun Infrastructure Layer selama kontrak publik tetap dipertahankan.

Pendekatan ini menjadi fondasi implementasi kemampuan platform pada sprint berikutnya, termasuk Event Bus, Scheduler, Queue, Feature Flags, Configuration Engine, dan Dependency Resolver.

---

# 11. Future Evolution

Pada sprint berikutnya, Platform Kernel akan berkembang dengan berbagai kemampuan baru seperti:

- Event Bus
- Scheduler
- Queue
- Feature Flags
- Configuration Engine
- Dependency Resolver
- Plugin Marketplace

Seluruh kemampuan tersebut harus ditempatkan pada lapisan yang sesuai tanpa melanggar batas tanggung jawab maupun arah dependency.

Walaupun implementasi teknologi, framework, maupun media penyimpanan dapat berubah seiring evolusi platform, prinsip pemisahan antara Presentation Layer, Application / Kernel Layer, dan Infrastructure Layer merupakan keputusan arsitektur permanen yang menjadi fondasi seluruh Platform Kernel EduCore.

---

# 12. References

- PRD CORE-001
- Sprint CORE-001
- ADR-001 — Kernel Architecture Overview
- ADR-002 — Modular Monolith Architecture
- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-007 — ModuleManager as Kernel Facade
- ADR-008 — Thin Command Pattern
- `docs/architecture/architecture-principles.md`
