# ADR-006 — Runtime Module State Repository

**Status** : Accepted

**Date** : 2026-07-01

**Sprint** : CORE-001

---

# Context

Setelah modul berhasil ditemukan dan metadata dimuat ke dalam `ModuleRegistry`, Kernel masih memerlukan informasi mengenai **status runtime** setiap modul.

Contohnya:

- Apakah modul aktif?
- Apakah modul dinonaktifkan?
- Apakah modul sedang dalam maintenance? (future)
- Apakah modul sudah terpasang? (future)

Pada tahap perancangan muncul pertanyaan apakah status tersebut sebaiknya disimpan di dalam `module.yaml` atau dipisahkan ke media penyimpanan khusus.

---

# Decision

EduCore memisahkan **metadata modul** dan **runtime state**.

Metadata tetap berada pada `ModuleRegistry`, sedangkan seluruh status runtime dikelola oleh `ModuleStateRepository`.

Pada Sprint CORE-001, runtime state disimpan dalam:

```text
storage/framework/modules.json
```

Seluruh akses terhadap status runtime harus melalui `ModuleStateRepository`.

Komponen lain tidak diperbolehkan membaca atau menulis file `modules.json` secara langsung.

---

# Rationale

Metadata dan runtime state memiliki karakteristik yang berbeda.

Metadata bersifat deklaratif, relatif tetap, dan berasal dari manifest modul.

Sebaliknya, runtime state bersifat dinamis dan dapat berubah selama aplikasi berjalan.

Dengan memisahkan keduanya:

- Manifest tetap menjadi kontrak statis.
- Runtime state dapat berubah tanpa mengubah manifest.
- Penyimpanan runtime dapat diganti tanpa memengaruhi komponen lain.
- Business rule tidak bergantung pada format penyimpanan.

Pendekatan ini juga membuka jalan bagi migrasi dari penyimpanan berbasis file ke database pada sprint berikutnya.

---

# Consequences

## Positive

- Metadata tetap bersifat immutable selama runtime.
- Runtime state dapat berubah secara independen.
- Pergantian media penyimpanan menjadi lebih mudah.
- Komponen lain tidak bergantung pada filesystem.

## Negative

- Membutuhkan satu komponen tambahan (`ModuleStateRepository`).
- Bootstrap memerlukan sinkronisasi antara metadata dan runtime state.

---

# Alternatives Considered

## Option A — Store Runtime State in `module.yaml`

Status seperti `enabled: true` disimpan langsung di manifest.

**Ditolak** karena:

- Manifest tidak lagi menjadi metadata murni.
- Mengubah status runtime berarti harus mengubah file manifest.
- Sulit diterapkan pada lingkungan produksi.
- Tidak mendukung penyimpanan terpusat.

---

## Option B — Store Runtime State in Database

Status runtime disimpan di tabel database.

**Ditolak untuk Sprint CORE-001** karena:

- Menambah kompleksitas implementasi awal.
- Membutuhkan migration dan bootstrap database lebih awal.

Pendekatan ini tetap menjadi kandidat kuat untuk evolusi platform.

---

## Option C — JSON Repository (**Dipilih**)

Runtime state disimpan dalam:

```text
storage/framework/modules.json
```

Pendekatan ini sederhana, mudah diimplementasikan, dan cukup untuk kebutuhan Sprint 1.

Yang terpenting, seluruh akses tetap melalui `ModuleStateRepository`, sehingga media penyimpanan dapat diganti di masa depan tanpa mengubah API.

---

# Responsibilities

`ModuleStateRepository` bertanggung jawab untuk:

- Membaca runtime state.
- Menyimpan runtime state.
- Mengaktifkan modul.
- Menonaktifkan modul.
- Menentukan apakah modul aktif.

Repository **tidak bertanggung jawab** untuk:

- Menjalankan business rule.
- Memvalidasi dependency.
- Menentukan apakah modul boleh diaktifkan.
- Menentukan apakah modul boleh dinonaktifkan.

Keputusan tersebut berada pada lapisan Kernel Domain.

---

# Current Implementation

Status implementasi pada Sprint CORE-001:

- ✅ ModuleStateRepository
- ✅ modules.json
- ✅ all()
- ✅ isEnabled()
- ✅ enable()
- ✅ disable()

Status runtime saat ini hanya terdiri dari nilai:

- `true`
- `false`

---

# Future Evolution

Media penyimpanan runtime dapat berubah tanpa memengaruhi komponen lain.

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
```

Selain itu, runtime state dapat berkembang dengan atribut tambahan seperti:

- Installed
- Maintenance
- Update Available
- Health Status
- Last Enabled At

Seluruh evolusi tersebut tetap menggunakan `ModuleStateRepository` sebagai abstraksi akses runtime state.

---

# References

- ADR-005 — Module Registry as Metadata Source of Truth
- ADR-007 — ModuleManager as Kernel Facade
- PRD CORE-001
- Sprint 001
