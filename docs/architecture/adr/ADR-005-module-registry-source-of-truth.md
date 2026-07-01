# ADR-005 — Module Registry as Metadata Source of Truth

**Status** : Accepted

**Date** : 2026-07-01

**Sprint** : CORE-001

---

# Context

Setelah proses discovery selesai, Kernel telah memperoleh seluruh metadata dari setiap modul melalui `module.yaml`.

Pertanyaan berikutnya adalah bagaimana metadata tersebut sebaiknya diakses oleh komponen lain selama aplikasi berjalan.

Salah satu pendekatan adalah membaca kembali file manifest setiap kali metadata dibutuhkan.

Pendekatan lainnya adalah memuat seluruh metadata sekali pada saat bootstrap, kemudian menyimpannya di dalam sebuah registry yang dapat digunakan selama siklus hidup aplikasi.

---

# Decision

EduCore menggunakan **ModuleRegistry** sebagai satu-satunya sumber metadata modul selama runtime.

Seluruh metadata hasil parsing manifest dimuat ke dalam `ModuleRegistry` pada saat bootstrap.

Setelah proses bootstrap selesai, komponen lain **tidak diperbolehkan membaca kembali `module.yaml` secara langsung**.

Seluruh akses metadata harus dilakukan melalui `ModuleRegistry`.

---

# Rationale

Keputusan ini dipilih karena:

- Menghindari pembacaan file berulang.
- Menjamin konsistensi metadata.
- Menyediakan satu titik akses terhadap informasi modul.
- Mempermudah pengujian.
- Mengurangi coupling terhadap filesystem.

Module Registry bertindak sebagai cache metadata di dalam memory selama aplikasi berjalan.

---

# Consequences

## Positive

- Metadata hanya dibaca sekali.
- Performa bootstrap lebih baik dibanding pembacaan berulang.
- Semua komponen memperoleh data yang konsisten.
- Mempermudah perubahan implementasi di masa depan.

## Negative

- Membutuhkan memori tambahan untuk menyimpan metadata.
- Registry harus dibangun sebelum digunakan oleh komponen lain.

---

# Alternatives Considered

## Option A — Read Manifest Every Time

Setiap komponen membaca `module.yaml` ketika membutuhkan metadata.

**Ditolak** karena:

- Banyak akses filesystem.
- Metadata dapat menjadi tidak konsisten.
- Coupling tinggi terhadap format manifest.

---

## Option B — Global Configuration Array

Metadata disimpan dalam array global.

**Ditolak** karena:

- Tidak memiliki kontrak yang jelas.
- Sulit diuji.
- Tidak mendukung evolusi platform.

---

## Option C — Module Registry (**Dipilih**)

Kernel memuat metadata satu kali selama bootstrap dan menyediakannya melalui `ModuleRegistry`.

Pendekatan ini menjaga konsistensi dan memisahkan proses parsing dari proses penggunaan metadata.

---

# Registry Responsibilities

`ModuleRegistry` bertanggung jawab untuk:

- Menyimpan seluruh `ModuleDefinition`.
- Menyediakan pencarian berdasarkan Module ID.
- Menyediakan daftar seluruh modul.
- Menyediakan jumlah modul yang berhasil dimuat.

Registry **tidak bertanggung jawab** untuk:

- Menemukan modul.
- Membaca file manifest.
- Mengubah status runtime modul.
- Menjalankan business rule.

---

# Current Implementation

Status implementasi pada Sprint CORE-001:

- ✅ Module Registry
- ✅ register()
- ✅ get()
- ✅ has()
- ✅ all()
- ✅ count()

Registry menyimpan objek `ModuleDefinition` sebagai representasi metadata setiap modul.

---

# Future Evolution

ModuleRegistry dapat berkembang dengan kemampuan tambahan seperti:

- Lazy indexing.
- Lookup berdasarkan kategori.
- Lookup berdasarkan provider.
- Lookup berdasarkan dependency.
- Cached registry snapshot.

Seluruh evolusi tersebut tetap mempertahankan prinsip bahwa `ModuleRegistry` merupakan satu-satunya sumber metadata modul selama runtime.

---

# References

- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-006 — Runtime Module State Repository
- PRD CORE-001
- Sprint 001
