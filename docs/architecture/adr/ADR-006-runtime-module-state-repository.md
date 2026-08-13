# ADR-006 — Runtime Module State Repository

**Version** : 1.2
**Status** : Superseded
**Date** : 2026-07-01
**Updated** : 2026-08-13

---

> ## Superseded — 2026-08-13
> **Replacement:** ADR-017 — Module Runtime & Bootstrap Contract.
>
> `ModuleStateRepository`, `storage/framework/modules.json`, `module:enable`, and `module:disable` were removed during Module Kernel Runtime Hardening. EduCore no longer persists module bootstrap activation state. A physically present module with a valid manifest and a dependency-closed graph participates in application bootstrap; tenant feature availability is a separate concern and must not be modeled as module runtime enable/disable state.
>
> The body below is retained as historical decision context only and is **not** a current implementation contract.

# Context

Setelah Discovery Pipeline selesai memproses seluruh modul, metadata setiap modul diregistrasikan ke dalam `ModuleRegistry` sebagai **Source of Truth** untuk informasi statis modul.

Namun Platform Kernel juga memerlukan informasi yang bersifat dinamis selama aplikasi berjalan, seperti status aktif atau nonaktif suatu modul.

Contohnya:

- Apakah modul aktif?
- Apakah modul dinonaktifkan?
- Apakah modul berada pada mode maintenance? (future)
- Apakah modul telah terpasang? (future)

Karakteristik runtime state berbeda dengan metadata yang berasal dari `module.yaml`.

Metadata bersifat deklaratif, relatif tetap selama proses bootstrap, sedangkan runtime state dapat berubah sewaktu-waktu tanpa mengubah definisi modul.

Pada tahap perancangan muncul pertanyaan apakah informasi tersebut sebaiknya disimpan di dalam `module.yaml`, digabungkan ke `ModuleRegistry`, atau dipisahkan ke media penyimpanan khusus.

---

# Decision

EduCore memisahkan **Module Metadata** dan **Runtime Module State** sebagai dua tanggung jawab yang berbeda.

Metadata setiap modul disimpan di dalam `ModuleRegistry` sebagai **Source of Truth** untuk informasi statis modul selama runtime.

Runtime Module State dikelola secara terpisah oleh `ModuleStateRepository`.

Pada Sprint CORE-001, runtime state disimpan pada:

```text
storage/framework/modules.json
```

Seluruh akses terhadap runtime state harus melalui `ModuleStateRepository`.

Komponen lain tidak diperbolehkan membaca ataupun menulis `modules.json` secara langsung.

`ModuleRegistry` tidak menyimpan maupun mengubah runtime state.

Sebaliknya, `ModuleStateRepository` tidak bertanggung jawab mengelola metadata modul.

---

# Rationale

Metadata dan runtime state memiliki karakteristik yang berbeda.

Metadata bersifat deklaratif, relatif tetap, dan berasal dari `module.yaml`.

Sebaliknya, runtime state bersifat dinamis dan dapat berubah selama aplikasi berjalan.

Dengan memisahkan keduanya:

- Manifest tetap menjadi kontrak statis.
- Metadata tetap immutable selama runtime.
- Runtime state dapat berubah tanpa memengaruhi metadata.
- Media penyimpanan runtime dapat diganti tanpa mengubah komponen lain.
- Business rule tidak bergantung pada mekanisme persistence.

Pendekatan ini juga membuka jalan bagi migrasi dari penyimpanan berbasis file ke media penyimpanan lain pada sprint berikutnya.

Pemisahan antara `ModuleRegistry` dan `ModuleStateRepository` juga memperjelas batas tanggung jawab antar komponen Kernel.

`ModuleRegistry` hanya menyediakan metadata yang bersifat statis selama runtime, sedangkan `ModuleStateRepository` hanya bertanggung jawab menyediakan dan menyimpan runtime state yang dapat berubah sewaktu-waktu.

Dengan batas tanggung jawab tersebut, perubahan pada runtime state tidak pernah memengaruhi metadata modul, dan perubahan metadata tidak memerlukan modifikasi pada mekanisme penyimpanan runtime state.

Keputusan ini mendukung penerapan prinsip Single Responsibility Principle (SRP) dan Separation of Concerns (SoC), karena setiap komponen memiliki satu tanggung jawab yang jelas.

## Selain itu, abstraksi melalui `ModuleStateRepository` memungkinkan mekanisme persistence berkembang dari penyimpanan berbasis file menjadi database atau layanan konfigurasi terdistribusi tanpa mengubah komponen Kernel lainnya.

# Consequences

## Positive

- Metadata tetap immutable selama runtime.
- Runtime state dapat berubah secara independen.
- Pergantian media penyimpanan menjadi lebih mudah.
- Komponen lain tidak bergantung pada implementasi persistence.
- Separation of Concerns menjadi lebih jelas.
- `ModuleRegistry` tetap menjadi Source of Truth untuk metadata.
- `ModuleStateRepository` hanya berfokus pada runtime state.
- Business rule tetap terpusat pada `ModuleManager`.
- Evolusi media penyimpanan tidak mengubah API publik Kernel.
- Testing terhadap metadata dan runtime state dapat dilakukan secara terpisah.

## Negative

- Membutuhkan komponen tambahan (`ModuleStateRepository`).
- Bootstrap memerlukan sinkronisasi antara metadata dan runtime state.
- Membutuhkan mekanisme persistence khusus untuk runtime state.
- Testing memerlukan verifikasi konsistensi antara metadata dan runtime state.

---

# Alternatives Considered

## Option A — Store Runtime State in `module.yaml`

Status seperti `enabled: true` disimpan langsung pada manifest.

**Rejected**, karena:

- Manifest tidak lagi menjadi metadata murni.
- Mengubah runtime state berarti mengubah manifest.
- Tidak sesuai untuk lingkungan produksi.
- Tidak mendukung penyimpanan terpusat.

---

## Option B — Store Runtime State in Database

Runtime state disimpan pada tabel database.

**Rejected untuk Sprint CORE-001**, karena:

- Menambah kompleksitas implementasi awal.
- Membutuhkan migration serta bootstrap database.

Pendekatan ini tetap menjadi kandidat untuk evolusi Platform Kernel.

---

## Option C — JSON Repository (**Accepted**)

Runtime state disimpan pada:

```text id="mstate-json"
storage/framework/modules.json
```

Pendekatan ini sederhana, mudah diimplementasikan, dan memadai untuk kebutuhan Sprint CORE-001.

Yang terpenting, seluruh akses tetap dilakukan melalui `ModuleStateRepository`, sehingga media penyimpanan dapat diganti tanpa mengubah API publik.

---

# Responsibilities

`ModuleStateRepository` bertanggung jawab untuk:

- Membaca runtime state.
- Menyimpan runtime state.
- Mengaktifkan modul.
- Menonaktifkan modul.
- Menentukan apakah modul aktif.
- Menyediakan abstraksi terhadap media penyimpanan runtime state.
- Menjamin konsistensi penyimpanan runtime state.

`ModuleStateRepository` **tidak bertanggung jawab** untuk:

- Menjalankan business rule.
- Memvalidasi dependency.
- Menentukan apakah modul boleh diaktifkan.
- Menentukan apakah modul boleh dinonaktifkan.
- Melakukan discovery modul.
- Mengelola metadata modul.
- Mengelola lifecycle bootstrap Kernel.
- Mengorkestrasi interaksi antara metadata dan runtime state.

Seluruh business rule berada pada `ModuleManager`.

---

# Architectural Rules

Platform Kernel menerapkan aturan berikut:

- `ModuleRegistry` hanya menyimpan metadata modul yang bersifat statis selama runtime.
- `ModuleStateRepository` hanya menyimpan runtime state modul.
- Runtime state tidak boleh disimpan di dalam `module.yaml`.
- Runtime state tidak boleh menjadi bagian dari `ModuleDefinition`.
- Seluruh akses terhadap runtime state harus melalui `ModuleStateRepository`.
- Komponen lain tidak diperbolehkan membaca maupun menulis media penyimpanan runtime state secara langsung.
- `ModuleStateRepository` tidak boleh mengandung business rule maupun logika orchestration.
- Seluruh business rule yang melibatkan metadata dan runtime state berada pada `ModuleManager`.

# Current Implementation

Status implementasi pada akhir Sprint CORE-001:

## Implemented Components

- ✅ `ModuleStateRepository`
- ✅ JSON-based runtime state persistence
- ✅ Runtime state abstraction
- ✅ Integration with `ModuleManager`

## Implemented Capabilities

- ✅ Membaca seluruh runtime state modul.
- ✅ Menentukan status aktif modul.
- ✅ Mengaktifkan modul.
- ✅ Menonaktifkan modul.
- ✅ Menyimpan perubahan runtime state.

Runtime state saat ini hanya memiliki dua nilai:

- `enabled`
- `disabled`

Seluruh akses terhadap runtime state dilakukan melalui `ModuleStateRepository`.

---

# Impact

## Production Code

Perubahan pada ADR ini berdampak langsung pada komponen berikut:

- `ModuleStateRepository`
- `ModuleManager`

Perubahan juga dapat memengaruhi integrasi dengan:

- `ModuleRegistry`
- `ModuleLoader`

Selama kontrak `ModuleStateRepository` tetap dipertahankan, perubahan media penyimpanan runtime state tidak memengaruhi komponen lain pada Platform Kernel.

---

## Testing

Perubahan keputusan ini memerlukan penyesuaian pada unit test yang berkaitan dengan:

- `ModuleStateRepositoryTest`
- `ModuleManagerTest`

Testing harus memverifikasi bahwa:

- Metadata tetap berasal dari `ModuleRegistry`.
- Runtime state hanya berasal dari `ModuleStateRepository`.
- Business rule tidak berpindah ke repository.
- Seluruh akses runtime state dilakukan melalui `ModuleStateRepository`.

---

## Documentation

Perubahan keputusan ini harus tetap konsisten dengan dokumen berikut:

- ADR-005 — Module Registry as Source of Truth
- ADR-007 — ModuleManager as Kernel Facade
- ADR-009 — Separation of Infrastructure and Kernel Domain

---

## Future Sprint

Keputusan ini menjadi fondasi bagi evolusi penyimpanan runtime state, seperti:

- SQLite
- PostgreSQL
- Distributed Configuration Service
- Cloud Configuration Provider

Seluruh evolusi tersebut harus mempertahankan `ModuleStateRepository` sebagai abstraksi akses runtime state.

# Future Evolution

Media penyimpanan runtime state dapat berkembang tanpa memengaruhi kontrak publik Platform Kernel.

Contoh evolusi:

```text
modules.json
      │
      ▼
SQLite
      │
      ▼
PostgreSQL
      │
      ▼
Distributed Configuration Service
      │
      ▼
Cloud Configuration Provider
```

Runtime state juga dapat berkembang dengan atribut tambahan seperti:

- Installed
- Maintenance
- Update Available
- Health Status
- Last Enabled At
- Last Disabled At
- Installed At

Seluruh evolusi tersebut harus tetap mempertahankan:

- `ModuleRegistry` sebagai Source of Truth untuk metadata.
- `ModuleStateRepository` sebagai abstraksi runtime state.
- `ModuleManager` sebagai pusat business rule.

---

# References

- PRD CORE-001
- Sprint CORE-001
- `docs/architecture/module-lifecycle.md`
- ADR-005 — Module Registry as Source of Truth
- ADR-007 — ModuleManager as Kernel Facade
- ADR-009 — Separation of Infrastructure and Kernel Domain
