# ADR-010 — Module Identity Strategy

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-07-02
**Sprint** : CORE-001 Sprint-1

---

> **Decision Summary**
>
> `ModuleDefinition` diperlakukan sebagai immutable metadata object dan bukan Domain Entity. Identitas modul berasal dari field `name` pada `module.yaml`, sehingga Platform Kernel tidak menghasilkan identifier baru seperti UUID untuk metadata modul. UUID tetap digunakan untuk Domain Entity yang memiliki lifecycle operasional.

---

# Related ADR

- ADR-003 — Module Manifest Specification
- ADR-005 — Module Registry as Source of Truth
- ADR-009 — Separation of Infrastructure and Kernel Domain

---

# 1. Context

EduCore menggunakan pendekatan Modular Monolith, di mana setiap modul direpresentasikan oleh sebuah `module.yaml` yang berisi metadata modul.

Pada tahap awal perancangan sempat dipertimbangkan penggunaan UUID v7 sebagai identifier untuk `ModuleDefinition`.

Setelah dilakukan evaluasi terhadap lifecycle modul dan peran `ModuleDefinition` di dalam Platform Kernel, pendekatan tersebut dinilai tidak sesuai karena `ModuleDefinition` bukan merupakan Domain Entity.

Platform Kernel memerlukan strategi identitas yang sederhana, stabil, deterministik, dan konsisten dengan prinsip _Convention over Configuration_.

---

# 2. Decision

## ModuleDefinition merupakan Metadata Object

`ModuleDefinition` merepresentasikan metadata hasil parsing `module.yaml`.

Objek ini:

- immutable;
- readonly;
- tidak memiliki lifecycle create, update, maupun delete;
- tidak memiliki persistence sendiri;
- bukan Domain Entity.

`ModuleDefinition` hanya digunakan sebagai representasi metadata selama proses discovery dan runtime.

---

## Module Identity menggunakan `name`

Identitas modul berasal dari field `name` pada `module.yaml`.

Contoh:

```yaml
name: core
```

Field tersebut harus memenuhi karakteristik berikut.

- unik;
- lowercase;
- stabil;
- tidak berubah selama umur modul.

Nilai `name` digunakan sebagai:

- Registry Key;
- Dependency Identifier;
- Module Lookup;
- Runtime Identifier.

Platform Kernel tidak menghasilkan identifier tambahan untuk metadata modul.

---

## Display Name dipisahkan

Nama yang digunakan untuk kebutuhan presentasi dipisahkan dari identitas modul.

Contoh:

```yaml
display_name: Core
```

Field ini hanya digunakan oleh Presentation Layer dan dapat berubah tanpa memengaruhi identitas modul.

---

## UUID v7 tidak digunakan pada ModuleDefinition

UUID v7 tidak digunakan karena metadata tidak memiliki lifecycle sebagaimana Domain Entity.

UUID tetap digunakan pada Domain Entity yang memiliki identitas operasional, misalnya:

- User
- Student
- Teacher
- School
- Invoice
- Payment
- AuditLog
- Notification
- Job

Strategi identitas metadata dan Domain Entity merupakan dua konsep yang berbeda dan tidak boleh dicampurkan.

---

# 3. Rationale

Keputusan ini dipilih karena identitas modul telah tersedia secara alami pada metadata.

Pendekatan tersebut memberikan beberapa keuntungan.

- Discovery bersifat deterministik.
- Registry menjadi lebih sederhana.
- Dependency antar modul lebih mudah dipahami.
- Metadata tetap deklaratif.
- Tidak terdapat identifier artifisial.
- Selaras dengan prinsip Immutable Metadata.
- Selaras dengan prinsip Source of Truth.
- Selaras dengan Convention over Configuration.

Penggunaan UUID pada metadata tidak memberikan manfaat tambahan karena metadata tidak memiliki lifecycle maupun persistence sendiri.

---

# 4. Responsibilities

## ModuleDefinition

Bertanggung jawab untuk:

- merepresentasikan metadata modul;
- menyediakan informasi konfigurasi modul;
- menjadi sumber identitas metadata.

Tidak bertanggung jawab untuk:

- menghasilkan identifier;
- mengelola lifecycle;
- melakukan persistence;
- menyimpan runtime state.

---

## Platform Kernel

Bertanggung jawab menggunakan identitas metadata sebagai acuan untuk:

- Registry;
- Discovery;
- Dependency Resolution;
- Module Lookup;
- Runtime Operations.

---

# 5. Architectural Rules

Seluruh implementasi Platform Kernel harus mengikuti aturan berikut.

- Identitas modul hanya berasal dari `module.yaml`.
- `ModuleDefinition` tidak boleh menghasilkan identifier baru.
- Metadata harus bersifat immutable.
- Runtime state dipisahkan dari metadata.
- Domain Entity menggunakan strategi identitasnya sendiri.
- Metadata dan Domain Entity tidak boleh berbagi konsep identity.

---

# 6. Consequences

## Positive

- Identitas modul stabil.
- Discovery bersifat deterministik.
- Registry menjadi sederhana.
- Dependency lebih mudah dipahami.
- Metadata lebih mudah dibaca.
- Tidak terdapat identifier yang dibuat secara artifisial.

## Negative

Apabila di masa mendatang metadata modul dikelola melalui proses CRUD dan persistence, maka diperlukan Domain Entity baru yang berbeda dengan `ModuleDefinition`.

Dengan demikian, metadata discovery dan persistence tetap memiliki tanggung jawab yang terpisah.

---

# 7. Alternatives Considered

## Option A — UUID untuk seluruh ModuleDefinition

Setiap metadata modul memperoleh UUID v7.

**Rejected**, karena:

- metadata bukan Domain Entity;
- menambah kompleksitas tanpa manfaat;
- menghasilkan identifier yang tidak memiliki makna operasional.

---

## Option B — Identity berasal dari Metadata (**Accepted**)

Field `name` pada `module.yaml` menjadi identitas tunggal modul.

Pendekatan ini sederhana, deterministik, dan konsisten dengan prinsip Convention over Configuration.

---

# 8. Architecture / Identity Flow

```text
module.yaml
      │
      ▼
 ModuleDefinition
      │
      ▼
 ModuleRegistry
      │
      ▼
Platform Kernel
```

Identitas modul berasal dari metadata dan dipertahankan sepanjang lifecycle discovery maupun runtime.

---

# 9. Current Implementation

## Implemented Components

- ModuleManifestParser
- ManifestValidator
- ModuleDefinitionFactory
- ModuleDefinition
- ModuleRegistry

## Implemented Capabilities

- Identitas modul berasal dari field `name`.
- Registry menggunakan `name` sebagai key.
- Dependency menggunakan `name`.
- Lookup menggunakan `name`.
- Tidak terdapat UUID pada metadata modul.

---

# 10. Impact

Keputusan ini menyederhanakan model identitas Platform Kernel dengan memanfaatkan identitas yang telah tersedia pada metadata.

Platform Kernel tidak perlu mengelola lifecycle identifier untuk metadata sehingga proses discovery tetap deterministik dan konsisten.

Pendekatan ini juga membedakan secara tegas antara metadata platform dan Domain Entity, sehingga evolusi domain bisnis di masa mendatang tidak memengaruhi mekanisme discovery modul.

---

# 11. Future Evolution

Apabila di masa mendatang EduCore menyediakan Module Marketplace, Module Repository, atau layanan distribusi modul, identitas metadata tetap berasal dari `module.yaml`.

Apabila diperlukan proses persistence terhadap informasi modul, Platform Kernel akan memperkenalkan Domain Entity baru yang memiliki lifecycle dan strategi identitas tersendiri.

Prinsip bahwa metadata memperoleh identitas dari sumber metadata merupakan keputusan arsitektur permanen dan tidak berubah selama Platform Kernel mempertahankan pendekatan Modular Monolith.

---

# 12. References

- PRD CORE-001
- Sprint CORE-001
- ADR-003 — Module Manifest Specification
- ADR-005 — Module Registry as Source of Truth
- ADR-009 — Separation of Infrastructure and Kernel Domain
- `docs/architecture/architecture-principles.md`
- `docs/architecture/discovery-flow.md`
- `docs/architecture/module-lifecycle.md`
