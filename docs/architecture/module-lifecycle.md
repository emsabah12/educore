# Module Lifecycle

Version : 1.0
Status : Locked
Updated : 2026-07-02
Sprint : CORE-001 Sprint-1

## Related ADR

- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-007 — Module Manager as Kernel Facade
- ADR-010 — Module Identity Strategy

---

# Overview

Module Lifecycle menjelaskan perjalanan hidup sebuah modul di dalam EduCore Platform Kernel, mulai dari keberadaannya di filesystem hingga digunakan pada runtime aplikasi.

Lifecycle dibagi menjadi dua domain utama yang memiliki tanggung jawab berbeda:

- **Discovery Lifecycle** — membangun metadata modul.
- **Runtime Lifecycle** — mengelola status runtime modul.

Kedua lifecycle tersebut dipisahkan secara desain untuk menjaga pemisahan antara **Metadata** dan **Runtime State**, namun saling terhubung melalui Platform Kernel.

---

# High-Level Lifecycle

```text
Filesystem (Modules/)
        │
        ▼
Discovery Pipeline
        │
        ▼
Immutable ModuleDefinition
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
Application Runtime
```

---

# Phase 1 — Filesystem Layer

Pada tahap ini, modul hanya berupa direktori di dalam:

```text
Modules/
```

Contoh:

```text
Modules/
├── Core/
├── Academic/
├── PPDB/
└── Finance/
```

Sebuah direktori belum dianggap sebagai modul sampai memiliki berkas:

```text
module.yaml
```

---

# Phase 2 — Discovery Lifecycle

Discovery Lifecycle dijalankan setiap kali aplikasi melakukan bootstrap melalui `CoreServiceProvider`.

Pipeline discovery mengikuti tahapan berikut.

---

## Step 1 — Module Discovery

`ModuleDiscovery` memindai seluruh direktori modul pada folder `Modules/`.

Komponen ini hanya bertanggung jawab menemukan lokasi modul dan tidak membaca isi manifest.

Output:

```text
Module Directory
```

---

## Step 2 — Manifest Loading

Untuk setiap modul yang ditemukan, `ModuleManifestLoader` membaca isi berkas:

```text
module.yaml
```

Loader hanya bertanggung jawab membaca isi berkas tanpa melakukan parsing maupun validasi.

Output:

```text
Raw YAML
```

---

## Step 3 — Manifest Parsing

`ModuleManifestParser` mengubah isi YAML menjadi struktur data yang dapat diproses oleh Kernel.

Parser tidak melakukan validasi maupun membangun objek domain.

Output:

```text
Parsed Manifest Data
```

---

## Step 4 — Manifest Validation

`ModuleManifestValidator` memvalidasi data hasil parsing.

Validator memastikan bahwa manifest memenuhi spesifikasi yang telah ditentukan, seperti:

- field wajib tersedia,
- nama modul valid,
- versi valid,
- provider terdefinisi,
- dependency memiliki format yang benar.

Discovery menggunakan pendekatan **Fail Fast**, sehingga proses dihentikan segera ketika ditemukan manifest yang tidak valid.

Output:

```text
Validated Manifest Data
```

---

## Step 5 — Module Definition Creation

`ModuleDefinitionFactory` membangun immutable `ModuleDefinition` dari data yang telah tervalidasi.

Factory merupakan satu-satunya komponen yang diperbolehkan membuat objek `ModuleDefinition`.

Setelah dibuat, metadata tidak dapat diubah selama runtime aplikasi.

Output:

```text
ModuleDefinition
```

---

## Step 6 — Module Registration

`ModuleDefinition` didaftarkan ke dalam `ModuleRegistry`.

`ModuleRegistry` menjadi **Source of Truth** untuk seluruh metadata modul selama aplikasi berjalan.

Pada tahap ini modul telah terdaftar secara metadata, tetapi belum tentu aktif.

---

# Phase 3 — Runtime Lifecycle

Setelah discovery selesai, Platform Kernel memasuki Runtime Lifecycle.

Pada fase ini tidak ada lagi proses discovery maupun perubahan metadata.

Kernel hanya mengelola status runtime setiap modul.

---

# Runtime State Source

Status runtime disimpan melalui:

```text
ModuleStateRepository
```

Dengan media penyimpanan:

```text
storage/framework/modules.json
```

Runtime State dipisahkan sepenuhnya dari metadata modul.

---

# Runtime State Model

Saat ini setiap modul memiliki dua state utama:

- `enabled`
- `disabled`

Belum terdapat state transisi lain pada Sprint CORE-001.

---

# State Transition

## Initial State

Setelah discovery selesai:

```text
ModuleRegistry
      │
      └── ModuleDefinition tersedia

ModuleStateRepository
      │
      └── disabled (default)
```

Artinya modul telah terdaftar, tetapi belum tentu aktif.

---

## Enable Flow

Ketika `ModuleManager::enable()` dipanggil:

```text
disabled
      │
      ▼
enabled
```

Proses yang dilakukan:

1. Memastikan modul terdaftar di `ModuleRegistry`.
2. Membaca status dari `ModuleStateRepository`.
3. Memastikan modul belum aktif.
4. Mengubah status menjadi `enabled`.
5. Menyimpan perubahan ke `ModuleStateRepository`.

---

## Disable Flow

Ketika `ModuleManager::disable()` dipanggil:

```text
enabled
      │
      ▼
disabled
```

Proses yang dilakukan:

1. Memastikan modul terdaftar di `ModuleRegistry`.
2. Membaca status runtime.
3. Memastikan modul masih aktif.
4. Mengubah status menjadi `disabled`.
5. Menyimpan perubahan ke `ModuleStateRepository`.

---

## Important Note

Perubahan runtime hanya memengaruhi `ModuleStateRepository`.

`ModuleDefinition` yang berada di dalam `ModuleRegistry` tetap bersifat immutable dan tidak berubah selama aplikasi berjalan.

---

# End-to-End Lifecycle

```text
Filesystem
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
      ▼
ModuleStateRepository
      │
      ▼
ModuleManager
      │
      ▼
Application Runtime
```

---

# Key Separation Rules

## Metadata vs Runtime

| Aspect          | Source of Truth       |
| --------------- | --------------------- |
| Module Metadata | ModuleRegistry        |
| Runtime State   | ModuleStateRepository |

Metadata dan Runtime State tidak boleh dicampur.

---

## Static vs Dynamic

| Static             | Dynamic       |
| ------------------ | ------------- |
| Discovery Pipeline | Runtime State |

Discovery hanya membangun metadata.

Runtime hanya mengelola status modul.

---

## Immutable vs Mutable

| Object           | Characteristic |
| ---------------- | -------------- |
| ModuleDefinition | Immutable      |
| Module State     | Mutable        |

---

# Lifecycle Constraints

## Discovery Only During Bootstrap

Discovery hanya dilakukan pada proses bootstrap aplikasi.

Kernel tidak melakukan discovery ulang selama runtime.

---

## Runtime Does Not Modify Metadata

Enable maupun disable modul tidak mengubah metadata yang berada di `ModuleRegistry`.

---

## Registry Does Not Persist Runtime State

`ModuleRegistry` hanya menyimpan metadata.

Status runtime disimpan secara terpisah melalui `ModuleStateRepository`.

---

# Failure Scenarios

## Scenario 1 — Module Not Found

Jika `ModuleManager` menerima nama modul yang tidak terdaftar:

- `ModuleNotFoundException` dilempar.
- Runtime State tidak berubah.

---

## Scenario 2 — Corrupted Runtime State

Apabila `storage/framework/modules.json` rusak:

- Repository menggunakan state bawaan.
- Error dicatat pada log.
- Bootstrap aplikasi tetap dilanjutkan.

---

## Scenario 3 — Invalid Manifest

Apabila manifest tidak memenuhi spesifikasi:

- Discovery dihentikan untuk modul tersebut.
- `ModuleDefinition` tidak dibuat.
- Modul tidak dimasukkan ke `ModuleRegistry`.

---

## Scenario 4 — Invalid Module Definition

Apabila `ModuleDefinitionFactory` gagal membangun objek karena data tidak memenuhi invariants:

- Registrasi dibatalkan.
- Metadata tidak disimpan.
- Bootstrap dihentikan sesuai prinsip **Fail Fast**.

---

# Design Philosophy

Module Lifecycle dibangun berdasarkan prinsip berikut:

- Separation of Metadata and Runtime
- Explicit Processing Pipeline
- Single Responsibility Principle
- Immutable Metadata
- Mutable Runtime State
- Fail Fast Validation
- Source of Truth
- Deterministic Module Behavior
- Idempotent Runtime Operations

---

# Related Documents

- `README.md`
- `kernel.md`
- `folder-structure.md`
- `discovery-flow.md`
- `module-manager.md`
- `architecture-principles.md`
