# ADR-004 — Automatic Module Discovery

**Status** : Accepted

**Date** : 2026-07-01

**Sprint** : CORE-001

---

# Context

EduCore dirancang sebagai platform modular yang dapat berkembang melalui penambahan modul baru.

Kernel memerlukan mekanisme untuk menemukan modul yang tersedia tanpa memerlukan registrasi manual setiap kali sebuah modul ditambahkan.

Registrasi manual akan meningkatkan biaya pemeliharaan, memperbesar kemungkinan kesalahan konfigurasi, dan menambah pekerjaan developer setiap kali membuat modul baru.

---

# Decision

EduCore menggunakan **Automatic Module Discovery**.

Kernel secara otomatis melakukan pemindaian terhadap direktori:

```

Modules/

```

Setiap subdirektori yang memiliki file:

```

module.yaml

```

akan dianggap sebagai sebuah modul yang valid.

Setelah ditemukan, manifest akan diproses melalui tahapan berikut:

```

Module Discovery
↓
Module Manifest Parser
↓
Manifest Validator
↓
Module Definition Factory
↓
Module Registry

```

Tidak diperlukan registrasi manual terhadap modul.

---

# Rationale

Automatic Module Discovery dipilih karena memberikan pengalaman pengembangan yang lebih sederhana dan konsisten.

Developer hanya perlu:

1. Membuat folder modul.
2. Menambahkan `module.yaml`.
3. Menambahkan Service Provider.

Kernel akan menemukan modul tersebut secara otomatis pada saat bootstrap.

Pendekatan ini mengurangi konfigurasi manual dan menjaga konsistensi proses bootstrap.

---

# Consequences

## Positive

- Tidak memerlukan registrasi manual.
- Menurunkan risiko human error.
- Mempermudah onboarding developer baru.
- Menambah modul menjadi proses yang sederhana.
- Bootstrap Kernel lebih konsisten.

## Negative

- Membutuhkan proses scanning direktori saat bootstrap.
- Manifest yang tidak valid harus ditangani dengan benar.
- Membutuhkan validasi sebelum modul diregistrasikan.

---

# Alternatives Considered

## Option A — Manual Registration

Setiap modul harus didaftarkan secara manual pada konfigurasi Kernel.

**Ditolak** karena:

- Mudah terlupakan.
- Tidak skalabel.
- Menambah konfigurasi yang harus dipelihara.

---

## Option B — Composer Package Discovery

Menggunakan mekanisme package discovery milik Composer.

**Ditolak** karena modul EduCore bukan package Composer independen.

Pendekatan ini akan memperumit struktur proyek tanpa memberikan manfaat yang signifikan.

---

## Option C — Automatic Discovery (**Dipilih**)

Kernel melakukan pemindaian direktori modul secara otomatis.

Pendekatan ini memberikan keseimbangan antara fleksibilitas dan kesederhanaan implementasi.

---

# Discovery Rules

Kernel menerapkan aturan berikut:

- Direktori root modul adalah `Modules/`.
- Setiap modul harus berada pada satu subdirektori.
- Setiap modul wajib memiliki `module.yaml`.
- Manifest harus lolos validasi sebelum diproses.
- Modul yang tidak valid tidak akan diregistrasikan.

---

# Current Implementation

Status implementasi pada Sprint CORE-001:

- ✅ Module Discovery
- ✅ Symfony YAML Parser
- ✅ Manifest Validator
- ✅ Module Definition Factory
- ✅ Module Registry
- ✅ Module Loader

---

# Future Evolution

Automatic Discovery dapat berkembang dengan kemampuan tambahan seperti:

- Recursive module discovery.
- Discovery cache.
- Module priority.
- Conditional discovery.
- Plugin directory.
- External module repository.

Seluruh evolusi tersebut tetap mempertahankan prinsip bahwa Kernel bertanggung jawab menemukan modul secara otomatis.

---

# References

- ADR-003 — Module Manifest Specification
- ADR-005 — Module Registry as Metadata Source of Truth
- PRD CORE-001
- Sprint 001
