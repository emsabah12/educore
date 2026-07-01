# ModuleManager

## Overview

`ModuleManager` adalah façade utama dari Platform Kernel yang bertanggung jawab menangani seluruh operasi runtime terkait modul.

Komponen ini merupakan **entry point business rule kernel** untuk interaksi modul pada level aplikasi.

Semua operasi seperti enable, disable, dan pengecekan status modul harus melalui `ModuleManager`.

---

# Responsibilities

`ModuleManager` memiliki tanggung jawab berikut:

- Mengaktifkan modul (enable)
- Menonaktifkan modul (disable)
- Mengecek status modul
- Mengorkestrasi `ModuleStateRepository`
- Memvalidasi rule sebelum perubahan state dilakukan
- Menyediakan API tunggal untuk operasi modul

---

# Non-Responsibilities

`ModuleManager` **tidak boleh**:

- Mengubah metadata modul (`ModuleRegistry`)
- Membaca file `module.yaml`
- Melakukan discovery modul
- Menyimpan logic parsing manifest
- Mengelola persistence detail (langsung ke storage)

Semua hal tersebut sudah menjadi tanggung jawab komponen lain dalam kernel.

---

# Position in Architecture

```text id="mmgr-arch"
Console / Services / External Layer
                │
                ▼
         ModuleManager
                │
     ┌──────────┴──────────┐
     ▼                     ▼
ModuleRegistry     ModuleStateRepository
```

---

# Core Operations

## enable(moduleName)

Mengaktifkan modul pada runtime.

### Flow:

1. Validasi apakah modul ada di `ModuleRegistry`
2. Cek apakah modul sudah aktif
3. Jika tidak aktif, update state di `ModuleStateRepository`
4. Simpan perubahan

### Rule:

- Modul yang tidak terdaftar tidak dapat di-enable
- Modul yang sudah aktif tidak boleh diproses ulang

---

## disable(moduleName)

Menonaktifkan modul pada runtime.

### Flow:

1. Validasi modul ada di registry
2. Cek apakah modul sudah nonaktif
3. Update state di `ModuleStateRepository`

---

## isEnabled(moduleName)

Mengembalikan status runtime modul.

Sumber data hanya dari:

```text id="state-src"
ModuleStateRepository
```

---

## getAllStatus()

Mengembalikan daftar status seluruh modul:

- metadata dari `ModuleRegistry`
- state dari `ModuleStateRepository`

---

# Business Rules

## Rule 1 — Registry First

Modul hanya dapat dimodifikasi jika sudah terdaftar di `ModuleRegistry`.

---

## Rule 2 — Idempotency

Operasi enable/disable bersifat idempotent:

- enable modul aktif → tidak melakukan apa-apa
- disable modul nonaktif → tidak melakukan apa-apa

---

## Rule 3 — Separation of Concerns

`ModuleManager` tidak boleh:

- menyimpan data permanen langsung
- membaca file sistem
- melakukan parsing YAML

Semua persistence dilakukan oleh `ModuleStateRepository`.

---

# Dependency Flow

```text id="dep-flow"
ModuleManager
    │
    ├── ModuleRegistry (metadata source of truth)
    │
    └── ModuleStateRepository (runtime state source of truth)
```

---

# Error Handling

ModuleManager harus menggunakan exception yang jelas:

- `ModuleNotFoundException`
- `ModuleAlreadyEnabledException`
- `ModuleAlreadyDisabledException`

Tujuan utama error handling:

- memperjelas root cause
- menghindari silent failure
- memudahkan debugging CLI

---

# Command Integration

Semua Artisan Command hanya bertindak sebagai thin layer:

```text id="cmd-flow"
User Input
   ↓
Console Command
   ↓
ModuleManager
   ↓
Repository Layer
```

Contoh:

- `module:enable` → `ModuleManager::enable()`
- `module:disable` → `ModuleManager::disable()`

---

# Design Principles

`ModuleManager` mengikuti prinsip:

- Single Responsibility Principle
- Facade Pattern (Kernel Entry Point)
- Thin Command Pattern
- Centralized Business Rules
- Explicit State Management

---

# Related Documents

- `kernel.md`
- `folder-structure.md`
- `discovery-flow.md`
- `module-lifecycle.md`

---

# Related ADR

- ADR-005 — Module Registry as Metadata Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-007 — ModuleManager as Kernel Facade
- ADR-008 — Thin Command Pattern
