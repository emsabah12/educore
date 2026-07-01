# ADR-008 — Thin Command Pattern

**Status** : Accepted

**Date** : 2026-07-01

**Sprint** : CORE-001

---

# Context

EduCore menyediakan antarmuka Command Line Interface (CLI) untuk menjalankan operasi terhadap Kernel, seperti:

- module:list
- module:status
- module:enable
- module:disable

Tanpa aturan yang jelas, terdapat risiko business rule berkembang di dalam command sehingga setiap command menjadi sulit dipelihara dan sulit diuji.

Kernel membutuhkan pemisahan yang jelas antara antarmuka pengguna dan logika platform.

---

# Decision

Seluruh Artisan Command pada EduCore mengikuti **Thin Command Pattern**.

Command hanya bertanggung jawab untuk:

- Menerima input pengguna.
- Memanggil service yang sesuai.
- Menampilkan hasil.
- Mengembalikan exit code.

Command **tidak boleh** berisi business rule.

Seluruh logika platform harus ditempatkan pada komponen Kernel seperti `ModuleManager`.

---

# Rationale

Pendekatan ini dipilih karena memberikan pemisahan tanggung jawab yang jelas.

Dengan menjaga command tetap tipis:

- Business rule hanya berada pada satu tempat.
- Command menjadi mudah dipahami.
- Pengujian menjadi lebih sederhana.
- Perubahan antarmuka CLI tidak memengaruhi Kernel.

Pendekatan ini juga memungkinkan logika yang sama digunakan kembali oleh antarmuka lain seperti HTTP Controller, REST API, Scheduler, maupun Queue Worker.

---

# Consequences

## Positive

- Command lebih kecil dan mudah dibaca.
- Business rule tidak tersebar.
- Pengujian lebih mudah.
- Reusability meningkat.
- Perubahan UI tidak memengaruhi Kernel.

## Negative

- Membutuhkan lapisan service tambahan.
- Membutuhkan disiplin agar command tetap sederhana.

---

# Alternatives Considered

## Option A — Fat Command

Business rule ditempatkan langsung pada command.

**Ditolak** karena:

- Sulit diuji.
- Sulit digunakan ulang.
- Menyebabkan duplikasi logika.

---

## Option B — Thin Command (**Dipilih**)

Command hanya menjadi adapter antara CLI dan Kernel.

Business rule ditempatkan pada `ModuleManager` atau service Kernel lainnya.

---

# Responsibilities

Command bertanggung jawab untuk:

- Membaca argument.
- Membaca option.
- Memanggil service.
- Menampilkan output.
- Mengembalikan exit code.

Command **tidak bertanggung jawab** untuk:

- Validasi dependency.
- Membaca filesystem.
- Mengubah runtime state secara langsung.
- Menjalankan business rule.
- Mengakses repository secara langsung.

---

# Current Implementation

Status implementasi pada Sprint CORE-001:

- ✅ kernel:test-loader
- ✅ module:list
- ✅ module:status
- ✅ module:enable
- ✅ module:disable

Seluruh command menggunakan service Kernel sebagai pusat logika.

---

# Future Evolution

Pada sprint berikutnya, antarmuka baru seperti:

- REST API
- Web Dashboard
- Queue Worker
- Scheduler
- GraphQL

akan menggunakan service Kernel yang sama.

Dengan demikian, perubahan business rule hanya dilakukan pada satu tempat tanpa memengaruhi antarmuka pengguna.

---

# References

- ADR-007 — ModuleManager as Kernel Facade
- ADR-009 — Separation of Infrastructure and Kernel Domain
- PRD CORE-001
- Sprint 001
