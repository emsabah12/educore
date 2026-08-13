# ADR-008 — Thin Command Pattern

**Version** : 1.1
**Status** : Accepted
**Date** : 2026-07-01
**Updated** : 2026-08-13
**Sprint** : CORE-001 Sprint-1

---

> **Decision Summary**
>
> Seluruh Artisan Command pada EduCore menerapkan **Thin Command Pattern**, di mana command hanya bertindak sebagai adapter antara Command Line Interface (CLI) dan Platform Kernel. Seluruh business rule diimplementasikan pada Platform Kernel, sehingga command tetap sederhana, mudah diuji, dan dapat digunakan kembali oleh berbagai antarmuka aplikasi.

---


> ## Revalidation Amendment — 2026-08-13
> **Decision:** KEEP. Current module console commands are read-only metadata adapters: `module:list` and `module:status` query `ModuleRepository` and do not own filesystem scanning, manifest parsing/validation, provider activation, or mutable runtime state. `module:enable`, `module:disable`, `ModuleManager`, and `ModuleStateRepository` are no longer part of the current contract. Historical mutation-command examples below are superseded by ADR-017.

# Related ADR

- ADR-007 — ModuleManager as Kernel Facade (**Superseded by ADR-017**)
- ADR-009 — Separation of Infrastructure and Kernel Domain
- ADR-017 — Module Runtime & Bootstrap Contract

---

# 1. Context

EduCore menyediakan Command Line Interface (CLI) sebagai salah satu entry point menuju Platform Kernel. Melalui CLI, administrator dapat menjalankan berbagai operasi seperti:

- `module:list`
- `module:status`
- `module:enable`
- `module:disable`

Tanpa aturan arsitektur yang jelas, terdapat kecenderungan business rule berkembang di dalam Artisan Command. Kondisi tersebut menyebabkan logika aplikasi tersebar pada berbagai command sehingga lebih sulit dipelihara, diuji, dan digunakan kembali oleh antarmuka lain.

Sebagai Platform Kernel yang akan melayani berbagai entry point, EduCore membutuhkan pemisahan tanggung jawab yang jelas antara Presentation Layer dan Application Layer.

---

# 2. Decision

Seluruh Artisan Command pada EduCore mengikuti **Thin Command Pattern**.

Command hanya bertanggung jawab untuk:

- menerima input pengguna;
- membaca argument dan option;
- memanggil service pada Platform Kernel;
- menampilkan hasil kepada pengguna;
- mengembalikan exit code.

Command **tidak boleh** mengandung business rule maupun mengakses komponen internal Platform Kernel secara langsung.

Seluruh logika aplikasi ditempatkan pada Platform Kernel, terutama melalui `ModuleManager` sebagai Kernel Facade.

---

# 3. Rationale

Keputusan ini diambil untuk menjaga pemisahan tanggung jawab antar layer arsitektur.

Dengan menjaga command tetap tipis:

- business rule hanya berada pada satu lokasi;
- perubahan antarmuka CLI tidak memengaruhi Platform Kernel;
- command menjadi lebih mudah dipahami dan dipelihara;
- pengujian command menjadi lebih sederhana;
- logika aplikasi dapat digunakan kembali oleh berbagai jenis antarmuka.

Pendekatan ini juga memperkuat prinsip bahwa Platform Kernel merupakan pusat orkestrasi seluruh operasi modul.

---

# 4. Responsibilities

## Artisan Command bertanggung jawab untuk:

- menerima input pengguna;
- membaca argument dan option;
- memanggil Platform Kernel;
- menyajikan output;
- mengembalikan exit code.

## Artisan Command tidak bertanggung jawab untuk:

- menjalankan business rule;
- melakukan module discovery;
- membaca `module.yaml`;
- mengakses filesystem;
- mengakses repository secara langsung;
- mengelola runtime state;
- melakukan validasi domain;
- mengorkestrasi lifecycle modul.

Seluruh tanggung jawab tersebut didelegasikan kepada Platform Kernel.

---

# 5. Architectural Rules

Seluruh implementasi CLI pada EduCore harus mengikuti aturan berikut.

- Command hanya menjadi adapter antara CLI dan Platform Kernel.
- Business rule tidak boleh berada di dalam command.
- Command tidak boleh bergantung langsung pada komponen Infrastructure.
- Command hanya berkomunikasi melalui public API Platform Kernel.
- Platform Kernel tetap menjadi satu-satunya tempat implementasi business rule.
- Command harus dapat diganti tanpa mengubah perilaku Platform Kernel.

---

# 6. Consequences

## Positive

- Command menjadi kecil dan mudah dipahami.
- Business rule tidak tersebar.
- Reusability meningkat.
- Pengujian menjadi lebih sederhana.
- Platform Kernel tetap independen terhadap jenis antarmuka.

## Negative

- Membutuhkan lapisan orchestration pada Platform Kernel.
- Membutuhkan disiplin agar seluruh business rule tetap berada pada Platform Kernel.

---

# 7. Alternatives Considered

## Option A — Fat Command

Business rule ditempatkan langsung pada Artisan Command.

**Rejected**, karena:

- mencampurkan Presentation Layer dan Application Layer;
- meningkatkan coupling;
- menyulitkan pengujian;
- menyebabkan duplikasi logika pada antarmuka lain.

---

## Option B — Thin Command (**Accepted**)

Command hanya bertindak sebagai adapter.

Seluruh business rule dijalankan oleh Platform Kernel melalui `ModuleManager` atau service kernel lainnya.

---

# 8. Architecture / Dependency Flow

```text
                Presentation Layer
                        │
                        ▼
               Artisan Command (CLI)
                        │
                        ▼
                 Platform Kernel
                 ModuleManager
                        │
          ┌─────────────┴─────────────┐
          ▼                           ▼
 ModuleRegistry           ModuleStateRepository
```

Command tidak mengetahui implementasi internal Platform Kernel dan hanya bergantung pada kontrak publik yang disediakan oleh Kernel Facade.

---

# 9. Current Implementation

## Implemented Components

- `KernelTestLoaderCommand`
- `ModuleListCommand`
- `ModuleStatusCommand`
- `ModuleEnableCommand`
- `ModuleDisableCommand`

## Implemented Capabilities

- CLI sebagai entry point menuju Platform Kernel.
- Seluruh command mendelegasikan business rule kepada `ModuleManager`.
- Command hanya menangani input, output, dan exit code.
- Tidak terdapat business rule pada implementasi command.

---

# 10. Impact

Keputusan ini menjadikan CLI sebagai salah satu adapter terhadap Platform Kernel tanpa membawa logika aplikasi.

Arsitektur menjadi lebih modular karena perubahan pada command tidak memengaruhi implementasi business rule. Sebaliknya, perubahan business rule dapat dilakukan pada Platform Kernel tanpa memerlukan perubahan pada setiap command.

Pendekatan ini juga memperkuat konsistensi antara CLI, HTTP API, Scheduler, Queue Worker, maupun antarmuka lain yang akan menggunakan Platform Kernel.

---

# 11. Future Evolution

Pada sprint berikutnya, berbagai entry point baru akan menggunakan Platform Kernel yang sama, antara lain:

- REST API
- Web Dashboard
- Queue Worker
- Scheduler
- GraphQL

Walaupun jumlah antarmuka bertambah, prinsip Thin Command tetap dipertahankan.

Business rule akan tetap berada pada Platform Kernel, sedangkan setiap antarmuka hanya bertindak sebagai adapter yang menerjemahkan request menuju operasi Platform Kernel.

Prinsip ini merupakan bagian permanen dari arsitektur EduCore dan tidak berubah meskipun implementasi antarmuka berkembang di masa mendatang.

---

# 12. References

- PRD CORE-001
- Sprint CORE-001
- ADR-007 — ModuleManager as Kernel Facade
- ADR-009 — Separation of Infrastructure and Kernel Domain
- `docs/architecture/module-manager.md`
