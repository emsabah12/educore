# ADR-007 — ModuleManager as Kernel Facade

Version : 2.1
Status : Superseded
Date : 2026-07-01  
Updated : 2026-08-13
Sprint : CORE-001 Sprint-1

---


> ## Superseded — 2026-08-13
> **Replacement:** ADR-017 — Module Runtime & Bootstrap Contract.
>
> `ModuleManager` and the module lifecycle-state mutation commands were removed during Module Kernel Runtime Hardening together with persisted runtime module state. Current module console operations are read-only metadata queries through `ModuleRepository`; module bootstrap is driven by physical discovery, validated manifests, dependency resolution, and provider registration.
>
> The body below is retained as historical decision context only and is **not** a current implementation contract.

# Related ADR

- ADR-001 — Kernel Architecture Overview
- ADR-002 — Modular Monolith Architecture
- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-008 — Thin Command Pattern
- ADR-009 — Separation of Infrastructure and Kernel Domain
- ADR-010 — Module Identity Strategy

---

# Context

Pada saat bootstrap, Platform Kernel melakukan proses discovery terhadap seluruh modul yang tersedia. Metadata setiap modul dimuat dari `module.yaml`, divalidasi, kemudian direpresentasikan sebagai `ModuleDefinition` dan disimpan di dalam `ModuleRegistry`.

Di sisi lain, status runtime setiap modul seperti aktif atau nonaktif dikelola secara terpisah oleh `ModuleStateRepository`.

Dengan adanya dua sumber informasi tersebut, Platform Kernel memerlukan sebuah komponen yang mampu mengoordinasikan operasi tingkat tinggi terhadap modul tanpa melanggar batas tanggung jawab masing-masing komponen.

Tanpa komponen tersebut, setiap Console Command, HTTP Controller, Dashboard, maupun layanan lain harus berinteraksi langsung dengan `ModuleRegistry` dan `ModuleStateRepository`. Pendekatan ini menyebabkan business rule tersebar pada banyak tempat, meningkatkan coupling antar komponen, serta menyulitkan pemeliharaan ketika aturan bisnis berkembang.

Platform Kernel memerlukan sebuah entry point yang menjadi pusat seluruh operasi runtime terhadap modul.

---

# Decision

EduCore menetapkan `ModuleManager` sebagai **Kernel Facade** sekaligus **Application Service** untuk seluruh operasi runtime terhadap modul.

Seluruh operasi tingkat tinggi terhadap modul harus dilakukan melalui `ModuleManager`.

`ModuleManager` bertanggung jawab mengorkestrasi komponen lain tanpa mengambil alih tanggung jawab internal masing-masing.

Sebagai Kernel Facade, `ModuleManager` menyediakan antarmuka yang sederhana dan konsisten bagi komponen eksternal, sementara kompleksitas koordinasi antar komponen tetap tersembunyi di dalam Platform Kernel.

---

# Rationale

Keputusan ini diambil untuk memisahkan dengan jelas antara metadata, runtime state, dan business rule.

`ModuleRegistry` bertanggung jawab sebagai sumber metadata modul.

`ModuleStateRepository` bertanggung jawab sebagai penyimpanan runtime state.

`ModuleManager` bertanggung jawab menjalankan business rule dengan memanfaatkan kedua komponen tersebut.

Pemisahan ini menghasilkan beberapa keuntungan:

- Business rule berada pada satu lokasi.
- Repository tetap fokus pada persistence.
- Registry tetap fokus pada metadata.
- Console Command tetap tipis.
- Dashboard maupun REST API menggunakan entry point yang sama.
- Evolusi Platform Kernel menjadi lebih mudah tanpa meningkatkan coupling.

Pendekatan ini juga mendukung prinsip Separation of Concerns, Single Responsibility Principle, serta mempermudah pengujian karena setiap komponen memiliki tanggung jawab yang jelas.

---

# Responsibilities

## ModuleManager bertanggung jawab untuk

- Mengoordinasikan seluruh operasi runtime terhadap modul.
- Menjadi entry point seluruh operasi tingkat tinggi.
- Menjalankan business rule Platform Kernel.
- Memvalidasi keberadaan modul sebelum menjalankan operasi.
- Menggunakan `ModuleRegistry` untuk memperoleh metadata modul.
- Menggunakan `ModuleStateRepository` untuk membaca dan memperbarui runtime state.
- Menyediakan API publik yang digunakan oleh Console Command, Dashboard, REST API, maupun komponen aplikasi lainnya.

## ModuleManager tidak bertanggung jawab untuk

- Melakukan discovery modul.
- Membaca `module.yaml`.
- Melakukan parsing manifest.
- Memvalidasi manifest.
- Membuat `ModuleDefinition`.
- Menyimpan metadata modul.
- Menyimpan runtime state.
- Mengakses filesystem secara langsung.
- Mengelola format penyimpanan runtime state.

Seluruh tanggung jawab tersebut tetap dimiliki oleh komponen lain sesuai keputusan arsitektur pada ADR terkait.

---

# Architectural Rules

Platform Kernel menerapkan aturan berikut:

- Seluruh business rule terkait lifecycle modul harus berada pada `ModuleManager`.
- Seluruh operasi runtime terhadap modul harus melewati `ModuleManager`.
- Console Command tidak diperbolehkan berinteraksi langsung dengan `ModuleRegistry`.
- Console Command tidak diperbolehkan berinteraksi langsung dengan `ModuleStateRepository`.
- `ModuleRegistry` tidak boleh mengandung business rule.
- `ModuleStateRepository` tidak boleh mengandung business rule.
- `ModuleManager` tidak boleh mengambil alih tanggung jawab discovery maupun persistence.
- `ModuleManager` hanya boleh menggunakan API publik dari komponen yang diorkestrasi.
- Penambahan fitur baru terkait lifecycle modul harus dilakukan melalui `ModuleManager`.

Aturan tersebut menjaga agar batas tanggung jawab antar komponen tetap konsisten sepanjang evolusi Platform Kernel.

# Consequences

## Positive

Penerapan `ModuleManager` sebagai Kernel Facade memberikan beberapa keuntungan:

- Seluruh business rule terpusat pada satu komponen.
- Console Command tetap tipis dan hanya bertanggung jawab menerima input serta menampilkan output.
- `ModuleRegistry` tetap menjadi sumber metadata tanpa mengetahui business rule.
- `ModuleStateRepository` tetap fokus pada persistence runtime state.
- Menurunkan coupling antar komponen Platform Kernel.
- Mempermudah pengujian unit maupun integration test.
- Mempermudah evolusi lifecycle modul tanpa memengaruhi API publik.
- Menyediakan API yang konsisten bagi Dashboard, REST API, Console Command, maupun komponen lain.

## Negative

Pendekatan ini juga memiliki beberapa konsekuensi:

- Menambah satu lapisan abstraksi pada Platform Kernel.
- Membutuhkan disiplin agar business rule tidak kembali tersebar ke Command maupun Repository.
- Berpotensi menjadikan `ModuleManager` terlalu besar apabila seluruh lifecycle ditempatkan di dalam satu kelas.

Risiko tersebut dikendalikan dengan mendelegasikan tanggung jawab tertentu kepada service khusus apabila kompleksitas meningkat pada sprint berikutnya.

---

# Alternatives Considered

## Option A — Console Command Calls Repository Directly

Console Command berinteraksi langsung dengan `ModuleRegistry` dan `ModuleStateRepository`.

**Rejected**, karena:

- Business rule tersebar.
- Coupling meningkat.
- Sulit diuji.
- Sulit dipelihara.
- Melanggar prinsip Thin Command.

---

## Option B — Repository Contains Business Rules

Repository bertanggung jawab terhadap persistence sekaligus menjalankan business rule.

**Rejected**, karena:

- Melanggar Single Responsibility Principle.
- Repository menjadi sulit diuji.
- Persistence bercampur dengan orchestration.
- Menyulitkan penggantian media penyimpanan.

---

## Option C — Multiple Service Entry Points

Setiap operasi memiliki service sendiri seperti:

- ModuleActivator
- ModuleDisabler
- ModuleInstaller

Tanpa adanya facade utama.

**Rejected untuk Sprint CORE-001**, karena:

- Menambah kompleksitas terlalu dini.
- Jumlah operasi masih relatif sedikit.
- Membutuhkan koordinasi tambahan antar service.

Pendekatan ini tetap menjadi kandidat evolusi internal tanpa mengubah API publik `ModuleManager`.

---

## Option D — ModuleManager as Kernel Facade (**Accepted**)

Platform Kernel menyediakan satu entry point yang bertanggung jawab mengorkestrasi seluruh operasi runtime terhadap modul.

Business rule ditempatkan pada `ModuleManager`, sedangkan metadata dan runtime state tetap dikelola oleh komponen yang memiliki tanggung jawab masing-masing.

Pendekatan ini memberikan keseimbangan antara maintainability, scalability, dan separation of concerns.

---

# Architecture / Dependency Flow

Seluruh operasi runtime mengikuti alur berikut:

```text
                Console Command
                       │
                       ▼
                 ModuleManager
                  │          │
                  │          │
                  ▼          ▼
          ModuleRegistry   ModuleStateRepository
                  │          │
                  └────┬─────┘
                       ▼
            Business Rule Execution
```

Pada implementasi lain seperti Dashboard maupun REST API, `ModuleManager` tetap menjadi entry point utama sehingga business rule selalu dieksekusi secara konsisten.

---

# Current Implementation

Status implementasi pada akhir Sprint CORE-001:

## Implemented Components

- ✅ `ModuleManager`
- ✅ Integrasi dengan `ModuleRegistry`
- ✅ Integrasi dengan `ModuleStateRepository`

## Implemented Capabilities

- ✅ Mengaktifkan modul.
- ✅ Menonaktifkan modul.
- ✅ Memeriksa status modul.
- ✅ Membaca metadata melalui `ModuleRegistry`.
- ✅ Membaca dan memperbarui runtime state melalui `ModuleStateRepository`.

Seluruh operasi runtime yang tersedia pada Sprint CORE-001 telah menggunakan `ModuleManager` sebagai entry point.

---

# Impact

Keputusan ini memberikan dampak terhadap beberapa aspek Platform Kernel.

## Production Code

- Business rule dipusatkan pada `ModuleManager`.
- Repository tetap sederhana.
- Registry tetap immutable.
- Console Command menjadi tipis.

## Testing

Testing dapat dilakukan secara terpisah terhadap:

- `ModuleRegistry`
- `ModuleStateRepository`
- `ModuleManager`

Business rule dapat diuji tanpa bergantung pada implementasi Console Command.

## Documentation

Arsitektur Platform Kernel menjadi lebih mudah dipahami karena seluruh operasi runtime memiliki satu entry point yang terdokumentasi secara eksplisit.

## Future Sprint

Sprint berikutnya dapat menambahkan lifecycle baru tanpa mengubah kontrak publik Platform Kernel.

---

# Future Evolution

Pada sprint berikutnya, `ModuleManager` akan berkembang menjadi pusat lifecycle modul.

Kemampuan yang direncanakan antara lain:

- Install Module
- Uninstall Module
- Publish Assets
- Update Module
- Reload Module
- Dependency Validation
- Version Compatibility Check
- Health Verification
- Lifecycle Events
- Module Maintenance Mode

Apabila kompleksitas meningkat, implementasi internal dapat didelegasikan kepada service khusus seperti:

- ModuleActivator
- ModuleInstaller
- ModuleUpdater
- DependencyResolver
- HealthChecker
- ModuleLifecycleCoordinator

Delegasi tersebut merupakan detail implementasi internal dan tidak mengubah kontrak publik `ModuleManager` sebagai Kernel Facade.

Dengan demikian, komponen eksternal tetap menggunakan API yang sama meskipun implementasi internal terus berkembang.

---

# References

- PRD CORE-001
- Sprint CORE-001
- `docs/architecture/module-manager.md`
- `docs/architecture/module-lifecycle.md`
- ADR-001 — Kernel Architecture Overview
- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-008 — Thin Command Pattern
- ADR-009 — Separation of Infrastructure and Kernel Domain
