# EduCore Platform Architecture

Version : 1.0
Status : Locked
Updated : 2026-07-02
Sprint : CORE-001 Sprint-1

## Related ADR

- ADR-001 — Kernel Architecture Overview
- ADR-002 — Modular Monolith Architecture
- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-007 — Module Manager as Kernel Facade
- ADR-008 — Thin Command Pattern
- ADR-009 — Separation of Infrastructure and Kernel Domain
- ADR-010 — Module Identity Strategy

---

# EduCore Platform Architecture

Selamat datang di dokumentasi arsitektur **EduCore Platform Kernel**.

Dokumentasi ini merupakan referensi resmi mengenai desain arsitektur platform, keputusan teknis yang telah disepakati, serta hubungan antar komponen di dalam Kernel. Seluruh keputusan yang terdokumentasi pada Sprint **CORE-001** berstatus **Locked** dan menjadi acuan implementasi berikutnya.

---

# Tujuan

Dokumentasi ini bertujuan untuk:

- Menjelaskan arsitektur Kernel EduCore.
- Menjadi referensi utama bagi seluruh developer.
- Menjelaskan tanggung jawab setiap komponen utama.
- Mendokumentasikan seluruh keputusan arsitektur melalui Architecture Decision Records (ADR).
- Menjaga konsistensi implementasi antar sprint.
- Menjadi dasar pengembangan fitur pada sprint berikutnya.

---

# Gambaran Arsitektur

EduCore dibangun menggunakan pendekatan **Modular Monolith** dengan prinsip **Clean Architecture**.

Setiap modul didefinisikan melalui _Module Manifest_ dan ditemukan secara otomatis saat proses bootstrap aplikasi.

Discovery Pipeline yang digunakan adalah:

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
ModuleManager
      │
      ▼
Runtime
```

Pada alur tersebut:

- **Module Manifest** merupakan metadata statis setiap modul.
- **ModuleRegistry** menjadi _Source of Truth_ seluruh metadata modul.
- **ModuleManager** bertindak sebagai façade untuk operasi Kernel.
- **Runtime State** dipisahkan dari metadata sehingga status modul dapat berubah tanpa mengubah definisi modul.
- **ModuleDefinition** merupakan immutable metadata object yang hanya dibuat melalui `ModuleDefinitionFactory`.

---

# Struktur Dokumentasi

## Architecture Overview

Dokumen utama yang menjelaskan struktur keseluruhan dokumentasi.

```
README.md
```

---

## Kernel

Menjelaskan filosofi, tanggung jawab, dan batasan EduCore Platform Kernel.

```
kernel.md
```

---

## Folder Structure

Menjelaskan struktur folder Kernel beserta alasan desainnya.

```
folder-structure.md
```

---

## Discovery Flow

Menjelaskan proses otomatis penemuan modul mulai dari filesystem hingga registry.

```
discovery-flow.md
```

---

## Module Manager

Menjelaskan peran `ModuleManager` sebagai Kernel Facade.

```
module-manager.md
```

---

## Module Lifecycle

Menjelaskan siklus hidup modul dari proses discovery hingga runtime.

```
module-lifecycle.md
```

---

## Architecture Principles

Menjelaskan prinsip-prinsip arsitektur yang menjadi pedoman pengembangan platform.

```
architecture-principles.md
```

---

## Architecture Decision Records (ADR)

Berisi seluruh keputusan arsitektur yang telah disetujui dan berstatus **Locked**.

```
docs/architecture/adr/
```

---

# Dokumentasi Proyek

Selain dokumentasi arsitektur, proyek juga memiliki dokumentasi berikut:

```
docs/prd/
```

Product Requirement Documents.

```
docs/sprint/
```

Sprint Planning dan Sprint Progress.

---

# Prinsip Arsitektur

EduCore Platform mengikuti prinsip-prinsip berikut:

- Clean Architecture
- Modular Monolith
- Separation of Concerns
- Single Responsibility Principle (SRP)
- Fail Fast
- Immutable Metadata
- Registry as Source of Truth
- Runtime State Separation
- Thin Command Pattern
- Infrastructure ≠ Kernel Domain

Seluruh implementasi baru harus mengikuti prinsip-prinsip tersebut.

---

# Status Dokumentasi

Seluruh dokumentasi pada Sprint **CORE-001** telah diselaraskan dengan implementasi Kernel dan keputusan arsitektur yang terdokumentasi pada ADR-001 hingga ADR-010.

Perubahan terhadap arsitektur inti tidak dilakukan secara langsung. Setiap perubahan harus melalui Architecture Decision Record (ADR) baru sebelum diimplementasikan.
