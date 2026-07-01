# Module Discovery Flow

## Overview

Module Discovery merupakan proses otomatis yang dilakukan oleh Platform Kernel untuk menemukan, memvalidasi, dan mendaftarkan seluruh modul yang tersedia ke dalam `ModuleRegistry`.

Proses ini dijalankan setiap kali aplikasi melakukan bootstrap melalui `CoreServiceProvider`.

Discovery menggunakan pendekatan **Convention over Configuration**, sehingga modul tidak memerlukan registrasi manual.

---

# Discovery Objectives

Proses discovery memiliki tujuan sebagai berikut:

- Menemukan seluruh modul yang tersedia.
- Membaca manifest setiap modul.
- Memvalidasi spesifikasi manifest.
- Membangun metadata modul.
- Mendaftarkan metadata ke `ModuleRegistry`.
- Menyiapkan metadata agar dapat digunakan oleh Platform Kernel.

Discovery **tidak** bertanggung jawab terhadap status aktif/nonaktif modul. Status runtime dikelola oleh `ModuleStateRepository`.

---

# Discovery Pipeline

Seluruh proses discovery mengikuti pipeline berikut.

```text
Modules/
    │
    ▼
ModuleDiscovery
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
```

Setiap tahapan memiliki tanggung jawab yang berbeda dan tidak saling tumpang tindih.

---

# Discovery Sequence

## Step 1 — Scan Module Directories

`ModuleDiscovery` memindai seluruh subdirektori pada folder:

```text
Modules/
```

Setiap direktori dianggap sebagai kandidat modul.

Contoh:

```text
Modules/
├── Core/
├── Academic/
├── PPDB/
└── Finance/
```

Jika suatu direktori tidak mengandung `module.yaml`, direktori tersebut diabaikan.

---

## Step 2 — Read Manifest

Untuk setiap modul yang ditemukan, `ModuleManifestParser` membaca file:

```text
module.yaml
```

Parser menggunakan Symfony YAML untuk mengubah isi manifest menjadi struktur data yang dapat diproses oleh kernel.

Parser hanya bertugas membaca data, bukan memvalidasi isinya.

---

## Step 3 — Validate Manifest

Data hasil parsing diteruskan ke `ManifestValidator`.

Validator memastikan bahwa manifest memenuhi spesifikasi yang telah ditetapkan, misalnya:

- field wajib tersedia,
- format versi valid,
- provider terdefinisi,
- dependency memiliki format yang benar.

Apabila validasi gagal, proses discovery untuk modul tersebut dihentikan dan exception yang sesuai akan dihasilkan.

---

## Step 4 — Build Module Definition

Manifest yang telah lolos validasi diteruskan ke `ModuleDefinitionFactory`.

Factory membangun objek `ModuleDefinition` yang menjadi representasi metadata modul di dalam Platform Kernel.

Contoh atribut metadata:

- name
- version
- provider
- description
- dependencies

Objek ini menjadi bentuk standar metadata yang digunakan oleh seluruh komponen kernel.

---

## Step 5 — Register Metadata

`ModuleDefinition` didaftarkan ke `ModuleRegistry`.

Registry menjadi **Single Source of Truth** untuk seluruh metadata modul selama aplikasi berjalan.

Setelah tahap ini selesai, metadata modul siap digunakan oleh komponen lain.

---

# Sequence Diagram

```text
CoreServiceProvider
        │
        ▼
 ModuleLoader
        │
        ▼
 ModuleDiscovery
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
```

---

# Error Handling

Discovery menggunakan pendekatan **fail fast**.

Kesalahan pada sebuah modul harus segera terdeteksi dan dilaporkan sedekat mungkin dengan sumber masalahnya.

Contoh kondisi yang dapat menyebabkan kegagalan:

- `module.yaml` tidak ditemukan.
- Format YAML tidak valid.
- Field wajib tidak tersedia.
- Nama modul kosong.
- Provider tidak valid.
- Dependency memiliki format yang tidak sesuai.

Exception yang spesifik mempermudah proses debugging dan meningkatkan kualitas pesan kesalahan.

---

# Separation of Responsibilities

Setiap komponen hanya memiliki satu tanggung jawab utama.

| Component               | Responsibility            |
| ----------------------- | ------------------------- |
| ModuleDiscovery         | Menemukan direktori modul |
| ModuleManifestParser    | Membaca `module.yaml`     |
| ManifestValidator       | Memvalidasi isi manifest  |
| ModuleDefinitionFactory | Membangun objek metadata  |
| ModuleRegistry          | Menyimpan metadata modul  |

Tidak ada komponen yang menangani lebih dari satu tanggung jawab utama.

---

# Discovery vs Runtime

Discovery dan runtime merupakan dua konsep yang berbeda.

## Discovery

Menghasilkan metadata modul.

Sumber data:

```text
module.yaml
```

Output:

```text
ModuleRegistry
```

---

## Runtime

Mengelola status aktif atau nonaktif modul.

Sumber data:

```text
storage/framework/modules.json
```

Output:

```text
ModuleStateRepository
```

Pemisahan ini memastikan metadata modul tidak berubah akibat perubahan status runtime.

---

# Design Principles

Proses discovery dibangun berdasarkan prinsip berikut:

- Convention over Configuration
- Single Responsibility Principle
- Fail Fast
- Separation of Concerns
- Immutable Metadata
- Single Source of Truth

---

# Related Documents

- `kernel.md`
- `folder-structure.md`
- `module-manager.md`
- `module-lifecycle.md`

---

# Related ADR

- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Metadata Source of Truth
- ADR-006 — Runtime Module State Repository
