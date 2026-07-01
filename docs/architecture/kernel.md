# Platform Kernel

## Overview

Platform Kernel adalah inti dari EduCore Platform yang bertanggung jawab mengelola seluruh modul aplikasi. Kernel menyediakan mekanisme untuk menemukan, memvalidasi, memuat, dan mengelola modul secara otomatis tanpa memerlukan registrasi manual.

Kernel merupakan fondasi dari arsitektur **Modular Monolith**, sehingga setiap modul dapat dikembangkan secara independen namun tetap berjalan dalam satu aplikasi Laravel.

---

# Responsibilities

Platform Kernel memiliki tanggung jawab utama sebagai berikut:

- Menemukan seluruh modul yang tersedia.
- Membaca dan memvalidasi `module.yaml`.
- Membangun metadata modul.
- Menyimpan metadata ke `ModuleRegistry`.
- Mengelola status aktif/nonaktif modul.
- Menyediakan API internal untuk pengelolaan modul.
- Menjadi fondasi bagi proses bootstrap aplikasi.

Kernel tidak bertanggung jawab terhadap logika bisnis dari masing-masing modul.

---

# Kernel Architecture

```
                 Laravel Bootstrap
                        │
                        ▼
              CoreServiceProvider
                        │
                        ▼
                 ModuleLoader
                        │
                        ▼
              Module Discovery
                        │
                        ▼
           ModuleManifestParser
                        │
                        ▼
             ManifestValidator
                        │
                        ▼
          ModuleDefinitionFactory
                        │
                        ▼
              ModuleRegistry
                        │
                        ▼
          ModuleStateRepository
                        │
                        ▼
              ModuleManager
                        │
                        ▼
              Console Commands
```

---

# Core Components

## CoreServiceProvider

Entry point Platform Kernel.

Bertugas melakukan bootstrap seluruh komponen kernel saat aplikasi Laravel dijalankan.

---

## ModuleLoader

Menginisialisasi proses discovery seluruh modul dan mendaftarkannya ke dalam registry.

---

## Discovery

Menemukan seluruh direktori modul yang tersedia pada folder `Modules`.

Discovery dilakukan secara otomatis tanpa konfigurasi manual.

---

## ModuleManifestParser

Membaca file `module.yaml` dari setiap modul menggunakan Symfony YAML.

---

## ManifestValidator

Memastikan seluruh manifest memenuhi spesifikasi yang telah ditentukan sebelum digunakan oleh kernel.

---

## ModuleDefinitionFactory

Mengubah hasil parsing manifest menjadi objek `ModuleDefinition`.

Objek ini menjadi representasi metadata modul di dalam kernel.

---

## ModuleRegistry

Menyimpan seluruh metadata modul yang telah berhasil didaftarkan.

Registry merupakan **Single Source of Truth** untuk metadata modul.

---

## ModuleStateRepository

Menyimpan status runtime setiap modul.

Repository ini menggunakan file:

```
storage/framework/modules.json
```

Runtime state dipisahkan dari metadata manifest.

---

## ModuleManager

Menyediakan API internal untuk seluruh operasi pengelolaan modul.

Seluruh business rule kernel berada pada komponen ini.

---

# Layer Separation

Kernel menerapkan pemisahan tanggung jawab menjadi tiga lapisan utama.

```
Presentation

↓

Kernel Domain

↓

Infrastructure
```

### Presentation

Antarmuka yang berinteraksi dengan pengguna, seperti Console Command.

### Kernel Domain

Berisi aturan bisnis inti Platform Kernel.

### Infrastructure

Berisi implementasi teknis seperti pembacaan file, parser YAML, dan penyimpanan runtime state.

---

# Startup Sequence

Saat aplikasi dijalankan, kernel melakukan langkah berikut:

1. CoreServiceProvider melakukan bootstrap.
2. ModuleLoader dijalankan.
3. Discovery menemukan seluruh modul.
4. Manifest dibaca dan divalidasi.
5. Metadata diubah menjadi `ModuleDefinition`.
6. Metadata disimpan pada `ModuleRegistry`.
7. Runtime state dibaca dari `ModuleStateRepository`.
8. `ModuleManager` siap melayani permintaan aplikasi.

---

# Design Principles

Platform Kernel dibangun berdasarkan prinsip berikut:

- Modular Monolith
- Single Responsibility Principle
- Separation of Concerns
- Single Source of Truth
- Thin Command Pattern
- Infrastructure Isolation
- Convention over Configuration

---

# Related Documents

- `folder-structure.md`
- `discovery-flow.md`
- `module-manager.md`
- `module-lifecycle.md`

---

# Related ADR

- ADR-001 — Kernel Architecture Overview
- ADR-002 — Modular Monolith Architecture
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Metadata Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-007 — ModuleManager as Kernel Facade
- ADR-008 — Thin Command Pattern
- ADR-009 — Separation of Infrastructure and Kernel Domain
