# Platform Kernel

Version : 1.0
Status : Locked
Updated : 2026-07-02
Sprint : CORE-001 Sprint-1

## Related ADR

- ADR-001 — Kernel Architecture Overview
- ADR-002 — Modular Monolith Architecture
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-007 — Module Manager as Kernel Facade
- ADR-008 — Thin Command Pattern
- ADR-009 — Separation of Infrastructure and Kernel Domain
- ADR-010 — Module Identity Strategy

---

# Overview

Platform Kernel adalah inti dari EduCore Platform yang bertanggung jawab mengelola seluruh siklus hidup modul. Kernel menyediakan mekanisme untuk menemukan, membaca, memvalidasi, mendaftarkan, dan mengelola modul secara otomatis tanpa memerlukan registrasi manual.

Kernel menjadi fondasi arsitektur **Modular Monolith**, sehingga setiap modul dapat dikembangkan secara independen namun tetap berjalan di dalam satu aplikasi Laravel.

Kernel hanya bertanggung jawab terhadap **manajemen modul**, bukan terhadap logika bisnis masing-masing modul.

---

# Responsibilities

Platform Kernel memiliki tanggung jawab utama sebagai berikut:

- Menemukan seluruh modul yang tersedia.
- Memuat berkas `module.yaml`.
- Mem-parsing metadata manifest.
- Memvalidasi spesifikasi manifest.
- Membangun immutable `ModuleDefinition`.
- Menyimpan metadata ke `ModuleRegistry`.
- Mengelola status aktif/nonaktif melalui `ModuleStateRepository`.
- Menyediakan API internal melalui `ModuleManager`.
- Menjadi fondasi proses bootstrap aplikasi.

---

# Kernel Architecture

```text
Laravel Bootstrap
        │
        ▼
CoreServiceProvider
        │
        ▼
ModuleLoader
        │
        ▼
ModuleDiscovery
        │
        ▼
ModuleManifestLoader
        │
        ▼
ModuleManifestParser
        │
        ▼
ModuleManifestValidator
        │
        ▼
ModuleDefinitionFactory
        │
        ▼
ModuleRegistry
        │
        ├──────────────► ModuleStateRepository
        │
        ▼
ModuleManager
        │
        ▼
Application Runtime
```

---

# Core Components

## CoreServiceProvider

Entry point Platform Kernel.

Melakukan bootstrap seluruh komponen Kernel ketika aplikasi Laravel dijalankan.

---

## ModuleLoader

Mengorkestrasi proses discovery dan registrasi modul selama bootstrap aplikasi.

---

## ModuleDiscovery

Menemukan seluruh direktori modul pada folder `Modules/`.

Komponen ini hanya bertanggung jawab menemukan lokasi modul dan tidak membaca isi manifest.

---

## ModuleManifestLoader

Membaca file `module.yaml` dari setiap modul.

Komponen ini hanya bertanggung jawab mengambil isi berkas tanpa melakukan parsing maupun validasi.

---

## ModuleManifestParser

Mengubah isi YAML menjadi struktur data yang dapat diproses oleh Kernel.

---

## ModuleManifestValidator

Memastikan seluruh manifest memenuhi spesifikasi yang telah ditetapkan sebelum digunakan oleh Kernel.

Pendekatan yang digunakan adalah **Fail Fast**, sehingga proses bootstrap dihentikan ketika ditemukan manifest yang tidak valid.

---

## ModuleDefinitionFactory

Membangun immutable `ModuleDefinition` dari hasil parsing yang telah tervalidasi.

Factory merupakan satu-satunya komponen yang diperbolehkan membuat objek `ModuleDefinition`.

---

## ModuleRegistry

Menyimpan seluruh metadata modul yang berhasil dimuat.

`ModuleRegistry` merupakan **Source of Truth** untuk metadata modul selama aplikasi berjalan.

---

## ModuleStateRepository

Menyimpan status runtime modul, seperti aktif atau nonaktif.

Status runtime disimpan pada:

```text
storage/framework/modules.json
```

Runtime State dipisahkan dari Metadata sehingga perubahan status modul tidak mengubah definisi manifest.

---

## ModuleManager

Menyediakan façade untuk seluruh operasi pengelolaan modul.

Seluruh aturan bisnis Kernel dipusatkan pada komponen ini sehingga lapisan lain tidak berinteraksi langsung dengan Registry maupun Repository.

---

# Layer Separation

Platform Kernel menerapkan pemisahan tanggung jawab menjadi tiga lapisan utama.

```text
Presentation
      │
      ▼
Kernel Domain
      │
      ▼
Infrastructure
```

## Presentation

Antarmuka pengguna seperti Console Command.

## Kernel Domain

Berisi aturan bisnis inti Kernel, termasuk Discovery, Registry, Manager, dan Metadata.

## Infrastructure

Berisi implementasi teknis seperti filesystem, YAML parser, dan penyimpanan runtime state.

---

# Startup Sequence

Saat aplikasi dijalankan, Kernel melakukan proses berikut:

1. `CoreServiceProvider` melakukan bootstrap.
2. `ModuleLoader` memulai proses registrasi modul.
3. `ModuleDiscovery` menemukan seluruh modul.
4. `ModuleManifestLoader` membaca setiap `module.yaml`.
5. `ModuleManifestParser` melakukan parsing YAML.
6. `ModuleManifestValidator` memvalidasi struktur manifest.
7. `ModuleDefinitionFactory` membangun immutable `ModuleDefinition`.
8. `ModuleRegistry` menyimpan metadata seluruh modul.
9. `ModuleStateRepository` memuat status runtime.
10. `ModuleManager` siap melayani permintaan aplikasi.

---

# Design Principles

Platform Kernel dibangun berdasarkan prinsip berikut:

- Clean Architecture
- Modular Monolith
- Single Responsibility Principle (SRP)
- Separation of Concerns
- Fail Fast
- Immutable Metadata
- Registry as Source of Truth
- Runtime State Separation
- Thin Command Pattern
- Infrastructure Isolation

Strategi identitas global platform menggunakan UUID v7 melalui Symfony UID sebagaimana dijelaskan pada ADR-010. Namun, identitas `ModuleDefinition` menggunakan nama modul (`name`) sebagai identifier.

---

# Related Documents

- `README.md`
- `folder-structure.md`
- `discovery-flow.md`
- `module-manager.md`
- `module-lifecycle.md`
- `architecture-principles.md`
