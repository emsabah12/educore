# ADR-005 — Module Registry as Metadata Source of Truth

Version : 1.0
Status : Accepted
Date : 2026-07-01
Updated : 2026-07-02
Sprint : CORE-001 Sprint-1


> ## Revalidation — 2026-08-12
> **Decision:** KEEP. `ModuleRegistry` remains the in-memory metadata source of truth for the current application process. Application-facing reads now normally go through concrete `ModuleRepository`, which also triggers guarded JIT bootstrap when the singleton registry is empty. Direct registry access should remain internal to Module Kernel composition/bootstrap concerns.

## Related ADR

- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-006 — Runtime Module State Repository
- ADR-010 — Module Identity Strategy

---

# Context

Setelah proses Discovery selesai, Platform Kernel telah memperoleh seluruh metadata dari setiap modul melalui `module.yaml`.

Pertanyaan berikutnya adalah bagaimana metadata tersebut sebaiknya diakses oleh komponen lain selama aplikasi berjalan.

Salah satu pendekatan adalah membaca kembali manifest setiap kali metadata dibutuhkan.

Pendekatan lainnya adalah memuat metadata satu kali pada saat bootstrap, kemudian menyimpannya di dalam sebuah registry yang dapat digunakan selama siklus hidup aplikasi.

---

# Decision

EduCore menggunakan **ModuleRegistry** sebagai **Single Source of Truth** untuk seluruh metadata modul selama runtime.

Seluruh metadata hasil Discovery Pipeline dimuat ke dalam `ModuleRegistry` pada saat bootstrap.

Setelah bootstrap selesai, komponen lain **tidak diperbolehkan membaca kembali `module.yaml` secara langsung**.

Seluruh akses metadata harus dilakukan melalui `ModuleRegistry`.

Metadata yang disimpan berupa objek `ModuleDefinition` yang bersifat immutable.

---

# Rationale

Keputusan ini dipilih karena:

- Menghindari pembacaan berulang terhadap filesystem.
- Menjamin konsistensi metadata.
- Menyediakan satu titik akses terhadap informasi modul.
- Mempermudah pengujian.
- Mengurangi coupling terhadap implementasi manifest.
- Memisahkan proses discovery dari penggunaan metadata.

`ModuleRegistry` bertindak sebagai penyimpanan metadata di dalam memori selama aplikasi berjalan.

---

# Consequences

## Positive

- Metadata hanya dibaca satu kali.
- Seluruh komponen menggunakan data yang konsisten.
- Discovery dan runtime terpisah dengan jelas.
- Metadata bersifat immutable.
- Implementasi lebih mudah diuji dan dikembangkan.

## Negative

- Membutuhkan memori tambahan untuk menyimpan metadata.
- Registry harus selesai dibangun sebelum digunakan oleh komponen lain.

---

# Alternatives Considered

## Option A — Read Manifest Every Time

Setiap komponen membaca `module.yaml` ketika membutuhkan metadata.

**Rejected**, karena:

- Banyak akses filesystem.
- Metadata dapat menjadi tidak konsisten.
- Coupling tinggi terhadap format manifest.

---

## Option B — Global Configuration Array

Metadata disimpan dalam array global.

**Rejected**, karena:

- Tidak memiliki kontrak yang jelas.
- Sulit diuji.
- Tidak mendukung evolusi Platform Kernel.

---

## Option C — Module Registry (**Accepted**)

Platform Kernel memuat metadata satu kali selama bootstrap dan menyediakannya melalui `ModuleRegistry`.

Pendekatan ini menjaga konsistensi metadata serta memisahkan proses discovery dari penggunaan metadata.

---

# Registry Responsibilities

`ModuleRegistry` bertanggung jawab untuk:

- Menyimpan seluruh objek `ModuleDefinition`.
- Menyediakan pencarian berdasarkan nama modul (`name`).
- Menyediakan daftar seluruh modul.
- Menyediakan jumlah modul yang berhasil diregistrasikan.

`ModuleRegistry` **tidak bertanggung jawab** untuk:

- Menemukan modul.
- Membaca `module.yaml`.
- Melakukan parsing manifest.
- Memvalidasi manifest.
- Mengelola runtime state.
- Menjalankan business rule.

Objek `ModuleDefinition` hanya dapat diregistrasikan melalui Discovery Pipeline.

---

# Current Implementation

Status implementasi pada akhir Sprint CORE-001:

- ✅ Module Registry.
- ✅ Penyimpanan immutable `ModuleDefinition`.
- ✅ `register()`.
- ✅ `get()`.
- ✅ `has()`.
- ✅ `all()`.
- ✅ `count()`.

Identity modul menggunakan field `name` sesuai ADR-010.

---

# Future Evolution

`ModuleRegistry` dapat berkembang dengan kemampuan tambahan seperti:

- Lazy indexing.
- Lookup berdasarkan kategori.
- Lookup berdasarkan provider.
- Lookup berdasarkan dependency.
- Cached registry snapshot.

Seluruh evolusi tersebut tetap mempertahankan prinsip bahwa `ModuleRegistry` merupakan **Single Source of Truth** untuk metadata modul selama runtime.

---

# References

- PRD CORE-001
- Sprint CORE-001
- `docs/architecture/discovery-flow.md`
- `docs/architecture/module-lifecycle.md`
- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-010 — Module Identity Strategy
