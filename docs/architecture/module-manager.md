# Module Manager

Version : 1.0
Status : Locked
Updated : 2026-07-02
Sprint : CORE-001 Sprint-1

## Related ADR

- ADR-005 — Module Registry as Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-007 — Module Manager as Kernel Facade
- ADR-008 — Thin Command Pattern

---

# Overview

`ModuleManager` merupakan façade utama Platform Kernel yang menyediakan satu titik masuk (single entry point) untuk seluruh operasi runtime yang berkaitan dengan modul.

Seluruh business rule yang berhubungan dengan pengelolaan status modul dipusatkan pada komponen ini. Komponen lain, seperti Console Command atau service aplikasi, tidak berinteraksi langsung dengan `ModuleRegistry` maupun `ModuleStateRepository`.

`ModuleManager` hanya mengelola **Runtime Lifecycle**. Proses discovery, parsing, validasi manifest, dan registrasi metadata merupakan tanggung jawab Discovery Pipeline.

---

# Responsibilities

`ModuleManager` memiliki tanggung jawab sebagai berikut:

- Mengaktifkan modul (`enable`).
- Menonaktifkan modul (`disable`).
- Mengecek status runtime modul.
- Menggabungkan metadata modul dengan runtime state.
- Mengorkestrasi `ModuleRegistry` dan `ModuleStateRepository`.
- Memvalidasi business rule sebelum perubahan runtime dilakukan.
- Menyediakan API tunggal untuk seluruh operasi runtime modul.

---

# Non-Responsibilities

`ModuleManager` **tidak bertanggung jawab** untuk:

- Melakukan discovery modul.
- Membaca berkas `module.yaml`.
- Memuat atau mem-parsing manifest.
- Memvalidasi struktur manifest.
- Membuat `ModuleDefinition`.
- Mengubah metadata pada `ModuleRegistry`.
- Berinteraksi langsung dengan filesystem.
- Menangani detail persistence di luar `ModuleStateRepository`.

Seluruh tanggung jawab tersebut telah dipisahkan ke komponen lain sesuai prinsip **Single Responsibility Principle (SRP)**.

---

# Position in Architecture

```text
                Application Layer
                       │
                       ▼
              Console / Services
                       │
                       ▼
                 ModuleManager
                  │          │
                  ▼          ▼
          ModuleRegistry   ModuleStateRepository
          (Metadata)         (Runtime State)
```

`ModuleManager` menjadi batas antara Application Layer dan komponen internal Platform Kernel.

---

# Core Operations

## enable(moduleName)

Mengaktifkan sebuah modul pada runtime.

### Flow

1. Memastikan modul terdaftar di `ModuleRegistry`.
2. Membaca status runtime dari `ModuleStateRepository`.
3. Memastikan modul belum aktif.
4. Mengubah status menjadi `enabled`.
5. Menyimpan perubahan melalui `ModuleStateRepository`.

### Rules

- Modul harus terdaftar pada `ModuleRegistry`.
- Modul yang sudah aktif tidak diproses ulang.
- Metadata modul tidak berubah.

---

## disable(moduleName)

Menonaktifkan sebuah modul pada runtime.

### Flow

1. Memastikan modul terdaftar di `ModuleRegistry`.
2. Membaca status runtime.
3. Memastikan modul masih aktif.
4. Mengubah status menjadi `disabled`.
5. Menyimpan perubahan melalui `ModuleStateRepository`.

### Rules

- Modul harus terdaftar.
- Modul yang sudah nonaktif tidak diproses ulang.
- Metadata tetap tidak berubah.

---

## isEnabled(moduleName)

Mengembalikan status runtime modul.

Sumber data:

```text
ModuleStateRepository
```

---

## getAllStatus()

Mengembalikan informasi seluruh modul dengan menggabungkan:

- Metadata dari `ModuleRegistry`.
- Runtime State dari `ModuleStateRepository`.

Dengan pendekatan ini, metadata tetap menjadi **Source of Truth** sedangkan runtime state menjadi informasi yang bersifat dinamis.

---

# Business Rules

## Rule 1 — Registry First

Seluruh operasi runtime harus diawali dengan validasi bahwa modul telah terdaftar di `ModuleRegistry`.

---

## Rule 2 — Metadata is Immutable

`ModuleManager` tidak boleh mengubah `ModuleDefinition`.

Perubahan runtime hanya memengaruhi `ModuleStateRepository`.

---

## Rule 3 — Runtime State is Mutable

Status modul dapat berubah selama aplikasi berjalan tanpa mengubah metadata.

---

## Rule 4 — Idempotent Operations

Operasi runtime harus bersifat idempotent.

Contoh:

- enable modul yang sudah aktif → tidak mengubah state.
- disable modul yang sudah nonaktif → tidak mengubah state.

---

## Rule 5 — Separation of Concerns

`ModuleManager` tidak boleh:

- membaca filesystem,
- memuat manifest,
- mem-parsing YAML,
- memvalidasi manifest,
- melakukan discovery.

Seluruh tanggung jawab tersebut telah dipisahkan ke Discovery Pipeline.

---

# Dependency Flow

```text
                  ModuleManager
                   │         │
                   ▼         ▼
          ModuleRegistry   ModuleStateRepository
          Source of Truth   Runtime State
```

`ModuleManager` mengorkestrasi kedua komponen tersebut tanpa mengetahui detail implementasi internal masing-masing.

---

# Error Handling

`ModuleManager` menggunakan exception yang spesifik agar penyebab kegagalan dapat diketahui dengan jelas.

Contoh:

- `ModuleNotFoundException`
- `ModuleAlreadyEnabledException`
- `ModuleAlreadyDisabledException`

Pendekatan ini mendukung prinsip **Fail Fast** dan mempermudah proses debugging.

---

# Command Integration

Seluruh Artisan Command mengikuti prinsip **Thin Command Pattern**.

Command hanya:

1. menerima input,
2. memanggil `ModuleManager`,
3. menampilkan hasil.

Diagram:

```text
User Input
      │
      ▼
Console Command
      │
      ▼
ModuleManager
      │
      ▼
ModuleRegistry
      │
      ▼
ModuleStateRepository
```

Contoh:

```text
module:list
module:enable
module:disable
module:status
```

Business rule tetap berada di dalam `ModuleManager`.

---

# Design Principles

`ModuleManager` dibangun berdasarkan prinsip berikut:

- Single Responsibility Principle (SRP)
- Facade Pattern
- Thin Command Pattern
- Centralized Business Rules
- Explicit Runtime State Management
- Separation of Metadata and Runtime
- Source of Truth
- Idempotent Operations
- Fail Fast

---

# Related Documents

- `README.md`
- `kernel.md`
- `folder-structure.md`
- `discovery-flow.md`
- `module-lifecycle.md`
- `architecture-principles.md`

---

# Summary

`ModuleManager` merupakan façade utama Platform Kernel untuk seluruh operasi runtime modul.

Komponen ini mengoordinasikan `ModuleRegistry` sebagai **Source of Truth** metadata dan `ModuleStateRepository` sebagai penyimpan runtime state, tanpa mengambil alih tanggung jawab Discovery Pipeline.

Dengan pemisahan tanggung jawab tersebut, Platform Kernel tetap memenuhi prinsip:

- Clean Architecture
- Single Responsibility Principle
- Separation of Concerns
- Explicit Runtime State Management
- Thin Command Pattern
- Fail Fast
- Immutable Metadata
- Source of Truth
