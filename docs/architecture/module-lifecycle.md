# Module Lifecycle

## Overview

Module Lifecycle menjelaskan seluruh perjalanan hidup sebuah modul dalam Platform Kernel, mulai dari keberadaan di filesystem hingga status runtime di aplikasi berjalan.

Lifecycle ini mencakup dua domain utama:

- **Discovery Lifecycle** (metadata creation)
- **Runtime Lifecycle** (state management)

Keduanya terpisah secara desain tetapi saling berhubungan secara konseptual.

---

# High Level Lifecycle

```text id="life-01"
Filesystem (Modules/*)
        │
        ▼
Discovery Process
        │
        ▼
ModuleRegistry (Metadata)
        │
        ▼
ModuleStateRepository (Runtime State)
        │
        ▼
Active Application Runtime
```

---

# Phase 1 — Filesystem Layer

Pada tahap ini, modul hanya berupa folder di dalam:

```text id="life-02"
Modules/
```

Contoh:

```text id="life-03"
Modules/
├── Core/
├── Academic/
├── PPDB/
```

Setiap folder **belum dianggap sebagai modul aktif** sampai memiliki `module.yaml`.

---

# Phase 2 — Discovery Lifecycle

Tahap ini terjadi saat bootstrap aplikasi.

## Step 1 — Detection

Kernel memindai folder `Modules/` untuk menemukan kandidat modul.

---

## Step 2 — Manifest Loading

Jika ditemukan `module.yaml`, maka:

- file dibaca oleh `ModuleManifestParser`
- diubah menjadi struktur data

---

## Step 3 — Validation

Manifest divalidasi oleh `ManifestValidator`.

Jika gagal:

- modul diabaikan
- error dicatat atau exception dilempar

---

## Step 4 — Definition Creation

Data valid dikonversi menjadi:

```text id="life-def"
ModuleDefinition
```

---

## Step 5 — Registration

`ModuleDefinition` disimpan ke:

```text id="life-reg"
ModuleRegistry
```

Pada tahap ini modul sudah **terdaftar secara metadata**, tetapi belum tentu aktif.

---

# Phase 3 — Runtime Lifecycle

Setelah discovery selesai, kernel masuk ke runtime state management.

---

## State Source

Runtime state disimpan di:

```text id="life-state"
storage/framework/modules.json
```

Melalui:

```text id="life-repo"
ModuleStateRepository
```

---

## State Model

Sebuah modul hanya memiliki dua state utama:

- `enabled`
- `disabled`

Tidak ada state transisi lain dalam desain saat ini.

---

# State Transition

## Initial State

Setelah discovery:

```text id="life-init"
ModuleRegistry → exists
ModuleStateRepository → default (disabled)
```

Artinya:

> Modul terdaftar tetapi belum aktif.

---

## Enable Flow

Ketika `ModuleManager::enable()` dipanggil:

```text id="life-en"
disabled → enabled
```

Flow:

1. Validasi modul ada di registry
2. Cek state saat ini
3. Jika disabled → ubah menjadi enabled
4. Simpan ke repository

---

## Disable Flow

Ketika `ModuleManager::disable()` dipanggil:

```text id="life-dis"
enabled → disabled
```

Flow:

1. Validasi modul ada di registry
2. Cek state saat ini
3. Jika enabled → ubah menjadi disabled
4. Simpan ke repository

---

# Lifecycle Diagram End-to-End

```text id="life-diag"
[Filesystem]
     │
     ▼
[module.yaml exists?]
     │ yes
     ▼
[Discovery Process]
     │
     ▼
[ModuleRegistry]
     │
     ▼
[ModuleStateRepository]
     │
     ▼
[ModuleManager]
     │
     ├── enable()
     ├── disable()
     └── isEnabled()
     │
     ▼
[Runtime Application]
```

---

# Key Separation Rules

## 1. Metadata vs Runtime

| Aspect          | Location              |
| --------------- | --------------------- |
| Module identity | ModuleRegistry        |
| Module status   | ModuleStateRepository |

Tidak boleh dicampur.

---

## 2. Static vs Dynamic

- Discovery = static (filesystem)
- Runtime = dynamic (application state)

---

## 3. Immutable vs Mutable

- ModuleDefinition = immutable
- ModuleState = mutable

---

# Lifecycle Constraints

## Constraint 1 — No Runtime Discovery

Discovery hanya terjadi saat bootstrap.

Tidak boleh dilakukan ulang saat runtime.

---

## Constraint 2 — State Does Not Affect Registry

Enable/disable tidak mengubah registry.

Registry tetap sebagai sumber metadata.

---

## Constraint 3 — Registry Does Not Persist State

Registry tidak menyimpan status modul.

---

# Failure Scenarios

## Scenario 1 — Module Missing in Registry

Jika enable/disable dipanggil untuk modul yang tidak ada:

→ `ModuleNotFoundException`

---

## Scenario 2 — Corrupted State File

Jika `modules.json` rusak:

- fallback ke default disabled state
- log error
- tidak menghentikan bootstrap

---

## Scenario 3 — Invalid Manifest

Jika manifest gagal validasi:

- modul tidak masuk registry
- lifecycle berhenti di discovery phase

---

# Design Philosophy

Lifecycle ini dibangun berdasarkan:

- Clear separation between metadata & runtime
- Predictable state transitions
- Fail-safe boot process
- Idempotent operations
- Deterministic module behavior

---

# Related Documents

- `kernel.md`
- `folder-structure.md`
- `discovery-flow.md`
- `module-manager.md`

---

# Related ADR

- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Metadata Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-007 — ModuleManager as Kernel Facade
