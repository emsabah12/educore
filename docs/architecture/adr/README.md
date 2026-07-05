# Architecture Decision Records (ADR)

**Version** : 2.0
**Status** : Locked
**Updated** : 2026-07-03
**Sprint** : CORE-001 Sprint-1

---

> **Decision Summary**
>
> Folder ini berisi seluruh **Architecture Decision Record (ADR)** untuk EduCore Platform. Setiap ADR mendokumentasikan keputusan arsitektur yang telah disepakati, alasan di balik keputusan tersebut, serta dampaknya terhadap evolusi Platform Kernel. Seluruh implementasi harus mengikuti keputusan yang telah ditetapkan oleh ADR yang berstatus **Accepted**.

---

# Purpose

Architecture Decision Record (ADR) digunakan untuk mendokumentasikan **keputusan arsitektur** yang menjadi dasar pengembangan EduCore Platform.

ADR menjelaskan:

- **Mengapa** suatu keputusan diambil.
- **Masalah** yang ingin diselesaikan.
- **Alternatif** yang dipertimbangkan.
- **Konsekuensi** dari keputusan tersebut.

ADR **tidak menjelaskan implementasi secara rinci**. Detail implementasi dapat berkembang seiring waktu selama tetap mematuhi keputusan arsitektur yang telah ditetapkan.

---

# Workflow

Seluruh perubahan arsitektur mengikuti workflow resmi proyek.

```text
Product Requirements Document (PRD)
                │
                ▼
Architecture Decision Record (ADR)
                │
                ▼
Implementation
                │
                ▼
Testing
                │
                ▼
Review
                │
                ▼
LOCK
```

Perubahan implementasi tidak boleh mendahului keputusan arsitektur.

---

# ADR Lifecycle

Setiap ADR memiliki siklus hidup berikut.

```text
Proposed
     │
     ▼
Review
     │
     ▼
Accepted
     │
     ├────────────► Deprecated
     │
     ▼
Superseded
```

| Status         | Description                                                                          |
| -------------- | ------------------------------------------------------------------------------------ |
| **Proposed**   | Keputusan masih dalam tahap pembahasan.                                              |
| **Accepted**   | Keputusan telah disetujui dan menjadi acuan implementasi.                            |
| **Superseded** | Keputusan telah digantikan oleh ADR yang lebih baru.                                 |
| **Deprecated** | Keputusan tidak lagi digunakan, tetapi tetap dipertahankan sebagai catatan historis. |

---

# Reading Order

ADR disusun secara berurutan karena setiap keputusan dibangun di atas keputusan sebelumnya.

```text
ADR-001
      │
      ▼
ADR-002
      │
      ▼
ADR-003
      │
      ▼
ADR-004
      │
      ▼
ADR-005
      │
      ▼
ADR-006
      │
      ▼
ADR-007
      │
      ▼
ADR-008
      │
      ▼
ADR-009
      │
      ▼
ADR-010
```

Developer baru disarankan membaca ADR sesuai urutan tersebut.

---

# Architecture Decision Tree

```text
Platform Architecture
│
├── ADR-001
│     Kernel Architecture Overview
│
├── ADR-002
│     Modular Monolith Architecture
│
├── Module System
│     ├── ADR-003 Module Manifest Specification
│     ├── ADR-004 Automatic Module Discovery
│     ├── ADR-005 Module Registry as Source of Truth
│     └── ADR-006 Runtime Module State Repository
│
├── Platform Kernel
│     ├── ADR-007 ModuleManager as Kernel Facade
│     ├── ADR-008 Thin Command Pattern
│     └── ADR-009 Separation of Infrastructure and Kernel Domain
│
└── Module Identity
      └── ADR-010 Module Identity Strategy
```

Diagram ini menggambarkan hubungan konseptual antar keputusan arsitektur.

---

# ADR Index

| ADR     | Title                                          | Status   |
| ------- | ---------------------------------------------- | -------- |
| ADR-001 | Kernel Architecture Overview                   | Accepted |
| ADR-002 | Modular Monolith Architecture                  | Accepted |
| ADR-003 | Module Manifest Specification                  | Accepted |
| ADR-004 | Automatic Module Discovery                     | Accepted |
| ADR-005 | Module Registry as Source of Truth             | Accepted |
| ADR-006 | Runtime Module State Repository                | Accepted |
| ADR-007 | ModuleManager as Kernel Facade                 | Accepted |
| ADR-008 | Thin Command Pattern                           | Accepted |
| ADR-009 | Separation of Infrastructure and Kernel Domain | Accepted |
| ADR-010 | Module Identity Strategy                       | Accepted |

---

# ADR Categories

| Category              | ADR                                |
| --------------------- | ---------------------------------- |
| Platform Architecture | ADR-001, ADR-002                   |
| Module System         | ADR-003, ADR-004, ADR-005, ADR-006 |
| Platform Kernel       | ADR-007                            |
| Application Layer     | ADR-008                            |
| Layered Architecture  | ADR-009                            |
| Domain Modeling       | ADR-010                            |

---

# ADR Template

Setiap ADR harus mengikuti struktur berikut.

1. Decision Summary
2. Related ADR
3. Context
4. Decision
5. Rationale
6. Responsibilities
7. Architectural Rules
8. Consequences
9. Alternatives Considered
10. Architecture / Dependency Flow
11. Current Implementation
12. Impact
13. Future Evolution
14. References

Struktur ini memastikan seluruh keputusan terdokumentasi secara konsisten dan mudah ditelusuri.

---

# Architectural Vocabulary

Istilah berikut digunakan secara konsisten pada seluruh dokumentasi arsitektur.

| Term                 | Description                                      |
| -------------------- | ------------------------------------------------ |
| Platform Kernel      | Pusat orkestrasi layanan platform.               |
| Kernel Facade        | Antarmuka publik Platform Kernel.                |
| Module Lifecycle     | Siklus hidup modul selama discovery dan runtime. |
| Discovery Pipeline   | Proses menemukan dan memuat metadata modul.      |
| Metadata             | Informasi deklaratif yang bersifat immutable.    |
| Runtime State        | Status dinamis modul saat aplikasi berjalan.     |
| Source of Truth      | Sumber data utama yang menjadi acuan sistem.     |
| Presentation Layer   | Lapisan antarmuka pengguna.                      |
| Application Layer    | Lapisan yang mengorkestrasi use case aplikasi.   |
| Infrastructure Layer | Lapisan yang mengimplementasikan detail teknis.  |

---

# Glossary

| Term              | Meaning                                                                    |
| ----------------- | -------------------------------------------------------------------------- |
| Module            | Komponen modular yang dapat ditemukan dan dijalankan oleh Platform Kernel. |
| Manifest          | Metadata deklaratif yang disimpan pada `module.yaml`.                      |
| Module Definition | Representasi immutable dari metadata modul.                                |
| Registry          | Penyimpanan metadata modul yang telah ditemukan.                           |
| Discovery         | Proses menemukan dan memuat modul dari filesystem.                         |
| Runtime State     | Status aktif/nonaktif modul saat runtime.                                  |
| Kernel            | Pusat orkestrasi Platform.                                                 |

---

# When to Create a New ADR

ADR baru **harus dibuat** apabila terjadi perubahan pada:

- Arsitektur sistem.
- Dependency antar komponen.
- Architectural Pattern.
- Public Contract.
- Layering.
- Module Lifecycle.
- Identity Strategy.
- Discovery Strategy.
- Runtime Strategy.

ADR **tidak diperlukan** untuk:

- Refactoring internal.
- Rename class.
- Rename method.
- Perubahan implementasi.
- Optimasi performa.
- Bug fixing.
- Penambahan unit test.

---

# Maintenance Rules

ADR merupakan catatan historis keputusan arsitektur.

Oleh karena itu:

- ADR tidak diubah hanya karena implementasi mengalami refactoring.
- Perubahan implementasi yang tetap mematuhi keputusan arsitektur cukup diperbarui pada dokumentasi teknis.
- Perubahan keputusan arsitektur harus dibuat sebagai ADR baru.
- ADR lama tidak dihapus.
- ADR yang tidak lagi menjadi acuan diubah statusnya menjadi **Superseded**.
- Seluruh ADR harus tetap dapat ditelusuri sebagai bagian dari sejarah evolusi arsitektur EduCore.

---

# References

- Product Requirements Document (PRD)
- Architecture Decision Records (ADR-001 s.d. ADR-010)
- `docs/architecture/architecture-principles.md` _(planned)_
- Sprint CORE-001 Documentation
