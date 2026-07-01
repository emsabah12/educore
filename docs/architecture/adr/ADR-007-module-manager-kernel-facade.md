# ADR-007 — ModuleManager as Kernel Facade

**Status** : Accepted

**Date** : 2026-07-01

**Sprint** : CORE-001

---

# Context

Setelah metadata modul tersedia melalui `ModuleRegistry` dan status runtime dikelola oleh `ModuleStateRepository`, Kernel memerlukan sebuah komponen yang bertanggung jawab mengoordinasikan operasi terhadap modul.

Tanpa komponen tersebut, setiap command atau service harus berinteraksi langsung dengan beberapa komponen sekaligus, sehingga business rule akan tersebar di berbagai tempat.

Kernel membutuhkan satu titik masuk (_entry point_) untuk seluruh operasi terhadap modul.

---

# Decision

EduCore menggunakan `ModuleManager` sebagai **Kernel Facade** untuk operasi terhadap modul.

Seluruh operasi tingkat tinggi, seperti mengaktifkan atau menonaktifkan modul, dilakukan melalui `ModuleManager`.

`ModuleManager` bertindak sebagai _application service_ yang mengorkestrasi komponen lain tanpa mengambil alih tanggung jawab masing-masing.

---

# Responsibilities

`ModuleManager` bertanggung jawab untuk:

- Mengoordinasikan operasi terhadap modul.
- Memvalidasi keberadaan modul.
- Menjalankan business rule Kernel.
- Berinteraksi dengan `ModuleRegistry`.
- Berinteraksi dengan `ModuleStateRepository`.

`ModuleManager` **tidak bertanggung jawab** untuk:

- Menyimpan metadata.
- Menyimpan runtime state.
- Melakukan discovery modul.
- Membaca manifest.
- Mengakses filesystem secara langsung.

---

# Rationale

Pendekatan ini dipilih karena memberikan pemisahan tanggung jawab yang jelas.

Dengan menjadikan `ModuleManager` sebagai pintu masuk utama:

- Business rule tidak tersebar.
- Repository tetap fokus pada persistensi.
- Registry tetap fokus pada metadata.
- Command tetap sederhana.
- Evolusi platform menjadi lebih mudah.

Selain itu, perubahan aturan bisnis hanya dilakukan pada satu tempat.

---

# Consequences

## Positive

- Business rule terpusat.
- Command menjadi tipis.
- Repository tetap sederhana.
- Registry tetap independen.
- Mudah diuji.
- Mudah dikembangkan.

## Negative

- Menambah satu lapisan abstraksi.
- Membutuhkan disiplin agar business rule tidak kembali tersebar.

---

# Alternatives Considered

## Option A — Command Calls Repository Directly

Setiap command berinteraksi langsung dengan `ModuleRegistry` dan `ModuleStateRepository`.

**Ditolak** karena:

- Business rule tersebar.
- Coupling meningkat.
- Sulit dipelihara.

---

## Option B — Repository Contains Business Rules

Repository menangani validasi sekaligus persistensi.

**Ditolak** karena melanggar prinsip Single Responsibility.

Repository seharusnya hanya menangani penyimpanan data.

---

## Option C — ModuleManager (**Dipilih**)

Kernel menyediakan satu komponen yang bertindak sebagai orchestrator.

Seluruh business rule ditempatkan pada komponen ini sehingga command tetap sederhana dan repository tetap fokus pada tanggung jawabnya.

---

# Current Implementation

Status implementasi pada Sprint CORE-001:

- ✅ ModuleManager
- ✅ isEnabled()
- ✅ enable()
- ✅ disable()

Saat ini `ModuleManager` mengorkestrasi:

- `ModuleRegistry`
- `ModuleStateRepository`

---

# Future Evolution

Pada sprint berikutnya, `ModuleManager` akan menjadi pusat lifecycle modul.

Kemampuan yang direncanakan meliputi:

- install()
- uninstall()
- publish()
- update()
- reload()
- dependency validation
- health verification
- lifecycle events

Implementasi internal dapat berkembang dengan mendelegasikan tanggung jawab kepada service khusus seperti:

- ModuleActivator
- ModuleInstaller
- DependencyResolver
- HealthChecker

Namun `ModuleManager` tetap menjadi API utama yang digunakan oleh command, dashboard, maupun REST API.

---

# References

- ADR-005 — Module Registry as Metadata Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-008 — Thin Command Pattern
- PRD CORE-001
- Sprint 001
